/**
 * @fileoverview connection.update handler - manages WhatsApp connection state changes
 * @module whatsapp-server/whatsapp/handlers/connection
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines 5039-5129)
 * Handles connection lifecycle events, QR code generation, and reconnection logic
 */

const { log } = require('../../../utils/logger');

/**
 * DisconnectReason constants (from Baileys)
 */
const DISCONNECT_REASON = {
    loggedOut: 401,
    connectionClosed: 428,
    connectionLost: 428,
    connectionReplaced: 432,
    serviceUnavailable: 503,
    badSession: 500,
    restartRequired: 515
};

/**
 * Process connection update events
 * @param {Object} update - Connection update from Baileys
 * @param {Object} options - Handler options
 */
async function process(update, options = {}) {
    const { connection, lastDisconnect, qr } = update;
    
    const {
        socket = null,
        broadcastToClients = () => {},
        persistInstanceStatus = () => {},
        updateInstancePhoneNumber = () => {},
        instanceId = 'unknown',
        instanceConfig = {}
    } = options;

    // Handle QR code
    if (qr) {
        await handleQRCode(qr, { broadcastToClients, instanceId });
    }

    // Handle connection states
    switch (connection) {
        case 'open':
            await handleConnectionOpen({
                socket,
                broadcastToClients,
                persistInstanceStatus,
                updateInstancePhoneNumber,
                instanceId,
                instanceConfig
            });
            break;
            
        case 'close':
            await handleConnectionClose(lastDisconnect, {
                socket,
                broadcastToClients,
                persistInstanceStatus,
                instanceId,
                restarting: options.restarting || false
            });
            break;
            
        case 'connecting':
            await handleConnectionConnecting({
                broadcastToClients,
                persistInstanceStatus,
                instanceId
            });
            break;
            
        case 'waiting':
            log('Waiting for connection...');
            break;
            
        default:
            log(`Unknown connection state: ${connection}`);
    }
}

/**
 * Handle successful connection
 * @param {Object} options - Handler options
 */
async function handleConnectionOpen(options) {
    const {
        socket,
        broadcastToClients,
        persistInstanceStatus,
        updateInstancePhoneNumber,
        instanceId
    } = options;
    
    log('WhatsApp connection established successfully');
    
    // Notify via WebSocket
    broadcastToClients("status", {
        instanceId,
        connectionStatus: "connected",
        whatsappConnected: true,
        hasQR: false
    });
    
    persistInstanceStatus("connected", "connected");
    
    // Update phone number if available
    const connectedPhone = socket?.user?.id || socket?.user?.jid || socket?.user?.name;
    if (connectedPhone) {
        updateInstancePhoneNumber(String(connectedPhone));
    }
}

/**
 * Handle connection closed
 * @param {Object} lastDisconnect - Last disconnect data
 * @param {Object} options - Handler options
 */
async function handleConnectionClose(lastDisconnect, options) {
    const {
        socket,
        broadcastToClients,
        persistInstanceStatus,
        instanceId,
        restarting
    } = options;
    
    const reason = lastDisconnect?.error;
    const statusCode = reason?.output?.statusCode;
    const errorMessage = reason?.message || null;
    const isLoggedOut = statusCode === DISCONNECT_REASON.loggedOut;
    
    log(`Connection closed. Status code: ${statusCode}, Error: ${errorMessage}`);
    
    broadcastToClients("status", {
        instanceId,
        connectionStatus: "disconnected",
        whatsappConnected: false,
        hasQR: false,
        lastConnectionError: errorMessage
    });
    
    persistInstanceStatus("disconnected", "disconnected");
    
    // Handle reconnection logic
    const shouldReconnect = statusCode !== DISCONNECT_REASON.loggedOut;
    
    if (shouldReconnect && !restarting) {
        log('Attempting to reconnect in 3 seconds...');
        // Return reconnection signal
        return { shouldReconnect: true, delay: 3000 };
    } else if (isLoggedOut) {
        log('Session expired. Please scan QR code again.');
        broadcastToClients("status", {
            instanceId,
            connectionStatus: "session_expired",
            message: 'Sessão expirada. Por favor, escaneie o QR Code novamente.'
        });
    } else {
        log('No automatic reconnection (logout or manual restart).');
    }
    
    return { shouldReconnect: false };
}

/**
 * Handle connection in progress
 * @param {Object} options - Handler options
 */
async function handleConnectionConnecting(options) {
    const { broadcastToClients, persistInstanceStatus, instanceId } = options;
    
    log('Connecting to WhatsApp...');
    
    broadcastToClients("status", {
        instanceId,
        connectionStatus: "starting",
        whatsappConnected: false,
        hasQR: false
    });
    
    persistInstanceStatus("starting", "starting");
}

/**
 * Handle QR code generation
 * @param {string} qr - QR code data
 * @param {Object} options - Handler options
 */
async function handleQRCode(qr, options) {
    const { broadcastToClients, instanceId } = options;
    
    log('QR code received. Scan to connect.');
    
    // Broadcast QR code to clients
    broadcastToClients("qr", { qr });
    broadcastToClients("status", {
        instanceId,
        connectionStatus: "qr",
        whatsappConnected: false,
        hasQR: true
    });
}

/**
 * Determine if should reconnect based on disconnect reason
 * @param {Object} lastDisconnect - Last disconnect data
 * @returns {boolean}
 */
function shouldReconnect(lastDisconnect) {
    const reason = lastDisconnect?.error;
    const statusCode = reason?.output?.statusCode;
    return statusCode !== DISCONNECT_REASON.loggedOut;
}

module.exports = {
    process,
    handleConnectionOpen,
    handleConnectionClose,
    handleConnectionConnecting,
    handleQRCode,
    shouldReconnect,
    DISCONNECT_REASON
};
