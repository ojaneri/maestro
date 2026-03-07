/**
 * @fileoverview OpenRouter provider implementation
 * @module whatsapp-server/ai/providers/openrouter
 *
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles OpenRouter API integration for accessing multiple AI models
 */

const fetch = require('node-fetch');

// Import AI module for shared utilities
const ai = require('../index');

const DEFAULT_OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

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
 * Generate response using OpenRouter
 * @param {Object} aiConfig - AI configuration
 * @param {Object} sessionContext - Session context
 * @param {string} messageBody - User message
 * @param {string} remoteJid - Remote JID (phone number)
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Object>}
 */
async function generateResponse(aiConfig, sessionContext, messageBody, remoteJid, dependencies = {}) {
    const { db } = dependencies;
    const apiKey = aiConfig.openrouter_api_key;
    const baseUrl = aiConfig.openrouter_base_url || DEFAULT_OPENROUTER_BASE_URL;
    
    if (!apiKey) {
        throw new Error('OPENROUTER_API_KEY não configurada');
    }

    // Import AI module for helpers
    const ai = require('../index');

    const historyRows = await ai.fetchHistoryRows(sessionContext, aiConfig.history_limit || 20, db);
    
    // Build phone number context for the AI
    const phoneNumberContext = buildPhoneNumberContext(remoteJid);
    const enrichedConfig = {
        ...aiConfig,
        injected_context: aiConfig.injected_context 
            ? aiConfig.injected_context + '\n\n' + phoneNumberContext
            : phoneNumberContext
    };
    
    const messages = buildMessages(messageBody, sessionContext, enrichedConfig, historyRows);
    const modelCandidates = ai.buildModelCandidates(aiConfig);

    // Try each model until one succeeds
    const errors = [];
    
    for (const candidateModel of modelCandidates) {
        try {
            console.log('[OpenRouter] Trying model:', candidateModel);
            
            const response = await fetch(`${baseUrl}/chat/completions`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${apiKey}`,
                    'Content-Type': 'application/json',
                    'HTTP-Referer': process.env.APP_URL || 'http://localhost:3000',
                    'X-Title': 'Maestro WhatsApp Server',
                },
                body: JSON.stringify({
                    model: candidateModel,
                    messages,
                    temperature: aiConfig.temperature || 0.7,
                    max_tokens: aiConfig.max_tokens || 2000,
                }),
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`OpenRouter API error (${response.status}): ${errorText}`);
            }

            const data = await response.json();

            if (!data.choices || data.choices.length === 0) {
                throw new Error('OpenRouter não retornou choices');
            }

            const text = data.choices[0]?.message?.content;
            if (!text) {
                throw new Error('OpenRouter retornou conteúdo vazio');
            }

            return {
                text,
                usage: data.usage || null,
                model: data.model || candidateModel
            };
        } catch (err) {
            errors.push({ model: candidateModel, error: err.message });
            console.warn(`[OpenRouter] Model ${candidateModel} failed:`, err.message);
        }
    }

    // All models failed
    const lastError = errors[errors.length - 1];
    throw new Error(`Todos os modelos OpenRouter falharam. Último erro: ${lastError?.error || 'Erro desconhecido'}`);
}

/**
 * Build messages array for OpenRouter API
 * @param {string} userMessage - User message
 * @param {Object} sessionContext - Session context
 * @param {Object} aiConfig - AI configuration
 * @param {Object} dependencies - External dependencies
 * @returns {Array}
 */
function buildMessages(userMessage, sessionContext, aiConfig, historyRows = []) {
    const messages = [];

    // Add date/time at the beginning
    const dateTimeInfo = getFormattedDateTime();
    
    // Add system prompt with date/time prepended
    if (aiConfig.system_prompt) {
        messages.push({
            role: 'system',
            content: `${dateTimeInfo}\n\n${aiConfig.system_prompt}`
        });
    } else {
        // Add just date/time if no system prompt
        messages.push({
            role: 'system',
            content: dateTimeInfo
        });
    }

    // Add injected context
    if (aiConfig.injected_context) {
        messages.push({
            role: 'system',
            content: aiConfig.injected_context
        });
    }

    // Add conversation history from database
    if (Array.isArray(historyRows) && historyRows.length > 0) {
        for (const msg of historyRows) {
            if (msg.role && msg.content) {
                // Format message with timestamp for AI context
                const formattedContent = ai.formatMessageWithTimestamp(msg);
                messages.push({
                    role: msg.role,
                    content: formattedContent
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
Responda de forma clara, objetiva e profissional em português brasileiro.

HISTÓRICO DA CONVERSA (mensagens mais recentes primeiro):${datetimeInfo}${contactInfo}${instanceInfo}`;

    if (context.customer) {
        systemPrompt += `\n\nInformações do cliente:
- Nome: ${context.customer.nome || 'Não informado'}
- Tipo: ${context.customer.tipo || 'Não informado'}`;
    }

    return systemPrompt;
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
    
    return `[CONTEXTO ADICIONAL]\nNúmero de telefone do usuário: ${phoneNumber}\nWhatsApp JID: ${remoteJid}`;
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
 * List available models from OpenRouter
 * @param {string} apiKey - OpenRouter API key
 * @returns {Promise<Array>}
 */
async function listModels(apiKey) {
    try {
        const response = await fetch('https://openrouter.ai/api/v1/models', {
            headers: {
                'Authorization': `Bearer ${apiKey}`,
            }
        });

        if (!response.ok) {
            throw new Error(`Failed to list models: ${response.status}`);
        }

        const data = await response.json();
        return data.data || [];
    } catch (err) {
        console.error('[OpenRouter] Error listing models:', err.message);
        return [];
    }
}

module.exports = {
    generateResponse,
    buildMessages,
    getSystemPrompt,
    listModels,
    DEFAULT_OPENROUTER_BASE_URL,
    buildPhoneNumberContext,
    formatPhoneNumberForAI,
};
