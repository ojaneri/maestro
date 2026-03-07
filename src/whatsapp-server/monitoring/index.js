/**
 * @fileoverview Monitoring and alarm system orchestration
 * @module whatsapp-server/monitoring
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Coordinates system monitoring and alarm notifications
 */

const alarms = require('./alarms');

const DEFAULT_CHECK_INTERVAL = 300000; // 5 minutes

let monitoringInterval = null;
let isMonitoring = false;
let lastHealthCheck = null;

/**
 * Start monitoring service
 * @param {Object} options - Monitoring options
 */
function start(options = {}) {
    const { checkInterval = DEFAULT_CHECK_INTERVAL, enabled = true } = options;
    
    if (!enabled) {
        console.log('Monitoring disabled');
        return { started: false, reason: 'disabled' };
    }
    
    if (isMonitoring) {
        console.log('Monitoring already running');
        return { started: false, reason: 'already_running' };
    }
    
    console.log(`Starting monitoring with ${checkInterval}ms interval`);
    isMonitoring = true;
    
    // Run immediately
    runHealthCheck().catch(err => 
        console.error('Erro na verificação inicial de saúde:', err.message)
    );
    
    // Set up interval
    monitoringInterval = setInterval(() => {
        runHealthCheck().catch(err => 
            console.error('Erro na verificação de saúde:', err.message)
        );
    }, checkInterval);
    
    return { started: true, interval: checkInterval };
}

/**
 * Stop monitoring service
 */
function stop() {
    if (monitoringInterval) {
        clearInterval(monitoringInterval);
        monitoringInterval = null;
        isMonitoring = false;
        console.log('Monitoring stopped');
        return { stopped: true };
    }
    return { stopped: false, reason: 'not_running' };
}

/**
 * Run health check across all instances
 * @returns {Promise<Object>}
 */
async function runHealthCheck() {
    try {
        const { INSTANCE_ID } = require('../config/constants');
        
        // Get system metrics
        const metrics = await collectSystemMetrics();
        
        // Check thresholds
        const triggeredAlarms = await alarms.checkThresholds(INSTANCE_ID, metrics);
        
        // Log results
        console.log(`Health check at ${new Date().toISOString()}:`, {
            instanceId: INSTANCE_ID,
            status: triggeredAlarms.length === 0 ? 'healthy' : 'issues',
            metrics,
            alarms: triggeredAlarms.length
        });
        
        // Store last result
        lastHealthCheck = {
            timestamp: new Date().toISOString(),
            status: triggeredAlarms.length === 0 ? 'healthy' : 'degraded',
            metrics,
            alarms: triggeredAlarms
        };
        
        return lastHealthCheck;
    } catch (error) {
        console.error('Error running health check:', error);
        lastHealthCheck = {
            timestamp: new Date().toISOString(),
            status: 'error',
            error: error.message
        };
        throw error;
    }
}

/**
 * Collect system metrics
 * @returns {Promise<Object>}
 */
async function collectSystemMetrics() {
    try {
        // Memory usage
        const memUsage = process.memoryUsage();
        const memoryUsage = Math.round((memUsage.heapUsed / memUsage.heapTotal) * 100);
        
        // CPU usage (simplified)
        const cpuUsage = process.cpuUsage().user;
        
        // Active connections from socket
        let activeConnections = 0;
        const sock = require('../server/express-app').getSocket();
        if (sock) {
            activeConnections = sock.active?.length || 0;
        }
        
        // Message queue size (if available)
        let queueSize = 0;
        const scheduler = require('../scheduler');
        const schedulerStatus = scheduler.getStatus();
        if (schedulerStatus.pendingMessages) {
            queueSize = schedulerStatus.pendingMessages;
        }
        
        // Uptime
        const uptime = process.uptime();
        
        return {
            memoryUsage,
            cpuUsage,
            activeConnections,
            queueSize,
            uptime,
            heapUsed: Math.round(memUsage.heapUsed / 1024 / 1024),
            heapTotal: Math.round(memUsage.heapTotal / 1024 / 1024)
        };
    } catch (error) {
        console.error('Error collecting system metrics:', error);
        return {
            error: error.message
        };
    }
}

/**
 * Schedule an alarm
 * @param {Object} alarmData - Alarm configuration
 * @returns {Promise<Object>}
 */
async function scheduleAlarm(alarmData) {
    return alarms.scheduleAlarm(alarmData);
}

/**
 * Clear a pending alarm
 * @param {string} alarmId - Alarm ID
 * @returns {Promise<Object>}
 */
async function clearAlarm(alarmId) {
    return alarms.clearPendingAlarm(alarmId);
}

/**
 * Clear alarm last sent
 * @param {string} instanceId - Instance ID
 * @param {string} alarmType - Alarm type
 * @returns {Promise<Object>}
 */
async function clearAlarmLastSent(instanceId, alarmType) {
    return alarms.clearAlarmLastSent(instanceId, alarmType);
}

/**
 * Check instance health
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object>}
 */
async function checkInstanceHealth(instanceId) {
    try {
        const metrics = await collectSystemMetrics();
        const thresholds = await alarms.checkThresholds(instanceId, metrics);
        
        return {
            instanceId,
            status: thresholds.length === 0 ? 'healthy' : 'degraded',
            lastCheck: new Date().toISOString(),
            metrics,
            thresholds: thresholds.length
        };
    } catch (error) {
        return {
            instanceId,
            status: 'error',
            lastCheck: new Date().toISOString(),
            error: error.message
        };
    }
}

/**
 * Get monitoring status
 * @returns {Object}
 */
function getStatus() {
    return {
        isRunning: isMonitoring,
        interval: monitoringInterval ? 'active' : 'inactive',
        lastCheck: lastHealthCheck,
        alarms: alarms.getAlarmStatus()
    };
}

/**
 * Get system metrics
 * @returns {Promise<Object>}
 */
async function getMetrics() {
    return collectSystemMetrics();
}

/**
 * Trigger immediate health check
 * @returns {Promise<Object>}
 */
async function triggerHealthCheck() {
    return runHealthCheck();
}

module.exports = {
    DEFAULT_CHECK_INTERVAL,
    start,
    stop,
    runHealthCheck,
    collectSystemMetrics,
    scheduleAlarm,
    clearAlarm,
    clearAlarmLastSent,
    checkInstanceHealth,
    getStatus,
    getMetrics,
    triggerHealthCheck,
    alarms
};
