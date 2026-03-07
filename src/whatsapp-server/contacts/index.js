/**
 * @fileoverview Contact utilities
 * @module whatsapp-server/contacts
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~2303-2365)
 * General contact management utilities and JID handling
 */

/**
 * Format WhatsApp JID from phone number
 * @param {string} phone - Phone number
 * @returns {string}
 */
function formatJid(phone) {
    const cleanPhone = String(phone).replace(/\D/g, '');
    return `${cleanPhone}@s.whatsapp.net`;
}

/**
 * Extract phone from JID
 * @param {string} jid - WhatsApp JID
 * @returns {string}
 */
function extractPhone(jid) {
    if (!jid) return '';
    return jid.replace('@s.whatsapp.net', '')
              .replace('@c.us', '')
              .replace('@g.us', '');
}

/**
 * Check if JID is a group
 * @param {string} jid - WhatsApp JID
 * @returns {boolean}
 */
function isGroup(jid) {
    if (!jid) return false;
    return jid.endsWith('@g.us');
}

/**
 * Check if JID is an individual (not group)
 * @param {string} jid - WhatsApp JID
 * @returns {boolean}
 */
function isIndividualJid(jid) {
    if (!jid) return false;
    return jid.endsWith('@s.whatsapp.net') || jid.endsWith('@c.us');
}

/**
 * Sanitize contact name
 * @param {string} name - Raw name
 * @returns {string}
 */
function sanitizeName(name) {
    if (!name) return '';
    return name.trim().replace(/[^\w\s\-áàâãéèêíïóôõöúçÁÀÂÃÉÈÊÍÏÓÔÕÖÚÇ]/gi, '');
}

/**
 * Get display name for contact
 * @param {Object} contact - Contact object
 * @returns {string}
 */
function getDisplayName(contact) {
    if (!contact) return '';
    return contact.notify || contact.name || contact.verifiedName || extractPhone(contact.id);
}

/**
 * Normalize metadata field
 * @param {any} value - Value to normalize
 * @returns {string|null}
 */
function normalizeMetaField(value) {
    if (value === null || value === undefined || value === 'changed') {
        return null;
    }
    return String(value).trim() || null;
}

/**
 * Fetch profile picture URL
 * @param {Object} sock - WhatsApp socket
 * @param {string} remoteJid - Remote JID
 * @returns {Promise<string|null>}
 */
async function fetchProfilePictureUrl(sock, remoteJid) {
    if (!sock || !remoteJid) return null;
    try {
        const url = await sock.profilePictureUrl(remoteJid, "image");
        return normalizeMetaField(url);
    } catch (err) {
        return null;
    }
}

/**
 * Fetch status/name
 * @param {Object} sock - WhatsApp socket
 * @param {string} remoteJid - Remote JID
 * @returns {Promise<string|null>}
 */
async function fetchStatusName(sock, remoteJid) {
    if (!sock || !remoteJid) return null;
    try {
        const statusData = await sock.fetchStatus(remoteJid);
        return normalizeMetaField(statusData?.status);
    } catch (err) {
        return null;
    }
}

/**
 * Update contact metadata in database
 * @param {Object} db - Database instance
 * @param {string} instanceId - Instance ID
 * @param {string} remoteJid - Remote JID
 * @param {Object} updates - Updates object
 * @returns {Promise<void>}
 */
async function updateContactMetadata(db, instanceId, remoteJid, updates = {}) {
    if (!db || !remoteJid) return;
    
    const payload = {
        contactName: normalizeMetaField(updates.contactName),
        statusName: normalizeMetaField(updates.statusName),
        profilePicture: normalizeMetaField(updates.profilePicture)
    };
    
    if (!payload.contactName && !payload.statusName && !payload.profilePicture) {
        return;
    }
    
    try {
        if (typeof db.saveContactMetadata === 'function') {
            await db.saveContactMetadata(instanceId, remoteJid, payload.contactName, payload.statusName, payload.profilePicture);
        }
    } catch (err) {
        console.error("Error saving contact metadata:", err.message);
    }
}

/**
 * Handle contact update from WhatsApp
 * @param {Object} sock - WhatsApp socket
 * @param {Object} db - Database instance
 * @param {string} instanceId - Instance ID
 * @param {Object} contact - Contact object
 * @returns {Promise<void>}
 */
async function handleContactUpsert(sock, db, instanceId, contact) {
    if (!contact) return;
    
    const remoteJid = contact.id;
    if (!isIndividualJid(remoteJid)) return;
    
    const contactName = normalizeMetaField(contact.notify || contact.name);
    const statusName = normalizeMetaField(contact.status);
    
    let profilePicture = null;
    const imgHint = normalizeMetaField(contact.imgUrl);
    if (imgHint && imgHint !== 'changed') {
        profilePicture = imgHint;
    } else {
        profilePicture = await fetchProfilePictureUrl(sock, remoteJid);
    }
    
    await updateContactMetadata(db, instanceId, remoteJid, { contactName, statusName, profilePicture });
}

/**
 * Handle contact from message
 * @param {Object} sock - WhatsApp socket
 * @param {Object} db - Database instance
 * @param {string} instanceId - Instance ID
 * @param {Object} msg - Message object
 * @returns {Promise<void>}
 */
async function handleContactFromMessage(sock, db, instanceId, msg) {
    const remoteJid = msg?.key?.remoteJid;
    if (!isIndividualJid(remoteJid)) return;
    
    const pushName = normalizeMetaField(msg.pushName);
    if (!pushName) return;
    
    await updateContactMetadata(db, instanceId, remoteJid, { contactName: pushName });
}

/**
 * Convert to number safely
 * @param {any} value - Value to convert
 * @param {number} fallback - Fallback value
 * @returns {number}
 */
function toNumber(value, fallback = 0) {
    const num = typeof value === "number" ? value : parseFloat(value);
    return Number.isFinite(num) ? num : fallback;
}

module.exports = {
    formatJid,
    extractPhone,
    isGroup,
    isIndividualJid,
    sanitizeName,
    getDisplayName,
    normalizeMetaField,
    fetchProfilePictureUrl,
    fetchStatusName,
    updateContactMetadata,
    handleContactUpsert,
    handleContactFromMessage,
    toNumber
};
