/**
 * @fileoverview Alarm scheduling and notification handling
 * @module whatsapp-server/monitoring/alarms
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~4407-4441)
 * Manages alarms, notifications, and alerting
 */

// In-memory alarm storage (production: use Redis)
const pendingAlarms = new Map();
const alarmHistory = [];
const ALARM_TTL_MS = 24 * 60 * 60 * 1000; // 24 hours

/**
 * Schedule an alarm
 * @param {Object} alarmData - Alarm configuration
 * @returns {Promise<Object>}
 */
async function scheduleAlarm(alarmData) {
    const { 
        instanceId, 
        type, 
        targetTime, 
        recipients = [], 
        message,
        metadata = {} 
    } = alarmData;
    
    const alarmId = `alarm_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    
    const alarm = {
        id: alarmId,
        instanceId,
        type,
        targetTime: new Date(targetTime).getTime(),
        recipients,
        message,
        metadata,
        status: 'scheduled',
        createdAt: Date.now()
    };
    
    // Store alarm
    pendingAlarms.set(alarmId, alarm);
    
    // Schedule timeout
    const delay = alarm.targetTime - Date.now();
    if (delay > 0) {
        alarm.timeoutId = setTimeout(() => {
            triggerAlarm(alarm);
        }, delay);
    } else {
        // Immediate trigger
        triggerAlarm(alarm);
    }
    
    console.log(`Scheduling alarm: ${type} for instance ${instanceId} at ${new Date(targetTime).toISOString()}`);
    
    return alarm;
}

/**
 * Clear a pending alarm
 * @param {string} alarmId - Alarm ID
 * @returns {Promise<Object>}
 */
async function clearPendingAlarm(alarmId) {
    const alarm = pendingAlarms.get(alarmId);
    if (!alarm) {
        return { cleared: false, reason: 'alarm_not_found' };
    }
    
    if (alarm.timeoutId) {
        clearTimeout(alarm.timeoutId);
    }
    
    alarm.status = 'cancelled';
    pendingAlarms.delete(alarmId);
    
    console.log(`Cleared pending alarm: ${alarmId}`);
    
    return { cleared: true, alarmId, status: 'cancelled' };
}

/**
 * Clear the last sent alarm for a specific type
 * @param {string} instanceId - Instance ID
 * @param {string} alarmType - Alarm type
 * @returns {Promise<Object>}
 */
async function clearAlarmLastSent(instanceId, alarmType) {
    // Find and remove from history
    const index = alarmHistory.findIndex(
        a => a.instanceId === instanceId && a.type === alarmType && a.status === 'sent'
    );
    
    if (index !== -1) {
        alarmHistory.splice(index, 1);
        console.log(`Cleared last sent alarm for ${instanceId}/${alarmType}`);
        return { cleared: true, instanceId, alarmType };
    }
    
    return { cleared: false, reason: 'no_sent_alarm_found' };
}

/**
 * Trigger alarm notification
 * @param {Object} alarm - Alarm object
 * @param {Object} data - Additional data
 * @returns {Promise<Object>}
 */
async function triggerAlarm(alarm, data = {}) {
    const { id, type, recipients, message, instanceId, metadata } = alarm;
    
    console.log(`Triggering alarm ${id}: ${type} for instance ${instanceId}`);
    
    alarm.status = 'triggering';
    alarm.triggeredAt = Date.now();
    
    const results = [];
    
    for (const recipient of recipients) {
        try {
            const result = await sendNotification(recipient, {
                type: 'alarm',
                alarmId: id,
                alarmType: type,
                instanceId,
                message: `${message}\n\nDetalhes: ${JSON.stringify({ ...data, ...metadata })}`,
                timestamp: new Date().toISOString()
            });
            results.push({ recipient, success: true, ...result });
        } catch (error) {
            console.error(`Error sending alarm notification to ${recipient}:`, error);
            results.push({ recipient, success: false, error: error.message });
        }
    }
    
    alarm.status = 'sent';
    alarm.results = results;
    
    // Move to history
    pendingAlarms.delete(id);
    alarmHistory.push(alarm);
    
    // Clean old history
    cleanupAlarmHistory();
    
    return { 
        alarmId: id, 
        type, 
        instanceId, 
        notified: recipients.length,
        results 
    };
}

/**
 * Send notification to recipient
 * @param {string} recipient - Recipient identifier
 * @param {Object} notification - Notification content
 * @returns {Promise<Object>}
 */
async function sendNotification(recipient, notification) {
    try {
        // Try WhatsApp notification first
        const { sendWhatsAppMessage } = require('../whatsapp/send-message');
        const sock = require('../server/express-app').getSocket();
        
        if (sock && recipient.includes('@')) {
            await sendWhatsAppMessage(sock, recipient, { text: notification.message });
            console.log(`WhatsApp notification sent to ${recipient}`);
            return { success: true, channel: 'whatsapp', recipient };
        }
        
        // Fallback: log notification
        console.log(`Notification for ${recipient}:`, notification.message?.substring(0, 200));
        return { success: true, channel: 'log', recipient };
    } catch (error) {
        console.error(`Error sending notification to ${recipient}:`, error);
        throw error;
    }
}

/**
 * Check alarm thresholds
 * @param {string} instanceId - Instance ID
 * @param {Object} metrics - Current metrics
 * @returns {Promise<Array>}
 */
async function checkThresholds(instanceId, metrics) {
    const triggeredAlarms = [];
    
    // Memory threshold check (default: 90%)
    if (metrics.memoryUsage && metrics.memoryUsage > 90) {
        triggeredAlarms.push({
            type: 'memory_high',
            threshold: 90,
            current: metrics.memoryUsage,
            message: `Memory usage high: ${metrics.memoryUsage}%`
        });
    }
    
    // CPU threshold check (default: 90%)
    if (metrics.cpuUsage && metrics.cpuUsage > 90) {
        triggeredAlarms.push({
            type: 'cpu_high',
            threshold: 90,
            current: metrics.cpuUsage,
            message: `CPU usage high: ${metrics.cpuUsage}%`
        });
    }
    
    // Connection threshold check (default: 1000)
    if (metrics.activeConnections && metrics.activeConnections > 1000) {
        triggeredAlarms.push({
            type: 'connections_high',
            threshold: 1000,
            current: metrics.activeConnections,
            message: `Active connections high: ${metrics.activeConnections}`
        });
    }
    
    // Message queue threshold
    if (metrics.queueSize && metrics.queueSize > 100) {
        triggeredAlarms.push({
            type: 'queue_high',
            threshold: 100,
            current: metrics.queueSize,
            message: `Message queue high: ${metrics.queueSize}`
        });
    }
    
    // Response time threshold
    if (metrics.avgResponseTime && metrics.avgResponseTime > 5000) {
        triggeredAlarms.push({
            type: 'response_time_high',
            threshold: 5000,
            current: metrics.avgResponseTime,
            message: `Average response time high: ${metrics.avgResponseTime}ms`
        });
    }
    
    return triggeredAlarms;
}

/**
 * Get pending alarms
 * @returns {Array}
 */
function getPendingAlarms() {
    return Array.from(pendingAlarms.values());
}

/**
 * Get alarm history
 * @param {Object} options - Filter options
 * @returns {Array}
 */
function getAlarmHistory(options = {}) {
    const { instanceId, type, limit = 100 } = options;
    
    let history = alarmHistory;
    
    if (instanceId) {
        history = history.filter(a => a.instanceId === instanceId);
    }
    
    if (type) {
        history = history.filter(a => a.type === type);
    }
    
    return history.slice(-limit);
}

/**
 * Clean up old alarm history
 */
function cleanupAlarmHistory() {
    const cutoff = Date.now() - ALARM_TTL_MS;
    
    while (alarmHistory.length > 0 && alarmHistory[0].triggeredAt < cutoff) {
        alarmHistory.shift();
    }
}

/**
 * Get alarm status summary
 * @returns {Object}
 */
function getAlarmStatus() {
    return {
        pending: pendingAlarms.size,
        historyCount: alarmHistory.length,
        pendingAlarms: getPendingAlarms().map(a => ({
            id: a.id,
            type: a.type,
            instanceId: a.instanceId,
            targetTime: new Date(a.targetTime).toISOString()
        }))
    };
}

module.exports = {
    pendingAlarms,
    alarmHistory,
    scheduleAlarm,
    clearPendingAlarm,
    clearAlarmLastSent,
    triggerAlarm,
    sendNotification,
    checkThresholds,
    getPendingAlarms,
    getAlarmHistory,
    cleanupAlarmHistory,
    getAlarmStatus
};
