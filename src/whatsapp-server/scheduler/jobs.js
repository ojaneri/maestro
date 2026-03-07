/**
 * @fileoverview Scheduled message job handler
 * @module whatsapp-server/scheduler/jobs
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~2244-2301)
 * Fetches and processes due scheduled messages
 */

const { sendWhatsAppMessage } = require('../whatsapp/send-message');

const SCHEDULE_FETCH_LIMIT = 50;

// Process state
let scheduleProcessorRunning = false;
let lastProcessedTime = null;

/**
 * Fetch and process due scheduled messages
 * @param {Object} options - Processing options
 * @returns {Promise<Object>}
 */
async function fetchDueScheduledMessages(options = {}) {
    const { limit = SCHEDULE_FETCH_LIMIT } = options;
    
    try {
        const db = require('../server/express-app').getDb();
        const { INSTANCE_ID } = require('../config/constants');
        
        if (!db || !require('../server/express-app').isWhatsAppConnected()) {
            return { processed: 0, skipped: 'not_ready' };
        }
        
        if (scheduleProcessorRunning) {
            return { processed: 0, skipped: 'already_running' };
        }
        
        scheduleProcessorRunning = true;
        lastProcessedTime = new Date().toISOString();
        
        const dueMessages = await db.fetchDueScheduledMessages(INSTANCE_ID, limit);
        const dueGroupMessages = await db.fetchDueGroupScheduledMessages(INSTANCE_ID, limit);
        
        if (!dueMessages.length && !dueGroupMessages.length) {
            scheduleProcessorRunning = false;
            return { processed: 0, skipped: 'no_messages' };
        }
        
        let processed = 0;
        
        // Process individual messages
        for (const job of dueMessages) {
            try {
                await sendWhatsAppMessage(job.remote_jid, { text: job.message });
                await db.saveMessage(INSTANCE_ID, job.remote_jid, "assistant", job.message, "outbound");
                await db.updateScheduledMessageStatus(job.id, "sent");
                console.log(`Mensagem agendada enviada para ${job.remote_jid} ${job.scheduled_at}`);
                processed++;
            } catch (err) {
                await db.updateScheduledMessageStatus(job.id, "failed", err.message);
                console.error(`Erro ao enviar mensagem agendada ${job.id}:`, err.message);
            }
        }
        
        // Process group messages
        for (const job of dueGroupMessages) {
            try {
                const { getSelfJid } = require('../whatsapp/connection');
                await sendWhatsAppMessage(job.group_jid, { text: job.message });
                await db.saveGroupMessage(INSTANCE_ID, job.group_jid, getSelfJid(), "outbound", job.message, JSON.stringify({ scheduled: true }));
                await db.updateGroupScheduledMessageStatus(job.id, "sent");
                console.log(`Mensagem agendada enviada para grupo ${job.group_jid} ${job.scheduled_at}`);
                processed++;
            } catch (err) {
                await db.updateGroupScheduledMessageStatus(job.id, "failed", err.message);
                console.error(`Erro ao enviar mensagem agendada de grupo ${job.id}:`, err.message);
            }
        }
        
        scheduleProcessorRunning = false;
        return { 
            processed, 
            timestamp: lastProcessedTime,
            messages: dueMessages.length,
            groups: dueGroupMessages.length
        };
    } catch (error) {
        console.error('Error fetching due scheduled messages:', error);
        scheduleProcessorRunning = false;
        throw error;
    }
}

/**
 * Process a single scheduled message
 * @param {Object} scheduledMessage - Scheduled message object
 * @returns {Promise<Object>}
 */
async function processScheduledMessage(scheduledMessage) {
    const { id, recipient, message, instanceId } = scheduledMessage;
    
    try {
        console.log(`Processing scheduled message ${id} for ${recipient}`);
        
        // Get socket for instance
        const sock = require('../server/express-app').getSocket();
        
        if (sock) {
            await sendWhatsAppMessage(sock, `${recipient}@s.whatsapp.net`, message);
            await markMessageAsSent(id);
            console.log(`Scheduled message ${id} sent successfully`);
            return { success: true, id };
        } else {
            console.error(`Socket not available for instance ${instanceId}`);
            await markMessageAsFailed(id, 'Socket not available');
            return { success: false, id, error: 'Socket not available' };
        }
    } catch (error) {
        console.error(`Error processing scheduled message ${id}:`, error);
        await markMessageAsFailed(id, error.message);
        return { success: false, id, error: error.message };
    }
}

/**
 * Mark scheduled message as sent
 * @param {string} messageId - Message ID
 * @returns {Promise<Object>}
 */
async function markMessageAsSent(messageId) {
    try {
        const db = require('../server/express-app').getDb();
        const { INSTANCE_ID } = require('../config/constants');
        
        if (db) {
            await db.updateScheduledMessageStatus(messageId, "sent");
        }
        console.log(`Marking message ${messageId} as sent`);
        return { success: true, messageId, status: 'sent' };
    } catch (error) {
        console.error(`Error marking message ${messageId} as sent:`, error);
        throw error;
    }
}

/**
 * Mark scheduled message as failed
 * @param {string} messageId - Message ID
 * @param {string} reason - Failure reason
 * @returns {Promise<Object>}
 */
async function markMessageAsFailed(messageId, reason) {
    try {
        const db = require('../server/express-app').getDb();
        const { INSTANCE_ID } = require('../config/constants');
        
        if (db) {
            await db.updateScheduledMessageStatus(messageId, "failed", reason);
        }
        console.log(`Marking message ${messageId} as failed: ${reason}`);
        return { success: true, messageId, status: 'failed', reason };
    } catch (error) {
        console.error(`Error marking message ${messageId} as failed:`, error);
        throw error;
    }
}

/**
 * Get scheduler status
 * @returns {Object}
 */
function getSchedulerStatus() {
    return {
        isRunning: scheduleProcessorRunning,
        lastProcessed: lastProcessedTime,
        fetchLimit: SCHEDULE_FETCH_LIMIT
    };
}

module.exports = {
    SCHEDULE_FETCH_LIMIT,
    fetchDueScheduledMessages,
    processScheduledMessage,
    markMessageAsSent,
    markMessageAsFailed,
    getSchedulerStatus
};
