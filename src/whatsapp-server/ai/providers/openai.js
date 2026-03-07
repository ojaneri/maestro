/**
 * @fileoverview OpenAI provider implementation
 * @module whatsapp-server/ai/providers/openai
 *
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles OpenAI API integration for chat completions and Assistants API
 */

const OpenAI = require('openai');

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

/**
 * Generate response using OpenAI
 * @param {Object} aiConfig - AI configuration
 * @param {Object} sessionContext - Session context
 * @param {string} messageBody - User message
 * @param {string} remoteJid - Remote JID (phone number)
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Object>}
 */
async function generateResponse(aiConfig, sessionContext, messageBody, remoteJid, dependencies = {}) {
    const { db, instanceId } = dependencies;
    const openaiApiKey = aiConfig.openai_api_key;
    
    if (!openaiApiKey) {
        throw new Error('Chave OpenAI não configurada');
    }

    const openai = new OpenAI({ apiKey: openaiApiKey });

    // Check if using Assistants API mode
    if (aiConfig.openai_mode === 'assistants') {
        return await generateWithAssistants(openai, aiConfig, sessionContext, messageBody, remoteJid, dependencies);
    }

    // Standard chat completions / responses mode
    return await generateWithResponses(openai, aiConfig, sessionContext, messageBody, remoteJid, dependencies);
}

/**
 * Generate response using OpenAI Assistants API
 * @param {Object} openai - OpenAI client
 * @param {Object} aiConfig - AI configuration
 * @param {Object} sessionContext - Session context
 * @param {string} messageBody - User message
 * @param {string} remoteJid - Remote JID (phone number)
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Object>}
 */
async function generateWithAssistants(openai, aiConfig, sessionContext, messageBody, remoteJid, dependencies = {}) {
    const { db } = dependencies;
    const resolvedInstanceId = dependencies.instanceId || sessionContext?.instanceId || 'default';
    
    if (!aiConfig.assistant_id) {
        throw new Error('Assistant ID necessário para o modo Assistants');
    }

    // Build phone number context for the AI
    const phoneNumberContext = buildPhoneNumberContext(remoteJid);
    const instructionText = appendInjectedInstruction(aiConfig.system_prompt, aiConfig.injected_context, phoneNumberContext) || undefined;

    // Get or create thread
    let threadId = null;
    if (db && sessionContext?.remoteJid) {
        try {
            const threadMeta = await db.getThreadMetadata(resolvedInstanceId, sessionContext.remoteJid);
            threadId = threadMeta?.threadId || null;
        } catch (err) {
            console.warn('[OpenAI] Could not get thread metadata:', err.message);
        }
    }

    if (!threadId) {
        // Create new thread and run
        const run = await openai.beta.threads.createAndRun({
            assistant_id: aiConfig.assistant_id,
            model: aiConfig.model,
            temperature: aiConfig.temperature,
            max_completion_tokens: aiConfig.max_tokens,
            instructions: instructionText,
            additional_instructions: aiConfig.assistant_prompt || undefined,
            additional_messages: [{ role: 'user', content: messageBody }],
            truncation_strategy: {
                type: 'last_messages',
                last_messages: aiConfig.history_limit
            }
        });
        threadId = run.thread_id;
    } else {
        // Add message to existing thread
        await openai.beta.threads.messages.create(threadId, {
            role: 'user',
            content: messageBody
        });
        
        // Run assistant
        await openai.beta.threads.runs.create(threadId, {
            assistant_id: aiConfig.assistant_id,
            model: aiConfig.model,
            temperature: aiConfig.temperature,
            max_completion_tokens: aiConfig.max_tokens,
            instructions: instructionText,
            additional_instructions: aiConfig.assistant_prompt || undefined,
            truncation_strategy: {
                type: 'last_messages',
                last_messages: aiConfig.history_limit
            }
        });
    }

    if (!threadId) {
        throw new Error('Não foi possível obter thread_id do Assistants API');
    }

    // Fetch assistant response
    const assistantMessage = await fetchAssistantMessageFromThread(
        openai.beta.threads,
        threadId,
        aiConfig.history_limit
    );

    if (!assistantMessage) {
        throw new Error('Assistants API não retornou resposta');
    }

    // Save thread metadata
    if (db && threadId && assistantMessage.messageId) {
        try {
            await db.saveThreadMetadata(
                resolvedInstanceId,
                sessionContext?.remoteJid,
                threadId,
                assistantMessage.messageId
            );
        } catch (metaErr) {
            console.warn('[OpenAI] Error saving thread metadata:', metaErr.message);
        }
    }

    return {
        text: assistantMessage.text,
        threadId,
        lastMessageId: assistantMessage.messageId
    };
}

/**
 * Fetch assistant message from thread
 * @param {Object} threads - OpenAI threads API
 * @param {string} threadId - Thread ID
 * @param {number} historyLimit - History limit
 * @returns {Promise<Object|null>}
 */
async function fetchAssistantMessageFromThread(threads, threadId, historyLimit) {
    let attempts = 0;
    const maxAttempts = 30;
    const pollInterval = 1000;

    while (attempts < maxAttempts) {
        try {
            const messages = await threads.list(threadId, { limit: 10, order: 'desc' });
            
            const assistantMsg = messages.data.find(
                msg => msg.role === 'assistant' && msg.content[0]?.type === 'text'
            );

            if (assistantMsg) {
                const text = assistantMsg.content[0]?.text?.value || '';
                return {
                    text,
                    messageId: assistantMsg.id
                };
            }
        } catch (err) {
            console.warn('[OpenAI] Error fetching thread messages:', err.message);
        }

        attempts++;
        await new Promise(resolve => setTimeout(resolve, pollInterval));
    }

    return null;
}

/**
 * Generate response using OpenAI Responses API
 * @param {Object} openai - OpenAI client
 * @param {Object} aiConfig - AI configuration
 * @param {Object} sessionContext - Session context
 * @param {string} messageBody - User message
 * @param {string} remoteJid - Remote JID (phone number)
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Object>}
 */
async function generateWithResponses(openai, aiConfig, sessionContext, messageBody, remoteJid, dependencies = {}) {
    const { db } = dependencies;
    
    // Import AI module for helpers
    const ai = require('../index');
    
    const historyMessages = await ai.fetchHistoryRows(sessionContext, aiConfig.history_limit, db);
    
    // Build phone number context for the AI
    const phoneNumberContext = buildPhoneNumberContext(remoteJid);
    const enrichedConfig = {
        ...aiConfig,
        injected_context: aiConfig.injected_context 
            ? aiConfig.injected_context + '\n\n' + phoneNumberContext
            : phoneNumberContext
    };
    
    const payload = ai.buildResponsesPayload(historyMessages, messageBody, enrichedConfig);
    const modelCandidates = ai.buildModelCandidates(aiConfig);

    return await ai.attemptModelSequence(modelCandidates, async (candidateModel) => {
        console.log('[OpenAI] Generating response with model:', candidateModel);
        
        const response = await openai.responses.create({
            model: candidateModel,
            input: payload.input,
            temperature: aiConfig.temperature,
            max_output_tokens: aiConfig.max_tokens
        });

        const text = ai.collectResponseText(response);
        if (!text) {
            throw new Error('Resposta inválida da OpenAI Responses API');
        }

        return { text };
    });
}

/**
 * Append injected instruction to system prompt
 * @param {string} systemPrompt - Original system prompt
 * @param {string} injectedContext - Injected context
 * @param {string} phoneNumberContext - Phone number context
 * @returns {string}
 */
function appendInjectedInstruction(systemPrompt, injectedContext, phoneNumberContext = '') {
    const parts = [];
    
    // Add date/time at the beginning
    parts.push(getFormattedDateTime());
    
    if (systemPrompt) {
        parts.push(systemPrompt);
    }
    
    if (injectedContext) {
        parts.push(injectedContext);
    }
    
    if (phoneNumberContext) {
        parts.push(phoneNumberContext);
    }
    
    return parts.join('\n\n');
}

/**
 * Build phone number context for AI
 * @param {string} remoteJid - Remote JID (e.g., 558586030781@s.whatsapp.net)
 * @returns {string}
 */
function buildPhoneNumberContext(remoteJid) {
    if (!remoteJid) return '';
    
    // Format phone number for display
    const phoneNumber = formatPhoneNumberForAI(remoteJid);
    
    return `[CONTEXTO ADICIONAL]
Número de telefone do usuário: ${phoneNumber}
WhatsApp JID: ${remoteJid}`;
}

/**
 * Format phone number for AI context display
 * @param {string} remoteJid - Remote JID
 * @returns {string}
 */
function formatPhoneNumberForAI(remoteJid) {
    if (!remoteJid) return 'Desconhecido';
    
    // Remove @s.whatsapp.net suffix
    const cleanNumber = remoteJid.replace('@s.whatsapp.net', '').replace('@g.us', '');
    
    // If it's a Brazilian number (starts with 55)
    if (cleanNumber.startsWith('55') && cleanNumber.length >= 12) {
        const ddd = cleanNumber.substring(2, 4);
        const numberPart = cleanNumber.substring(4);
        
        // Format as +55 (XX) XXXXX-XXXX
        if (numberPart.length >= 8) {
            const prefix = numberPart.substring(0, numberPart.length - 4);
            const suffix = numberPart.substring(numberPart.length - 4);
            return `+55 (${ddd}) ${prefix}-${suffix}`;
        }
        return `+55 ${cleanNumber}`;
    }
    
    return cleanNumber;
}

/**
 * Build messages array for OpenAI API
 * @param {string} userMessage - User message
 * @param {Object} context - Context object
 * @param {Object} aiConfig - AI configuration
 * @returns {Array}
 */
function buildMessages(userMessage, context, aiConfig) {
    const messages = [];
    
    // Add system prompt
    if (aiConfig.system_prompt) {
        messages.push({
            role: 'system',
            content: aiConfig.system_prompt
        });
    }
    
    // Add injected context
    if (aiConfig.injected_context) {
        messages.push({
            role: 'system',
            content: aiConfig.injected_context
        });
    }
    
    // Add conversation history
    if (context.history && context.history.length > 0) {
        for (const msg of context.history) {
            if (msg.role && msg.content) {
                messages.push({
                    role: msg.role,
                    content: msg.content
                });
            }
        }
    }
    
    // Add current user message
    messages.push({
        role: 'user',
        content: userMessage
    });
    
    return messages;
}

/**
 * Get system prompt based on context
 * @param {Object} context - Context object
 * @returns {string}
 */
function getSystemPrompt(context) {
    // Build datetime info for the prompt
    const datetimeInfo = context.currentDateTime ? `
DATA E HORA ATUAL (UTC-3 - Brasília): ${context.currentDateTime.iso}` : '';
    
    // Build contact info
    const contactInfo = context.jid ? `
CONTATO:
- JID: ${context.jid}
- LID: ${context.lid || 'N/A'}` : '';
    
    // Build instance info
    const instanceInfo = context.instanceId ? `
INSTÂNCIA: ${context.instanceId}` : '';
    
    let systemPrompt = `Você é um assistente virtual do sistema Maestro.
Sua função é ajudar os usuários com suas dúvidas e solicitações.
Responda de forma clara, objetiva e profissional.

HISTÓRICO DA CONVERSA (mensagens mais recentes primeiro):${datetimeInfo}${contactInfo}${instanceInfo}`;
    
    // Add customer-specific context if available
    if (context.customer) {
        systemPrompt += `\n\nInformações do cliente:
- Nome: ${context.customer.nome || 'Não informado'}
- Tipo: ${context.customer.tipo || 'Não informado'}`;
    }
    
    return systemPrompt;
}

module.exports = {
    generateResponse,
    buildMessages,
    getSystemPrompt,
    generateWithAssistants,
    generateWithResponses,
    fetchAssistantMessageFromThread,
    appendInjectedInstruction,
    buildPhoneNumberContext,
    formatPhoneNumberForAI,
};
