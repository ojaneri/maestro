/**
 * @fileoverview WhatsApp message sending functions
 * @module whatsapp-server/whatsapp/send-message
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines 1157-1300)
 * Provides sendWhatsAppMessage and sendWhatsAppCommand functions
 */

const { log } = require('../../utils/logger');
const db = require('../../../db-updated');

// Recent outgoing message tracking for deduplication
const recentOutgoingText = new Map();
const RECENT_OUTGOING_TTL_MS = 15000;
const RECENT_OUTGOING_LIMIT = 1000;

function isSocketConnected(socket) {
    if (!socket) return false;
    const wsReadyState = socket?.ws?.readyState;
    if (typeof wsReadyState === "number") {
        return wsReadyState === 1;
    }
    return true;
}

/**
 * Ensure Brazilian country code
 * @param {string} digits - Phone digits
 * @returns {string}
 */
function ensureBrazilCountryCode(digits) {
    if (!digits) return '';
    if (digits.startsWith('55')) {
        return digits;
    }
    if (digits.length >= 10 && digits.length <= 11) {
        return `55${digits}`;
    }
    return digits;
}

/**
 * Format outgoing JID from phone number
 * @param {string} value - Phone number or JID
 * @returns {string|null}
 */
function formatOutgoingJid(value) {
    if (!value) return null;
    if (value.includes("@")) return value;
    const digits = String(value).replace(/\D/g, "");
    const normalized = ensureBrazilCountryCode(digits);
    return normalized ? `${normalized}@s.whatsapp.net` : null;
}

/**
 * Extract phone number from JID
 * @param {string} jid - WhatsApp JID
 * @returns {string}
 */
function extractPhoneFromJid(jid) {
    if (!jid || typeof jid !== "string") {
        return "";
    }
    return jid.split("@")[0].replace(/\D/g, "");
}

/**
 * Check if JID is a valid WhatsApp recipient
 * @param {string} jid - JID to check
 * @returns {boolean}
 */
function shouldCheckWhatsAppRecipient(jid) {
    if (!jid || typeof jid !== "string") {
        return false;
    }
    const lower = jid.toLowerCase();
    if (lower.startsWith("status@broadcast")) {
        return false;
    }
    return lower.endsWith("@s.whatsapp.net");
}

/**
 * Compute typing delay based on message length
 * @param {string} text - Message text
 * @returns {number}
 */
function computeTypingDelayMs(text) {
    const normalized = (text || "").trim();
    if (!normalized) {
        return 0;
    }
    const charCount = normalized.length;
    const seconds = Math.min(10, Math.max(1, Math.ceil(charCount / 20)));
    const randomFactor = Math.random() * 1200 + 200;
    return seconds * 1000 + randomFactor;
}

/**
 * Simulate typing indicator
 * @param {Object} socket - WhatsApp socket
 * @param {string} jid - Recipient JID
 * @param {string} text - Message text
 * @param {string} temperature - Contact temperature
 */
async function simulateTypingIndicator(socket, jid, text, temperature = 'warm') {
    if (!socket || !jid) return;
    
    try {
        await socket.sendPresenceUpdate('composing', jid);
        const delay = computeTypingDelayMs(text);
        await new Promise(resolve => setTimeout(resolve, delay));
        await socket.sendPresenceUpdate('paused', jid);
    } catch (err) {
        log('Error sending typing indicator:', err.message);
    }
}

/**
 * Check if WhatsApp number exists
 * @param {Object} socket - WhatsApp socket
 * @param {string} jid - Recipient JID
 * @returns {Promise<boolean>}
 */
async function ensureWhatsAppRecipientExists(socket, jid) {
    if (!shouldCheckWhatsAppRecipient(jid)) {
        return true;
    }
    if (!isSocketConnected(socket)) {
        throw new Error("whatsapp(): WhatsApp não conectado");
    }
    const phone = extractPhoneFromJid(jid);
    if (!phone) {
        throw new Error("whatsapp(): número inválido");
    }
    
    try {
        const result = await socket.onWhatsApp(phone);
        const exists = Array.isArray(result) && result[0]?.exists === true;
        if (!exists) {
            throw new Error("whatsapp(): número não existe no WhatsApp");
        }
        return true;
    } catch (err) {
        log('Error checking WhatsApp recipient:', err.message);
        throw err;
    }
}

/**
 * Persist session message to database
 * @param {Object} options - Message options
 */
async function persistSessionMessage(options = {}) {
    const {
        sessionContext = null,
        remoteJid = "",
        role,
        content,
        direction = "inbound",
        metadata = null,
        forcedSessionId = ""
    } = options;
    
    if (!db) return;
    
    try {
        const targetRemote = (sessionContext?.remoteJid || remoteJid || "").trim();
        if (!targetRemote) {
            return;
        }
        
        const sessionId = (sessionContext?.sessionId || forcedSessionId || "").toString().trim();
        await db.saveMessage(
            sessionContext?.instanceId || global.INSTANCE_ID || 'default',
            targetRemote,
            role,
            content,
            direction,
            metadata,
            { sessionId }
        );
    } catch (err) {
        log("Error saving session message:", err.message);
    }
}

/**
 * Send a text message via WhatsApp
 * @param {Object} socket - WhatsApp socket instance
 * @param {string} jid - Recipient JID
 * @param {Object} payload - Message payload
 * @returns {Promise<Object>}
 */
async function sendWhatsAppMessage(socket, jid, payload) {
    if (!isSocketConnected(socket)) {
        throw new Error("WhatsApp não conectado");
    }
    
    log("flow.send.request", {
        jid,
        kind: payload?.text
            ? "text"
            : payload?.image
                ? "image"
                : payload?.audio
                    ? "audio"
                : payload?.video
                    ? "video"
                    : payload?.contacts
                        ? "contact"
                        : "other"
    });
    
    const textPayload = (payload?.text || "").trim();
    
    // Simulate typing indicator for text messages
    if (textPayload) {
        await simulateTypingIndicator(socket, jid, textPayload);
    }
    
    // Verify recipient exists
    await ensureWhatsAppRecipientExists(socket, jid);
    
    // Send message
    const result = await socket.sendMessage(jid, payload);
    
    // Track recent outgoing for deduplication
    if (textPayload) {
        const key = `${jid}|${textPayload}`;
        recentOutgoingText.set(key, Date.now());
        if (recentOutgoingText.size > RECENT_OUTGOING_LIMIT) {
            const cutoff = Date.now() - RECENT_OUTGOING_TTL_MS;
            for (const [entryKey, ts] of recentOutgoingText.entries()) {
                if (ts < cutoff) {
                    recentOutgoingText.delete(entryKey);
                }
                if (recentOutgoingText.size <= RECENT_OUTGOING_LIMIT) {
                    break;
                }
            }
        }
    }
    
    log("flow.send.done", { jid });
    return result;
}

/**
 * Send a command message (with session tracking)
 * @param {Object} socket - WhatsApp socket instance
 * @param {string} remoteJid - Recipient phone or JID
 * @param {string} message - Message text
 * @param {Object} options - Additional options
 * @returns {Promise<string>}
 */
async function sendWhatsAppCommand(socket, remoteJid, message, options = {}) {
    const jid = formatOutgoingJid(remoteJid);
    if (!jid) {
        throw new Error("whatsapp(): número inválido");
    }
    if (!isSocketConnected(socket)) {
        throw new Error("whatsapp(): WhatsApp não conectado");
    }

    await sendWhatsAppMessage(socket, jid, { text: message });
    
    // Save to database
    if (db) {
        const { sessionContext = null, sessionId = "" } = options;
        try {
            await persistSessionMessage({
                sessionContext,
                remoteJid: jid,
                role: "assistant",
                content: message,
                direction: "outbound",
                forcedSessionId: sessionId
            });
        } catch (err) {
            log("Error saving assistant message:", err.message);
        }
    }
    return jid;
}

/**
 * Send media message (image, audio, document, video)
 * @param {Object} socket - WhatsApp socket instance
 * @param {string} jid - Recipient JID
 * @param {Object} media - Media object with type and content
 * @returns {Promise<Object>}
 */
async function sendWhatsAppMedia(socket, jid, media) {
    if (!isSocketConnected(socket)) {
        throw new Error("WhatsApp não conectado");
    }
    
    const { type, content, caption, filename, mimetype } = media;
    
    let message;
    
    // Check if content is URL or base64 and format accordingly
    const isBase64 = content.startsWith('data:') || /^[A-Za-z0-9+/=]+$/.test(content.substring(0, 100));
    const isUrl = content.startsWith('http://') || content.startsWith('https://');
    
    let mediaContent;
    if (isUrl) {
        // For URLs, use the url object format
        mediaContent = { url: content };
    } else if (isBase64) {
        // For base64, decode to buffer
        try {
            const base64Data = content.replace(/^data:[^;]+;base64,/, '');
            mediaContent = Buffer.from(base64Data, 'base64');
        } catch (e) {
            // If decode fails, treat as raw content
            mediaContent = content;
        }
    } else {
        mediaContent = content;
    }
    
    switch (type) {
        case 'image':
            message = { image: mediaContent, caption };
            break;
        case 'audio':
            message = { audio: mediaContent, mimetype: mimetype || 'audio/ogg' };
            break;
        case 'document':
            message = { document: mediaContent, fileName: filename, mimetype };
            break;
        case 'video':
            message = { video: mediaContent, caption };
            break;
        default:
            throw new Error(`Unsupported media type: ${type}`);
    }
    
    const result = await socket.sendMessage(jid, message);
    return result;
}

module.exports = {
    sendWhatsAppMessage,
    sendWhatsAppCommand,
    sendWhatsAppMedia,
    formatOutgoingJid,
    extractPhoneFromJid,
    ensureBrazilCountryCode,
    computeTypingDelayMs,
    simulateTypingIndicator,
    persistSessionMessage
};
