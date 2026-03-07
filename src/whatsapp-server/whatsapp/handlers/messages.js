/**
 * @fileoverview messages.upsert handler - processes incoming and outgoing WhatsApp messages
 * @module whatsapp-server/whatsapp/handlers/messages
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines 3000-5000)
 * Handles message processing, AI routing, and response generation
 */

const { log } = require('../../../utils/logger');
const { createSessionContext } = require('../../../utils/session-context');
const db = require('../../../../db-updated');
const aiModule = require('../../ai');

// Constants
const RECENT_OUTBOUND_TTL_MS = 15000;
const pendingAiByConversation = new Map();
const recentOwnerOutgoing = new Map();

// Multi-input delay tracking
let lastProcessedMessage = {
    remoteJid: null,
    timestamp: 0
};

/**
 * Extract inbound message text from message object
 * @param {Object} message - Message object
 * @returns {string}
 */
function extractInboundMessageText(message) {
    if (!message) return "";
    
    // Try to get text content
    const text = message.conversation
        || message.extendedTextMessage?.text
        || message.imageMessage?.caption
        || message.videoMessage?.caption
        || message.documentMessage?.caption
        || "";
    
    if (text && String(text).trim()) {
        return String(text).trim();
    }
    
    // If no text, return descriptive placeholder for media types
    if (message.imageMessage) {
        const hasCaption = message.imageMessage.caption;
        return hasCaption ? String(hasCaption).trim() : "🖼️ Imagem recebida";
    }
    if (message.videoMessage) {
        const hasCaption = message.videoMessage.caption;
        return hasCaption ? String(hasCaption).trim() : "🎥 Vídeo recebido";
    }
    if (message.audioMessage) {
        return "🎤 Áudio recebido";
    }
    if (message.documentMessage) {
        const doc = message.documentMessage;
        const fileName = doc.fileName || "documento";
        const hasCaption = message.documentMessage.caption;
        return hasCaption ? String(hasCaption).trim() : `📄 Documento: ${fileName}`;
    }
    
    return "";
}

/**
 * Check if JID is a group
 * @param {string} remoteJid - JID to check
 * @returns {boolean}
 */
function isGroupJid(remoteJid) {
    return typeof remoteJid === "string" && remoteJid.includes("@g.us");
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
 * Apply multi-input delay if needed
 * @param {string} remoteJid - The remote JID
 * @param {Object} aiConfig - AI configuration with multi_input_delay
 * @returns {Promise<number>} - Returns the delay applied (0 if no delay needed)
 */
async function applyMultiInputDelay(remoteJid, aiConfig) {
    const delayMs = aiConfig?.multi_input_delay ?? 0;
    if (delayMs <= 0) return 0;
    
    const now = Date.now();
    
    // Check if this is the same user as the last message
    if (lastProcessedMessage.remoteJid === remoteJid) {
        const timeSinceLastMessage = now - lastProcessedMessage.timestamp;
        
        // If messages are coming in quickly from the same user, apply delay
        if (timeSinceLastMessage < 2000) { // Within 2 seconds
            console.log(`[Multi-Input Delay] Applying ${delayMs}ms delay for ${remoteJid}`);
            await new Promise(resolve => setTimeout(resolve, delayMs));
            return delayMs;
        }
    }
    
    // Update last processed message
    lastProcessedMessage = {
        remoteJid: remoteJid,
        timestamp: now
    };
    
    return 0;
}

/**
 * Process incoming messages from Baileys
 * @param {Object} data - Messages upsert data from Baileys
 * @param {Object} socket - WhatsApp socket instance
 */
async function processMessagesUpsert(data, socket) {
    const { messages, type } = data;
    
    for (const message of messages) {
        try {
            if (type === 'notify') {
                await handleSingleMessage(message, socket);
            }
        } catch (error) {
            console.error('Error processing message:', error);
        }
    }
}

/**
 * Handle a single message
 * @param {Object} message - Single message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleSingleMessage(message, socket) {
    const { key, message: messageContent } = message;
    const remoteJid = key.remoteJid;
    
    // Skip self messages
    if (key.fromMe) return;
    
    // Handle group messages
    if (isGroupJid(remoteJid)) {
        await handleGroupMessage(message, socket);
        return;
    }
    
    // Skip status broadcast
    const remoteJidLower = remoteJid.toLowerCase();
    if (remoteJidLower.startsWith('status@broadcast')) {
        return;
    }
    
    // Extract message details
    const messageType = Object.keys(messageContent || {})[0];
    const text = extractInboundMessageText(messageContent);
    
    console.log(`Received message from ${remoteJid}: ${text?.substring(0, 100)}`);
    
    // Check for debug command
    if (text && text.trim().toLowerCase() === '#debug#') {
        console.log('[DEBUG] Debug command detected from', remoteJid);
        await handleDebugCommand(message, socket);
        return;
    }
    
    // Check for debug2# - Customer Data Functions
    if (text && text.trim().toLowerCase() === '#debug2#') {
        console.log('[DEBUG2] Customer Data Functions test from', remoteJid);
        await handleDebug2Command(message, socket);
        return;
    }
    
    // Check for debug3# - Calendar Functions
    if (text && text.trim().toLowerCase() === '#debug3#') {
        console.log('[DEBUG3] Calendar Functions test from', remoteJid);
        await handleDebug3Command(message, socket);
        return;
    }
    
    // Check for debug4# - Scheduling Functions
    if (text && text.trim().toLowerCase() === '#debug4#') {
        console.log('[DEBUG4] Scheduling Functions test from', remoteJid);
        await handleDebug4Command(message, socket);
        return;
    }
    
    // Check for debug5# - Context/State Functions
    if (text && text.trim().toLowerCase() === '#debug5#') {
        console.log('[DEBUG5] Context/State Functions test from', remoteJid);
        await handleDebug5Command(message, socket);
        return;
    }
    
    // Check for debug6# - Messaging Functions
    if (text && text.trim().toLowerCase() === '#debug6#') {
        console.log('[DEBUG6] Messaging Functions test from', remoteJid);
        await handleDebug6Command(message, socket);
        return;
    }
    
    // Route to AI processing
    await processMessageWithAI(message, socket);
}

/**
 * Handle debug command - return instance configuration and status
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleDebugCommand(msg, socket) {
    const remoteJid = msg.key.remoteJid;
    const instanceId = global.INSTANCE_ID || 'default';
    
    try {
        // Import dependencies
        const db = require('../../../../db-updated');
        const aiModule = require('../../ai');
        
        // Get AI config
        const aiConfig = await aiModule.loadAIConfig(db, instanceId);
        
        // Get process info
        const uptime = process.uptime();
        const uptimeFormatted = formatUptime(uptime);
        const memoryMB = (process.memoryUsage().heapUsed / 1024 / 1024).toFixed(2);
        const port = process.env.PORT || process.env.port || 'N/A';
        
        // Get system prompt (first 200 chars)
        const systemPrompt = aiConfig.system_prompt || aiConfig.ai_system_prompt || 'Not configured';
        const promptPreview = systemPrompt.substring(0, 200) + (systemPrompt.length > 200 ? '...' : '');
        
        // Build debug message with required format
        const debugInfo = `🔍 DEBUG INFO - Instance Diagnostics

📋 Instance: ${instanceId}
🌐 Port: ${port}
⚙️ PID: ${process.pid}
⏱️ Uptime: ${uptimeFormatted}
🧠 Memory: ${memoryMB} MB

📝 System Prompt (200 chars):
"${promptPreview}"

🤖 AI Config:
- Provider: ${aiConfig.provider || aiConfig.ai_provider || 'N/A'}
- Model: ${aiConfig.model || aiConfig.ai_model || 'N/A'}
- Auto Pause: ${aiConfig.auto_pause_enabled ? 'enabled' : 'disabled'}
- Sleep Delay: ${aiConfig.sleep_delay || aiConfig.multi_input_delay || 0}ms

💻 Environment:
- Node: ${process.version}
- Env: ${process.env.NODE_ENV || 'production'}`;
        
        // Send debug info
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: debugInfo });
            console.log('[DEBUG] Debug info sent to', remoteJid);
        }
        
    } catch (error) {
        console.error('[DEBUG] Error generating debug info:', error);
        
        // Send error message
        const errorMsg = `❌ Erro ao gerar debug: ${error.message}`;
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: errorMsg });
        }
    }
}

/**
 * Format uptime in hours, minutes, seconds
 * @param {number} seconds - Uptime in seconds
 * @returns {string}
 */
function formatUptime(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    return `${hours}h ${minutes}m ${secs}s`;
}

/**
 * Handle debug2# - Test Customer Data Functions
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleDebug2Command(msg, socket) {
    const remoteJid = msg.key.remoteJid;
    const instanceId = global.INSTANCE_ID || 'default';
    
    try {
        const db = require('../../../../db-updated');
        const dadosHandler = require('../../commands/handlers/dados');
        
        let results = "🧪 DEBUG2 - Customer Data Functions\n\n";
        
        // Test 1: dados()
        results += "📋 1. dados('teste@exemplo.com')\n";
        try {
            const dadosResult = await dadosHandler.getCustomerData.call({ instanceId, db, remoteJid }, 'teste@exemplo.com');
            results += `   ✅ Result: ${JSON.stringify(dadosResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 2: optout()
        results += "📋 2. optout()\n";
        try {
            const optoutResult = await dadosHandler.optOut.call({ instanceId, db, remoteJid });
            results += `   ✅ Result: ${JSON.stringify(optoutResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 3: status_followup()
        results += "📋 3. status_followup()\n";
        try {
            const statusResult = await dadosHandler.statusFollowup.call({ instanceId, db, remoteJid });
            results += `   ✅ Result: ${JSON.stringify(statusResult).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 4: tempo_sem_interacao()
        results += "📋 4. tempo_sem_interacao()\n";
        try {
            const tempoResult = await dadosHandler.tempoSemInteracao.call({ instanceId, db, remoteJid });
            results += `   ✅ Result: ${JSON.stringify(tempoResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 5: log_evento()
        results += "📋 5. log_evento('teste','Debug test', '{}')\n";
        try {
            const logResult = await dadosHandler.logEvent.call({ instanceId, db, remoteJid }, 'teste', 'Debug test', '{}');
            results += `   ✅ Result: ${JSON.stringify(logResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        results += "✅ Debug2 test completed";
        
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: results });
            console.log('[DEBUG2] Results sent to', remoteJid);
        }
        
    } catch (error) {
        console.error('[DEBUG2] Error:', error);
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: `❌ Erro: ${error.message}` });
        }
    }
}

/**
 * Handle debug3# - Test Calendar Functions
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleDebug3Command(msg, socket) {
    const remoteJid = msg.key.remoteJid;
    const instanceId = global.INSTANCE_ID || 'default';
    
    try {
        const db = require('../../../../db-updated');
        const calendarHandler = require('../../calendar');
        
        let results = "🧪 DEBUG3 - Calendar Functions\n\n";
        
        // Test 1: verificar_disponibilidade()
        results += "📋 1. verificar_disponibilidade('2026-03-10T09:00','2026-03-10T10:00', 1, 'America/Fortaleza')\n";
        try {
            const dispResult = await calendarHandler.checkAvailability.call(
                { instanceId, db, remoteJid },
                '2026-03-10T09:00',
                '2026-03-10T10:00',
                1,
                'America/Fortaleza'
            );
            results += `   ✅ Result: ${JSON.stringify(dispResult).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 2: sugerir_horarios()
        results += "📋 2. sugerir_horarios('2026-03-10', '09:00-18:00', 60, 5, 1, 'America/Fortaleza')\n";
        try {
            const sugResult = await calendarHandler.suggestAvailableSlots.call(
                { instanceId, db, remoteJid },
                '2026-03-10',
                '09:00-18:00',
                60,
                5,
                1,
                'America/Fortaleza'
            );
            results += `   ✅ Result: ${JSON.stringify(sugResult).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 3: listar_eventos()
        results += "📋 3. listar_eventos('2026-03-01', '2026-03-31', 1, 'America/Fortaleza')\n";
        try {
            const listResult = await calendarHandler.listEvents.call(
                { instanceId, db, remoteJid },
                '2026-03-01',
                '2026-03-31',
                1,
                'America/Fortaleza'
            );
            results += `   ✅ Result: ${JSON.stringify(listResult).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        results += "✅ Debug3 test completed";
        
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: results });
            console.log('[DEBUG3] Results sent to', remoteJid);
        }
        
    } catch (error) {
        console.error('[DEBUG3] Error:', error);
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: `❌ Erro: ${error.message}` });
        }
    }
}

/**
 * Handle debug4# - Test Scheduling Functions
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleDebug4Command(msg, socket) {
    const remoteJid = msg.key.remoteJid;
    const instanceId = global.INSTANCE_ID || 'default';
    
    try {
        const db = require('../../../../db-updated');
        const schedulingHandler = require('../../commands/handlers/scheduling');
        
        let results = "🧪 DEBUG4 - Scheduling Functions\n\n";
        
        // Test 1: agendar2()
        results += "📋 1. agendar2('+5m', 'Teste de agendamento', 'debug', 'test', false)\n";
        try {
            const ag2Result = await schedulingHandler.agendar2.call(
                { instanceId, db, remoteJid },
                '+5m',
                'Teste de agendamento',
                'debug',
                'test',
                false
            );
            results += `   ✅ Result: ${JSON.stringify(ag2Result).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 2: agendar3()
        results += "📋 2. agendar3('2026-03-15 10:00', 'Teste data exata', 'debug', 'test', false)\n";
        try {
            const ag3Result = await schedulingHandler.agendar3.call(
                { instanceId, db, remoteJid },
                '2026-03-15 10:00',
                'Teste data exata',
                'debug',
                'test',
                false
            );
            results += `   ✅ Result: ${JSON.stringify(ag3Result).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 3: listar_agendamentos()
        results += "📋 3. listar_agendamentos('debug', 'test', false)\n";
        try {
            const listResult = await schedulingHandler.listar_agendamentos.call(
                { instanceId, db, remoteJid },
                'debug',
                'test',
                false
            );
            results += `   ✅ Result: ${JSON.stringify(listResult).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 4: cancelar_e_agendar2()
        results += "📋 4. cancelar_e_agendar2('+10m', 'Novo agendamento', 'debug', 'test', false)\n";
        try {
            const cancelResult = await schedulingHandler.cancelar_e_agendar2.call(
                { instanceId, db, remoteJid },
                '+10m',
                'Novo agendamento',
                'debug',
                'test',
                false
            );
            results += `   ✅ Result: ${JSON.stringify(cancelResult).substring(0, 300)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        results += "✅ Debug4 test completed";
        
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: results });
            console.log('[DEBUG4] Results sent to', remoteJid);
        }
        
    } catch (error) {
        console.error('[DEBUG4] Error:', error);
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: `❌ Erro: ${error.message}` });
        }
    }
}

/**
 * Handle debug5# - Test Context/State Functions
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleDebug5Command(msg, socket) {
    const remoteJid = msg.key.remoteJid;
    const instanceId = global.INSTANCE_ID || 'default';
    
    try {
        const db = require('../../../../db-updated');
        const contextHandler = require('../../commands/handlers/context');
        
        let results = "🧪 DEBUG5 - Context/State Functions\n\n";
        
        // Test 1: set_estado()
        results += "📋 1. set_estado('interessado')\n";
        try {
            const setEstadoResult = await contextHandler.set_estado.call(
                { instanceId, db, remoteJid },
                'interessado'
            );
            results += `   ✅ Result: ${JSON.stringify(setEstadoResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 2: get_estado()
        results += "📋 2. get_estado()\n";
        try {
            const getEstadoResult = await contextHandler.get_estado.call(
                { instanceId, db, remoteJid }
            );
            results += `   ✅ Result: ${JSON.stringify(getEstadoResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 3: set_contexto()
        results += "📋 3. set_contexto('debug_test', 'valor_teste')\n";
        try {
            const setCtxResult = await contextHandler.set_contexto.call(
                { instanceId, db, remoteJid },
                'debug_test',
                'valor_teste'
            );
            results += `   ✅ Result: ${JSON.stringify(setCtxResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 4: get_contexto()
        results += "📋 4. get_contexto('debug_test')\n";
        try {
            const getCtxResult = await contextHandler.get_contexto.call(
                { instanceId, db, remoteJid },
                'debug_test'
            );
            results += `   ✅ Result: ${JSON.stringify(getCtxResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 5: limpar_contexto()
        results += "📋 5. limpar_contexto(['debug_test'])\n";
        try {
            const cleanCtxResult = await contextHandler.limpar_contexto.call(
                { instanceId, db, remoteJid },
                ['debug_test']
            );
            results += `   ✅ Result: ${JSON.stringify(cleanCtxResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 6: set_variavel()
        results += "📋 6. set_variavel('test_var', 'test_value')\n";
        try {
            const setVarResult = await contextHandler.set_variavel.call(
                { instanceId, db, remoteJid },
                'test_var',
                'test_value'
            );
            results += `   ✅ Result: ${JSON.stringify(setVarResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        // Test 7: get_variavel()
        results += "📋 7. get_variavel('test_var')\n";
        try {
            const getVarResult = await contextHandler.get_variavel.call(
                { instanceId, db, remoteJid },
                'test_var'
            );
            results += `   ✅ Result: ${JSON.stringify(getVarResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        results += "✅ Debug5 test completed";
        
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: results });
            console.log('[DEBUG5] Results sent to', remoteJid);
        }
        
    } catch (error) {
        console.error('[DEBUG5] Error:', error);
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: `❌ Erro: ${error.message}` });
        }
    }
}

/**
 * Handle debug6# - Test Messaging Functions
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleDebug6Command(msg, socket) {
    const remoteJid = msg.key.remoteJid;
    const instanceId = global.INSTANCE_ID || 'default';
    
    try {
        const db = require('../../../../db-updated');
        const whatsappHandler = require('../../commands/handlers/whatsapp');
        
        let results = "🧪 DEBUG6 - Messaging Functions\n\n";
        
        // Note: We don't actually send messages to avoid spam
        // Instead we just check if the functions are accessible
        
        results += "📋 Functions available for testing:\n";
        results += "   - whatsapp(numero, mensagem)\n";
        results += "   - boomerang(mensagem)\n";
        results += "   - mail(destino, assunto, corpo, remetente?)\n";
        results += "   - template(id, var1?, var2?, var3?)\n";
        results += "   - IMG:uploads/...|legenda\n";
        results += "   - VIDEO:uploads/...|legenda\n";
        results += "   - AUDIO:uploads/...\n";
        results += "   - CONTACT:Nome|Note\n\n";
        
        // Test boomerang (doesn't send to anyone)
        results += "📋 1. boomerang('Teste interno')\n";
        try {
            const boomResult = await whatsappHandler.boomerang.call(
                { instanceId, db, remoteJid, socket },
                'Teste interno'
            );
            results += `   ✅ Result: ${JSON.stringify(boomResult).substring(0, 200)}\n\n`;
        } catch (e) {
            results += `   ❌ Error: ${e.message}\n\n`;
        }
        
        results += "✅ Debug6 test completed (messaging functions require parameters)";
        
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: results });
            console.log('[DEBUG6] Results sent to', remoteJid);
        }
        
    } catch (error) {
        console.error('[DEBUG6] Error:', error);
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: `❌ Erro: ${error.message}` });
        }
    }
}

/**
 * Handle group messages
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function handleGroupMessage(msg, socket) {
    // Group message handling logic
    const remoteJid = msg.key.remoteJid;
    log('Group message received from:', remoteJid);
    // Additional group processing can be added here
}

/**
 * Process message with AI
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function processMessageWithAI(msg, socket) {
    if (!msg.key?.fromMe && msg.message) {
        const remoteJid = msg.key.remoteJid;
        if (!remoteJid) return;
        
        const isGroup = isGroupJid(remoteJid);
        const isStatus = remoteJid.toLowerCase().startsWith('status@broadcast');
        
        // Get instanceId from global
        const instanceId = global.INSTANCE_ID || 'default';
        if (!global.INSTANCE_ID) {
            console.warn('[Messages] ⚠️ WARNING: global.INSTANCE_ID not set, using default');
        }
        const sessionContext = createSessionContext(
            instanceId,
            remoteJid,
            msg,
            { sessionId: remoteJid.toLowerCase() }
        );
        
        // Detect and extract media if present
        const mediaPayload = detectMediaPayload(msg.message);
        let mediaData = null;
        
        if (mediaPayload) {
            console.log('[Media] Detected:', mediaPayload.type, '- Attempting download...');
            try {
                mediaData = await downloadMediaMessage(msg.message, socket, mediaPayload.downloadType);
                if (mediaData) {
                    console.log('[Media] Download successful:', mediaPayload.type, '- mime:', mediaData.mimeType);
                } else {
                    console.log('[Media] Download failed, using placeholder text');
                }
            } catch (mediaError) {
                console.error('[Media] Error downloading media:', mediaError.message);
            }
        }
        
        const text = extractInboundMessageText(msg.message);
        
        // Apply multi-input delay before processing
        const aiConfig = await aiModule.loadAIConfig(db, instanceId);
        
        // Auto Pause: Check if within pause window
        if (aiConfig.auto_pause_enabled) {
            const autoPauseWindowMs = (aiConfig.auto_pause_minutes || 5) * 60 * 1000;
            try {
                // Get last messages and find the last inbound message time
                const recentMessages = await db.getLastMessages(instanceId, remoteJid, 10);
                if (recentMessages && recentMessages.length > 0) {
                    // Find last inbound message
                    const lastInboundMsg = recentMessages.find(m => m.direction === 'inbound');
                    if (lastInboundMsg && lastInboundMsg.timestamp) {
                        const lastInboundTime = new Date(lastInboundMsg.timestamp).getTime();
                        if ((Date.now() - lastInboundTime) < autoPauseWindowMs) {
                            console.log(`[Auto Pause] Skipping AI - within ${aiConfig.auto_pause_minutes}min window for ${remoteJid}. Last msg: ${new Date(lastInboundTime).toISOString()}`);
                            return; // Skip AI processing
                        }
                        console.log(`[Auto Pause] Debug - lastInboundTime: ${new Date(lastInboundTime).toISOString()}, window: ${autoPauseWindowMs}ms, diff: ${Date.now() - lastInboundTime}ms`);
                    }
                }
            } catch (err) {
                console.warn('[Auto Pause] Could not check last inbound time:', err.message);
            }
        }
        
        // Save inbound message to database before AI processing
        if (!isGroup && !isStatus && db && typeof db.saveMessage === "function") {
            try {
                const inboundContent = extractInboundMessageText(msg.message) || "Mensagem recebida sem conteúdo textual.";
                const metadata = JSON.stringify({
                    source: "handler_messages",
                    messageType: Object.keys(msg.message || {}).join(","),
                    pushName: msg.pushName || null
                });
                await db.saveMessage(
                    instanceId,
                    remoteJid,
                    "user",
                    inboundContent,
                    "inbound",
                    metadata,
                    {
                        sessionId: sessionContext.sessionId || "",
                        waMessageId: msg.key.id || null,
                        remoteJidAlt: msg.remoteJidAlt || null,
                        participantAlt: msg.participantAlt || null,
                        senderPn: msg.senderPn || null
                    }
                );
                console.log(`[DB] Inbound message saved for ${remoteJid}`);
            } catch (err) {
                log("Error saving inbound message to DB:", err.message);
            }
        }
        
        log('processMessageWithAI', {
            remoteJid,
            text: text?.substring(0, 100) || '[media]',
            fromMe: msg.key?.fromMe
        });
        
        const delaySeconds = Math.max(0, Number(aiConfig.multi_input_delay || 0));
        const delayMs = Math.round(delaySeconds * 1000);
        const conversationKey = `${instanceId}|${remoteJid.toLowerCase()}`;
        const runNow = async () => {
            await dispatchAIMessage({
                sessionContext,
                text,
                socket,
                instanceId,
                msg,
                mediaData
            });
        };

        if (delayMs <= 0) {
            await runNow();
            return;
        }

        const existing = pendingAiByConversation.get(conversationKey);
        if (existing?.timer) {
            clearTimeout(existing.timer);
        }

        const timer = setTimeout(async () => {
            const current = pendingAiByConversation.get(conversationKey);
            if (!current || current.timer !== timer) return;
            pendingAiByConversation.delete(conversationKey);
            try {
                await dispatchAIMessage({
                    sessionContext: current.sessionContext,
                    text: current.text,
                    socket: current.socket,
                    instanceId: current.instanceId,
                    msg: current.msg,
                    mediaData: current.mediaData
                });
            } catch (err) {
                log('Error dispatching delayed AI response:', err.message);
            }
        }, delayMs);

        pendingAiByConversation.set(conversationKey, {
            timer,
            sessionContext,
            text,
            socket,
            instanceId,
            msg,
            mediaData
        });
        log(`[Multi-Input Delay] Scheduled AI in ${delaySeconds}s for ${remoteJid}`);
        return;
    }
}

async function dispatchAIMessage({ sessionContext, text, socket, instanceId, msg, mediaData }) {
    const remoteJid = sessionContext?.remoteJid || msg?.key?.remoteJid;
    if (!remoteJid) return;

    // Send typing indicator right before dispatching AI response.
    if (socket && typeof socket.sendPresenceUpdate === 'function') {
        try {
            await socket.sendPresenceUpdate('composing', remoteJid);
        } catch (err) {
            console.warn('[Presence] Could not send typing:', err.message);
        }
    }

    // Route to AI service for processing
    const aiService = require('../../ai');

    // Build media info for AI if available
    const mediaInfo = mediaData ? {
        hasMedia: true,
        mimeType: mediaData.mimeType,
        fileName: mediaData.fileName,
        buffer: mediaData.buffer,
        bufferLength: mediaData.buffer?.length || 0
    } : null;

    if (aiService && typeof aiService.dispatchAIResponse === 'function') {
        try {
            await aiService.dispatchAIResponse(
                sessionContext,
                text,
                null,
                { media: mediaInfo },
                {
                    sock: socket,
                    db: db,
                    instanceId: instanceId,
                    sendWhatsAppMessage: async (jid, content) => {
                        if (socket && socket.sendMessage) {
                            const payload = (content && typeof content === "object" && !Array.isArray(content)) ? content : { text: String(content ?? "") };
                            await socket.sendMessage(jid, payload);
                        }
                    },
                    persistSessionMessage: async (data) => {
                        // Try to use db from dependencies, fallback to requiring db-updated
                        let dbInstance = db;
                        if (!dbInstance || typeof dbInstance.saveMessage !== 'function') {
                            try {
                                dbInstance = require('../../../../db-updated');
                            } catch (e) {
                                console.error('[persistSessionMessage] Failed to load db:', e.message);
                                return;
                            }
                        }
                        if (dbInstance && typeof dbInstance.saveMessage === 'function') {
                            try {
                                await dbInstance.saveMessage(
                                    instanceId,
                                    data.sessionContext.remoteJid,
                                    data.role,
                                    data.content,
                                    data.direction,
                                    data.metadata || null,
                                    { sessionId: data.sessionContext.sessionId || '' }
                                );
                            } catch (err) {
                                console.error('[persistSessionMessage] Error saving message:', err.message);
                            }
                        }
                    }
                }
            );
            log('AI response dispatched successfully');
            return;
        } catch (err) {
            log('Error dispatching AI response:', err.message);
            return;
        }
    }

    if (aiService && typeof aiService.processMessage === 'function') {
        // Fallback to processMessage if dispatchAIResponse not available
        await aiService.processMessage(msg, socket, {
            instanceId: instanceId,
            db: db,
            sendWhatsAppMessage: async (jid, content) => {
                if (socket && socket.sendMessage) {
                    const payload = (content && typeof content === "object" && !Array.isArray(content)) ? content : { text: String(content ?? "") };
                    await socket.sendMessage(jid, payload);
                }
            }
        });
    }
}

/**
 * Process owner quick reply (messages from owner)
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket instance
 */
async function processOwnerQuickReply(msg, socket) {
    if (!msg?.key?.fromMe || !msg.message) {
        return;
    }
    
    const remoteJid = msg.key.remoteJid;
    if (!isIndividualJid(remoteJid)) return;
    
    const remoteJidLower = remoteJid.toLowerCase();
    if (remoteJidLower.startsWith("status@broadcast")) {
        return;
    }
    
    const messageBody = msg.message.conversation || msg.message.extendedTextMessage?.text || "";
    const trimmed = (messageBody || "").trim();
    if (!trimmed) return;

    const now = Date.now();
    const dedupeKey = `${remoteJid}|${trimmed}`;
    const recentTimestamp = recentOwnerOutgoing.get(dedupeKey);
    if (recentTimestamp && now - recentTimestamp < RECENT_OUTBOUND_TTL_MS) {
        return;
    }
    recentOwnerOutgoing.set(dedupeKey, now);
    if (recentOwnerOutgoing.size > 1000) {
        const cutoff = now - RECENT_OUTBOUND_TTL_MS;
        for (const [key, ts] of recentOwnerOutgoing.entries()) {
            if (ts < cutoff) {
                recentOwnerOutgoing.delete(key);
            }
            if (recentOwnerOutgoing.size <= 1000) {
                break;
            }
        }
    }
    
    const sessionContext = createSessionContext(
        global.INSTANCE_ID || 'default',
        remoteJid,
        msg
    );
    
    // Save to database
    if (db) {
        try {
            const metadata = JSON.stringify({ source: "owner" });
            await db.saveMessage(
                global.INSTANCE_ID || 'default',
                remoteJid,
                "user",
                trimmed,
                "outbound",
                metadata,
                { sessionId: sessionContext.sessionId || "" }
            );
        } catch (err) {
            log("Error saving owner outbound message:", err.message);
        }
    }
    
    // Process with secretary if enabled
    const aiService = require('../../ai');
    if (aiService && typeof aiService.processOwnerMessage === 'function') {
        await aiService.processOwnerMessage(remoteJid, trimmed, sessionContext, {
            socket,
            db,
            instanceId: global.INSTANCE_ID || 'default'
        });
    }
}

/**
 * Send message via socket
 * @param {Object} socket - WhatsApp socket instance
 * @param {string} jid - Recipient JID
 * @param {Object} message - Message content
 * @returns {Promise<Object>}
 */
async function sendMessage(socket, jid, message) {
    try {
        const result = await socket.sendMessage(jid, message);
        return result;
    } catch (error) {
        console.error('Error sending message:', error);
        throw error;
    }
}

/**
 * Detect media payload from message
 * @param {Object} message - Message object
 * @returns {Object|null}
 */
function detectMediaPayload(message) {
    if (!message) return null;
    
    if (message.imageMessage) {
        return { type: "imagem", node: message.imageMessage, downloadType: "image" };
    }
    if (message.audioMessage) {
        return { type: "audio", node: message.audioMessage, downloadType: "audio" };
    }
    if (message.videoMessage) {
        return { type: "video", node: message.videoMessage, downloadType: "video" };
    }
    if (message.documentMessage) {
        return { type: "documento", node: message.documentMessage, downloadType: "document" };
    }
    
    return null;
}

/**
 * Download media message from WhatsApp using Baileys
 * @param {Object} message - Message object with media
 * @param {Object} socket - WhatsApp socket instance
 * @param {string} downloadType - Type of media (image, video, audio, document)
 * @returns {Promise<{buffer: Buffer, mimeType: string, fileName: string}|null>}
 */
async function downloadMediaMessage(message, socket, downloadType) {
    try {
        if (!socket || typeof socket.downloadMedia !== 'function') {
            console.error('[DownloadMedia] Socket or downloadMedia function not available');
            return null;
        }

        // Get the media message node based on type
        let mediaNode = null;
        if (downloadType === 'image' && message.imageMessage) {
            mediaNode = message.imageMessage;
        } else if (downloadType === 'video' && message.videoMessage) {
            mediaNode = message.videoMessage;
        } else if (downloadType === 'audio' && message.audioMessage) {
            mediaNode = message.audioMessage;
        } else if (downloadType === 'document' && message.documentMessage) {
            mediaNode = message.documentMessage;
        }

        if (!mediaNode) {
            console.error('[DownloadMedia] No media node found for type:', downloadType);
            return null;
        }

        // Download the media using Baileys
        const buffer = await socket.downloadMedia(mediaNode);
        
        if (!buffer) {
            console.error('[DownloadMedia] Failed to download media - empty buffer');
            return null;
        }

        // Extract metadata
        const mimeType = mediaNode.mimetype || 'application/octet-stream';
        const fileName = mediaNode.fileName || `${downloadType}_${Date.now()}`;

        console.log('[DownloadMedia] Successfully downloaded:', downloadType, 'mime:', mimeType);
        
        return {
            buffer,
            mimeType,
            fileName
        };
    } catch (error) {
        console.error('[DownloadMedia] Error downloading media:', error.message);
        return null;
    }
}

/**
 * Detect contact payload from message
 * @param {Object} message - Message object
 * @returns {Object|null}
 */
function detectContactPayload(message) {
    if (!message?.contactsArrayMessage) return null;
    return message.contactsArrayMessage;
}

module.exports = {
    process: processMessagesUpsert,
    handleSingleMessage,
    handleGroupMessage,
    processMessageWithAI,
    processOwnerQuickReply,
    sendMessage,
    extractInboundMessageText,
    isGroupJid,
    isIndividualJid,
    normalizeMetaField,
    detectMediaPayload,
    detectContactPayload,
    downloadMediaMessage,
    applyMultiInputDelay,
    // Debug commands
    handleDebugCommand,
    handleDebug2Command,
    handleDebug3Command,
    handleDebug4Command,
    handleDebug5Command,
    handleDebug6Command
};
