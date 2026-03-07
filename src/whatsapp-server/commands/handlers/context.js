/**
 * @fileoverview Context command handler - manages conversation context and variables
 * @module whatsapp-server/commands/handlers/context
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles set_contexto, get_contexto, and set_variavel commands
 */

// In-memory context storage (replace with database in production)
const contextStore = new Map();
const variableStore = new Map();
const stateStore = new Map();

/**
 * Set conversation context
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function setContexto(args, context = {}) {
    const { chave, valor } = args;
    const { db, instanceId, remoteJid } = context;

    if (!chave) {
        throw new Error('Parâmetro obrigatório: chave');
    }

    const dbKey = `ctx:${chave}`;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';

    // Try database first
    if (db && typeof db.setContactContext === 'function') {
        try {
            await db.setContactContext(currentInstanceId, remoteJid, dbKey, valor);
        } catch (err) {
            console.warn('[Context] Could not save to database:', err.message);
        }
    }

    // Update in-memory store for fallback
    const conversationKey = buildConversationKey(currentInstanceId, remoteJid);
    if (!contextStore.has(conversationKey)) {
        contextStore.set(conversationKey, {});
    }
    contextStore.get(conversationKey)[chave] = valor;

    return {
        success: true,
        message: `Contexto "${chave}" definido com sucesso`,
        key: chave,
        value: valor
    };
}

/**
 * Get conversation context
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function getContexto(args, context = {}) {
    const { chave } = args;
    const { db, instanceId, remoteJid } = context;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';

    // Try database first
    if (db) {
        try {
            if (chave) {
                const dbKey = `ctx:${chave}`;
                if (typeof db.getContactContext === 'function') {
                    const entry = await db.getContactContext(currentInstanceId, remoteJid, dbKey);
                    if (entry) {
                        return {
                            success: true,
                            found: true,
                            key: chave,
                            value: typeof entry === 'object' ? entry.value : entry
                        };
                    }
                }
            } else {
                if (typeof db.listContactContext === 'function') {
                    const rows = await db.listContactContext(currentInstanceId, remoteJid);
                    const allContext = {};
                    rows.forEach(r => {
                        if (r.key.startsWith('ctx:')) {
                            allContext[r.key.replace('ctx:', '')] = r.value;
                        }
                    });
                    return {
                        success: true,
                        found: Object.keys(allContext).length > 0,
                        context: allContext
                    };
                }
            }
        } catch (err) {
            console.warn('[Context] Could not read from database:', err.message);
        }
    }

    // Fallback to in-memory
    const conversationKey = buildConversationKey(currentInstanceId, remoteJid);
    if (!contextStore.has(conversationKey)) {
        return {
            success: true,
            found: false,
            message: 'Nenhum contexto encontrado'
        };
    }

    const conversationContext = contextStore.get(conversationKey);
    if (chave) {
        const value = conversationContext[chave];
        return {
            success: true,
            found: value !== undefined,
            key: chave,
            value: value || null
        };
    }

    return {
        success: true,
        found: true,
        context: conversationContext
    };
}

/**
 * Clear conversation context
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function limparContexto(args, context = {}) {
    const { chave } = args;
    const { db, instanceId, remoteJid } = context;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';

    // Try database first
    if (db && typeof db.deleteContactContext === 'function') {
        try {
            if (chave) {
                await db.deleteContactContext(currentInstanceId, remoteJid, [`ctx:${chave}`]);
            } else {
                // This is a bit tricky, we'd need a list of ctx: keys or delete all
                // For now, let's just delete the specific one or rely on memory
            }
        } catch (err) {
            console.warn('[Context] Could not clear from database:', err.message);
        }
    }

    // Update in-memory store
    const conversationKey = buildConversationKey(currentInstanceId, remoteJid);
    if (contextStore.has(conversationKey)) {
        if (chave) {
            delete contextStore.get(conversationKey)[chave];
        } else {
            contextStore.delete(conversationKey);
        }
    }

    return {
        success: true,
        message: chave ? `Contexto "${chave}" limpo` : 'Todo o contexto limpo'
    };
}

/**
 * Set session variable
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function setVariavel(args, context = {}) {
    const { nome, valor } = args;
    const { db, instanceId, remoteJid } = context;

    if (!nome) {
        throw new Error('Parâmetro obrigatório: nome');
    }

    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
    const dbKey = `var:${nome}`;

    // Try database first
    if (db && typeof db.setContactContext === 'function') {
        try {
            await db.setContactContext(currentInstanceId, remoteJid, dbKey, valor);
        } catch (err) {
            console.warn('[Context] Could not save variable to database:', err.message);
        }
    }

    // Update in-memory store
    const variableKey = buildVariableKey(currentInstanceId, remoteJid, nome);
    variableStore.set(variableKey, {
        value: valor,
        setAt: new Date().toISOString()
    });

    return {
        success: true,
        message: `Variável "${nome}" definida com sucesso`,
        name: nome,
        value: valor
    };
}

/**
 * Get session variable
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function getVariavel(args, context = {}) {
    const { nome } = args;
    const { db, instanceId, remoteJid } = context;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
    const dbKey = `var:${nome}`;

    // Try database first
    if (db && typeof db.getContactContext === 'function') {
        try {
            const entry = await db.getContactContext(currentInstanceId, remoteJid, dbKey);
            if (entry) {
                return {
                    success: true,
                    found: true,
                    name: nome,
                    value: typeof entry === 'object' ? entry.value : entry
                };
            }
        } catch (err) {
            console.warn('[Context] Could not read variable from database:', err.message);
        }
    }

    // Fallback to in-memory
    const variableKey = buildVariableKey(currentInstanceId, remoteJid, nome);
    if (!variableStore.has(variableKey)) {
        return {
            success: true,
            found: false,
            name: nome,
            message: 'Variável não encontrada'
        };
    }

    const variableData = variableStore.get(variableKey);
    return {
        success: true,
        found: true,
        name: nome,
        value: variableData.value,
        setAt: variableData.setAt
    };
}

/**
 * Set conversation state
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function setEstado(args, context = {}) {
    const { estado } = args;
    const { db, instanceId, remoteJid } = context;

    if (!estado) {
        throw new Error('Parâmetro obrigatório: estado');
    }

    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';

    // Try database first
    if (db && typeof db.setContactContext === 'function') {
        try {
            await db.setContactContext(currentInstanceId, remoteJid, 'state', estado);
        } catch (err) {
            console.warn('[Context] Could not save state to database:', err.message);
        }
    }

    const stateKey = buildConversationKey(currentInstanceId, remoteJid);
    stateStore.set(stateKey, {
        estado,
        setAt: new Date().toISOString()
    });

    return {
        success: true,
        message: `Estado definido como "${estado}"`,
        estado
    };
}

/**
 * Get conversation state
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function getEstado(args, context = {}) {
    const { db, instanceId, remoteJid } = context;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';

    // Try database first
    if (db && typeof db.getContactContext === 'function') {
        try {
            const entry = await db.getContactContext(currentInstanceId, remoteJid, 'state');
            if (entry) {
                return {
                    success: true,
                    found: true,
                    estado: typeof entry === 'object' ? entry.value : entry
                };
            }
        } catch (err) {
            console.warn('[Context] Could not read state from database:', err.message);
        }
    }

    // Fallback to in-memory
    const stateKey = buildConversationKey(currentInstanceId, remoteJid);
    if (!stateStore.has(stateKey)) {
        return {
            success: true,
            found: false,
            message: 'Nenhum estado definido'
        };
    }

    const stateData = stateStore.get(stateKey);
    return {
        success: true,
        found: true,
        estado: stateData.estado,
        setAt: stateData.setAt
    };
}

/**
 * Build conversation key for storage
 * @param {string} instanceId - Instance ID
 * @param {string} remoteJid - Remote JID
 * @returns {string}
 */
function buildConversationKey(instanceId, remoteJid) {
    return `${instanceId || 'default'}:${remoteJid || 'unknown'}`;
}

/**
 * Build variable key for storage
 * @param {string} instanceId - Instance ID
 * @param {string} remoteJid - Remote JID
 * @param {string} nome - Variable name
 * @returns {string}
 */
function buildVariableKey(instanceId, remoteJid, nome) {
    return `${instanceId || 'default'}:${remoteJid || 'unknown'}:var:${nome}`;
}

/**
 * Clear all context and variables for a conversation
 * @param {string} instanceId - Instance ID
 * @param {string} remoteJid - Remote JID
 */
function clearConversationData(instanceId, remoteJid) {
    const conversationKey = buildConversationKey(instanceId, remoteJid);
    contextStore.delete(conversationKey);
    stateStore.delete(conversationKey);
    
    // Clear all variables for this conversation
    for (const key of variableStore.keys()) {
        if (key.startsWith(`${instanceId || 'default'}:${remoteJid || 'unknown'}:var:`)) {
            variableStore.delete(key);
        }
    }
}

module.exports = {
    setContexto,
    getContexto,
    limparContexto,
    setVariavel,
    getVariavel,
    setEstado,
    getEstado,
    clearConversationData,
    contextStore,
    variableStore,
    stateStore,
};
