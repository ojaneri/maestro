/**
 * @fileoverview Baileys socket setup, events, and reconnection logic
 * @module whatsapp-server/whatsapp/connection
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines 428-5200)
 * Manages WhatsApp connection lifecycle using Baileys library
 */

const path = require('path');
const { v4: uuidv4 } = require('uuid');
const { log } = require('../../utils/logger');

// Dynamic Baileys import (ESM)
let baileysModulePromise = null;
let baileysModule = null;

function getBaileys() {
    if (!baileysModulePromise) {
        baileysModulePromise = import('@whiskeysockets/baileys');
    }
    return baileysModulePromise;
}

/**
 * WhatsApp Connection Manager
 * Handles socket creation, event binding, and automatic reconnection
 */
class WhatsAppConnection {
    constructor(options = {}) {
        this.instanceId = options.instanceId || uuidv4();
        this.socket = null;
        this.authState = null;
        this.isConnected = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = options.maxReconnectAttempts || 5;
        this.reconnectDelay = options.reconnectDelay || 3000;
        
        // Baileys imports
        this.DisconnectReason = null;
        
        // Global state references (set by main module)
        this.broadcastToClients = options.broadcastToClients || (() => {});
        this.persistInstanceStatus = options.persistInstanceStatus || (() => {});
        this.updateInstancePhoneNumber = options.updateInstancePhoneNumber || (() => {});
        this.onStateChange = options.onStateChange || (() => {});
        this.instanceConfig = options.instanceConfig || {};
        
        // State variables
        this.connectionStatus = "starting";
        this.qrCodeData = null;
        this.whatsappConnected = false;
        this.lastConnectionError = null;
        this.restarting = false;
        
        // Caches
        this.groupMetadataCache = new Map();
    }

    /**
     * Initialize WhatsApp connection with authentication
     * @returns {Promise<Object>} - Socket instance
     */
    async connect() {
        log('Iniciando conexão Baileys...');
        this.restarting = false;

        try {
            const baileysPackage = await getBaileys();
            baileysModule = baileysPackage;
            const {
                default: makeWASocket,
                DisconnectReason,
                useMultiFileAuthState,
                fetchLatestBaileysVersion,
                Browsers
            } = baileysPackage;
            
            // Store DisconnectReason for use in event handlers
            this.DisconnectReason = DisconnectReason;

            const authDir = path.join(__dirname, `../../../../auth_${this.instanceId}`);
            const { state, saveCreds } = await useMultiFileAuthState(authDir);
            const { version } = await fetchLatestBaileysVersion();
            const browserTuple = (Browsers && typeof Browsers.macOS === "function")
                ? Browsers.macOS("Desktop")
                : ["Mac OS", "Chrome", "120.0.0"];

            this.authState = state;

            this.socket = makeWASocket({
                version,
                auth: state,
                printQRInTerminal: true,
                browser: browserTuple,
                syncFullHistory: false,
                cachedGroupMetadata: async (jid) => this.groupMetadataCache.get(jid)
            });

            // Bind event handlers
            this.bindEvents(saveCreds);

            this.isConnected = true;
            log('Socket Baileys criado com sucesso');
            return this.socket;
        } catch (err) {
            log('Erro ao iniciar WhatsApp:', err.message);
            this.lastConnectionError = err.message;
            this.connectionStatus = "error";
            throw err;
        }
    }

    /**
     * Bind all Baileys event handlers
     */
    bindEvents(saveCreds) {
        if (!this.socket) return;

        // Credentials update
        this.socket.ev.on('creds.update', saveCreds);

        // Connection update
        this.socket.ev.on('connection.update', (update) => {
            this.handleConnectionUpdate(update);
        });

        // Contacts upsert
        this.socket.ev.on('contacts.upsert', async (updates) => {
            await this.handleContactsUpsert(updates);
        });

        // Messages upsert
        this.socket.ev.on('messages.upsert', async (m) => {
            await this.handleMessagesUpsert(m);
        });
    }

    /**
     * Handle connection update events
     * @param {Object} update - Connection update data
     */
    handleConnectionUpdate(update) {
        const { connection, lastDisconnect, qr } = update;

        // QR Code handling
        if (qr) {
            this.qrCodeData = qr;
            this.connectionStatus = "qr";
            log('QR code atualizado');
            this.broadcastToClients("qr", { qr });
            this.broadcastToClients("status", {
                instanceId: this.instanceId,
                connectionStatus: this.connectionStatus,
                whatsappConnected: this.whatsappConnected,
                hasQR: !!this.qrCodeData
            });
            this.persistInstanceStatus("qr", "qr");
            this.emitStateChange();
        }

        // Connection opened
        if (connection === "open") {
            this.whatsappConnected = true;
            this.connectionStatus = "connected";
            this.qrCodeData = null;
            this.lastConnectionError = null;
            log('Conectado ao WhatsApp');
            
            const connectedPhone = this.socket?.user?.id || this.socket?.user?.jid || this.socket?.user?.name;
            if (connectedPhone) {
                this.updateInstancePhoneNumber(String(connectedPhone));
            }
            
            this.broadcastToClients("status", {
                instanceId: this.instanceId,
                connectionStatus: this.connectionStatus,
                whatsappConnected: this.whatsappConnected,
                hasQR: !!this.qrCodeData
            });
            this.persistInstanceStatus("connected", "connected");
            this.emitStateChange();
        }

        // Connection closed
        if (connection === "close") {
            this.whatsappConnected = false;
            this.connectionStatus = "disconnected";

            const reason = lastDisconnect?.error;
            this.lastConnectionError = reason?.message || null;

            log('Conexão fechada:', this.lastConnectionError || 'sem detalhe');

            this.broadcastToClients("status", {
                instanceId: this.instanceId,
                connectionStatus: this.connectionStatus,
                whatsappConnected: this.whatsappConnected,
                hasQR: !!this.qrCodeData,
                lastConnectionError: this.lastConnectionError
            });
            this.persistInstanceStatus("disconnected", "disconnected");
            this.emitStateChange();

            const shouldReconnect = reason?.output?.statusCode !== this.DisconnectReason?.loggedOut;

            if (shouldReconnect && !this.restarting) {
                log('Tentando reconectar automaticamente em 3s...');
                setTimeout(() => {
                    this.connect().catch(err => log('Erro ao reconectar:', err.message));
                }, 3000);
            } else {
                log('Sem reconexão automática (logout ou restart manual).');
            }
        }

        // Connection connecting
        if (connection === "connecting") {
            this.connectionStatus = "starting";
            this.broadcastToClients("status", {
                instanceId: this.instanceId,
                connectionStatus: this.connectionStatus,
                whatsappConnected: this.whatsappConnected,
                hasQR: !!this.qrCodeData
            });
            this.persistInstanceStatus("starting", "starting");
            this.emitStateChange();
        }
    }

    emitStateChange() {
        try {
            this.onStateChange(this.getStatus(), this);
        } catch (err) {
            log('Erro ao propagar mudança de estado:', err.message);
        }
    }

    /**
     * Handle contacts upsert (delegated to contacts handler)
     * @param {Array} updates - Contact updates
     */
    async handleContactsUpsert(updates) {
        const contactsHandler = require('./handlers/contacts');
        if (!Array.isArray(updates) || updates.length === 0) return;
        await Promise.allSettled(updates.map(contact => contactsHandler.handleContactUpsert(contact)));
    }

    /**
     * Handle incoming messages (delegated to messages handler)
     * @param {Object} data - Messages upsert data
     */
    async handleMessagesUpsert(m) {
        const messagesHandler = require('./handlers/messages');
        const contactsHandler = require('./handlers/contacts');
        try {
            const msgs = m.messages || [];
            if (msgs.length) {
                // Extract contact info from messages (pushName update)
                await Promise.allSettled(msgs.map(msg => contactsHandler.handleContactFromMessage(msg, this.socket)));
            }
            
            // Log incoming messages
            msgs.forEach(msg => {
                const text = msg.message?.conversation
                    || msg.message?.extendedTextMessage?.text
                    || msg.message?.extendedTextMessage?.contextInfo?.quotedMessage?.conversation
                    || "";
                log('messages.upsert incoming', {
                    remoteJid: msg.key?.remoteJid,
                    fromMe: msg.key?.fromMe,
                    stub: msg.messageStubType,
                    text: text ? text.substring(0, 80) : "[media]"
                });
            });

            // Broadcast basic message info
            const basic = msgs.map(msg => ({
                key: msg.key,
                pushName: msg.pushName,
                fromMe: msg.key?.fromMe,
                remoteJid: msg.key?.remoteJid,
                messageStubType: msg.messageStubType
            }));
            this.broadcastToClients("messages", { type: m.type, messages: basic });

            // Process messages with AI
            for (const msg of msgs) {
                if (msg.key?.fromMe) {
                    await messagesHandler.processOwnerQuickReply(msg);
                    continue;
                }
                await messagesHandler.processMessageWithAI(msg, this.socket);

                // Anti-ban: Mark as read with 5-10 second delay
                if (msg.key?.fromMe === false) {
                    setTimeout(() => {
                        if (this.socket && this.whatsappConnected) {
                            try {
                                this.socket.readMessages([msg.key]);
                            } catch (err) {
                                log('Erro ao marcar como lida:', err.message);
                            }
                        }
                    }, Math.random() * 5000 + 5000);
                }
            }
        } catch (e) {
            log('Error processing messages.upsert:', e.message);
        }
    }

    /**
     * Get group metadata
     * @param {string} groupJid - Group JID
     * @returns {Promise<Object>}
     */
    async getGroupMetadata(groupJid) {
        if (!groupJid) return null;
        if (this.groupMetadataCache.has(groupJid)) {
            return this.groupMetadataCache.get(groupJid);
        }
        if (!this.socket || typeof this.socket.groupMetadata !== "function") {
            return null;
        }
        try {
            const meta = await this.socket.groupMetadata(groupJid);
            if (meta) {
                this.groupMetadataCache.set(groupJid, meta);
            }
            return meta || null;
        } catch (err) {
            return null;
        }
    }

    /**
     * Disconnect from WhatsApp
     */
    async disconnect() {
        this.restarting = true;
        if (this.socket) {
            try {
                await this.socket.end();
            } catch (err) {
                log('Error disconnecting socket:', err.message);
            }
            this.socket = null;
            this.isConnected = false;
            this.whatsappConnected = false;
            this.connectionStatus = "disconnected";
            this.emitStateChange();
        }
    }

    /**
     * Get current connection status
     * @returns {Object}
     */
    getStatus() {
        return {
            instanceId: this.instanceId,
            isConnected: this.isConnected,
            whatsappConnected: this.whatsappConnected,
            connectionStatus: this.connectionStatus,
            reconnectAttempts: this.reconnectAttempts,
            hasQR: !!this.qrCodeData,
            qrCodeData: this.qrCodeData || null,
            lastConnectionError: this.lastConnectionError
        };
    }
}

module.exports = {
    WhatsAppConnection,
    getBaileys,
    connect,
    disconnect,
    setConnectionInstance,
    getSocket,
    getSelfJid,
    isConnected,
    getConnectionStatus,
    getQRCode
};

// Singleton instance for module access
let connectionInstance = null;

/**
 * Connect to WhatsApp with the given instance ID
 * @param {string} instanceId - Instance ID
 * @param {Object} options - Connection options (persistInstanceStatus, updateInstancePhoneNumber, etc)
 * @returns {Promise<WhatsAppConnection>}
 */
async function connect(instanceId = 'default', options = {}) {
    const conn = new WhatsAppConnection({ 
        instanceId,
        ...options
    });
    await conn.connect();
    connectionInstance = conn;
    return conn;
}

/**
 * Disconnect singleton connection instance
 * @returns {Promise<boolean>}
 */
async function disconnect() {
    if (!connectionInstance) {
        return false;
    }
    await connectionInstance.disconnect();
    connectionInstance = null;
    return true;
}

/**
 * Set connection instance (called by express-app)
 * @param {WhatsAppConnection} instance
 */
function setConnectionInstance(instance) {
    connectionInstance = instance;
}

/**
 * Get socket instance
 * @returns {Object|null}
 */
function getSocket() {
    return connectionInstance?.socket || null;
}

/**
 * Get self JID
 * @returns {string|null}
 */
function getSelfJid() {
    return connectionInstance?.socket?.authState?.creds?.me?.id || null;
}

/**
 * Check if connected
 * @returns {boolean}
 */
function isConnected() {
    return connectionInstance?.isConnected || false;
}

/**
 * Get connection status
 * @returns {Object}
 */
function getConnectionStatus() {
    return connectionInstance?.getStatus() || {};
}

/**
 * Get current QR code if available
 * @returns {string|null}
 */
function getQRCode() {
    return connectionInstance?.qrCodeData || null;
}
