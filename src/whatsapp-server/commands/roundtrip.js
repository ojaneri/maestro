/**
 * @fileoverview AI roundtrip executor - handles AI command flow with human fallback
 * @module whatsapp-server/commands/roundtrip
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Executes commands with AI turnaround for complex scenarios
 */

const parser = require('./parser');
const commands = require('./index');

function buildCommandMetadata(commandResults = [], notes = []) {
    const normalizedCommands = (Array.isArray(commandResults) ? commandResults : [])
        .map((cmd) => {
            if (!cmd || typeof cmd !== 'object') return null;
            const type = cmd.type || cmd.name || 'função';
            const args = cmd.args !== undefined ? cmd.args : [];
            const result = cmd.result !== undefined
                ? cmd.result
                : (cmd.success === false ? { ok: false, error: cmd.error || 'erro desconhecido' } : null);
            return { type, args, result };
        })
        .filter(Boolean);

    if (!normalizedCommands.length && !(Array.isArray(notes) && notes.length)) {
        return null;
    }

    return {
        commands: normalizedCommands,
        notes: Array.isArray(notes) ? notes : [],
        source: 'roundtrip'
    };
}

/**
 * Functions that need AI return after execution
 */
const FUNCTIONS_NEEDING_AI_RETURN = [
    'verificar_disponibilidade',
    'sugerir_horarios',
    'marcar_evento',
    'remarcar_evento',
    'desmarcar_evento',
    'listar_eventos',
    'get_estado',
    'listar_agendamentos',
    'get_contexto',
    'get_variavel',
    'status_followup',
    'tempo_sem_interacao',
    'boomerang',
    'whatsapp',
    'mail',
    'get_web'
];

/**
 * Execute command flow with AI turnaround
 * @param {Object} sessionContext - Session context
 * @param {string} aiText - AI response text with commands
 * @param {Object} providedConfig - AI configuration
 * @param {Object} options - Execution options
 * @param {Object} dependencies - External dependencies
 * @param {number} turnarounds - Max roundtrip iterations
 * @returns {Promise<Object>}
 */
async function executeWithAITurnaround(sessionContext, aiText, providedConfig, options = {}, dependencies = {}, turnarounds = 3) {
    if (turnarounds <= 0) {
        return { text: aiText, commands: [], notes: [] };
    }

    const { sendWhatsAppMessage, persistSessionMessage } = dependencies;
    const remoteJid = sessionContext?.remoteJid || '';

    // Extract commands from AI text
    const extractedCommands = parser.extractAssistantCommands(aiText);
    
    // DEBUG: Log what AI is outputting
    console.log('[ROUNDTRIP DEBUG] AI raw text (first 500 chars):', aiText.substring(0, 500));
    console.log('[ROUNDTRIP DEBUG] Contains &&&:', aiText.includes('&&&'));
    console.log('[ROUNDTRIP DEBUG] Contains [[:', aiText.includes('[['));
    console.log('[ROUNDTRIP DEBUG] Extracted commands count:', extractedCommands.length);
    console.log('[ROUNDTRIP DEBUG] Extracted commands:', JSON.stringify(extractedCommands));
    
    if (!extractedCommands.length) {
        return { text: aiText };
    }

    const functionNotes = [];
    let hasAIReturn = false;

    // Check if any function needs AI return
    for (const command of extractedCommands) {
        const commandType = command?.type || command?.name || '';
        if (FUNCTIONS_NEEDING_AI_RETURN.includes(commandType)) {
            hasAIReturn = true;
            break;
        }
    }

    if (hasAIReturn) {
        // Step 1: Execute commands/functions
        const result = await handleAssistantCommands(sessionContext, aiText, providedConfig, options, dependencies);

        // Step 2: Build commands summary for AI
        const commandsSummary = (result.commands || []).map(cmd => {
            const cmdResult = cmd.result || {};
            const argsStr = cmd.args ? JSON.stringify(cmd.args) : '';
            return `Função: ${cmd.type}(${argsStr})\nRetorno: ${JSON.stringify(cmdResult)}`;
        }).join('\n\n');

        const aiPrompt = `
MENSAGEM JÁ ENVIADA AO CLIENTE:
"${result.text}"

EXECUTEI AS SEGUINTES FUNÇÕES:
${commandsSummary}

TAREFA: Forneça uma resposta curta e direta ao cliente com base nestes resultados.
REGRAS:
1. NÃO repita a saudação ou a mensagem inicial acima.
2. Se não houver mais nada a dizer além do que já foi enviado, responda apenas "PARAR".
`;

        try {
            // Import AI module dynamically to avoid circular dependency
            const ai = require('../ai');
            
            const followUpResponse = await ai.generateAIResponse(sessionContext, aiPrompt, providedConfig, dependencies);
            const followUpText = followUpResponse.text?.trim();

            if (followUpText && 
                followUpText.toLowerCase() !== 'parar' && 
                followUpText.toLowerCase() !== 'stop') {
                
                // Process possible new commands from AI response
                const followUpResult = await executeWithAITurnaround(
                    sessionContext, followUpText, providedConfig, options, dependencies, turnarounds - 1
                );

                // Combine results
                const combinedText = result.text + (followUpResult.text ? '\n' + followUpResult.text : '');
                return {
                    text: combinedText.trim(),
                    commands: [...(result.commands || []), ...(followUpResult.commands || [])],
                    notes: [...(result.notes || []), ...(followUpResult.notes || [])]
                };
            }
        } catch (err) {
            console.error('[Roundtrip] Error in AI roundtrip:', err.message);
        }

        return result;
    }

    // If no function needs round-trip, process normally
    return handleAssistantCommands(sessionContext, aiText, providedConfig, options, dependencies);
}

/**
 * Split text into hash segments
 * @param {string} text - Text to split
 * @returns {Array<string>}
 */
function splitHashSegments(text) {
    if (!text) return [];
    
    if (text.includes('#')) {
        const segments = text.split('#').filter(s => s.trim());
        if (segments.length > 0) {
            return segments;
        }
    }
    
    return [text];
}

/**
 * Handle assistant commands execution
 * @param {Object} sessionContext - Session context
 * @param {string} aiText - AI response text
 * @param {Object} providedConfig - AI configuration
 * @param {Object} options - Execution options
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Object>}
 */
async function handleAssistantCommands(sessionContext, aiText, providedConfig, options = {}, dependencies = {}) {
    const extractedCommands = parser.extractAssistantCommands(aiText);
    const cleanedText = parser.removeCommandsFromText(aiText, extractedCommands);
    
    const results = [];
    
    for (const command of extractedCommands) {
        const commandType = command?.type || command?.name || '';
        try {
            const handler = commands.COMMANDS[commandType];
            
            if (!handler) {
                results.push({
                    type: commandType || 'desconhecido',
                    success: false,
                    error: `Comando desconhecido: ${commandType || 'desconhecido'}`
                });
                continue;
            }

            const whatsappService = require('../../infra/whatsapp-service');
            whatsappService.writeDebugLog('FUNCTION_CALL', `Executing ${commandType} for ${sessionContext?.remoteJid}`, { args });
            
            // Execute command handler
            const result = await handler(args, { ...sessionContext, ...dependencies });
            
            whatsappService.writeDebugLog('FUNCTION_RESULT', `Result of ${commandType}`, result);
            
            results.push({
                type: commandType,
                args,
                success: true,
                result
            });
        } catch (err) {
            console.error(`[Roundtrip] Error executing command ${commandType || 'desconhecido'}:`, err.message);
            results.push({
                type: commandType || 'desconhecido',
                args,
                success: false,
                error: err.message
            });
        }
    }

    return {
        text: cleanedText,
        commands: results,
        notes: []
    };
}

/**
 * Execute with human handoff fallback
 * @param {string} text - User input text
 * @param {Object} context - Execution context
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Object>}
 */
async function executeWithHumanFallback(text, context, dependencies = {}) {
    try {
        const result = await executeWithAITurnaround(context, text, null, {}, dependencies);
        
        if (result.requiresFollowUp) {
            // Queue for human agent review
            console.log(`[Roundtrip] Queuing for human review: ${context?.remoteJid}`);
            
            return {
                ...result,
                humanHandoff: true,
                message: 'Um de nossos atendentes irá analisar sua solicitação em breve.'
            };
        }
        
        return result;
    } catch (error) {
        // Fallback to human agent
        console.error('[Roundtrip] Critical error in AI turnaround:', error);
        
        return {
            success: false,
            humanHandoff: true,
            message: 'Desculpe, estamos transferindo para um atendente.',
            error: error.message
        };
    }
}

/**
 * Check if command needs AI return
 * @param {string} commandType - Command type name
 * @returns {boolean}
 */
function needsAIReturn(commandType) {
    return FUNCTIONS_NEEDING_AI_RETURN.includes(commandType);
}

module.exports = {
    executeWithAITurnaround,
    executeWithHumanFallback,
    handleAssistantCommands,
    needsAIReturn,
    FUNCTIONS_NEEDING_AI_RETURN,
    splitHashSegments,
};
