const { v4: uuidv4 } = require("uuid");

async function sendWhatsAppMessage(sock, jid, payload) {
    if (!sock) throw new Error("Socket não disponível");
    return await sock.sendMessage(jid, payload);
}

function detectMediaPayload(message) {
    if (!message) return null;
    if (message.imageMessage) return "image";
    if (message.videoMessage) return "video";
    if (message.audioMessage) return "audio";
    if (message.documentMessage) return "document";
    if (message.stickerMessage) return "sticker";
    return null;
}

function isIndividualJid(remoteJid) {
    return remoteJid && remoteJid.endsWith("@s.whatsapp.net");
}

function isGroupJid(remoteJid) {
    return remoteJid && remoteJid.endsWith("@g.us");
}

async function simulateTypingIndicator(sock, remoteJid, text, temperature = 'warm') {
    if (!sock) return;
    await sock.sendPresenceUpdate('composing', remoteJid);
    const delay = text ? Math.min(text.length * 50, 3000) : 1000;
    await new Promise(resolve => setTimeout(resolve, delay));
    await sock.sendPresenceUpdate('paused', remoteJid);
}

module.exports = {
    sendWhatsAppMessage,
    detectMediaPayload,
    isIndividualJid,
    isGroupJid,
    simulateTypingIndicator
};
