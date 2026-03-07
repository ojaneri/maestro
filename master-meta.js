// master-meta.js - Meta API Router for Master Server
// Routes Meta API requests to appropriate instances or handles them directly

const express = require('express');
const { fetch } = require('undici');

const router = express.Router();

const LOG_FILE = require('path').join(__dirname, 'master-meta.log');

function log(...args) {
    const timestamp = new Date().toISOString();
    const message = '[' + timestamp + '] ' + args.map(arg => 
        typeof arg === 'string' ? arg : JSON.stringify(arg)
    ).join(' ');
    console.log(message);
    require('fs').appendFileSync(LOG_FILE, message + '\n', { encoding: 'utf8' });
}

function normalizePhoneNumber(phone) {
    if (!phone) return null;
    const digits = String(phone).replace(/\D/g, '');
    if (digits.startsWith('55')) {
        return digits;
    }
    if (digits.length >= 10 && digits.length <= 11) {
        return '55' + digits;
    }
    return digits;
}

// Send template message
router.post('/send-template', async (req, res) => {
    try {
        const { phoneNumberId, accessToken, to, templateName, params, language } = req.body;

        if (!phoneNumberId || !accessToken || !to || !templateName) {
            return res.status(400).json({
                ok: false,
                error: 'Missing required parameters: phoneNumberId, accessToken, to, templateName'
            });
        }

        const result = await sendTemplateMessage(
            phoneNumberId,
            accessToken,
            to,
            templateName,
            params || [],
            language || 'pt_BR'
        );

        res.json({ ok: true, result });
    } catch (error) {
        log('Error sending template message:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Send text message
router.post('/send-text', async (req, res) => {
    try {
        const { phoneNumberId, accessToken, to, text } = req.body;

        if (!phoneNumberId || !accessToken || !to || !text) {
            return res.status(400).json({
                ok: false,
                error: 'Missing required parameters: phoneNumberId, accessToken, to, text'
            });
        }

        const result = await sendTextMessage(phoneNumberId, accessToken, to, text);
        res.json({ ok: true, result });
    } catch (error) {
        log('Error sending text message:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Send media message
router.post('/send-media', async (req, res) => {
    try {
        const { phoneNumberId, accessToken, to, mediaType, mediaUrl, caption } = req.body;

        if (!phoneNumberId || !accessToken || !to || !mediaType || !mediaUrl) {
            return res.status(400).json({
                ok: false,
                error: 'Missing required parameters: phoneNumberId, accessToken, to, mediaType, mediaUrl'
            });
        }

        const result = await sendMediaMessage(phoneNumberId, accessToken, to, mediaType, mediaUrl, caption || '');
        res.json({ ok: true, result });
    } catch (error) {
        log('Error sending media message:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Mark message as read
router.post('/mark-read', async (req, res) => {
    try {
        const { phoneNumberId, accessToken, messageId } = req.body;

        if (!phoneNumberId || !accessToken || !messageId) {
            return res.status(400).json({
                ok: false,
                error: 'Missing required parameters: phoneNumberId, accessToken, messageId'
            });
        }

        const result = await markMessageAsRead(phoneNumberId, accessToken, messageId);
        res.json({ ok: true, result });
    } catch (error) {
        log('Error marking message as read:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Get phone number info
router.get('/phone-info', async (req, res) => {
    try {
        const { phoneNumberId, accessToken } = req.query;

        if (!phoneNumberId || !accessToken) {
            return res.status(400).json({
                ok: false,
                error: 'Missing required parameters: phoneNumberId, accessToken'
            });
        }

        const result = await getPhoneNumberInfo(phoneNumberId, accessToken);
        res.json({ ok: true, result });
    } catch (error) {
        log('Error getting phone number info:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Get business profile
router.get('/profile', async (req, res) => {
    try {
        const { phoneNumberId, accessToken } = req.query;

        if (!phoneNumberId || !accessToken) {
            return res.status(400).json({
                ok: false,
                error: 'Missing required parameters: phoneNumberId, accessToken'
            });
        }

        const result = await getBusinessProfile(phoneNumberId, accessToken);
        res.json({ ok: true, result });
    } catch (error) {
        log('Error getting business profile:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// ===== Meta API Methods =====

async function sendTemplateMessage(phoneNumberId, accessToken, to, templateName, params = [], language = 'pt_BR') {
    const url = 'https://graph.facebook.com/v22.0/' + phoneNumberId + '/messages';
    const payload = {
        messaging_product: 'whatsapp',
        to: normalizePhoneNumber(to),
        type: 'template',
        template: {
            name: templateName,
            language: { code: language },
            components: []
        }
    };

    if (params.length > 0) {
        payload.template.components.push({
            type: 'body',
            parameters: params.map(param => ({
                type: 'text',
                text: String(param || '')
            }))
        });
    }

    log('Sending template message:', { phoneNumberId, to: normalizePhoneNumber(to), template: templateName });

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + accessToken,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    });

    const data = await response.json();

    if (!response.ok) {
        log('Template message failed:', data);
        throw new Error(data?.error?.message || 'HTTP ' + response.status);
    }

    log('Template message sent successfully:', data);
    return data;
}

async function sendTextMessage(phoneNumberId, accessToken, to, text) {
    const url = 'https://graph.facebook.com/v22.0/' + phoneNumberId + '/messages';
    const payload = {
        messaging_product: 'whatsapp',
        to: normalizePhoneNumber(to),
        type: 'text',
        text: { body: text }
    };

    log('Sending text message:', { phoneNumberId, to: normalizePhoneNumber(to) });

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + accessToken,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    });

    const data = await response.json();

    if (!response.ok) {
        log('Text message failed:', data);
        throw new Error(data?.error?.message || 'HTTP ' + response.status);
    }

    log('Text message sent successfully:', data);
    return data;
}

async function sendMediaMessage(phoneNumberId, accessToken, to, mediaType, mediaUrl, caption = '') {
    const url = 'https://graph.facebook.com/v22.0/' + phoneNumberId + '/messages';
    const payload = {
        messaging_product: 'whatsapp',
        to: normalizePhoneNumber(to),
        type: mediaType,
        [mediaType]: {
            link: mediaUrl,
            caption: caption
        }
    };

    log('Sending media message:', { phoneNumberId, to: normalizePhoneNumber(to), mediaType });

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + accessToken,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    });

    const data = await response.json();

    if (!response.ok) {
        log('Media message failed:', data);
        throw new Error(data?.error?.message || 'HTTP ' + response.status);
    }

    log('Media message sent successfully:', data);
    return data;
}

async function markMessageAsRead(phoneNumberId, accessToken, messageId) {
    const url = 'https://graph.facebook.com/v22.0/' + phoneNumberId + '/messages';
    const payload = {
        messaging_product: 'whatsapp',
        status: 'read',
        message_id: messageId
    };

    log('Marking message as read:', { phoneNumberId, messageId });

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + accessToken,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    });

    const data = await response.json();

    if (!response.ok) {
        log('Mark as read failed:', data);
        throw new Error(data?.error?.message || 'HTTP ' + response.status);
    }

    return data;
}

async function getPhoneNumberInfo(phoneNumberId, accessToken) {
    const url = 'https://graph.facebook.com/v22.0/' + phoneNumberId;

    log('Getting phone number info:', { phoneNumberId });

    const response = await fetch(url, {
        method: 'GET',
        headers: {
            'Authorization': 'Bearer ' + accessToken,
            'Content-Type': 'application/json'
        }
    });

    const data = await response.json();

    if (!response.ok) {
        log('Phone number info failed:', data);
        throw new Error(data?.error?.message || 'HTTP ' + response.status);
    }

    return data;
}

async function getBusinessProfile(phoneNumberId, accessToken) {
    const url = 'https://graph.facebook.com/v22.0/' + phoneNumberId + '/whatsapp_business_profile';

    log('Getting business profile:', { phoneNumberId });

    const response = await fetch(url, {
        method: 'GET',
        headers: {
            'Authorization': 'Bearer ' + accessToken,
            'Content-Type': 'application/json'
        }
    });

    const data = await response.json();

    if (!response.ok) {
        log('Business profile failed:', data);
        throw new Error(data?.error?.message || 'HTTP ' + response.status);
    }

    return data;
}

module.exports = router;
