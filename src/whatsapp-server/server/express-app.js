/**
 * @fileoverview Express application setup with CORS and middleware configuration
 * @module whatsapp-server/server/express-app
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines 350-427)
 * Configures Express app, CORS, body parsing, and API routes
 */

const express = require("express");
const bodyParser = require("body-parser");
const path = require("path");
const { log } = require("../../utils/logger");

// Shared state
let app = null;
let server = null;
let whatsappConnected = false;
let sock = null;
let db = null;
let INSTANCE_ID = 'default';
let instanceConfig = {};

function syncConnectionState(snapshot = {}, socketRef = null) {
    const connected = Boolean(snapshot.whatsappConnected);
    const status = snapshot.connectionStatus || (connected ? 'connected' : 'disconnected');
    const hasQR = Boolean(snapshot.hasQR);
    const qrCodeData = hasQR ? (snapshot.qrCodeData || null) : null;
    const lastConnectionError = snapshot.lastConnectionError || null;

    if (socketRef !== undefined) {
        sock = socketRef;
    }
    whatsappConnected = connected;

    // Keep globals updated for backward-compatible WebSocket initial snapshots.
    global.INSTANCE_ID = INSTANCE_ID;
    global.connectionStatus = status;
    global.whatsappConnected = connected;
    global.qrCodeData = qrCodeData;
    global.lastConnectionError = lastConnectionError;
}

/**
 * Set database instance
 * @param {Object} database - Database instance
 */
function setDb(database) {
    db = database;
}

/**
 * Get database instance
 * @returns {Object|null}
 */
function getDb() {
    return db;
}

/**
 * Set WhatsApp socket
 * @param {Object} socket - WhatsApp socket
 */
function setSocket(socket) {
    sock = socket;
}

/**
 * Get WhatsApp socket
 * @returns {Object|null}
 */
function getSocket() {
    return sock;
}

/**
 * Set WhatsApp connection status
 * @param {boolean} connected - Connection status
 */
function setWhatsAppConnected(connected) {
    whatsappConnected = connected;
}

/**
 * Check if WhatsApp is connected
 * @returns {boolean}
 */
function isWhatsAppConnected() {
    try {
        const connection = require('../whatsapp/connection');
        const status = connection.getConnectionStatus();
        if (status && typeof status.whatsappConnected === 'boolean') {
            whatsappConnected = status.whatsappConnected;
            return status.whatsappConnected;
        }
    } catch (err) {
        // Fallback to local state
    }
    return whatsappConnected;
}

/**
 * Set instance ID
 * @param {string} id - Instance ID
 */
function setInstanceId(id) {
    INSTANCE_ID = id;
}

/**
 * Get instance ID
 * @returns {string}
 */
function getInstanceId() {
    return INSTANCE_ID;
}

/**
 * Update instance configuration
 * @param {Object} updates - Configuration updates
 * @returns {Object}
 */
function updateInstanceConfig(updates) {
    instanceConfig = { ...instanceConfig, ...updates };
    return { updated: true, config: instanceConfig };
}

/**
 * Get server status
 * @returns {Object}
 */
function getStatus() {
    return {
        app,
        server,
        whatsappConnected,
        INSTANCE_ID,
        instanceConfig
    };
}

/**
 * Initialize WhatsApp connection
 * @returns {Promise<void>}
 */
async function initializeWhatsApp() {
    try {
        const connection = require('../whatsapp/connection');
        const websocketServer = require('./websocket-server');
        
        // Get instance ID from the config or environment
        const instanceId = INSTANCE_ID || process.env.INSTANCE_ID || 'default';
        
        // Get database module directly
        let dbModule = null;
        try {
            dbModule = require('../../../db-updated');
        } catch (e) {
            log('Database module not available:', e.message);
        }
        
        // Create persistInstanceStatus function
        const persistInstanceStatus = async (statusValue = null, connectionState = null) => {
            if (!dbModule || !dbModule.saveInstanceRecord) return;
            
            const payload = {};
            if (statusValue) payload.status = statusValue;
            if (connectionState) payload.connection_status = connectionState;
            
            try {
                await dbModule.saveInstanceRecord(instanceId, {
                    instance_id: instanceId,
                    ...payload
                });
                log('Instance status persisted:', statusValue, connectionState);
            } catch (err) {
                log('Error persisting instance status:', err.message);
            }
        };
        
        // Create updateInstancePhoneNumber function
        const updateInstancePhoneNumber = async (phoneJid) => {
            const normalized = (phoneJid || '').trim();
            if (!normalized || !dbModule || !dbModule.saveInstanceRecord) return;
            
            try {
                await dbModule.saveInstanceRecord(instanceId, {
                    instance_id: instanceId,
                    phone: normalized
                });
            } catch (err) {
                log('Error updating phone number:', err.message);
            }
        };
        
        // Create and connect with proper instance ID and callbacks
        const conn = await connection.connect(instanceId, {
            persistInstanceStatus,
            updateInstancePhoneNumber,
            broadcastToClients: websocketServer.broadcastToClients,
            onStateChange: (statusSnapshot, connectionRef) => {
                syncConnectionState(statusSnapshot, connectionRef?.socket || null);
            }
        });
        syncConnectionState(conn.getStatus(), conn.socket || null);
        log('WhatsApp connection initialized for instance:', instanceId);
    } catch (err) {
        log('Error initializing WhatsApp:', err.message);
        syncConnectionState({ whatsappConnected: false, connectionStatus: 'error', lastConnectionError: err.message }, null);
        throw err;
    }
}

/**
 * Logout WhatsApp connection
 * @returns {Promise<void>}
 */
async function logoutWhatsApp() {
    try {
        const connection = require('../whatsapp/connection');
        await connection.disconnect();
        syncConnectionState({ whatsappConnected: false, connectionStatus: 'disconnected', hasQR: false }, null);
        log('WhatsApp disconnected');
    } catch (err) {
        log('Error disconnecting WhatsApp:', err.message);
        throw err;
    }
}

/**
 * Restart WhatsApp connection
 * @returns {Promise<void>}
 */
async function restartWhatsApp() {
    await logoutWhatsApp();
    setTimeout(async () => {
        try {
            await initializeWhatsApp();
        } catch (err) {
            log('Error restarting WhatsApp:', err.message);
        }
    }, 2000);
}

/**
 * Reload configuration
 * @param {Object} options - Updated options
 */
function reloadConfig(options = {}) {
    if (options.instanceId) {
        setInstanceId(options.instanceId);
    }
    log('Configuration reloaded');
}

/**
 * Initialize Express application with middleware
 * @param {Object} options - Configuration options
 * @returns {Promise<{app: Object, server: Object}>}
 */
async function initialize(options = {}) {
    const { instanceId = 'default', database = null } = options;
    
    app = express();
    server = require("http").createServer(app);
    
    INSTANCE_ID = instanceId;
    global.INSTANCE_ID = INSTANCE_ID;
    if (database) {
        db = database;
    }
    
    // Body parsing middleware
    app.use(bodyParser.json({ limit: '50mb' }));
    app.use(bodyParser.urlencoded({ extended: true, limit: '50mb' }));
    
    // CORS configuration - use ALLOWED_ORIGINS env var (comma-separated list)
    const ALLOWED_ORIGINS = process.env.ALLOWED_ORIGINS?.split(',').map(o => o.trim()).filter(o => o) || [];
    app.use((req, res, next) => {
        const origin = req.headers.origin;
        // Check if origin is in allowed list (or if ALLOWED_ORIGINS is empty, deny all)
        if (ALLOWED_ORIGINS.length > 0 && origin && ALLOWED_ORIGINS.includes(origin)) {
            res.setHeader('Access-Control-Allow-Origin', origin);
        } else if (ALLOWED_ORIGINS.length === 0) {
            // If no ALLOWED_ORIGINS set, deny all cross-origin requests
            res.setHeader('Access-Control-Allow-Origin', 'null');
        } else {
            // Origin not allowed
            return res.status(403).json({ error: 'Origin not allowed' });
        }
        res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
        res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        if (req.method === 'OPTIONS') {
            return res.sendStatus(200);
        }
        next();
    });
    
    // Request logging middleware
    app.use((req, res, next) => {
        log(`${new Date().toISOString()} ${req.method} ${req.path}`);
        next();
    });
    
    // Static files
    app.use("/assets", express.static(path.join(__dirname, "../../assets")));
    app.use("/files", express.static(path.join(__dirname, "../../files")));
    app.use("/images", express.static(path.join(__dirname, "../../images")));
    app.use("/media", express.static(path.join(__dirname, "../../media")));
    
    // Health check endpoint - BACKWARD COMPATIBLE with PHP expectations
    app.get("/health", (req, res) => {
        res.json({ 
            ok: true,
            instanceId: INSTANCE_ID,
            status: whatsappConnected ? 'connected' : 'disconnected',
            whatsappConnected,
            timestamp: new Date().toISOString(),
            uptime: process.uptime()
        });
    });
    
    // Status endpoint - BACKWARD COMPATIBLE with PHP expectations
    app.get("/status", (req, res) => {
        res.json({
            ok: true,
            instanceId: INSTANCE_ID,
            connectionStatus: whatsappConnected ? 'connected' : 'disconnected',
            whatsappConnected
        });
    });
    
    // API routes
    app.use("/api", require("../routes/api"));
    
    // Error handling middleware
    app.use((err, req, res, next) => {
        console.error("Express error:", err);
        res.status(500).json({ 
            error: "Internal server error",
            message: err.message
        });
    });
    
    // 404 handler
    app.use((req, res) => {
        res.status(404).json({ error: "Not found" });
    });
    
    // Initialize WhatsApp connection automatically
    try {
        await initializeWhatsApp();
    } catch (err) {
        log('Auto-initialize WhatsApp failed:', err.message);
    }
    
    return { app, server };
}

module.exports = {
    initialize,
    setDb,
    getDb,
    setSocket,
    getSocket,
    setWhatsAppConnected,
    isWhatsAppConnected,
    setInstanceId,
    getInstanceId,
    updateInstanceConfig,
    getStatus,
    initializeWhatsApp,
    logoutWhatsApp,
    restartWhatsApp,
    reloadConfig
};
