/**
 * Cryptography Utilities
 * Extracted from whatsapp-server-intelligent.js
 */

const crypto = require("crypto");
const { CALENDAR_TOKEN_SECRET } = require("../config/env");

function toBase64Url(buffer) {
    return buffer
        .toString("base64")
        .replace(/\+/g, "-")
        .replace(/\//g, "_")
        .replace(/=+$/, "");
}

function getCalendarEncryptionKey() {
    if (!CALENDAR_TOKEN_SECRET) {
        return null;
    }
    return crypto.createHash("sha256").update(CALENDAR_TOKEN_SECRET, "utf8").digest();
}

function encryptCalendarToken(value) {
    if (!value) return null;
    const key = getCalendarEncryptionKey();
    if (!key) {
        throw new Error("Chave CALENDAR_TOKEN_SECRET não configurada");
    }
    const iv = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv("aes-256-gcm", key, iv);
    const encrypted = Buffer.concat([cipher.update(String(value), "utf8"), cipher.final()]);
    const tag = cipher.getAuthTag();
    return `v1:${iv.toString("base64")}:${tag.toString("base64")}:${encrypted.toString("base64")}`;
}

function decryptCalendarToken(value) {
    if (!value) return null;
    const text = String(value);
    if (!text.startsWith("v1:")) {
        return text;
    }
    const key = getCalendarEncryptionKey();
    if (!key) {
        throw new Error("Chave CALENDAR_TOKEN_SECRET não configurada");
    }
    const [, ivB64, tagB64, dataB64] = text.split(":");
    const iv = Buffer.from(ivB64, "base64");
    const tag = Buffer.from(tagB64, "base64");
    const data = Buffer.from(dataB64, "base64");
    const decipher = crypto.createDecipheriv("aes-256-gcm", key, iv);
    decipher.setAuthTag(tag);
    const decrypted = Buffer.concat([decipher.update(data), decipher.final()]);
    return decrypted.toString("utf8");
}

module.exports = {
    toBase64Url,
    getCalendarEncryptionKey,
    encryptCalendarToken,
    decryptCalendarToken
};