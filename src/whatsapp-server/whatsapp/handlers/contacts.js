/**
 * @fileoverview contacts.upsert handler - manages WhatsApp contact updates
 * @module whatsapp-server/whatsapp/handlers/contacts
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines 2340-2365)
 * Handles contact synchronization and profile management
 */

const { log } = require('../../../utils/logger');

/**
 * Normalize meta field value
 * @param {*} value - Value to normalize
 * @returns {string|null}
 */
function normalizeMetaField(value) {
    if (value === undefined || value === null) return null;
    const text = typeof value === "string" ? value : String(value);
    const trimmed = text.trim();
    return trimmed === "" ? null : trimmed;
}

/**
 * Check if JID is individual (not group or broadcast)
 * @param {string} remoteJid - JID to check
 * @returns {boolean}
 */
function isIndividualJid(remoteJid) {
    if (!remoteJid || typeof remoteJid !== "string") return false;
    return !remoteJid.includes("@g.us") && !remoteJid.includes("@broadcast");
}

/**
 * Fetch profile picture URL for a contact
 * @param {Object} socket - WhatsApp socket
 * @param {string} jid - Contact JID
 * @returns {Promise<string|null>}
 */
async function fetchProfilePictureUrl(socket, jid) {
    if (!socket || !jid) return null;
    try {
        const result = await socket.profilePictureUrl(jid, 'image');
        return result || null;
    } catch (err) {
        return null;
    }
}

/**
 * Update contact metadata in database
 * @param {string} jid - Contact JID
 * @param {Object} metadata - Metadata to update
 */
async function updateContactMetadata(jid, metadata) {
    let db;
    try {
        db = require('../../../../db-updated');
    } catch (e) {
        console.error('[Contacts] Failed to load db-updated module:', e.message);
    }
    if (!db || typeof db.upsertContactMetadata !== "function") return;
    
    try {
        await db.upsertContactMetadata(jid, {
            jid,
            contactName: metadata.contactName || null,
            statusName: metadata.statusName || null,
            profilePicture: metadata.profilePicture || null
        });
    } catch (err) {
        log("Error saving contact metadata:", err.message);
    }
}

/**
 * Process a single contact upsert from Baileys
 * @param {Object} contact - Contact object from Baileys
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleContactUpsert(contact, socket) {
    if (!contact) return;
    const remoteJid = contact.id;
    if (!isIndividualJid(remoteJid)) return;

    const contactName = normalizeMetaField(contact.notify || contact.name);
    const statusName = normalizeMetaField(contact.status);

    let profilePicture = null;
    const imgHint = normalizeMetaField(contact.imgUrl);
    if (imgHint && imgHint !== "changed") {
        profilePicture = imgHint;
    } else if (socket) {
        profilePicture = await fetchProfilePictureUrl(socket, remoteJid);
    }

    await updateContactMetadata(remoteJid, { contactName, statusName, profilePicture });
}

/**
 * Handle contact information extracted from a message
 * @param {Object} msg - Message object from Baileys
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleContactFromMessage(msg, socket) {
    const remoteJid = msg?.key?.remoteJid;
    if (!isIndividualJid(remoteJid)) return;
    const pushName = normalizeMetaField(msg.pushName);
    if (!pushName) return;
    await updateContactMetadata(remoteJid, { contactName: pushName });
}

/**
 * Process contacts upsert from Baileys
 * @param {Object} data - Contacts upsert data
 * @param {Object} socket - WhatsApp socket instance
 */
async function process(data, socket) {
    const { contacts } = data;
    
    for (const contact of contacts) {
        try {
            await handleContactUpsert(contact, socket);
        } catch (error) {
            console.error('Error processing contact:', error);
        }
    }
}

/**
 * Get contact profile info
 * @param {Object} socket - WhatsApp socket instance
 * @param {string} jid - Contact JID
 * @returns {Promise<Object>}
 */
async function getContactProfile(socket, jid) {
    try {
        const profile = await socket.onWhatsApp(jid);
        return profile;
    } catch (error) {
        console.error('Error getting profile:', error);
        throw error;
    }
}

module.exports = {
    process,
    handleContactUpsert,
    handleContactFromMessage,
    getContactProfile,
    normalizeMetaField,
    isIndividualJid,
    fetchProfilePictureUrl,
    updateContactMetadata
};
