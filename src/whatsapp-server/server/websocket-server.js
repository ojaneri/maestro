/**
 * @fileoverview WebSocket handlers and broadcast functionality
 * @module whatsapp-server/server/websocket-server
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines 367-426)
 * Manages WebSocket connections, message broadcasting, and client management
 */

const WebSocket = require("ws");
const { v4: uuidv4 } = require("uuid");
const { log } = require("../../utils/logger");

// Active WebSocket connections registry
let clientConnections = [];

/**
 * Initialize WebSocket server
 * @param {Object} httpServer - HTTP server instance
 * @param {Object} options - Configuration options
 * @returns {WebSocket.Server}
 */
function initialize(httpServer, options = {}) {
    const { path = "/ws" } = options;
    const wss = new WebSocket.Server({ server: httpServer, path });
    
    wss.on("connection", (ws, req) => {
        handleConnection(ws, req);
    });
    
    return wss;
}

/**
 * Handle new WebSocket connection
 * @param {WebSocket} ws - Client WebSocket
 * @param {Object} req - HTTP request
 */
function handleConnection(ws, req) {
    const clientId = uuidv4();
    log("Novo cliente WebSocket conectado:", clientId);
    
    const connectionInfo = {
        id: clientId,
        ws,
        connectedAt: new Date(),
        instanceId: null
    };
    
    clientConnections.push(connectionInfo);
    
    // Send initial state
    sendInitialState(ws, clientId);
    
    // Handle messages
    ws.on("message", async (message) => {
        await handleMessage(ws, clientId, message);
    });
    
    // Handle close
    ws.on("close", () => {
        log("Cliente WebSocket desconectado:", clientId);
        clientConnections = clientConnections.filter(c => c.id !== clientId);
    });
    
    // Handle errors
    ws.on("error", (err) => {
        log("Erro no WebSocket do cliente", clientId + ":", err.message);
    });
}

/**
 * Send initial state to newly connected client
 * @param {WebSocket} ws - Client WebSocket
 * @param {string} clientId - Client identifier
 */
function sendInitialState(ws, clientId) {
    try {
        const instanceId = global.INSTANCE_ID || 'unknown';
        const connectionStatus = global.connectionStatus || 'unknown';
        const whatsappConnected = global.whatsappConnected || false;
        const qrCodeData = global.qrCodeData || null;
        const lastConnectionError = global.lastConnectionError || null;
        
        ws.send(JSON.stringify({
            type: "status",
            data: {
                instanceId,
                connectionStatus,
                whatsappConnected,
                hasQR: !!qrCodeData,
                lastConnectionError
            }
        }));
        
        if (qrCodeData) {
            ws.send(JSON.stringify({
                type: "qr",
                data: { qr: qrCodeData }
            }));
        }
    } catch (e) {
        log("Erro ao enviar estado inicial WS:", e.message);
    }
}

/**
 * Handle incoming WebSocket messages
 * @param {WebSocket} ws - Client WebSocket
 * @param {string} clientId - Client identifier
 * @param {Buffer|string} message - Raw message
 */
async function handleMessage(ws, clientId, message) {
    try {
        const data = JSON.parse(message.toString());
        log("WebSocket message from", clientId + ":", data.type || "unknown");
        
        // Handle different message types
        switch (data.type) {
            case "ping":
                ws.send(JSON.stringify({ type: "pong", timestamp: Date.now() }));
                break;
                
            case "subscribe":
                // Handle subscription to instance updates
                log("Client", clientId, "subscribed to updates");
                break;
                
            case "status":
                // Request current status
                sendInitialState(ws, clientId);
                break;
                
            default:
                log("Unknown WebSocket message type:", data.type);
        }
    } catch (error) {
        log("Error parsing WebSocket message:", error.message);
    }
}

/**
 * Broadcast message to all connected clients
 * @param {string} type - Message type
 * @param {Object} data - Data to broadcast
 */
function broadcastToClients(type, data) {
    const payload = JSON.stringify({ type, data });
    clientConnections = clientConnections.filter(c => {
        if (c.ws.readyState === WebSocket.OPEN) {
            try {
                c.ws.send(payload);
                return true;
            } catch (e) {
                return false;
            }
        }
        return false;
    });
}

/**
 * Send message to specific client
 * @param {string} clientId - Target client ID
 * @param {Object} data - Data to send
 * @returns {boolean}
 */
function sendToClient(clientId, data) {
    const connection = clientConnections.find(c => c.id === clientId);
    if (connection && connection.ws.readyState === WebSocket.OPEN) {
        connection.ws.send(JSON.stringify(data));
        return true;
    }
    return false;
}

/**
 * Get all active connections
 * @returns {Array}
 */
function getActiveConnections() {
    return clientConnections.map(c => ({
        id: c.id,
        instanceId: c.instanceId,
        connectedAt: c.connectedAt
    }));
}

/**
 * Get connection count
 * @returns {number}
 */
function getConnectionCount() {
    return clientConnections.length;
}

module.exports = {
    initialize,
    broadcastToClients,
    sendToClient,
    getActiveConnections,
    getConnectionCount,
    handleConnection,
    handleMessage
};
