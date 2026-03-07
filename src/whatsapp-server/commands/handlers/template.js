/**
 * @fileoverview Template command handler - sends Meta template messages
 * @module whatsapp-server/commands/handlers/template
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles Meta template message sending via WhatsApp Business API
 */

/**
 * Send template message (Meta templates)
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sendTemplate(args, context = {}) {
    const { 
        to, 
        template_name, 
        language = 'pt_BR', 
        parameters,
        components 
    } = args;
    
    const { socket, sendWhatsAppMessage, db, instanceId } = context;

    if (!to) {
        throw new Error('Parâmetro obrigatório: to');
    }

    if (!template_name) {
        throw new Error('Parâmetro obrigatório: template_name');
    }

    // Try socket first, then fallback
    if (socket || sendWhatsAppMessage) {
        try {
            const templatePayload = buildTemplatePayload({
                to: normalizeJid(to),
                templateName: template_name,
                language,
                parameters,
                components
            });

            const result = await (sendWhatsAppMessage || socket.sendTemplate)(normalizeJid(to), templatePayload);

            console.log('[Template] Template enviado:', template_name);

            return {
                success: true,
                to: normalizeJid(to),
                templateName: template_name,
                language,
                messageId: result?.key?.id,
                sent: true
            };
        } catch (err) {
            // If sendTemplate not available, try via API
            console.warn('[Template] Direct send failed, trying via API:', err.message);
        }
    }

    // Fallback to Meta API via database
    if (db) {
        try {
            const templatePayload = buildTemplatePayload({
                to: normalizePhone(to),
                templateName: template_name,
                language,
                parameters,
                components
            });

            await db.queueTemplateMessage(instanceId || 'default', {
                to: normalizePhone(to),
                template: templatePayload
            });

            return {
                success: true,
                to: normalizePhone(to),
                templateName: template_name,
                queued: true,
                message: 'Template adicionado à fila de envio'
            };
        } catch (err) {
            console.error('[Template] Error queueing template:', err.message);
        }
    }

    // Development fallback - log the template
    console.log('[Template] Template simulado:', {
        to,
        template_name,
        language,
        parameters
    });

    return {
        success: true,
        to: normalizeJid(to),
        templateName: template_name,
        simulated: true,
        message: 'Template simulado (sem conexão WhatsApp)'
    };
}

/**
 * Build template message payload for Meta API
 * @param {Object} options - Template options
 * @returns {Object}
 */
function buildTemplatePayload(options) {
    const { to, templateName, language, parameters, components } = options;

    const template = {
        name: templateName,
        language: {
            code: language || 'pt_BR'
        }
    };

    // Add parameters if provided
    if (parameters && parameters.length > 0) {
        template.components = [
            {
                type: 'body',
                parameters: parameters.map(param => ({
                    type: 'text',
                    text: String(param)
                }))
            }
        ];
    }

    // Add custom components if provided
    if (components && components.length > 0) {
        template.components = [...(template.components || []), ...components];
    }

    return {
        messaging_product: 'whatsapp',
        to: to,
        type: 'template',
        template
    };
}

/**
 * Build button component for template
 * @param {string} buttonType - Button type (quick_reply, url)
 * @param {string} buttonId - Button ID
 * @param {string} text - Button text
 * @returns {Object}
 */
function buildButtonComponent(buttonType, buttonId, text) {
    if (buttonType === 'quick_reply') {
        return {
            type: 'button',
            sub_type: 'quick_reply',
            index: 0,
            parameters: {
                type: 'action',
                action: {
                    button: text,
                    button_param: buttonId
                }
            }
        };
    }

    if (buttonType === 'url') {
        return {
            type: 'button',
            sub_type: 'url',
            index: 0,
            parameters: {
                type: 'action',
                action: {
                    url: buttonId
                }
            }
        };
    }

    throw new Error(`Tipo de botão não suportado: ${buttonType}`);
}

/**
 * Build list component for template
 * @param {string} title - List title
 * @param {Array} rows - List rows
 * @returns {Object}
 */
function buildListComponent(title, rows) {
    return {
        type: 'list',
        button: title,
        sections: [
            {
                title: title,
                rows: rows.map((row, index) => ({
                    id: row.id || String(index),
                    title: row.title,
                    description: row.description || null
                }))
            }
        ]
    };
}

/**
 * Normalize phone number for WhatsApp
 * @param {string} phone - Phone number
 * @returns {string}
 */
function normalizePhone(phone) {
    if (!phone) return '';
    // Remove non-numeric characters
    return phone.replace(/\D/g, '');
}

/**
 * Normalize JID for WhatsApp
 * @param {string} identifier - Phone number or JID
 * @returns {string}
 */
function normalizeJid(identifier) {
    if (!identifier) return '';
    
    if (identifier.includes('@')) {
        return identifier;
    }
    
    const phone = normalizePhone(identifier);
    return `${phone}@s.whatsapp.net`;
}

/**
 * Send interactive template message
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sendInteractiveTemplate(args, context = {}) {
    const { 
        to, 
        templateName, 
        type, // list, product, product_list
        header,
        body,
        footer,
        action
    } = args;

    if (!to || !templateName) {
        throw new Error('Parâmetros obrigatórios: to, templateName');
    }

    const templatePayload = {
        messaging_product: 'whatsapp',
        to: normalizeJid(to),
        type: 'template',
        template: {
            name: templateName,
            language: { code: 'pt_BR' },
            components: []
        }
    };

    // Add header if provided
    if (header) {
        templatePayload.template.components.push({
            type: 'header',
            parameters: [
                {
                    type: 'text',
                    text: header
                }
            ]
        });
    }

    // Add body if provided
    if (body) {
        templatePayload.template.components.push({
            type: 'body',
            parameters: [
                {
                    type: 'text',
                    text: body
                }
            ]
        });
    }

    // Add footer if provided
    if (footer) {
        templatePayload.template.components.push({
            type: 'footer',
            text: footer
        });
    }

    console.log('[Template] Interactive template preparado:', templateName);

    return {
        success: true,
        to: normalizeJid(to),
        templateName,
        payload: templatePayload,
        message: 'Template interativo preparado'
    };
}

module.exports = {
    sendTemplate,
    sendInteractiveTemplate,
    buildTemplatePayload,
    buildButtonComponent,
    buildListComponent,
    normalizePhone,
    normalizeJid,
};
