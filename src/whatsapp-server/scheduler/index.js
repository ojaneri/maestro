/**
 * @fileoverview Scheduled message processor
 * @module whatsapp-server/scheduler
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~2244-2301)
 * Manages scheduled message processing and job execution
 */

const jobs = require('./jobs');

const SCHEDULE_CHECK_INTERVAL_MS = process.env.SCHEDULE_CHECK_INTERVAL_MS || 30000; // 30 seconds

let schedulerInterval = null;
let isSchedulerRunning = false;
let dbInstance = null;

/**
 * Initialize scheduler with database instance
 * @param {Object} database - Database instance
 */
function initialize(database) {
    dbInstance = database;
    console.log('[Scheduler] Database initialized');
}

/**
 * Start the scheduler service
 * @param {Object} options - Scheduler options
 */
function start(options = {}) {
    const { checkInterval = SCHEDULE_CHECK_INTERVAL_MS } = options;
    
    if (isSchedulerRunning) {
        console.log('Scheduler already running');
        return { started: false, reason: 'already_running' };
    }
    
    console.log(`Starting scheduler with ${checkInterval}ms interval`);
    isSchedulerRunning = true;
    
    // Run immediately
    jobs.fetchDueScheduledMessages().catch(err => 
        console.error('Erro inicial no scheduler:', err.message)
    );
    
    // Set up interval
    schedulerInterval = setInterval(() => {
        jobs.fetchDueScheduledMessages().catch(err => 
            console.error('Erro no scheduler:', err.message)
        );
    }, checkInterval);
    
    return { started: true, interval: checkInterval };
}

/**
 * Stop the scheduler service
 */
function stop() {
    if (schedulerInterval) {
        clearInterval(schedulerInterval);
        schedulerInterval = null;
        isSchedulerRunning = false;
        console.log('Scheduler stopped');
        return { stopped: true };
    }
    return { stopped: false, reason: 'not_running' };
}

/**
 * Schedule a new message
 * @param {Object} messageData - Message to schedule
 * @returns {Promise<Object>}
 */
async function scheduleMessage(messageData) {
    const { recipient, message, scheduledFor, tag = 'default', tipo = 'fixed', instanceId } = messageData;
    
    try {
        // Use initialized db or try express-app as fallback
        let db = dbInstance;
        if (!db) {
            try {
                // Try global db first
                db = global.db;
                if (!db) {
                    db = require('../server/express-app').getDb();
                }
            } catch (e) {}
        }
        
        const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
        
        if (!db) {
            throw new Error('Database not available');
        }
        
        if (!recipient || !message || !scheduledFor) {
            throw new Error('recipient, message, and scheduledFor are required');
        }
        
        // Validate scheduledFor is a valid date
        const scheduledDate = new Date(scheduledFor);
        if (isNaN(scheduledDate.getTime())) {
            throw new Error('Invalid scheduledFor date');
        }
        
        const result = await db.enqueueScheduledMessage(
            currentInstanceId,
            recipient,
            message,
            scheduledDate,
            tag,
            tipo
        );
        
        console.log(`Mensagem agendada para ${scheduledFor}: ${recipient}`);
        
        return {
            scheduledId: result.scheduledId,
            scheduledAt: result.scheduledAt,
            recipient,
            tag,
            tipo,
            status: 'pending'
        };
    } catch (error) {
        console.error('Error scheduling message:', error);
        throw error;
    }
}

/**
 * Schedule a relative message (from now)
 * @param {Object} messageData - Message to schedule
 * @returns {Promise<Object>}
 */
async function scheduleRelativeMessage(messageData) {
    const { recipient, message, relativeTime, tag = 'default', tipo = 'relative', instanceId } = messageData;
    
    try {
        // Use initialized db or try express-app as fallback
        let db = dbInstance;
        if (!db) {
            try {
                db = require('../server/express-app').getDb();
            } catch (e) {}
        }
        const { INSTANCE_ID } = require('../config/constants');
        
        if (!db) {
            throw new Error('Database not available');
        }
        
        if (!recipient || !message || !relativeTime) {
            throw new Error('recipient, message, and relativeTime are required');
        }
        
        // Parse relative time (e.g., "1h", "30m", "2d")
        const scheduledDate = parseRelativeTime(relativeTime);
        if (!scheduledDate) {
            throw new Error('Invalid relative time format');
        }
        
        const result = await db.enqueueScheduledMessage(
            INSTANCE_ID,
            recipient,
            message,
            scheduledDate,
            tag,
            tipo
        );
        
        console.log(`Mensagem agendada para ${scheduledDate.toISOString()}: ${recipient}`);
        
        return {
            scheduledId: result.scheduledId,
            scheduledAt: result.scheduledAt,
            recipient,
            relativeTime,
            tag,
            tipo,
            status: 'pending'
        };
    } catch (error) {
        console.error('Error scheduling relative message:', error);
        throw error;
    }
}

/**
 * Parse relative time string
 * @param {string} timeStr - Time string (e.g., "1h", "30m", "2d")
 * @returns {Date|null}
 */
function parseRelativeTime(timeStr) {
    if (!timeStr || typeof timeStr !== 'string') return null;
    
    const match = timeStr.match(/^(\d+)([mhd])$/i);
    if (!match) return null;
    
    const value = parseInt(match[1], 10);
    const unit = match[2].toLowerCase();
    
    const now = new Date();
    
    switch (unit) {
        case 'm': // minutes
            return new Date(now.getTime() + value * 60 * 1000);
        case 'h': // hours
            return new Date(now.getTime() + value * 60 * 60 * 1000);
        case 'd': // days
            return new Date(now.getTime() + value * 24 * 60 * 60 * 1000);
        default:
            return null;
    }
}

/**
 * Cancel a scheduled message
 * @param {string} messageId - Message ID to cancel
 * @returns {Promise<Object>}
 */
async function cancelScheduledMessage(messageId) {
    try {
        // Use initialized db or try express-app as fallback
        let db = dbInstance;
        if (!db) {
            try {
                db = require('../server/express-app').getDb();
            } catch (e) {}
        }
        const { INSTANCE_ID } = require('../config/constants');
        
        if (!db) {
            throw new Error('Database not available');
        }
        
        const result = await db.deleteScheduledMessage(messageId);
        console.log(`Cancelling scheduled message: ${messageId}`);
        
        return {
            success: true,
            messageId,
            deleted: result?.deleted || 1,
            message: 'Mensagem agendada cancelada'
        };
    } catch (error) {
        console.error('Error cancelling scheduled message:', error);
        throw error;
    }
}

/**
 * Cancel scheduled messages by tag
 * @param {string} tag - Tag to filter
 * @param {string} recipient - Optional recipient filter
 * @returns {Promise<Object>}
 */
async function cancelScheduledMessagesByTag(tag, recipient = null) {
    try {
        // Use initialized db or try express-app as fallback
        let db = dbInstance;
        if (!db) {
            try {
                db = require('../server/express-app').getDb();
            } catch (e) {}
        }
        const { INSTANCE_ID } = require('../config/constants');
        
        if (!db) {
            throw new Error('Database not available');
        }
        
        const result = await db.deleteScheduledMessagesByTag(INSTANCE_ID, recipient, tag);
        console.log(`Cancelling scheduled messages with tag: ${tag}`);
        
        return {
            success: true,
            tag,
            deleted: result?.deleted || 0,
            message: `Agendamentos com tag ${tag} cancelados`
        };
    } catch (error) {
        console.error('Error cancelling scheduled messages by tag:', error);
        throw error;
    }
}

/**
 * List scheduled messages
 * @param {Object} filters - Optional filters
 * @returns {Promise<Array>}
 */
async function listScheduledMessages(filters = {}) {
    try {
        // Use initialized db or try express-app as fallback
        let db = dbInstance;
        if (!db) {
            try {
                db = require('../server/express-app').getDb();
            } catch (e) {}
        }
        const { INSTANCE_ID } = require('../config/constants');
        
        if (!db) {
            throw new Error('Database not available');
        }
        
        const { tag = null, tipo = null, recipient = null } = filters;
        
        const messages = await db.listScheduledMessages(INSTANCE_ID, recipient, tag, tipo);
        return messages;
    } catch (error) {
        console.error('Error listing scheduled messages:', error);
        throw error;
    }
}

/**
 * Check availability for scheduling
 * @param {string} date - Date string
 * @param {string} time - Time string
 * @returns {Promise<Object>}
 */
async function checkAvailability(date, time) {
    const calendar = require('../calendar');
    return calendar.checkAvailability(date, time);
}

/**
 * Get scheduler status
 * @returns {Object}
 */
function getStatus() {
    return {
        isRunning: isSchedulerRunning,
        interval: schedulerInterval ? 'active' : 'inactive',
        ...jobs.getSchedulerStatus()
    };
}

module.exports = {
    SCHEDULE_CHECK_INTERVAL_MS,
    initialize,
    start,
    stop,
    scheduleMessage,
    scheduleRelativeMessage,
    cancelScheduledMessage,
    cancelScheduledMessagesByTag,
    listScheduledMessages,
    checkAvailability,
    getStatus,
    parseRelativeTime
};
