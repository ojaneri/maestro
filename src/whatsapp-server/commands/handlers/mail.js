/**
 * @fileoverview Mail command handler - sends emails
 * @module whatsapp-server/commands/handlers/mail
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles send_mail command execution
 */

/**
 * Send email command
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sendMail(args, context = {}) {
    const { to, subject, body, attachments, html } = args;
    const { db, instanceId } = context;

    if (!to) {
        throw new Error('Parâmetro obrigatório: to');
    }

    try {
        // Build email payload
        const emailPayload = {
            to,
            subject: subject || 'Mensagem do Maestro',
            body: body || '',
            html: html || false,
            attachments: attachments || []
        };

        // Try to send via nodemailer if configured
        if (process.env.SMTP_HOST) {
            const result = await sendViaSMTP(emailPayload);
            return {
                success: true,
                messageId: result.messageId,
                to,
                subject: emailPayload.subject,
                sent: true
            };
        }

        // Fallback to database queue
        if (db) {
            await db.queueEmail(instanceId || 'default', emailPayload);
            return {
                success: true,
                messageId: `queue_${Date.now()}`,
                to,
                subject: emailPayload.subject,
                queued: true,
                message: 'E-mail adicionado à fila de envio'
            };
        }

        // Log for development
        console.log('[Mail] E-mail simulado:', emailPayload);

        return {
            success: true,
            messageId: `dev_${Date.now()}`,
            to,
            subject: emailPayload.subject,
            simulated: true,
            message: 'E-mail simulado (sem configuração SMTP)'
        };
    } catch (error) {
        console.error('[Mail] Error sending email:', error);
        throw error;
    }
}

/**
 * Send email via SMTP
 * @param {Object} payload - Email payload
 * @returns {Promise<Object>}
 */
async function sendViaSMTP(payload) {
    // Dynamic import to avoid requiring nodemailer if not installed
    let nodemailer;
    try {
        nodemailer = require('nodemailer');
    } catch (err) {
        throw new Error('nodemailer não está instalado');
    }

    const transporter = nodemailer.createTransport({
        host: process.env.SMTP_HOST,
        port: parseInt(process.env.SMTP_PORT, 10) || 587,
        secure: process.env.SMTP_SECURE === 'true',
        auth: {
            user: process.env.SMTP_USER,
            pass: process.env.SMTP_PASS
        }
    });

    const mailOptions = {
        from: process.env.SMTP_FROM || process.env.SMTP_USER,
        to: payload.to,
        subject: payload.subject,
        text: payload.html ? undefined : payload.body,
        html: payload.html ? payload.body : undefined,
        attachments: payload.attachments
    };

    const result = await transporter.sendMail(mailOptions);

    return {
        messageId: result.messageId,
        accepted: result.accepted,
        rejected: result.rejected
    };
}

/**
 * Send bulk emails
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sendBulkMail(args, context = {}) {
    const { recipients, subject, body, html } = args;
    const { db, instanceId } = context;

    if (!recipients || !Array.isArray(recipients)) {
        throw new Error('Parâmetro obrigatório: recipients (array)');
    }

    if (!subject) {
        throw new Error('Parâmetro obrigatório: subject');
    }

    try {
        const results = {
            success: true,
            sent: 0,
            failed: 0,
            queued: 0
        };

        for (const recipient of recipients) {
            try {
                await sendMail({
                    to: recipient,
                    subject,
                    body,
                    html
                }, context);
                results.sent++;
            } catch (err) {
                results.failed++;
                console.error(`[Mail] Failed to send to ${recipient}:`, err.message);
            }
        }

        return {
            ...results,
            message: `Enviados: ${results.sent}, Falharam: ${results.failed}`
        };
    } catch (error) {
        console.error('[Mail] Error in bulk send:', error);
        throw error;
    }
}

/**
 * Validate email address
 * @param {string} email - Email to validate
 * @returns {boolean}
 */
function validateEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
}

module.exports = {
    sendMail,
    sendBulkMail,
    sendViaSMTP,
    validateEmail,
};
