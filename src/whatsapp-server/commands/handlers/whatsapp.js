/**
 * @fileoverview WhatsApp command handler - sends WhatsApp messages
 * @module whatsapp-server/commands/handlers/whatsapp
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles send_whatsapp and boomerang command execution
 */

/**
 * Send WhatsApp message command
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context (includes socket)
 * @returns {Promise<Object>}
 */
async function sendWhatsApp(args, context = {}) {
    const { to, message, type = 'text' } = args;
    const { socket, sendWhatsAppMessage } = context;

    if (!to) {
        throw new Error('Parâmetro obrigatório: to');
    }

    if (!message) {
        throw new Error('Parâmetro obrigatório: message');
    }

    if (!socket && !sendWhatsAppMessage) {
        throw new Error('Socket ou sendWhatsAppMessage não disponível');
    }

    try {
        const jid = normalizeJid(to);
        const result = await (sendWhatsAppMessage || socket.sendMessage)(jid, { text: message });

        console.log('[WhatsApp] Mensagem enviada:', result?.key?.id);

        return {
            success: true,
            messageId: result?.key?.id,
            to: jid,
            type
        };
    } catch (error) {
        console.error('[WhatsApp] Error sending message:', error);
        throw error;
    }
}

/**
 * Boomerang - send message back to sender
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function boomerang(args, context = {}) {
    const { mensagem } = args;
    const { remoteJid, socket, sendWhatsAppMessage } = context;

    if (!mensagem) {
        throw new Error('Parâmetro obrigatório: mensagem');
    }

    if (!remoteJid) {
        throw new Error('remoteJid não disponível');
    }

    if (!socket && !sendWhatsAppMessage) {
        throw new Error('Socket ou sendWhatsAppMessage não disponível');
    }

    try {
        const result = await (sendWhatsAppMessage || socket.sendMessage)(remoteJid, { text: mensagem });

        console.log('[WhatsApp] Boomerang enviado:', result?.key?.id);

        return {
            success: true,
            messageId: result?.key?.id,
            to: remoteJid,
            type: 'boomerang'
        };
    } catch (error) {
        console.error('[WhatsApp] Error sending boomerang:', error);
        throw error;
    }
}

/**
 * Send media message
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sendMedia(args, context = {}) {
    const { to, type, url, caption, mimetype } = args;
    const { socket, sendWhatsAppMessage } = context;

    if (!to || !type || !url) {
        throw new Error('Parâmetros obrigatórios: to, type, url');
    }

    if (!socket && !sendWhatsAppMessage) {
        throw new Error('Socket ou sendWhatsAppMessage não disponível');
    }

    try {
        const jid = normalizeJid(to);
        const payload = buildMediaPayload(type, url, caption, mimetype);
        const result = await (sendWhatsAppMessage || socket.sendMessage)(jid, payload);

        return {
            success: true,
            messageId: result?.key?.id,
            to: jid,
            type,
            url
        };
    } catch (error) {
        console.error('[WhatsApp] Error sending media:', error);
        throw error;
    }
}

/**
 * Send location
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sendLocation(args, context = {}) {
    const { to, latitude, longitude, name, address } = args;
    const { socket, sendWhatsAppMessage } = context;

    if (!to || !latitude || !longitude) {
        throw new Error('Parâmetros obrigatórios: to, latitude, longitude');
    }

    if (!socket && !sendWhatsAppMessage) {
        throw new Error('Socket ou sendWhatsAppMessage não disponível');
    }

    try {
        const jid = normalizeJid(to);
        const result = await (sendWhatsAppMessage || socket.sendMessage)(jid, {
            location: {
                latitude: parseFloat(latitude),
                longitude: parseFloat(longitude),
                name: name || undefined,
                address: address || undefined
            }
        });

        return {
            success: true,
            messageId: result?.key?.id,
            to: jid,
            type: 'location',
            latitude,
            longitude
        };
    } catch (error) {
        console.error('[WhatsApp] Error sending location:', error);
        throw error;
    }
}

/**
 * Send contact
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sendContact(args, context = {}) {
    const { to, phone, displayName, note } = args;
    const { socket, sendWhatsAppMessage } = context;

    if (!to || !phone || !displayName) {
        throw new Error('Parâmetros obrigatórios: to, phone, displayName');
    }

    if (!socket && !sendWhatsAppMessage) {
        throw new Error('Socket ou sendWhatsAppMessage não disponível');
    }

    try {
        const jid = normalizeJid(to);
        
        const vcard = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            `FN:${displayName}`,
            `TEL;type=CELL;waid=${phone.replace(/\D/g, '')}:${phone}`
        ];
        
        if (note) {
            vcard.push(`NOTE:${note}`);
        }
        
        vcard.push('END:VCARD');

        const result = await (sendWhatsAppMessage || socket.sendMessage)(jid, {
            contacts: {
                displayName,
                contacts: [{ vcard: vcard.join('\n') }]
            }
        });

        return {
            success: true,
            messageId: result?.key?.id,
            to: jid,
            type: 'contact',
            phone,
            displayName
        };
    } catch (error) {
        console.error('[WhatsApp] Error sending contact:', error);
        throw error;
    }
}

/**
 * Normalize JID for WhatsApp
 * @param {string} identifier - Phone number or JID
 * @returns {string}
 */
function normalizeJid(identifier) {
    if (!identifier) return '';
    
    // Already has @s.whatsapp.net or @g.us
    if (identifier.includes('@')) {
        return identifier;
    }
    
    // Remove non-numeric characters
    const phone = identifier.replace(/\D/g, '');
    
    return `${phone}@s.whatsapp.net`;
}

/**
 * Build media payload based on type
 * @param {string} type - Media type
 * @param {string} url - Media URL
 * @param {string} caption - Caption
 * @param {string} mimetype - MIME type
 * @returns {Object}
 */
function buildMediaPayload(type, url, caption, mimetype) {
    const payload = {};
    
    switch (type.toLowerCase()) {
        case 'image':
        case 'img':
            payload.image = { url };
            if (caption) payload.image.caption = caption;
            if (mimetype) payload.image.mimetype = mimetype;
            break;
            
        case 'video':
            payload.video = { url };
            if (caption) payload.video.caption = caption;
            if (mimetype) payload.video.mimetype = mimetype;
            break;
            
        case 'audio':
            payload.audio = { url };
            if (mimetype) payload.audio.mimetype = mimetype;
            break;
            
        case 'document':
        case 'documento':
            payload.document = { url };
            if (caption) payload.document.caption = caption;
            if (mimetype) payload.document.mimetype = mimetype;
            break;
            
        default:
            throw new Error(`Tipo de mídia não suportado: ${type}`);
    }
    
    return payload;
}

module.exports = {
    sendWhatsApp,
    boomerang,
    sendMedia,
    sendLocation,
    sendContact,
    normalizeJid,
    buildMediaPayload,
};
