/**
 * @fileoverview Calendar OAuth flow and token management
 * @module whatsapp-server/calendar/oauth
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~1-1300)
 * Handles Google OAuth authentication for Calendar API
 */

const nodeCrypto = require('crypto');
const { google: googleApis } = require('googleapis');

const CALENDAR_TOKEN_SECRET = process.env.CALENDAR_TOKEN_SECRET || "";
const GOOGLE_OAUTH_CLIENT_ID = process.env.GOOGLE_OAUTH_CLIENT_ID || "";
const GOOGLE_OAUTH_CLIENT_SECRET = process.env.GOOGLE_OAUTH_CLIENT_SECRET || "";
const GOOGLE_OAUTH_REDIRECT_URL = process.env.GOOGLE_OAUTH_REDIRECT_URL || "";
const GOOGLE_CALENDAR_SCOPES = [
    'https://www.googleapis.com/auth/calendar',
    'https://www.googleapis.com/auth/calendar.events',
    'https://www.googleapis.com/auth/userinfo.email',
    'openid',
    'profile',
    'email'
];

// In-memory state for OAuth flows (production: use Redis)
const calendarOauthStates = new Map();

// Helper: Base64 URL-safe encoding
function toBase64Url(buffer) {
    return buffer.toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

// Helper: Base64 URL-safe decoding
function fromBase64Url(str) {
    str = str.replace(/-/g, '+').replace(/_/g, '/');
    while (str.length % 4) str += '=';
    return Buffer.from(str, 'base64');
}

// Crypto helpers for token encryption
function encryptCalendarToken(plainText) {
    if (!plainText) return "";
    if (!CALENDAR_TOKEN_SECRET) return plainText;
    try {
        const iv = nodeCrypto.randomBytes(16);
        const key = nodeCrypto.scryptSync(CALENDAR_TOKEN_SECRET, 'calendar-salt', 32);
        const cipher = nodeCrypto.createCipheriv('aes-256-cbc', key, iv);
        let encrypted = cipher.update(plainText, 'utf8', 'base64');
        encrypted += cipher.final('base64');
        return `${iv.toString('hex')}:${encrypted}`;
    } catch (err) {
        console.error("calendar encrypt error:", err.message);
        return plainText;
    }
}

function decryptCalendarToken(encryptedText) {
    if (!encryptedText) return "";
    if (!CALENDAR_TOKEN_SECRET || !encryptedText.includes(':')) return encryptedText;
    try {
        const [ivHex, encrypted] = encryptedText.split(':');
        const iv = Buffer.from(ivHex, 'hex');
        const key = nodeCrypto.scryptSync(CALENDAR_TOKEN_SECRET, 'calendar-salt', 32);
        const decipher = nodeCrypto.createDecipheriv('aes-256-cbc', key, iv);
        let decrypted = decipher.update(encrypted, 'base64', 'utf8');
        decrypted += decipher.final('utf8');
        return decrypted;
    } catch (err) {
        console.error("calendar decrypt error:", err.message);
        return encryptedText;
    }
}

// Assert Google SDK is available
function assertCalendarSdk() {
    if (!googleApis || !googleApis.google) {
        throw new Error("Google Calendar SDK não instalado");
    }
    if (!GOOGLE_OAUTH_CLIENT_ID || !GOOGLE_OAUTH_CLIENT_SECRET) {
        throw new Error("Credenciais Google OAuth ausentes (GOOGLE_OAUTH_CLIENT_ID/SECRET)");
    }
    if (!GOOGLE_OAUTH_REDIRECT_URL) {
        throw new Error("GOOGLE_OAUTH_REDIRECT_URL não configurada");
    }
}

// Build OAuth2 client
function buildGoogleOAuthClient() {
    assertCalendarSdk();
    return new googleApis.google.auth.OAuth2(
        GOOGLE_OAUTH_CLIENT_ID,
        GOOGLE_OAUTH_CLIENT_SECRET,
        GOOGLE_OAUTH_REDIRECT_URL
    );
}

// OAuth state management
function generateOAuthState(instanceId) {
    const state = toBase64Url(nodeCrypto.randomBytes(24));
    calendarOauthStates.set(state, {
        instanceId,
        createdAt: Date.now()
    });
    return state;
}

function validateOAuthState(state) {
    if (!state || typeof state !== 'string') return null;
    const meta = calendarOauthStates.get(state);
    if (!meta) return null;
    
    // Clean up expired states (30 minutes)
    const expiry = meta.createdAt + (30 * 60 * 1000);
    if (Date.now() > expiry) {
        calendarOauthStates.delete(state);
        return null;
    }
    
    return meta;
}

function consumeOAuthState(state) {
    const meta = calendarOauthStates.get(state);
    calendarOauthStates.delete(state);
    return meta;
}

// Cleanup expired states
function cleanupExpiredCalendarStates() {
    const now = Date.now();
    const expiry = 30 * 60 * 1000; // 30 minutes
    
    for (const [state, meta] of calendarOauthStates.entries()) {
        if (now - meta.createdAt > expiry) {
            calendarOauthStates.delete(state);
        }
    }
}

// Persist pending auth to database (placeholder - override in main)
async function persistPendingCalendarAuth(instanceId, state, db) {
    // Override with db implementation
}

async function loadPendingCalendarAuth(instanceId, db) {
    return null;
}

async function clearPendingCalendarAuth(instanceId, db, state = null) {
    // Override with db implementation
}

// Get authorization URL
function getAuthUrl(instanceId) {
    const oauth2Client = buildGoogleOAuthClient();
    const state = generateOAuthState(instanceId);
    
    return oauth2Client.generateAuthUrl({
        access_type: "offline",
        scope: GOOGLE_CALENDAR_SCOPES,
        prompt: "consent",
        state
    });
}

// Exchange code for tokens
async function exchangeCodeForTokens(code) {
    const oauth2Client = buildGoogleOAuthClient();
    const { tokens } = await oauth2Client.getToken(code);
    return tokens;
}

// Get user email from OAuth
async function getUserEmail(oauth2Client) {
    try {
        const oauth2 = googleApis.google.oauth2({ version: "v2", auth: oauth2Client });
        const userInfo = await oauth2.userinfo.get();
        return userInfo?.data?.email || null;
    } catch (err) {
        console.error("calendar userinfo error:", err.message);
        return null;
    }
}

module.exports = {
    CALENDAR_TOKEN_SECRET,
    GOOGLE_OAUTH_CLIENT_ID,
    GOOGLE_OAUTH_CLIENT_SECRET,
    GOOGLE_OAUTH_REDIRECT_URL,
    GOOGLE_CALENDAR_SCOPES,
    calendarOauthStates,
    encryptCalendarToken,
    decryptCalendarToken,
    assertCalendarSdk,
    buildGoogleOAuthClient,
    generateOAuthState,
    validateOAuthState,
    consumeOAuthState,
    cleanupExpiredCalendarStates,
    persistPendingCalendarAuth,
    loadPendingCalendarAuth,
    clearPendingCalendarAuth,
    getAuthUrl,
    exchangeCodeForTokens,
    getUserEmail
};
