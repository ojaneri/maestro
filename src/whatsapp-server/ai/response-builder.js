/**
 * @fileoverview AI response builder - formats responses and builds context
 * @module whatsapp-server/ai/response-builder
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles response formatting, context building, and payload construction
 */

// Import AI module for shared utilities
const ai = require('./index');

/**
 * Build complete response payload
 * @param {string} userInput - Original user message
 * @param {Object} aiResponse - AI response object
 * @param {Object} context - Conversation context
 * @returns {Promise<Object>}
 */
async function buildResponsesPayload(userInput, aiResponse, context) {
    const { content, usage, model } = aiResponse;
    
    // Parse AI response for commands
    const hasCommands = content && content.includes('[[') && content.includes(']]');
    
    const payload = {
        text: content || '',
        originalInput: userInput,
        aiModel: model,
        usage: usage || null,
        timestamp: new Date().toISOString(),
        hasCommands,
        context: {
            jid: context?.jid,
            isGroup: context?.isGroup || false
        }
    };
    
    return payload;
}

/**
 * Build context from message and database
 * @param {Object} message - WhatsApp message
 * @param {Object} socket - WhatsApp socket
 * @param {Object} dependencies - External dependencies (db, etc.)
 * @returns {Promise<Object>}
 */
async function buildContext(message, socket, dependencies = {}) {
    const { key, message: messageContent } = message;
    const remoteJid = key.remoteJid;
    const db = dependencies.db;
    
    // Extract message type and content
    const messageType = Object.keys(messageContent || {})[0];
    const text = extractText(messageContent);
    
    const context = {
        jid: remoteJid,
        isGroup: remoteJid?.endsWith('@g.us') || false,
        messageType,
        text,
        timestamp: new Date().toISOString(),
        // Load from database if available
        customer: null,
        conversationHistory: []
    };
    
    // Load conversation history if database is available
    if (db && dependencies.instanceId) {
        try {
            const history = await db.getLastMessages(
                dependencies.instanceId,
                remoteJid,
                20,
                key?.id
            );
            context.conversationHistory = history || [];
        } catch (err) {
            console.warn('[ResponseBuilder] Could not load history:', err.message);
            context.conversationHistory = [];
        }
    }
    
    return context;
}

/**
 * Extract text content from message object
 * @param {Object} messageContent - Message content from Baileys
 * @returns {string}
 */
function extractText(messageContent) {
    if (!messageContent) return '';
    
    if (messageContent.conversation) return messageContent.conversation;
    if (messageContent.extendedTextMessage?.text) return messageContent.extendedTextMessage.text;
    if (messageContent.imageMessage?.caption) return messageContent.imageMessage.caption;
    if (messageContent.documentMessage?.caption) return messageContent.documentMessage.caption;
    if (messageContent.videoMessage?.caption) return messageContent.videoMessage.caption;
    if (messageContent.audioMessage?.transcription) return messageContent.audioMessage.transcription;
    
    return '';
}

/**
 * Format response for specific message type
 * @param {string} text - Response text
 * @param {string} messageType - Original message type
 * @returns {Object}
 */
function formatForMessageType(text, messageType) {
    const formatted = {
        text,
    };
    
    // Add formatting based on original message type
    if (messageType === 'imageMessage') {
        formatted.image = true;
    }
    
    return formatted;
}

/**
 * Build messages array for AI API
 * @param {Array} history - Conversation history
 * @param {string} currentMessage - Current user message
 * @param {Object} aiConfig - AI configuration
 * @returns {Array}
 */
function buildMessagesArray(history, currentMessage, aiConfig) {
    const messages = [];
    
    // Add system prompt if configured
    if (aiConfig.system_prompt) {
        messages.push({
            role: 'system',
            content: aiConfig.system_prompt
        });
    }
    
    // Add injected context if available
    if (aiConfig.injected_context) {
        messages.push({
            role: 'system',
            content: aiConfig.injected_context
        });
    }
    
    // Add conversation history
    if (Array.isArray(history)) {
        for (const row of history) {
            if (row.role && row.content) {
                // Format message with timestamp for AI context
                const formattedContent = ai.formatMessageWithTimestamp(row);
                messages.push({
                    role: row.role,
                    content: formattedContent
                });
            }
        }
    }
    
    // Add current user message
    messages.push({
        role: 'user',
        content: currentMessage
    });
    
    return messages;
}

/**
 * Parse media directive from AI response
 * @param {string} segment - Response segment
 * @returns {Object|null}
 */
function parseMediaDirective(segment) {
    // Validação de tipo - tratar segmento não-string
    if (typeof segment !== 'string' || !segment) {
        return null;
    }
    
    const mediaPatterns = {
        image: /(?:image|img|foto):\s*(https?:\/\/[^\s]+)/i,
        video: /(?:video|vídeo):\s*(https?:\/\/[^\s]+)/i,
        audio: /(?:audio|áudio):\s*(https?:\/\/[^\s]+)/i,
        contact: /contact[:\(]\s*([^)]+)\)?/i
    };
    
    for (const [type, pattern] of Object.entries(mediaPatterns)) {
        const match = segment.match(pattern);
        if (match) {
            return {
                type,
                url: match[1],
                raw: segment
            };
        }
    }
    
    return null;
}

/**
 * Clean AI response by removing assistant call markers
 * @param {string} text - AI response text
 * @returns {string}
 */
function stripAssistantCalls(text) {
    if (!text) return '';
    
    // Remove command markers like [[command_name(...)]]
    const commandRegex = /\[\[[^\]]+\]\]/g;
    return text.replace(commandRegex, '').trim();
}

/**
 * Split text into segments using hash separator
 * @param {string} text - Text to split
 * @returns {Array<string>}
 */
function splitHashSegments(text) {
    // Validação de tipo - tratar texto não-string
    if (typeof text !== 'string' || !text) {
        return [];
    }
    
    // Check for hash-separated segments
    if (text.includes('#')) {
        const segments = text.split('#').filter(s => s.trim());
        if (segments.length > 0) {
            return segments;
        }
    }
    
    return [text];
}

/**
 * Replace status placeholder in text
 * @param {string} text - Text with placeholder
 * @param {string} statusName - Status name to replace
 * @returns {string}
 */
function replaceStatusPlaceholder(text, statusName) {
    if (!text) return '';
    return text.replace(/\{\{status_name\}\}/g, statusName || 'Cliente');
}

/**
 * Get text snippet for logging
 * @param {string} text - Full text
 * @param {number} length - Max length
 * @returns {string}
 */
function getSnippet(text, length = 120) {
    if (!text) return '';
    const cleaned = text.replace(/[\r\n]+/g, ' ').trim();
    return cleaned.length > length ? cleaned.substring(0, length) + '...' : cleaned;
}

module.exports = {
    buildResponsesPayload,
    buildContext,
    extractText,
    formatForMessageType,
    buildMessagesArray,
    parseMediaDirective,
    stripAssistantCalls,
    splitHashSegments,
    replaceStatusPlaceholder,
    getSnippet,
};
