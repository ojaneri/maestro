/**
 * @fileoverview AI response processing and dispatching - centralizes AI logic
 * @module whatsapp-server/ai
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Manages AI provider selection, history fetching, prompt building, and response dispatching
 */

const { log } = require('../../config/config');
const { logDebug, logInfo, logWarn, logError, logCritical } = require('../../utils/logger');
const responseBuilder = require('./response-builder');

// Constants
const DEFAULT_PROVIDER = 'openai';
const DEFAULT_HISTORY_LIMIT = 20;
const DEFAULT_TEMPERATURE = 0.7;
const DEFAULT_MAX_TOKENS = 1000;

/**
 * Format a message with timestamp for AI conversation history
 * Format: User [DD/MM/YYYY hh:mmA] - message content
 * @param {Object} message - Message object with role, content, and timestamp
 * @returns {string} Formatted message with timestamp
 */
function formatMessageWithTimestamp(message) {
    if (!message || !message.content) {
        return '';
    }
    
    let formattedTimestamp = '';
    
    if (message.timestamp) {
        try {
            // Handle both Unix timestamp (seconds) and ISO datetime string
            let date;
            if (typeof message.timestamp === 'number') {
                // Unix timestamp - check if seconds or milliseconds
                date = message.timestamp > 9999999999 
                    ? new Date(message.timestamp) 
                    : new Date(message.timestamp * 1000);
            } else if (typeof message.timestamp === 'string') {
                // ISO datetime string from SQLite
                date = new Date(message.timestamp);
            } else {
                date = new Date(message.timestamp);
            }
            
            // Check if date is valid
            if (!isNaN(date.getTime())) {
                formattedTimestamp = date.toLocaleString('pt-BR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true,
                    timeZone: 'America/Sao_Paulo'
                });
            }
        } catch (err) {
            console.warn('[AI] Error formatting timestamp:', err.message);
        }
    }
    
    const role = message.role === 'user' ? 'User' : 'IA';
    const timestampPrefix = formattedTimestamp ? `[${formattedTimestamp}] ` : '';
    return `${role} ${timestampPrefix}- ${message.content}`;
}

/**
 * Get formatted date/time string in Portuguese (Brazil)
 * Timezone: America/Sao_Paulo (UTC-3)
 * @returns {string} Formatted date/time string
 */
function getFormattedDateTime() {
    const now = new Date();
    const options = {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'America/Sao_Paulo'
    };
    const formatted = now.toLocaleDateString('pt-BR', options);
    // Capitalize first letter of weekday
    const capitalized = formatted.charAt(0).toUpperCase() + formatted.slice(1);
    return `DATA E HORA ATUAL: ${capitalized} (Horário de Brasília - UTC-3)`;
}

// Provedores
const PROVIDERS = {
    openai: require('./providers/openai'),
    gemini: require('./providers/gemini'),
    openrouter: require('./providers/openrouter'),
};

/**
 * Initialize AI service
 */
async function initialize() {
    log('[AI] Initializing AI service...');
}

/**
 * Load AI configuration from database
 */
async function loadAIConfig(dbInstance, instanceId) {
    const db = dbInstance || global.db;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
    
    // console.log(`[AI] Loading config for instance: "${currentInstanceId}"`);

    if (!db) {
        console.error('[AI] Database not available during loadAIConfig!');
        return {
            provider: DEFAULT_PROVIDER,
            model: 'gpt-4o-mini',
            temperature: DEFAULT_TEMPERATURE,
            max_tokens: DEFAULT_MAX_TOKENS,
            history_limit: DEFAULT_HISTORY_LIMIT,
            system_prompt: '',
            gemini_instruction: ''
        };
    }

    try {
        const settings = await db.getSettings(currentInstanceId, [
            'ai_provider', 'ai_model', 'ai_temperature', 'ai_max_tokens',
            'ai_history_limit', 'ai_system_prompt', 'gemini_instruction',
            'ai_injected_context', 'ai_model_fallback_1', 'ai_model_fallback_2',
            'ai_multi_input_delay',
            'gemini_api_key',      // API keys must be loaded for provider authentication
            'openai_api_key',
            'openrouter_api_key'
        ]);

        // console.log(`[AI] Settings found for ${currentInstanceId}:`, Object.keys(settings).length);

        return {
            provider: settings.ai_provider || DEFAULT_PROVIDER,
            model: settings.ai_model || 'gpt-4o-mini',
            temperature: parseFloat(settings.ai_temperature) || DEFAULT_TEMPERATURE,
            max_tokens: parseInt(settings.ai_max_tokens) || DEFAULT_MAX_TOKENS,
            history_limit: parseInt(settings.ai_history_limit) || DEFAULT_HISTORY_LIMIT,
            system_prompt: settings.ai_system_prompt || '',
            gemini_instruction: settings.gemini_instruction || '',
            injected_context: settings.ai_injected_context || '',
            model_fallback_1: settings.ai_model_fallback_1,
            model_fallback_2: settings.ai_model_fallback_2,
            multi_input_delay: parseFloat(settings.ai_multi_input_delay) || 0,
            // API keys for providers
            gemini_api_key: settings.gemini_api_key || process.env.GEMINI_API_KEY || null,
            openai_api_key: settings.openai_api_key || process.env.OPENAI_API_KEY || null,
            openrouter_api_key: settings.openrouter_api_key || process.env.OPENROUTER_API_KEY || null
        };
    } catch (err) {
        console.error(`[AI] Error loading config for ${currentInstanceId}:`, err.message);
        return {
            provider: DEFAULT_PROVIDER,
            model: 'gpt-4o-mini',
            temperature: DEFAULT_TEMPERATURE,
            max_tokens: DEFAULT_MAX_TOKENS,
            history_limit: DEFAULT_HISTORY_LIMIT,
            system_prompt: '',
            gemini_instruction: ''
        };
    }
}

/**
 * Persist AI configuration to database
 */
async function persistAIConfig(db, instanceId, config) {
    if (!db || !instanceId || !config) return false;

    try {
        const settings = {
            ai_provider: config.provider,
            ai_model: config.model,
            ai_temperature: String(config.temperature),
            ai_max_tokens: String(config.max_tokens),
            ai_history_limit: String(config.history_limit),
            ai_system_prompt: config.system_prompt,
            ai_injected_context: config.injected_context,
            ai_multi_input_delay: String(config.multi_input_delay || 0)
        };

        for (const [key, value] of Object.entries(settings)) {
            if (value !== undefined) {
                await db.setSetting(instanceId, key, value);
            }
        }
        return true;
    } catch (err) {
        console.error('[AI] Error persisting AI config:', err.message);
        return false;
    }
}

/**
 * Generate AI response text
 */
async function generateAIResponse(sessionContext, messageBody, providedConfig = null, dependencies = {}) {
    const db = dependencies.db || global.db;
    const currentInstanceId = sessionContext?.instanceId || dependencies.instanceId || global.INSTANCE_ID || 'default';
    
    // 1. Load configuration
    const aiConfig = providedConfig || await loadAIConfig(db, currentInstanceId);
    
    // 2. Select provider
    const providerName = aiConfig.provider?.toLowerCase() || DEFAULT_PROVIDER;
    const provider = PROVIDERS[providerName];
    
    if (!provider) {
        throw new Error(`AI Provider not found: ${providerName}`);
    }

    // 3. Build injected context (vars, contact data)
    const injectedPrompt = await buildInjectedPromptContext(sessionContext, db);
    if (injectedPrompt) {
        aiConfig.injected_context = (aiConfig.injected_context || '') + injectedPrompt;
    }

    // 4. Fetch history
    const historyLimit = aiConfig.history_limit || DEFAULT_HISTORY_LIMIT;
    const historyRows = await fetchHistoryRows(sessionContext, historyLimit, db);
    
    // 5. Build payload
    const payload = buildResponsesPayload(historyRows, messageBody, aiConfig);
    
    const whatsappService = require('../../infra/whatsapp-service');
    whatsappService.writeDebugLog('AI_PAYLOAD', `Sending payload to ${providerName} for ${sessionContext.remoteJid}`, {
        model: aiConfig.model,
        messages: payload.input,
        instanceId: currentInstanceId
    });
    
    // 6. Generate response using candidates for fallback
    const candidates = buildModelCandidates(aiConfig);
    
    const result = await attemptModelSequence(candidates, async (model) => {
        // Correção de assinatura: Gemini espera (aiConfig, sessionContext, messageBody, remoteJid, dependencies)
        const response = await provider.generateResponse(
            { ...aiConfig, model },
            sessionContext,
            messageBody,
            sessionContext.remoteJid,
            { ...dependencies, db, instanceId: currentInstanceId }
        );
        
        whatsappService.writeDebugLog('AI_RAW_RESPONSE', `Response from ${model}`, response);
        return response;
    });

    return {
        text: collectResponseText(result),
        provider: providerName,
        model: result.model || candidates[0],
        raw: result,
        functionCalls: result.functionCalls || []
    };
}

/**
 * Dispatch AI response - process and send via WhatsApp
 */
async function dispatchAIResponse(sessionContext, messageBody, providedConfig = null, options = {}, dependencies = {}) {
    if (!messageBody || !messageBody.trim()) {
        throw new Error('Mensagem vazia para IA');
    }

    const db = dependencies.db || global.db;
    const instanceId = dependencies.instanceId || sessionContext?.instanceId || global.INSTANCE_ID || 'default';

    // Generate AI response - ensuring correct instanceId is used
    const aiResponse = await generateAIResponse(sessionContext, messageBody, providedConfig, { ...dependencies, db, instanceId });
    const aiText = aiResponse.text?.trim();

    // ✅ FLOW 1: Native Function Calls
    if (aiResponse.functionCalls && aiResponse.functionCalls.length > 0) {
        console.log('[AI] Processing function calls:', aiResponse.functionCalls.map(fc => fc.name).join(', '));
        
        const initialText = aiResponse.text?.trim();
        const results = [];
        const geminiProvider = PROVIDERS.gemini;
        
        for (const functionCall of aiResponse.functionCalls) {
            try {
                const result = await geminiProvider.executeTool(functionCall.name, functionCall.args, {
                    ...dependencies,
                    instanceId: instanceId,
                    db: db
                });
                results.push({ function: functionCall.name, args: functionCall.args, result });
            } catch (err) {
                console.error(`[AI] Error executing ${functionCall.name}:`, err);
                results.push({ function: functionCall.name, args: functionCall.args, error: err.message });
            }
        }
        
        const functionResultsSummary = results.map(r => {
            if (r.error) return `- ${r.function}: ERRO - ${r.error}`;
            return `- ${r.function}: ${JSON.stringify(r.result)}`;
        }).join('\n');
        
        const followUpPrompt = `
${initialText ? `MENSAGEM QUE VOCÊ JÁ ENVIOU AO CLIENTE: "${initialText}"\n\n` : ''}
RESULTADO DAS FUNÇÕES EXECUTADAS:
${functionResultsSummary}

TAREFA: Responda ao cliente com o resultado. 
REGRAS CRÍTICAS:
1. NÃO repita a saudação ou a mensagem inicial acima.
2. NÃO diga novamente o que você "vai fazer" (pois já foi feito e enviado).
3. Seja curto e direto ao ponto.
`;

        const FOLLOWUP_HISTORY_LIMIT = 5;
        const followUpConfig = providedConfig ? { ...providedConfig, history_limit: FOLLOWUP_HISTORY_LIMIT } : { history_limit: FOLLOWUP_HISTORY_LIMIT };
        
        try {
            const followUpResponse = await generateAIResponse(sessionContext, followUpPrompt, followUpConfig, dependencies);
            const followUpText = followUpResponse.text?.trim();
            
            const combinedText = (initialText ? initialText + '\n#\n' : '') + (followUpText || 'OK');
            return processAndSendFinalText(combinedText, results.map(r => ({ type: r.function, args: r.args, result: r.result })), [], aiResponse, sessionContext, dependencies);
            
        } catch (followUpErr) {
            console.error('[AI] Error in follow-up AI call:', followUpErr);
            return processAndSendFinalText(initialText || 'OK', results.map(r => ({ type: r.function, args: r.args, result: r.result })), [], aiResponse, sessionContext, dependencies);
        }
    }
    
    // ✅ FLOW 2: Text response with possible &&& commands
    const roundtrip = require('../commands/roundtrip');
    const { text: processedText, commands: executedCommands, notes } = await roundtrip.executeWithAITurnaround(
        sessionContext, aiText, providedConfig, options, dependencies
    );

    const visibleMessageText = processedText || aiText;
    return processAndSendFinalText(visibleMessageText, executedCommands, notes, aiResponse, sessionContext, dependencies);
}

/**
 * Helper to process, segment and send the final text
 */
async function processAndSendFinalText(text, commands, notes, aiResponse, sessionContext, dependencies) {
    const { sock, db, sendWhatsAppMessage, persistSessionMessage } = dependencies;
    const remoteJid = sessionContext?.remoteJid;

    const visibleMessageText = (text || '').split('&&&')[0].trim();
    if (!visibleMessageText) throw new Error('IA retornou resposta inválida ou vazia');

    const segments = parseTextSegments(visibleMessageText);
    const commandMetadata = buildCommandMetadata(commands, notes);
    
    for (const segment of segments) {
        const cleanedSegment = segment.trim();
        if (!cleanedSegment) continue;

        if (sock && sendWhatsAppMessage) {
            if (typeof sock.sendPresenceUpdate === 'function') {
                await sock.sendPresenceUpdate('composing', remoteJid);
                const typingTime = Math.min(Math.max(cleanedSegment.length * 30, 1000), 10000);
                await new Promise(resolve => setTimeout(resolve, typingTime));
            }
            
            await sendWhatsAppMessage(remoteJid, { text: cleanedSegment });
            
            if (db && persistSessionMessage) {
                try {
                    await persistSessionMessage({
                        sessionContext,
                        role: 'assistant',
                        content: cleanedSegment,
                        direction: 'outbound',
                        metadata: commandMetadata ? JSON.stringify(commandMetadata) : null
                    });
                } catch (err) {
                    console.error('[AI] Error saving message:', err.message);
                }
            }
        }
    }

    return { ...aiResponse, text: visibleMessageText, commands, segments: segments.length };
}

/**
 * Build injected prompt context
 */
async function buildInjectedPromptContext(sessionContext, db) {
    if (!db || !sessionContext) return '';
    const { instanceId, remoteJid } = sessionContext;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
    let contextParts = [];

    try {
        if (typeof db.getContactMetadata === 'function') {
            const metadata = await db.getContactMetadata(currentInstanceId, remoteJid);
            if (metadata) {
                if (metadata.contact_name) contextParts.push(`Nome do cliente: ${metadata.contact_name}`);
                if (metadata.status_name) contextParts.push(`Status atual: ${metadata.status_name}`);
                if (metadata.temperature) contextParts.push(`Temperatura do lead: ${metadata.temperature}`);
            }
        }

        if (typeof db.listContactContext === 'function') {
            const rows = await db.listContactContext(currentInstanceId, remoteJid);
            if (rows && rows.length > 0) {
                const vars = [], ctx = [];
                let state = '';
                rows.forEach(row => {
                    if (row.key.startsWith('var:')) vars.push(`${row.key.replace('var:', '')}: ${row.value}`);
                    else if (row.key.startsWith('ctx:')) ctx.push(`${row.key.replace('ctx:', '')}: ${row.value}`);
                    else if (row.key === 'state') state = row.value;
                });
                if (vars.length > 0) contextParts.push(`Variáveis salvas:\n${vars.join('\n')}`);
                if (ctx.length > 0) contextParts.push(`Contexto da conversa:\n${ctx.join('\n')}`);
                if (state) contextParts.push(`Estado interno: ${state}`);
            }
        }
    } catch (err) { console.warn('[AI] Error building injected context:', err.message); }

    return contextParts.length > 0 ? `\n--- CONTEXTO ATUAL DO CLIENTE ---\n${contextParts.join('\n\n')}\n-------------------------------\n` : '';
}

/**
 * Helper to build command metadata
 */
function buildCommandMetadata(commandResults = [], notes = []) {
    const normalizedCommands = (Array.isArray(commandResults) ? commandResults : [])
        .map(cmd => {
            if (!cmd || typeof cmd !== 'object') return null;
            return { type: cmd.type || cmd.name || 'função', args: cmd.args || [], result: cmd.result || (cmd.success === false ? { ok: false, error: cmd.error || 'erro' } : null) };
        }).filter(Boolean);
    return (!normalizedCommands.length && !notes.length) ? null : { commands: normalizedCommands, notes, source: 'dispatch' };
}

/**
 * Parse text into segments
 */
function parseTextSegments(text) {
    if (!text) return [];
    const segments = text.split('#').filter(s => s.trim());
    return segments.length > 0 ? segments : [text];
}

/**
 * Build responses payload for AI
 */
function buildResponsesPayload(historyMessages, messageBody, aiConfig) {
    const messages = [];
    
    // Get current date/time for AI context
    const dateTimeContext = getFormattedDateTime();
    
    // Choose the primary prompt based on availability
    const primaryPrompt = (aiConfig.gemini_instruction && aiConfig.gemini_instruction.trim())
        ? aiConfig.gemini_instruction
        : (aiConfig.system_prompt && aiConfig.system_prompt.trim())
            ? aiConfig.system_prompt
            : 'You are a helpful assistant.';

    // Prepend date/time to the system prompt
    const systemPromptWithDateTime = `${dateTimeContext}\n\n${primaryPrompt}`;
    
    messages.push({ role: 'system', content: systemPromptWithDateTime });
    
    // Add injected context if exists
    if (aiConfig.injected_context) {
        messages.push({ role: 'system', content: aiConfig.injected_context });
    }

    if (Array.isArray(historyMessages)) {
        historyMessages.forEach(row => { 
            if (row.role && row.content) {
                // Format message with timestamp for AI context
                const formattedContent = formatMessageWithTimestamp(row);
                messages.push({ role: row.role, content: formattedContent }); 
            }
        });
    }
    
    messages.push({ role: 'user', content: messageBody });
    return { input: messages };
}

/**
 * Build model candidates
 */
function buildModelCandidates(aiConfig) {
    const candidates = [];
    if (aiConfig.model) candidates.push(aiConfig.model);
    if (aiConfig.model_fallback_1) candidates.push(aiConfig.model_fallback_1);
    if (aiConfig.model_fallback_2) candidates.push(aiConfig.model_fallback_2);
    return candidates.length > 0 ? candidates : ['gpt-4o', 'gpt-4o-mini'];
}

/**
 * Attempt model sequence
 */
async function attemptModelSequence(candidates, attemptFn) {
    const errors = [];
    for (const candidate of candidates) {
        try { return await attemptFn(candidate); } 
        catch (err) { errors.push({ model: candidate, error: err.message }); }
    }
    throw new Error(`All models failed. Last error: ${errors[errors.length - 1].error}`);
}

/**
 * Collect response text
 */
function collectResponseText(response) {
    if (!response) return null;
    const normalize = (v) => typeof v === 'string' ? v.trim() : null;
    const choice = Array.isArray(response.choices) ? response.choices[0] : null;
    if (choice) {
        const msg = normalize(choice.message?.content || choice.message?.text);
        if (msg) return msg;
    }
    return normalize(response.message?.content) || normalize(response.text) || normalize(response.response) || null;
}

/**
 * Fetch history rows
 */
async function fetchHistoryRows(sessionContext, limit, db) {
    if (!db || !sessionContext) return [];
    try {
        const currentInstanceId = sessionContext.instanceId || global.INSTANCE_ID || 'default';
        return await db.getLastMessages(currentInstanceId, sessionContext.remoteJid, limit);
    } catch (err) { return []; }
}

async function processOwnerMessage() { /* Placeholder */ }
async function processMessage() { /* Placeholder */ }
async function selectProvider() { /* Placeholder */ }
async function buildContext() { /* Placeholder */ }

/**
 * Handle #debug# command from WhatsApp
 */
async function handleDebugCommand(msg, sock) {
    const remoteJid = msg.key.remoteJid;
    const instanceId = global.INSTANCE_ID || 'default';
    
    console.log(`[DEBUG] Handling command for instance: ${instanceId}`);
    
    try {
        const db = global.db;
        if (!db) throw new Error('Database not available globally');
        
        // Use the actual loader to see what AI sees
        const aiConfig = await loadAIConfig(db, instanceId);
        const injectedContext = await buildInjectedPromptContext({ remoteJid, instanceId }, db);
        
        const promptToUse = (aiConfig.gemini_instruction && aiConfig.gemini_instruction.trim())
            ? aiConfig.gemini_instruction
            : (aiConfig.system_prompt && aiConfig.system_prompt.trim())
                ? aiConfig.system_prompt
                : 'Vazio/Padrão';

        const debugInfo = `
🤖 *DIAGNÓSTICO IA*
--------------------------
🆔 *Instância:* ${instanceId}
🔌 *Provider:* ${aiConfig.provider}
🧠 *Modelo:* ${aiConfig.model}
⏳ *Multi-Input:* ${aiConfig.multi_input_delay}s

📝 *Prompt Ativo:*
${promptToUse.substring(0, 300)}...

🗂️ *Contexto Injetado:*
${injectedContext ? 'Sim (Variáveis detectadas)' : 'Nenhum contexto.'}
--------------------------
`.trim();

        if (sock && sock.sendMessage) {
            await sock.sendMessage(remoteJid, { text: debugInfo });
            console.log(`[DEBUG] Info sent to ${remoteJid}`);
        }
    } catch (err) {
        console.error('[AI] Error in handleDebugCommand:', err);
    }
}

module.exports = {
    initialize,
    generateAIResponse,
    dispatchAIResponse,
    loadAIConfig,
    persistAIConfig,
    handleDebugCommand,
    buildInjectedPromptContext,
    fetchHistoryRows,
    parseTextSegments,
    buildResponsesPayload,
    buildModelCandidates,
    attemptModelSequence,
    collectResponseText,
    getFormattedDateTime,
    formatMessageWithTimestamp,
    PROVIDERS,
    DEFAULT_PROVIDER,
    DEFAULT_HISTORY_LIMIT,
    DEFAULT_TEMPERATURE,
    DEFAULT_MAX_TOKENS
};
