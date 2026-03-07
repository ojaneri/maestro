/**
 * @fileoverview WhatsApp Server API routes
 * @module whatsapp-server/routes/api
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~5331-6513)
 * REST API endpoints for the WhatsApp server
 */

const express = require('express');
const router = express.Router();

// API Authentication Middleware
// Enable by setting API_AUTH_ENABLED=true and API_AUTH_TOKEN in environment
function apiAuth(req, res, next) {
    // Skip auth if not enabled
    if (process.env.API_AUTH_ENABLED !== 'true') {
        return next();
    }
    
    const authToken = process.env.API_AUTH_TOKEN;
    if (!authToken) {
        console.warn('[API] Auth enabled but no token configured - blocking all requests');
        return res.status(500).json({ error: 'Server misconfiguration: API auth token not set' });
    }
    
    const providedToken = req.headers['x-api-token'] || req.query.api_token;
    
    if (!providedToken) {
        return res.status(401).json({ error: 'Authentication required. Provide X-API-Token header or api_token query parameter.' });
    }
    
    if (providedToken !== authToken) {
        console.warn('[API] Invalid API token attempt from:', req.ip);
        return res.status(403).json({ error: 'Invalid authentication token' });
    }
    
    return next();
}

// Sensitive endpoints that require authentication
const sensitiveEndpoints = [
    '/contacts',
    '/messages',
    '/send',
    '/instance',
    '/groups',
    '/settings',
    '/ai/config'
];

// Apply authentication to sensitive endpoints
router.use(apiAuth);

// Helper to get dependencies
function getDeps() {
    return {
        db: require('../server/express-app').getDb(),
        sock: require('../server/express-app').getSocket(),
        isConnected: require('../server/express-app').isWhatsAppConnected(),
        INSTANCE_ID: require('../config/constants').INSTANCE_ID
    };
}

// ==================== HEALTH & STATUS ====================

router.get('/qr', async (req, res) => {
    try {
        const { isConnected, db, sock, INSTANCE_ID } = getDeps();
        
        if (isConnected && sock) {
            return res.json({ qr: null, connected: true });
        }
        
        // Get QR code from connection module
        try {
            const connection = require('../whatsapp/connection');
            const qrCode = connection.getQRCode();
            
            if (qrCode) {
                return res.json({ qr: qrCode, connected: false });
            }
        } catch (connErr) {
            // Connection module not available
        }
        
        return res.json({ qr: null, connected: false, waiting: true });
    } catch (err) {
        res.status(500).json({ error: err.message });
    }
});

router.get('/health', async (req, res) => {
    const { isConnected, db } = getDeps();
    
    let dbStatus = 'unknown';
    if (db) {
        try {
            await db.ping();
            dbStatus = 'connected';
        } catch (err) {
            dbStatus = 'error';
        }
    }
    
    res.json({
        status: isConnected ? 'connected' : 'disconnected',
        database: dbStatus,
        timestamp: new Date().toISOString()
    });
});

// ==================== CONTACTS ====================

router.get('/contacts', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { remoteJid } = req.query;
        const contacts = await db.listContacts(INSTANCE_ID, remoteJid);
        res.json({ ok: true, contacts });
    } catch (err) {
        console.error("Error listing contacts:", err.message);
        res.status(500).json({ error: "Failed to list contacts", detail: err.message });
    }
});

router.get('/contacts/:jid', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { jid } = req.params;
        const contact = await db.getContact(INSTANCE_ID, jid);
        
        if (!contact) {
            return res.status(404).json({ error: "Contact not found" });
        }
        
        res.json({ ok: true, contact });
    } catch (err) {
        console.error("Error getting contact:", err.message);
        res.status(500).json({ error: "Failed to get contact", detail: err.message });
    }
});

router.delete('/contacts/:jid', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { jid } = req.params;
        await db.deleteContact(INSTANCE_ID, jid);
        res.json({ ok: true, jid });
    } catch (err) {
        console.error("Error deleting contact:", err.message);
        res.status(500).json({ error: "Failed to delete contact", detail: err.message });
    }
});

// ==================== MESSAGES ====================

router.get('/messages', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { remoteJid, limit = 50, offset = 0 } = req.query;
        const messages = await db.listMessages(INSTANCE_ID, remoteJid, Number(limit), Number(offset));
        res.json({ ok: true, messages });
    } catch (err) {
        console.error("Error listing messages:", err.message);
        res.status(500).json({ error: "Failed to list messages", detail: err.message });
    }
});

router.get('/messages/:jid', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { jid } = req.params;
        const { limit = 50 } = req.query;
        const messages = await db.listMessages(INSTANCE_ID, jid, Number(limit));
        res.json({ ok: true, messages, remoteJid: jid });
    } catch (err) {
        console.error("Error getting messages:", err.message);
        res.status(500).json({ error: "Failed to get messages", detail: err.message });
    }
});

// ==================== SCHEDULED MESSAGES ====================

router.get('/scheduled', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { remoteJid, tag, tipo } = req.query;
        const scheduled = await db.listScheduledMessages(INSTANCE_ID, remoteJid, tag, tipo);
        res.json({ ok: true, scheduled });
    } catch (err) {
        console.error("Error listing scheduled messages:", err.message);
        res.status(500).json({ error: "Failed to list scheduled messages", detail: err.message });
    }
});

router.post('/scheduled', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { remoteJid, message, scheduledFor, tag, tipo } = req.body;
        
        if (!remoteJid || !message || !scheduledFor) {
            return res.status(400).json({ error: "remoteJid, message, and scheduledFor are required" });
        }
        
        const scheduledDate = new Date(scheduledFor);
        const result = await db.enqueueScheduledMessage(INSTANCE_ID, remoteJid, message, scheduledDate, tag, tipo);
        
        res.json({ ok: true, scheduledId: result.scheduledId, scheduledAt: result.scheduledAt });
    } catch (err) {
        console.error("Error creating scheduled message:", err.message);
        res.status(500).json({ error: "Failed to create scheduled message", detail: err.message });
    }
});

router.delete('/scheduled/:id', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { id } = req.params;
        await db.deleteScheduledMessage(id);
        res.json({ ok: true, id });
    } catch (err) {
        console.error("Error deleting scheduled message:", err.message);
        res.status(500).json({ error: "Failed to delete scheduled message", detail: err.message });
    }
});

router.delete('/scheduled', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { remoteJid, tag, tipo } = req.query;
        
        if (tag) {
            const result = await db.deleteScheduledMessagesByTag(INSTANCE_ID, remoteJid, tag);
            return res.json({ ok: true, deleted: result.deleted, tag });
        }
        
        if (tipo) {
            const result = await db.deleteScheduledMessagesByTipo(INSTANCE_ID, remoteJid, tipo);
            return res.json({ ok: true, deleted: result.deleted, tipo });
        }
        
        res.status(400).json({ error: "tag or tipo parameter required" });
    } catch (err) {
        console.error("Error deleting scheduled messages:", err.message);
        res.status(500).json({ error: "Failed to delete scheduled messages", detail: err.message });
    }
});

// ==================== GROUPS ====================

router.get('/groups', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const groups = await db.listGroups(INSTANCE_ID);
        res.json({ ok: true, groups });
    } catch (err) {
        console.error("Error listing groups:", err.message);
        res.status(500).json({ error: "Failed to list groups", detail: err.message });
    }
});

router.get('/groups/:jid', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { jid } = req.params;
        const group = await db.getGroup(INSTANCE_ID, jid);
        
        if (!group) {
            return res.status(404).json({ error: "Group not found" });
        }
        
        res.json({ ok: true, group });
    } catch (err) {
        console.error("Error getting group:", err.message);
        res.status(500).json({ error: "Failed to get group", detail: err.message });
    }
});

// ==================== CALENDAR ====================

router.get('/calendar/auth-url', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        
        const result = await calendar.getAuthUrl(instanceId);
        res.json(result);
    } catch (err) {
        console.error("calendar auth-url error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao gerar URL OAuth", detail: err.message });
    }
});

router.get('/calendar/oauth2/callback', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        const { code, state } = req.query;
        
        if (!code || !state) {
            return res.status(400).json({ ok: false, error: "Parâmetros code/state obrigatórios" });
        }
        
        const result = await calendar.handleOAuthCallback(instanceId, code, state);
        res.json(result);
    } catch (err) {
        console.error("calendar oauth2 callback error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao concluir OAuth", detail: err.message });
    }
});

router.post('/calendar/disconnect', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        
        const result = await calendar.disconnect(instanceId);
        res.json(result);
    } catch (err) {
        console.error("calendar disconnect error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao desconectar calendar", detail: err.message });
    }
});

router.get('/calendar/config', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        
        const result = await calendar.getConfig(instanceId);
        res.json(result);
    } catch (err) {
        console.error("calendar config error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao ler configuração", detail: err.message });
    }
});

router.get('/calendar/google-calendars', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        
        const result = await calendar.listGoogleCalendars(instanceId);
        res.json(result);
    } catch (err) {
        console.error("calendar list error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao listar calendários", detail: err.message });
    }
});

router.post('/calendar/calendars', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        
        const result = await calendar.saveCalendarConfig(instanceId, req.body);
        res.json(result);
    } catch (err) {
        console.error("calendar save error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao salvar calendário", detail: err.message });
    }
});

router.delete('/calendar/calendars', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        const calendarId = req.query.calendar_id;
        
        if (!calendarId) {
            return res.status(400).json({ ok: false, error: "calendar_id é obrigatório" });
        }
        
        const result = await calendar.deleteCalendarConfig(instanceId, calendarId);
        res.json(result);
    } catch (err) {
        console.error("calendar delete error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao remover calendário", detail: err.message });
    }
});

router.post('/calendar/default', async (req, res) => {
    try {
        const calendar = require('../calendar');
        const { INSTANCE_ID } = getDeps();
        const instanceId = req.query.instance || INSTANCE_ID;
        const calendarId = req.body?.calendar_id;
        
        if (!calendarId) {
            return res.status(400).json({ ok: false, error: "calendar_id é obrigatório" });
        }
        
        const result = await calendar.setDefaultCalendar(instanceId, calendarId);
        res.json(result);
    } catch (err) {
        console.error("calendar default error:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao definir calendário padrão", detail: err.message });
    }
});

// ==================== SETTINGS ====================

router.get('/settings/:key', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { key } = req.params;
        const value = await db.getSetting(INSTANCE_ID, key);
        
        res.json({ ok: true, key, value });
    } catch (err) {
        console.error("Error getting setting:", err.message);
        res.status(500).json({ error: "Failed to get setting", detail: err.message });
    }
});

router.post('/settings/:key', async (req, res) => {
    try {
        const { db, INSTANCE_ID } = getDeps();
        if (!db) return res.status(503).json({ error: "Database not available" });
        
        const { key } = req.params;
        const { value } = req.body;
        
        await db.setSetting(INSTANCE_ID, key, value);
        
        res.json({ ok: true, key, value });
    } catch (err) {
        console.error("Error setting value:", err.message);
        res.status(500).json({ error: "Failed to set setting", detail: err.message });
    }
});

// ==================== AI CONFIG ====================

router.get('/ai-config', async (req, res) => {
    try {
        const ai = require('../ai');
        const { db, INSTANCE_ID } = getDeps();
        const config = await ai.loadAIConfig(db, INSTANCE_ID);
        res.json({ ok: true, config });
    } catch (err) {
        console.error("Error loading AI config:", err.message);
        res.status(500).json({ ok: false, error: "Failed to load AI config" });
    }
});

router.post('/ai-config', async (req, res) => {
    try {
        const ai = require('../ai');
        const { db, INSTANCE_ID } = getDeps();
        const payload = req.body || {};
        await ai.persistAIConfig(db, INSTANCE_ID, payload);
        res.json({ ok: true });
    } catch (err) {
        console.error("Error saving AI config:", err.message);
        res.status(500).json({ ok: false, error: "Failed to save AI config", detail: err.message });
    }
});

// ==================== INSTANCE ====================

router.post('/instance', async (req, res) => {
    try {
        const { INSTANCE_ID } = getDeps();
        const payload = req.body || {};
        const requestedInstanceId = req.query.instance || INSTANCE_ID;
        
        if (requestedInstanceId !== INSTANCE_ID) {
            return res.status(400).json({ ok: false, error: "Instância inválida para este processo" });
        }
        
        // Handle instance updates (implementation in express-app)
        const result = require('../server/express-app').updateInstanceConfig(payload);
        res.json({ ok: true, ...result });
    } catch (err) {
        console.error("Error syncing instance metadata:", err.message);
        res.status(500).json({ ok: false, error: "Não foi possível atualizar a instância", detail: err.message });
    }
});

// ==================== AI TEST ====================

router.post('/ai-test', async (req, res) => {
    try {
        const ai = require('../ai');
        const { message, remote_jid } = req.body || {};
        const { db, isConnected, INSTANCE_ID } = getDeps();
        
        if (!message || typeof message !== "string" || !message.trim()) {
            return res.status(400).json({ ok: false, error: "Mensagem é obrigatória" });
        }
        
        // Check WhatsApp connection
        if (!isConnected) {
            return res.status(503).json({ error: "WhatsApp não conectado" });
        }
        
        // Fix: Create proper sessionContext object with required properties
        const targetJid = remote_jid || `test-${INSTANCE_ID}`;
        const sessionContext = {
            remoteJid: targetJid,
            instanceId: INSTANCE_ID,
            sessionId: `test-${Date.now()}`
        };
        
        // Fix: Pass correct parameters to loadAIConfig (db, instanceId)
        const aiConfig = await ai.loadAIConfig(db, INSTANCE_ID);
        const testConfig = { ...aiConfig, enabled: true };
        
        // Fix: Pass sessionContext object instead of string, with dependencies
        const response = await ai.generateAIResponse(
            sessionContext,
            message.trim(),
            testConfig,
            { db, instanceId: INSTANCE_ID }
        );
        
        res.json({
            ok: true,
            provider: response.provider,
            response: response.text
        });
    } catch (err) {
        console.error("AI test failed:", err.message);
        res.status(500).json({ ok: false, error: "Falha ao testar IA", detail: err.message });
    }
});

// ==================== SEND MESSAGE ====================

router.post('/send-message', async (req, res) => {
    console.log("[DEBUG Modular] /api/send-message hit.", req.body);
    const { isConnected, sock, db, INSTANCE_ID } = getDeps();
    console.log("[DEBUG Modular] isConnected:", isConnected, "sock:", sock);
    
    if (!isConnected || !sock) {
        return res.status(503).json({ error: "WhatsApp não conectado" });
    }
    
    // Extract all possible fields including media
    const { 
        to, 
        message,
        // Image fields
        image_url,
        image_base64,
        // Video fields
        video_url,
        video_base64,
        // Audio field
        audio_url,
        // Caption for media
        caption
    } = req.body;
    
    // Determine if this is a media message
    const hasImage = !!(image_url || image_base64);
    const hasVideo = !!(video_url || video_base64);
    const hasAudio = !!audio_url;
    const hasMedia = hasImage || hasVideo || hasAudio;
    
    // Validate: need 'to' always, need either message or media
    if (!to) {
        return res.status(400).json({ error: "Parâmetro 'to' é obrigatório" });
    }
    
    if (!message && !hasMedia) {
        return res.status(400).json({ error: "Parâmetro 'message' ou dados de mídia são obrigatórios" });
    }
    
    try {
        let jid = to;
        if (!jid.includes("@")) {
            const digits = String(jid).replace(/\D/g, "");
            jid = `${digits}@s.whatsapp.net`;
        }
        
        // Handle media message
        if (hasMedia) {
            const { sendWhatsAppMedia } = require('../whatsapp/send-message');
            
            let mediaType, mediaContent;
            
            if (hasImage) {
                mediaType = 'image';
                mediaContent = image_url || image_base64;
            } else if (hasVideo) {
                mediaType = 'video';
                mediaContent = video_url || video_base64;
            } else if (hasAudio) {
                mediaType = 'audio';
                mediaContent = audio_url;
            }
            
            const mediaPayload = {
                type: mediaType,
                content: mediaContent,
                caption: caption || message || ''
            };
            
            console.log("[DEBUG Modular] Sending media:", mediaType, "to:", jid);
            const result = await sendWhatsAppMedia(sock, jid, mediaPayload);
            
            // Save sent media message
            if (db) {
                try {
                    const mediaDesc = `${mediaType}: ${caption || message || ''}`;
                    await db.saveMessage(INSTANCE_ID, jid, 'assistant', mediaDesc, 'outbound');
                } catch (err) {
                    console.error("Error saving sent media message:", err.message);
                }
            }
            
            return res.json({
                ok: true,
                instanceId: INSTANCE_ID,
                to: jid,
                type: mediaType,
                result
            });
        }
        
        // Handle text message (legacy)
        const { sendWhatsAppMessage } = require('../whatsapp/send-message');
        const result = await sendWhatsAppMessage(sock, jid, { text: message });
        
        // Save sent message
        if (db) {
            try {
                await db.saveMessage(INSTANCE_ID, jid, 'assistant', message, 'outbound');
            } catch (err) {
                console.error("Error saving sent message:", err.message);
            }
        }
        
        res.json({
            ok: true,
            instanceId: INSTANCE_ID,
            to: jid,
            result
        });
    } catch (err) {
        const detail = err.message || "Falha ao enviar mensagem";
        const normalized = detail.toLowerCase();
        let status = 500;
        let error = "Falha ao enviar mensagem";
        
        if (normalized.includes("número inválido")) {
            status = 400;
            error = "Número inválido";
        } else if (normalized.includes("não existe")) {
            status = 404;
            error = "Número não existe no WhatsApp";
        }
        
        console.error("Erro ao enviar mensagem:", detail);
        res.status(status).json({ error, detail });
    }
});

// ==================== DISCONNECT / RESTART ====================

router.post('/disconnect', async (req, res) => {
    try {
        await require('../server/express-app').logoutWhatsApp();
        res.json({ ok: true, message: "Logout realizado" });
    } catch (err) {
        res.status(500).json({ error: "Falha ao fazer logout", detail: err.message });
    }
});

router.post('/restart', async (req, res) => {
    try {
        await require('../server/express-app').restartWhatsApp();
        res.json({ ok: true, message: "Restart solicitado" });
    } catch (err) {
        res.status(500).json({ error: "Falha ao reiniciar", detail: err.message });
    }
});

// ==================== MONITORING ====================

router.get('/monitoring/status', async (req, res) => {
    try {
        const monitoring = require('../monitoring');
        res.json(monitoring.getStatus());
    } catch (err) {
        console.error("Error getting monitoring status:", err.message);
        res.status(500).json({ error: "Failed to get monitoring status", detail: err.message });
    }
});

router.get('/monitoring/metrics', async (req, res) => {
    try {
        const monitoring = require('../monitoring');
        const metrics = await monitoring.getMetrics();
        res.json({ ok: true, metrics });
    } catch (err) {
        console.error("Error getting metrics:", err.message);
        res.status(500).json({ error: "Failed to get metrics", detail: err.message });
    }
});

module.exports = router;
