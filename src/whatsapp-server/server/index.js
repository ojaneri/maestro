/**
 * @fileoverview Main entry point for WhatsApp server - Express + WebSocket setup
 * @module whatsapp-server/server
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Coordinates Express app initialization, WebSocket server, and all modules
 */

const { INSTANCE_ID: GLOBAL_INSTANCE_ID, PORT: GLOBAL_PORT } = require('../../config/globals');
const expressApp = require('./express-app');
const websocketServer = require('./websocket-server');

// Import database module for scheduler
const dbModule = require('../../../db-updated');

let serverInstance = null;
let wssInstance = null;

/**
 * Initialize and start the WhatsApp server
 * @param {Object} options - Server configuration options
 * @returns {Promise<{express: Object, wss: Object, server: Object}>}
 */
async function startServer(options = {}) {
    const { 
        port = GLOBAL_PORT || 3000, 
        instanceId = GLOBAL_INSTANCE_ID || 'default',
        host = '0.0.0.0',
        startScheduler = true,
        startMonitoring = true,
        startAI = true
    } = options;
    
    console.log(`Starting WhatsApp Server on http://${host}:${port}`);
    console.log(`Instance ID: ${instanceId}`);
    console.log('Initializing modules...');
    
    // Initialize Express app with instance ID and database
    const { app, server } = await expressApp.initialize({ 
        instanceId,
        database: dbModule
    });
    console.log('✓ Express app initialized');
    
    // Initialize WebSocket server
    const wss = await websocketServer.initialize(server);
    console.log('✓ WebSocket server initialized');
    
    // Start scheduler if enabled
    if (startScheduler) {
        try {
            const scheduler = require('../scheduler');
            scheduler.initialize(dbModule);
            console.log('[Server] Database module initialized for scheduler');
            scheduler.start();
            console.log('✓ Scheduler started');
        } catch (err) {
            console.error('✗ Failed to start scheduler:', err.message);
        }
    }
    
    // Start monitoring if enabled
    if (startMonitoring) {
        try {
            const monitoring = require('../monitoring');
            monitoring.start();
            console.log('✓ Monitoring started');
        } catch (err) {
            console.error('✗ Failed to start monitoring:', err.message);
        }
    }
    
    // Initialize AI engine
    if (startAI) {
        try {
            const ai = require('../ai');
            await ai.initialize();
            console.log('✓ AI engine initialized');
        } catch (err) {
            console.error('✗ Failed to initialize AI:', err.message);
        }
    }
    
    // Start HTTP server
    return new Promise((resolve, reject) => {
        server.listen(port, host, () => {
            console.log(`\n╔════════════════════════════════════════╗`);
            console.log(`║   WhatsApp Server running on :${port}    ║`);
            console.log(`╚════════════════════════════════════════╝\n`);
            
            serverInstance = server;
            wssInstance = wss;
            
            resolve({ express: app, wss, server });
        });
        
        server.on('error', (err) => {
            console.error('Server error:', err);
            reject(err);
        });
    });
}

/**
 * Stop the server gracefully
 * @param {Object} server - HTTP server instance
 */
async function stopServer(server) {
    console.log('\nShutting down WhatsApp Server...');
    
    // Stop scheduler
    try {
        const scheduler = require('../scheduler');
        scheduler.stop();
        console.log('✓ Scheduler stopped');
    } catch (err) {
        console.error('Error stopping scheduler:', err.message);
    }
    
    // Stop monitoring
    try {
        const monitoring = require('../monitoring');
        monitoring.stop();
        console.log('✓ Monitoring stopped');
    } catch (err) {
        console.error('Error stopping monitoring:', err.message);
    }
    
    // Close WebSocket connections
    if (wssInstance) {
        wssInstance.clients.forEach(client => {
            client.close(1001, 'Server shutting down');
        });
        console.log('✓ WebSocket connections closed');
    }
    
    // Close HTTP server
    return new Promise((resolve) => {
        if (serverInstance) {
            serverInstance.close(() => {
                console.log('✓ HTTP server closed');
                serverInstance = null;
                wssInstance = null;
                console.log('\nWhatsApp Server stopped');
                resolve();
            });
        } else {
            resolve();
        }
    });
}

/**
 * Restart WhatsApp connection
 * @returns {Promise<Object>}
 */
async function restartWhatsApp() {
    console.log('Restarting WhatsApp connection...');
    await expressApp.logoutWhatsApp();
    
    setTimeout(async () => {
        try {
            await expressApp.initializeWhatsApp();
            console.log('✓ WhatsApp connection restarted');
        } catch (err) {
            console.error('✗ Failed to restart WhatsApp:', err.message);
        }
    }, 2000);
    
    return { ok: true, message: "Restart solicitado" };
}

/**
 * Get server status
 * @returns {Object}
 */
function getServerStatus() {
    const scheduler = require('../scheduler');
    const monitoring = require('../monitoring');
    const expressStatus = expressApp.getStatus();
    
    return {
        server: {
            running: serverInstance !== null,
            port: serverInstance?.address()?.port || null
        },
        websocket: {
            clients: wssInstance?.clients?.size || 0
        },
        whatsapp: {
            connected: expressStatus.whatsappConnected,
            instanceId: expressStatus.INSTANCE_ID
        },
        scheduler: scheduler.getStatus(),
        monitoring: monitoring.getStatus()
    };
}

/**
 * Reload configuration
 * @param {Object} options - Updated options
 */
function reloadConfig(options = {}) {
    console.log('Reloading configuration...');
    expressApp.reloadConfig(options);
}

module.exports = {
    startServer,
    stopServer,
    restartWhatsApp,
    getServerStatus,
    reloadConfig
};
