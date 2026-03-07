/**
 * @fileoverview Customer data command handler - retrieves and updates customer profiles
 * @module whatsapp-server/commands/handlers/dados
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles customer profile lookup and updates
 */

// In-memory stores for development (replace with database in production)
const customerCache = new Map();
const optOutSet = new Set();

/**
 * Get customer data by phone or identifier
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function getCustomerData(args, context = {}) {
    const { phone, cpf, email, id } = args;
    const { db, instanceId } = context;

    if (!phone && !cpf && !email && !id) {
        throw new Error('Parâmetro obrigatório: phone, cpf, email ou id');
    }

    try {
        // Try database lookup first
        if (db && typeof db.getContactMetadata === 'function') {
            const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
            const identifier = phone ? normalizePhone(phone) + '@s.whatsapp.net' : context.remoteJid;
            
            if (identifier) {
                const customer = await db.getContactMetadata(currentInstanceId, identifier);
                if (customer) {
                    return {
                        success: true,
                        found: true,
                        customer: {
                            nome: customer.contact_name,
                            status: customer.status_name,
                            foto: customer.profile_picture,
                            temperatura: customer.temperature,
                            taxar: customer.taxar
                        }
                    };
                }
            }
        }

        // Fallback to cache for development
        const cacheKey = phone || cpf || email || id;
        if (customerCache.has(cacheKey)) {
            return {
                success: true,
                found: true,
                customer: customerCache.get(cacheKey)
            };
        }

        return {
            success: true,
            found: false,
            message: 'Cliente não encontrado'
        };
    } catch (error) {
        console.error('[Dados] Error getting customer:', error);
        throw error;
    }
}

/**
 * Update customer data
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function updateCustomerData(args, context = {}) {
    const { phone, cpf, email, id, data } = args;
    const { db, instanceId } = context;

    if (!id && !phone && !cpf) {
        throw new Error('Parâmetro obrigatório: id, phone ou cpf');
    }

    if (!data || typeof data !== 'object') {
        throw new Error('Dados para atualização são obrigatórios');
    }

    try {
        if (db) {
            const query = id ? { id } : phone ? { phone: normalizePhone(phone) } : { cpf };
            const updated = await db.updateCustomer(instanceId || 'default', query, data);
            
            return {
                success: true,
                message: 'Cliente atualizado com sucesso',
                customer: updated
            };
        }

        // Fallback to cache
        const cacheKey = id || phone || cpf;
        const existing = customerCache.get(cacheKey) || {};
        customerCache.set(cacheKey, { ...existing, ...data });

        return {
            success: true,
            message: 'Cliente atualizado (cache)',
            customer: { ...existing, ...data }
        };
    } catch (error) {
        console.error('[Dados] Error updating customer:', error);
        throw error;
    }
}

/**
 * Opt out from communications
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function optOut(args, context = {}) {
    const { phone } = args;
    const { db, instanceId, remoteJid } = context;

    const identifier = phone || remoteJid;
    if (!identifier) {
        throw new Error('Telefone ou remoteJid é obrigatório');
    }

    try {
        if (db) {
            await db.saveOptOut(instanceId || 'default', identifier);
        }
        
        optOutSet.add(identifier);

        return {
            success: true,
            message: 'Opt-out registrado com sucesso'
        };
    } catch (error) {
        console.error('[Dados] Error setting opt-out:', error);
        throw error;
    }
}

/**
 * Check and update status follow-up
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function statusFollowup(args, context = {}) {
    const { phone, status } = args;
    const { db, instanceId } = context;

    const identifier = phone || context.remoteJid;
    if (!identifier) {
        throw new Error('Telefone é obrigatório');
    }

    try {
        if (db && status) {
            await db.saveStatusFollowup(instanceId || 'default', identifier, status);
        }

        return {
            success: true,
            message: `Status follow-up: ${status || 'atualizado'}`
        };
    } catch (error) {
        console.error('[Dados] Error in status followup:', error);
        throw error;
    }
}

/**
 * Log an event
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function logEvent(args, context = {}) {
    const { type, description, metadata } = args;
    const { db, instanceId, remoteJid } = context;

    if (!type) {
        throw new Error('Tipo de evento é obrigatório');
    }

    try {
        const event = {
            type,
            description: description || '',
            metadata: metadata || {},
            jid: remoteJid,
            timestamp: new Date().toISOString()
        };

        if (db) {
            await db.saveEventLog(instanceId || 'default', event);
        }

        console.log('[Dados] Event logged:', event.type);

        return {
            success: true,
            message: 'Evento registrado',
            eventId: `${Date.now()}`
        };
    } catch (error) {
        console.error('[Dados] Error logging event:', error);
        throw error;
    }
}

/**
 * Get time since last interaction
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function tempoSemInteracao(args, context = {}) {
    const { phone } = args;
    const { db, instanceId } = context;

    const identifier = phone || context.remoteJid;
    if (!identifier) {
        throw new Error('Telefone é obrigatório');
    }

    try {
        let lastInteraction = null;

        if (db) {
            lastInteraction = await db.getLastInteraction(instanceId || 'default', identifier);
        }

        if (lastInteraction) {
            const now = new Date();
            const diff = now - new Date(lastInteraction);
            const minutes = Math.floor(diff / 60000);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);

            let formatted;
            if (days > 0) {
                formatted = `${days} dia(s)`;
            } else if (hours > 0) {
                formatted = `${hours} hora(s)`;
            } else {
                formatted = `${minutes} minuto(s)`;
            }

            return {
                success: true,
                tempoSemInteracao: formatted,
                ultimaInteracao: lastInteraction
            };
        }

        return {
            success: true,
            tempoSemInteracao: 'Desconhecido',
            message: 'Nenhuma interação registrada'
        };
    } catch (error) {
        console.error('[Dados] Error getting interaction time:', error);
        throw error;
    }
}

/**
 * Normalize phone number
 * @param {string} phone - Phone number
 * @returns {string}
 */
function normalizePhone(phone) {
    if (!phone) return '';
    // Remove non-numeric characters
    return phone.replace(/\D/g, '');
}

/**
 * Format customer data for response
 * @param {Object} customer - Customer object
 * @returns {Object}
 */
function formatCustomerData(customer) {
    return {
        id: customer.id,
        phone: customer.phone,
        nome: customer.nome || customer.name,
        email: customer.email,
        cpf: customer.cpf,
        tipo: customer.tipo || customer.type,
        status: customer.status,
        createdAt: customer.created_at || customer.createdAt,
        updatedAt: customer.updated_at || customer.updatedAt
    };
}

/**
 * Check if customer is opted out
 * @param {string} identifier - Phone or JID
 * @returns {boolean}
 */
function isOptedOut(identifier) {
    return optOutSet.has(identifier);
}

module.exports = {
    getCustomerData,
    updateCustomerData,
    optOut,
    statusFollowup,
    logEvent,
    tempoSemInteracao,
    isOptedOut,
    normalizePhone,
    formatCustomerData,
    customerCache,
    optOutSet,
};
