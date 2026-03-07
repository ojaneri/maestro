/**
 * @fileoverview Gemini (Google AI) provider implementation
 * @module whatsapp-server/ai/providers/gemini
 *
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles Google Gemini API integration for chat completions
 */

const { GoogleGenerativeAI } = require('@google/generative-ai');

// Import AI module for shared utilities
const ai = require('../index');

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
 * Default tool definitions for WhatsApp functions
 * These tools allow the AI to interact with the WhatsApp system
 */
const DEFAULT_TOOL_DEFINITIONS = [
    // Basic messaging
    {
        name: 'sendMessage',
        description: 'Send a WhatsApp message to a contact. Use this when the user wants to send a message to someone.',
        parameters: {
            type: 'object',
            properties: {
                to: {
                    type: 'string',
                    description: 'The phone number to send the message to (with country code, e.g., 558586030781)'
                },
                message: {
                    type: 'string',
                    description: 'The message content to send'
                }
            },
            required: ['to', 'message']
        }
    },
    {
        name: 'getContactInfo',
        description: 'Get information about a contact. Use this to retrieve contact details like name, phone, etc.',
        parameters: {
            type: 'object',
            properties: {
                phone: {
                    type: 'string',
                    description: 'The phone number to look up (with country code)'
                }
            },
            required: ['phone']
        }
    },
    {
        name: 'searchContacts',
        description: 'Search for contacts by name or phone number. Use this to find contacts in the system.',
        parameters: {
            type: 'object',
            properties: {
                query: {
                    type: 'string',
                    description: 'The search query (name or phone number)'
                }
            },
            required: ['query']
        }
    },
    {
        name: 'createContact',
        description: 'Create a new contact in the system. Use this when the user wants to add a new contact.',
        parameters: {
            type: 'object',
            properties: {
                name: {
                    type: 'string',
                    description: 'The name of the contact'
                },
                phone: {
                    type: 'string',
                    description: 'The phone number (with country code)'
                },
                email: {
                    type: 'string',
                    description: 'The email address (optional)'
                }
            },
            required: ['name', 'phone']
        }
    },
    {
        name: 'listContacts',
        description: 'List all contacts in the system. Use this to show the user all available contacts.',
        parameters: {
            type: 'object',
            properties: {
                limit: {
                    type: 'number',
                    description: 'Maximum number of contacts to return (default 50)'
                }
            }
        }
    },
    {
        name: 'getConversationHistory',
        description: 'Get the conversation history with a specific contact.',
        parameters: {
            type: 'object',
            properties: {
                phone: {
                    type: 'string',
                    description: 'The phone number to get history for'
                },
                limit: {
                    type: 'number',
                    description: 'Maximum number of messages to return (default 20)'
                }
            },
            required: ['phone']
        }
    },
    
    // Scheduling functions (as per user's list)
    {
        name: 'agendar',
        description: 'Agendar lembrete/lembrete. Parâmetros: data(DD/MM/AAAA), hora(HH:MM), texto, tag, tipo, interno. Retorna ID+horário. Use interno=true para manter contexto interno.',
        parameters: {
            type: 'object',
            properties: {
                data: { type: 'string', description: 'Data em DD/MM/AAAA (ex: 27/02/2026)' },
                hora: { type: 'string', description: 'Hora em HH:MM (ex: 14:30)' },
                texto: { type: 'string', description: 'Texto do lembrete' },
                tag: { type: 'string', description: 'Tag opcional (padrão: default)' },
                tipo: { type: 'string', description: 'Tipo opcional (padrão: followup)' },
                interno: { type: 'boolean', description: 'interno=true registra apenas no log sem notificar usuário' }
            },
            required: ['data', 'hora', 'texto']
        }
    },
    {
        name: 'agendar2',
        description: 'Agendar com tempo relativo. Parâmetros: tempo(+5m, +24h), texto, tag, tipo, interno. Use interno=true para replanejar sem notificar.',
        parameters: {
            type: 'object',
            properties: {
                tempo: { type: 'string', description: 'Tempo relativo (ex: +5m, +24h)' },
                texto: { type: 'string', description: 'Texto do lembrete' },
                tag: { type: 'string', description: 'Tag opcional' },
                tipo: { type: 'string', description: 'Tipo opcional' },
                interno: { type: 'boolean', description: 'interno=true para contexto interno' }
            },
            required: ['tempo', 'texto']
        }
    },
    {
        name: 'agendar3',
        description: 'Agendar para horário exato. Parâmetros: datetime(YYYY-MM-DD HH:mm:ss), texto, tag, tipo, interno.',
        parameters: {
            type: 'object',
            properties: {
                datetime: { type: 'string', description: 'Data e hora exatos (YYYY-MM-DD HH:mm:ss)' },
                texto: { type: 'string', description: 'Texto do lembrete' },
                tag: { type: 'string', description: 'Tag opcional' },
                tipo: { type: 'string', description: 'Tipo opcional' },
                interno: { type: 'boolean', description: 'interno=true para contexto interno' }
            },
            required: ['datetime', 'texto']
        }
    },
    {
        name: 'cancelar_e_agendar2',
        description: 'Cancela tudo pendente e cria novo lembrete. Parâmetros: mensagem_id (ID da mensagem anterior), destinatario (número), mensagem (texto), data (YYYY-MM-DD), hora (HH:MM).',
        parameters: {
            type: 'object',
            properties: {
                mensagem_id: { type: 'string', description: 'ID da mensagem anterior a ser cancelada' },
                destinatario: { type: 'string', description: 'Número do destinatário com país e DDD' },
                mensagem: { type: 'string', description: 'Texto da mensagem a ser agendada' },
                data: { type: 'string', description: 'Data no formato YYYY-MM-DD' },
                hora: { type: 'string', description: 'Hora no formato HH:MM' }
            },
            required: ['mensagem_id', 'destinatario', 'mensagem', 'data', 'hora']
        }
    },
    {
        name: 'cancelar_e_agendar3',
        description: 'Limpa pendentes da tag e cria lembrete para horário exato.',
        parameters: {
            type: 'object',
            properties: {
                datetime: { type: 'string', description: 'Data/hora exatos (YYYY-MM-DD HH:mm:ss)' },
                texto: { type: 'string', description: 'Texto do lembrete' },
                tag: { type: 'string', description: 'Tag opcional' },
                tipo: { type: 'string', description: 'Tipo opcional' },
                interno: { type: 'boolean', description: 'interno=true para contexto interno' }
            },
            required: ['datetime', 'texto']
        }
    },
    {
        name: 'listar_agendamentos',
        description: 'Lista agendamentos do contato. Parâmetros: tag, tipo, interno.',
        parameters: {
            type: 'object',
            properties: {
                tag: { type: 'string', description: 'Filtrar por tag' },
                tipo: { type: 'string', description: 'Filtrar por tipo' },
                interno: { type: 'boolean', description: 'interno=true para listar apenas internos' }
            }
        }
    },
    {
        name: 'apagar_agenda',
        description: 'Apaga agendamento específico. Parâmetros: scheduledId, interno.',
        parameters: {
            type: 'object',
            properties: {
                scheduledId: { type: 'string', description: 'ID do agendamento' },
                interno: { type: 'boolean', description: 'interno=true para contexto interno' }
            },
            required: ['scheduledId']
        }
    },
    {
        name: 'apagar_agendas_por_tag',
        description: 'Apaga todos agendamentos de uma tag.',
        parameters: {
            type: 'object',
            properties: {
                tag: { type: 'string', description: 'Tag dos agendamentos' },
                interno: { type: 'boolean', description: 'interno=true para contexto interno' }
            },
            required: ['tag']
        }
    },
    {
        name: 'apagar_agendas_por_tipo',
        description: 'Apaga todos agendamentos de um tipo.',
        parameters: {
            type: 'object',
            properties: {
                tipo: { type: 'string', description: 'Tipo dos agendamentos' },
                interno: { type: 'boolean', description: 'interno=true para contexto interno' }
            },
            required: ['tipo']
        }
    },
    {
        name: 'cancelar',
        description: 'Cancela agendamento específico.',
        parameters: {
            type: 'object',
            properties: {
                mensagem_id: { type: 'string', description: 'ID da mensagem' }
            },
            required: ['mensagem_id']
        }
    },
    {
        name: 'verificar_disponibilidade',
        description: 'Consulta disponibilidade no Google Calendar. Parâmetros: inicio, fim, calendar_num, timezone.',
        parameters: {
            type: 'object',
            properties: {
                inicio: { type: 'string', description: 'Data/hora início (YYYY-MM-DD HH:mm:ss)' },
                fim: { type: 'string', description: 'Data/hora fim (YYYY-MM-DD HH:mm:ss)' },
                calendar_num: { type: 'number', description: 'Número do calendário (1, 2, 3...)' },
                timezone: { type: 'string', description: 'Timezone (padrão: America/Fortaleza)' }
            },
            required: ['inicio', 'fim']
        }
    },
    {
        name: 'sugerir_horarios',
        description: 'Sugere horários livres. Parâmetros: data, janela, duracao_min, limite, calendar_num, timezone.',
        parameters: {
            type: 'object',
            properties: {
                data: { type: 'string', description: 'Data (YYYY-MM-DD)' },
                janela: { type: 'string', description: 'Janela (ex: 09:00-18:00)' },
                duracao_min: { type: 'number', description: 'Duração em minutos' },
                limite: { type: 'number', description: 'Quantidade de sugestões' },
                calendar_num: { type: 'number', description: 'Número do calendário' },
                timezone: { type: 'string', description: 'Timezone' }
            },
            required: ['data']
        }
    },
    
    // Calendar functions
    {
        name: 'marcar_evento',
        description: 'Cria evento no Google Calendar.',
        parameters: {
            type: 'object',
            properties: {
                titulo: { type: 'string', description: 'Título do evento' },
                inicio: { type: 'string', description: 'Data/hora início (YYYY-MM-DD HH:mm:ss)' },
                fim: { type: 'string', description: 'Data/hora fim (YYYY-MM-DD HH:mm:ss)' },
                participantes: { type: 'string', description: 'Emails separados por vírgula' },
                descricao: { type: 'string', description: 'Descrição do evento' },
                calendar_num: { type: 'number', description: 'Número do calendário (1, 2, 3...)' },
                timezone: { type: 'string', description: 'Timezone' }
            },
            required: ['titulo', 'inicio', 'fim']
        }
    },
    {
        name: 'remarcar_evento',
        description: 'Remarca evento existente.',
        parameters: {
            type: 'object',
            properties: {
                evento_id: { type: 'string', description: 'ID do evento' },
                novo_inicio: { type: 'string', description: 'Novo início (YYYY-MM-DD HH:mm:ss)' },
                novo_fim: { type: 'string', description: 'Novo fim (YYYY-MM-DD HH:mm:ss)' },
                calendar_num: { type: 'number', description: 'Número do calendário' },
                timezone: { type: 'string', description: 'Timezone' }
            },
            required: ['evento_id', 'novo_inicio', 'novo_fim']
        }
    },
    {
        name: 'desmarcar_evento',
        description: 'Remove evento do Google Calendar.',
        parameters: {
            type: 'object',
            properties: {
                evento_id: { type: 'string', description: 'ID do evento' },
                calendar_num: { type: 'number', description: 'Número do calendário' }
            },
            required: ['evento_id']
        }
    },
    {
        name: 'listar_eventos',
        description: 'Lista eventos no período.',
        parameters: {
            type: 'object',
            properties: {
                inicio: { type: 'string', description: 'Data/hora início (YYYY-MM-DD HH:mm:ss)' },
                fim: { type: 'string', description: 'Data/hora fim (YYYY-MM-DD HH:mm:ss)' },
                calendar_num: { type: 'number', description: 'Número do calendário' },
                timezone: { type: 'string', description: 'Timezone' }
            },
            required: ['inicio', 'fim']
        }
    },
    
    // Data and context functions
    {
        name: 'dados',
        description: 'Busca dados do cliente no MySQL KitPericia. Parâmetros: email, phone, cpf, id.',
        parameters: {
            type: 'object',
            properties: {
                email: { type: 'string', description: 'Email do cliente' },
                phone: { type: 'string', description: 'Telefone do cliente' },
                cpf: { type: 'string', description: 'CPF do cliente' },
                id: { type: 'string', description: 'ID do cliente' }
            }
        }
    },
    {
        name: 'set_estado',
        description: 'Salva estágio do funil.',
        parameters: {
            type: 'object',
            properties: {
                estado: { type: 'string', description: 'Nome do estágio' }
            },
            required: ['estado']
        }
    },
    {
        name: 'get_estado',
        description: 'Consulta estágio atual do funil.',
        parameters: {
            type: 'object',
            properties: {}
        }
    },
    {
        name: 'set_contexto',
        description: 'Salva memória curta por contato.',
        parameters: {
            type: 'object',
            properties: {
                chave: { type: 'string', description: 'Chave do contexto' },
                valor: { type: 'string', description: 'Valor do contexto' }
            },
            required: ['chave', 'valor']
        }
    },
    {
        name: 'get_contexto',
        description: 'Consulta memória curta.',
        parameters: {
            type: 'object',
            properties: {
                chave: { type: 'string', description: 'Chave do contexto' }
            },
            required: ['chave']
        }
    },
    {
        name: 'limpar_contexto',
        description: 'Limpa memória curta.',
        parameters: {
            type: 'object',
            properties: {
                chave: { type: 'string', description: 'Chave(s) para limpar (array)' }
            }
        }
    },
    {
        name: 'set_variavel',
        description: 'Salva variável persistente por instância.',
        parameters: {
            type: 'object',
            properties: {
                chave: { type: 'string', description: 'Nome da variável' },
                valor: { type: 'string', description: 'Valor da variável' }
            },
            required: ['chave', 'valor']
        }
    },
    {
        name: 'get_variavel',
        description: 'Consulta variável persistente.',
        parameters: {
            type: 'object',
            properties: {
                chave: { type: 'string', description: 'Nome da variável' }
            },
            required: ['chave']
        }
    },
    {
        name: 'optout',
        description: 'Cancela follow-ups e marca cliente como opt-out.',
        parameters: {
            type: 'object',
            properties: {}
        }
    },
    {
        name: 'template',
        description: 'Envia mensagem template via Meta API.',
        parameters: {
            type: 'object',
            properties: {
                ID_Template: { type: 'string', description: 'ID do template' },
                var1: { type: 'string', description: 'Variável 1 (opcional)' },
                var2: { type: 'string', description: 'Variável 2 (opcional)' },
                var3: { type: 'string', description: 'Variável 3 (opcional)' }
            },
            required: ['ID_Template']
        }
    },
    {
        name: 'status_followup',
        description: 'Resumo de estado, trilhas ativas e próximos agendamentos.',
        parameters: {
            type: 'object',
            properties: {}
        }
    },
    {
        name: 'tempo_sem_interacao',
        description: 'Retorna segundos sem interação do cliente.',
        parameters: {
            type: 'object',
            properties: {}
        }
    },
    {
        name: 'log_evento',
        description: 'Auditoria leve para métricas.',
        parameters: {
            type: 'object',
            properties: {
                categoria: { type: 'string', description: 'Categoria do evento' },
                descricao: { type: 'string', description: 'Descrição' },
                json_opcional: { type: 'string', description: 'Dados JSON adicionais' }
            },
            required: ['categoria', 'descricao']
        }
    },
    {
        name: 'boomerang',
        description: 'Sinaliza envio imediato.',
        parameters: {
            type: 'object',
            properties: {
                mensagem: { type: 'string', description: 'Mensagem do boomerang' }
            }
        }
    },
    {
        name: 'whatsapp',
        description: 'Envia mensagem WhatsApp (alias para sendMessage).',
        parameters: {
            type: 'object',
            properties: {
                numero: { type: 'string', description: 'Número do destinatário' },
                mensagem: { type: 'string', description: 'Mensagem' }
            },
            required: ['numero', 'mensagem']
        }
    },
    {
        name: 'mail',
        description: 'Envia email.',
        parameters: {
            type: 'object',
            properties: {
                destino: { type: 'string', description: 'Email do destinatário' },
                assunto: { type: 'string', description: 'Assunto' },
                corpo: { type: 'string', description: 'Corpo do email' },
                remetente: { type: 'string', description: 'Email do remetente (opcional)' }
            },
            required: ['destino', 'assunto', 'corpo']
        }
    },
    {
        name: 'get_web',
        description: 'Faz request HTTP.',
        parameters: {
            type: 'object',
            properties: {
                URL: { type: 'string', description: 'URL para fazer request' }
            },
            required: ['URL']
        }
    }
];

/**
 * Generate response using Gemini
 * @param {Object} aiConfig - AI configuration
 * @param {Object} sessionContext - Session context
 * @param {string} messageBody - User message
 * @param {string} remoteJid - Remote JID (phone number)
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Object>}
 */
async function generateResponse(aiConfig, sessionContext, messageBody, remoteJid, dependencies = {}) {
    const { db } = dependencies;
    const geminiApiKey = aiConfig.gemini_api_key;
    
    if (!geminiApiKey) {
        throw new Error('Chave Gemini não configurada');
    }

    const genAI = new GoogleGenerativeAI(geminiApiKey);
    const modelName = aiConfig.model || 'gemini-1.5-pro';
    
    // Parse tool definitions from config or use defaults
    let toolDefinitions = DEFAULT_TOOL_DEFINITIONS;
    if (aiConfig.ai_tools) {
        try {
            const customTools = typeof aiConfig.ai_tools === 'string' 
                ? JSON.parse(aiConfig.ai_tools) 
                : aiConfig.ai_tools;
            if (Array.isArray(customTools) && customTools.length > 0) {
                toolDefinitions = customTools;
            }
        } catch (e) {
            console.warn('[Gemini] Failed to parse custom tools, using defaults:', e.message);
        }
    }
    
    // Build tools configuration for Gemini
    const toolsConfig = {
        functionDeclarations: toolDefinitions
    };
    
    const systemInstruction = buildSystemInstruction(aiConfig, remoteJid);
    
    // Gemini API expects systemInstruction as { role: 'user', parts: [{ text: '...' }] }
    const systemInstructionObj = {
        role: 'user',
        parts: [{ text: systemInstruction }]
    };
    
    const model = genAI.getGenerativeModel({
        model: modelName,
        systemInstruction: systemInstructionObj,
        tools: [{ functionDeclarations: toolDefinitions }]
    });

    // Build history
    const history = await buildHistory(sessionContext, aiConfig, dependencies);

    // Start chat session
    const chat = model.startChat({
        history: history,
        generationConfig: {
            temperature: aiConfig.temperature || 0.7,
            maxOutputTokens: aiConfig.max_tokens || 2000,
        }
    });

    // Send message
    const result = await chat.sendMessage(messageBody);
    const response = result.response;

    if (!response) {
        throw new Error('Gemini não retornou resposta');
    }

    // Check for function calls (Gemini function calling)
    const functionCalls = response.functionCalls();
    
    if (functionCalls && functionCalls.length > 0) {
        console.log('[Gemini] Function calls detected:', functionCalls.map(fc => fc.name).join(', '));
        
        // Return function call information to be processed by the caller
        return {
            text: null,
            functionCalls: functionCalls.map(fc => ({
                name: fc.name,
                args: typeof fc.args === 'string' ? JSON.parse(fc.args) : (fc.args || {})
            })),
            usage: null,
            model: modelName
        };
    }
    
    const text = response.text();
    if (!text) {
        throw new Error('Gemini retornou resposta vazia');
    }

    return {
        text,
        usage: null,
        model: modelName
    };
}

/**
 * Build system instruction for Gemini
 * @param {Object} aiConfig - AI configuration
 * @param {string} remoteJid - Remote JID (phone number)
 * @returns {string}
 */
function buildSystemInstruction(aiConfig, remoteJid = null) {
    const parts = [];

    // ============================================================
    // DATA E HORA ATUAL - sempre no início do prompt
    // ============================================================
    const dateTimeInfo = getFormattedDateTime();
    parts.push(dateTimeInfo);

    // ============================================================
    // HIERARQUIA DE PRIORIDADE DE PROMPTS
    // ============================================================
    // 1. gemini_instruction (prioridade máxima - instrução específica do Gemini)
    // 2. system_prompt (prompt geral do sistema)
    // 3. Fallback global (prompt padrão)
    //
    // IMPORTANTE: injected_context é adicionado APENAS como complemento
    // após a escolha do prompt principal, nunca em conflito.
    // ============================================================

    // Determina o prompt principal baseado na hierarquia
    const primaryPrompt = (aiConfig.gemini_instruction && aiConfig.gemini_instruction.trim())
        ? aiConfig.gemini_instruction
        : (aiConfig.system_prompt && aiConfig.system_prompt.trim())
            ? aiConfig.system_prompt
            : null;

    // Adiciona o prompt principal selecionado
    if (primaryPrompt) {
        parts.push(primaryPrompt);
    }

    // injected_context pode ser adicionado se existir (contexto dinâmico)
    if (aiConfig.injected_context && aiConfig.injected_context.trim()) {
        parts.push(aiConfig.injected_context);
    }

    // ============================================================
    // ADICIONAR CONTEXTO DO TELEFONE DO USUÁRIO
    // ============================================================
    // Inclui o número de telefone do usuário explicitamente para que
    // a IA saiba qual número está entrando em contato
    // ============================================================
    if (remoteJid) {
        // Format phone number for display
        const phoneNumber = formatPhoneNumberForAI(remoteJid);
        parts.push(`\n\n[CONTEXTO ADICIONAL]\nNúmero de telefone do usuário: ${phoneNumber}\nWhatsApp JID: ${remoteJid}`);
    }

    // Fallback: retorna prompt global se nenhum prompt foi definido
    if (parts.length === 1) {
        // Only dateTimeInfo was added, no prompt defined
        const dateTimePart = parts[0];
        return `${dateTimePart}\n\nVocê é um assistente virtual do sistema Maestro.
Sua função é ajudar os usuários com suas dúvidas e solicitações.
Responda de forma clara, objetiva e profissional em português brasileiro.${remoteJid ? '\n\n[CONTEXTO ADICIONAL]\nNúmero de telefone do usuário: ' + formatPhoneNumberForAI(remoteJid) : ''}`;
    }

    return parts.filter(Boolean).join('\n\n');
}

/**
 * Format phone number for AI context display
 * @param {string} remoteJid - Remote JID (e.g., 558586030781@s.whatsapp.net)
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
 * Build history for Gemini chat session
 * @param {Object} sessionContext - Session context
 * @param {Object} aiConfig - AI configuration
 * @param {Object} dependencies - External dependencies
 * @returns {Promise<Array>}
 */
async function buildHistory(sessionContext, aiConfig, dependencies = {}) {
    const { db } = dependencies;
    const resolvedInstanceId = dependencies.instanceId || sessionContext?.instanceId || 'default';
    const history = [];

    // Fetch history from database
    if (db && sessionContext?.remoteJid) {
        try {
            let messages = await db.getLastMessages(
                resolvedInstanceId,
                sessionContext.remoteJid,
                aiConfig.history_limit || 20,
                sessionContext.sessionId
            );

            // Reverse to chronological order (oldest first) for Gemini
            messages = (messages || []).reverse();

            // Find the first user message to ensure proper conversation start
            let firstUserIndex = messages.findIndex(msg => msg.role === 'user');
            
            // BUGFIX: If no user message found or history starts with 'model', handle properly
            if (firstUserIndex === -1) {
                // No user message in history - start with empty history to avoid "First content should be with role 'user'" error
                console.warn('[Gemini] No user message found in history, starting with empty history');
                messages = [];
            } else if (firstUserIndex > 0) {
                // If first message is not from user, skip to first user message
                console.log('[Gemini] Skipping', firstUserIndex, 'non-user messages at start of history');
                messages = messages.slice(firstUserIndex);
            } else {
                // firstUserIndex === 0 - already starts with user, which is correct
                console.log('[Gemini] History starts with user message as expected');
            }

            // Additional validation: ensure first item in processed history is 'user'
            if (messages.length > 0 && messages[0].role !== 'user') {
                console.warn('[Gemini] First message role is', messages[0].role, '- filtering to find user message');
                const validUserIndex = messages.findIndex(msg => msg.role === 'user');
                if (validUserIndex === -1) {
                    messages = [];
                } else {
                    messages = messages.slice(validUserIndex);
                }
            }

            for (const msg of messages) {
                if (msg.role && msg.content) {
                    // Format message with timestamp for AI context
                    const formattedContent = ai.formatMessageWithTimestamp(msg);
                    history.push({
                        role: msg.role === 'user' ? 'user' : 'model',
                        parts: [{ text: formattedContent }]
                    });
                }
            }
        } catch (err) {
            console.warn('[Gemini] Could not fetch history:', err.message);
        }
    }

    return history;
}

/**
 * Build messages array for Gemini API (alternative format)
 * @param {string} userMessage - User message
 * @param {Object} context - Context object
 * @param {Object} aiConfig - AI configuration
 * @returns {Array}
 */
function buildMessages(userMessage, context, aiConfig) {
    const messages = [];

    // Add system instruction as first message if provided
    const systemInstruction = buildSystemInstruction(aiConfig);
    if (systemInstruction) {
        messages.push({
            role: 'user',
            parts: [{ text: systemInstruction }]
        });
    }

    // Add conversation history
    if (context.history && context.history.length > 0) {
        for (const msg of context.history) {
            messages.push({
                role: msg.role === 'user' ? 'user' : 'model',
                parts: [{ text: msg.content }]
            });
        }
    }

    // Add current user message
    messages.push({
        role: 'user',
        parts: [{ text: userMessage }]
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
    
    // Build context info (variables, states, scheduled events)
    const contextInfo = context.sessionContext ? `
VARIÁVEIS E CONTEXTO:
${context.sessionContext}` : '';
    
    let systemPrompt = `Você é um assistente virtual do sistema Maestro.
Sua função é ajudar os usuários com suas dúvidas e solicitações.
Responda de forma clara, objetiva e profissional em português brasileiro.

HISTÓRICO DA CONVERSA (mensagens mais recentes primeiro):${datetimeInfo}${contactInfo}${instanceInfo}${contextInfo}`;

    if (context.customer) {
        systemPrompt += `\n\nInformações do cliente:
- Nome: ${context.customer.nome || 'Não informado'}
- Tipo: ${context.customer.tipo || 'Não informado'}`;
    }

    return systemPrompt;
}

/**
 * Execute a tool call based on the function name and arguments
 * @param {string} functionName - The name of the function to execute
 * @param {Object} args - The arguments for the function
 * @param {Object} dependencies - Dependencies including db, sendMessage, etc.
 * @returns {Promise<Object>}
 */
async function executeTool(functionName, args, dependencies = {}) {
    const { db, sendMessage, getContactInfo, searchContacts, createContact, listContacts, getConversationHistory } = dependencies;
    
    // Strip namespace prefix (e.g., "default_api.agendar2" -> "agendar2")
    const normalizedFunctionName = functionName.includes('.') ? functionName.split('.').pop() : functionName;
    
    console.log(`[Gemini] Executing tool: ${functionName} (normalized: ${normalizedFunctionName})`, args);
    
    try {
        switch (normalizedFunctionName) {
            case 'sendMessage':
                if (sendMessage) {
                    const result = await sendMessage(args.to, args.message);
                    return { success: true, result };
                }
                return { success: false, error: 'sendMessage function not available' };
            
            case 'getContactInfo':
                if (getContactInfo) {
                    const contact = await getContactInfo(args.phone);
                    return { success: true, result: contact };
                }
                if (db) {
                    const contact = await db.getContact(args.phone);
                    return { success: true, result: contact };
                }
                return { success: false, error: 'Contact lookup not available' };
            
            case 'searchContacts':
                if (searchContacts) {
                    const contacts = await searchContacts(args.query);
                    return { success: true, result: contacts };
                }
                if (db) {
                    const contacts = await db.searchContacts(args.query);
                    return { success: true, result: contacts };
                }
                return { success: false, error: 'Contact search not available' };
            
            case 'createContact':
                if (createContact) {
                    const contact = await createContact(args.name, args.phone, args.email);
                    return { success: true, result: contact };
                }
                if (db) {
                    const contactId = await db.createContact({
                        name: args.name,
                        phone: args.phone,
                        email: args.email || null
                    });
                    return { success: true, result: { id: contactId, name: args.name, phone: args.phone } };
                }
                return { success: false, error: 'Contact creation not available' };
            
            case 'listContacts':
                if (listContacts) {
                    const contacts = await listContacts(args.limit || 50);
                    return { success: true, result: contacts };
                }
                if (db) {
                    const contacts = await db.getContacts(args.limit || 50);
                    return { success: true, result: contacts };
                }
                return { success: false, error: 'Contact list not available' };
            
            case 'getConversationHistory':
                if (getConversationHistory) {
                    const history = await getConversationHistory(args.phone, args.limit || 20);
                    return { success: true, result: history };
                }
                if (db) {
                    const history = await db.getLastMessages(null, args.phone + '@s.whatsapp.net', args.limit || 20);
                    return { success: true, result: history };
                }
                return { success: false, error: 'Conversation history not available' };
            
            case 'agendar': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.agendar(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in agendar:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'listarAgendamentos': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.listarAgendamentos(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in listarAgendamentos:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'cancelar': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.cancelar(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in cancelar:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'verificar_disponibilidade': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.verificarDisponibilidade(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in verificar_disponibilidade:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'sugerir_horarios': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.sugerirHorarios(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in sugerir_horarios:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'agendar2': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.agendar2(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in agendar2:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'cancelar_e_agendar2': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.cancelarEAgendar2(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in cancelar_e_agendar2:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'agendar3': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || null;
                try {
                    const { datetime, texto, tag, tipo, interno } = args;
                    const result = await schedulingHandler.agendar3.call(
                        { instanceId, db, remoteJid },
                        datetime,
                        texto,
                        tag || 'default',
                        tipo || 'followup',
                        interno || false
                    );
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in agendar3:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'cancelar_e_agendar3': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || null;
                try {
                    const { datetime, texto, tag, tipo, interno } = args;
                    const result = await schedulingHandler.cancelar_e_agendar3.call(
                        { instanceId, db, remoteJid },
                        datetime,
                        texto,
                        tag || 'default',
                        tipo || 'followup',
                        interno || false
                    );
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in cancelar_e_agendar3:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'apagar_agendas_por_tipo': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const { tipo, interno } = args;
                    const result = await schedulingHandler.apagar_agendas_por_tipo.call(
                        { instanceId, db },
                        tipo,
                        interno || false
                    );
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in apagar_agendas_por_tipo:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'listar_agendamentos': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.listarAgendamentos(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in listar_agendamentos:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'apagar_agenda': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.apagarAgenda(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in apagar_agenda:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'apagar_agendas_por_tag': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await schedulingHandler.apagarAgendasPorTag(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in apagar_agendas_por_tag:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'marcar_evento': {
                const calendarHandler = require('../../commands/handlers/calendar');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await calendarHandler.marcarEvento(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in marcar_evento:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'remarcar_evento': {
                const calendarHandler = require('../../commands/handlers/calendar');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await calendarHandler.remarcarEvento(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in remarcar_evento:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'desmarcar_evento': {
                const calendarHandler = require('../../commands/handlers/calendar');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await calendarHandler.desmarcarEvento(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in desmarcar_evento:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'listar_eventos': {
                const calendarHandler = require('../../commands/handlers/calendar');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await calendarHandler.listarEventos(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in listar_eventos:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'dados': {
                const dadosHandler = require('../../commands/handlers/dados');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await dadosHandler.getCustomerData(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in dados:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'set_estado': {
                const contextHandler = require('../../commands/handlers/context');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await contextHandler.setEstado(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in set_estado:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'get_estado': {
                const contextHandler = require('../../commands/handlers/context');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await contextHandler.getEstado(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in get_estado:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'set_contexto': {
                const contextHandler = require('../../commands/handlers/context');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await contextHandler.setContexto(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in set_contexto:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'get_contexto': {
                const contextHandler = require('../../commands/handlers/context');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await contextHandler.getContexto(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in get_contexto:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'limpar_contexto': {
                const contextHandler = require('../../commands/handlers/context');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await contextHandler.limparContexto(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in limpar_contexto:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'set_variavel': {
                const contextHandler = require('../../commands/handlers/context');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await contextHandler.setVariavel(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in set_variavel:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'get_variavel': {
                const contextHandler = require('../../commands/handlers/context');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await contextHandler.getVariavel(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in get_variavel:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'optout': {
                const dadosHandler = require('../../commands/handlers/dados');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await dadosHandler.optOut(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in optout:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'template': {
                const templateHandler = require('../../commands/handlers/template');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await templateHandler.sendTemplate(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in template:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'status_followup': {
                const dadosHandler = require('../../commands/handlers/dados');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await dadosHandler.statusFollowup(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in status_followup:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'tempo_sem_interacao': {
                const dadosHandler = require('../../commands/handlers/dados');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await dadosHandler.tempoSemInteracao(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in tempo_sem_interacao:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'log_evento': {
                const dadosHandler = require('../../commands/handlers/dados');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await dadosHandler.logEvent(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in log_evento:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'boomerang': {
                const whatsappHandler = require('../../commands/handlers/whatsapp');
                const instanceId = dependencies.instanceId || 'default';
                const remoteJid = dependencies.remoteJid || '';
                try {
                    const result = await whatsappHandler.boomerang(args, { instanceId, db, remoteJid });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in boomerang:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'whatsapp': {
                const whatsappHandler = require('../../commands/handlers/whatsapp');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await whatsappHandler.sendWhatsApp(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in whatsapp:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'mail': {
                const mailHandler = require('../../commands/handlers/mail');
                const instanceId = dependencies.instanceId || 'default';
                try {
                    const result = await mailHandler.sendMail(args, { instanceId, db });
                    return { success: true, result };
                } catch (err) {
                    console.error('[Gemini] Error in mail:', err);
                    return { success: false, error: err.message };
                }
            }
            
            case 'get_web': {
                try {
                    const https = require('https');
                    const http = require('http');
                    const url = new URL(args.URL);
                    
                    // SSRF Protection: Validate URL and block internal/private IPs
                    const hostname = url.hostname.toLowerCase();
                    const blockedPatterns = [
                        /^localhost$/i,
                        /^127\.\d+\.\d+\.\d+$/,
                        /^::1$/,
                        /^0\./,
                        /^10\./,
                        /^172\.(1[6-9]|2\d|3[01])\./,
                        /^192\.168\./,
                        /^224\./,
                        /^240\./,
                        /\.local$/i,
                        /^localhost\./i
                    ];
                    
                    for (const pattern of blockedPatterns) {
                        if (pattern.test(hostname)) {
                            console.error('[Gemini] SSRF blocked: Internal IP or blocked hostname:', hostname);
                            return { success: false, error: 'SSRF protection: Request to internal/private network not allowed' };
                        }
                    }
                    
                    // Only allow http and https protocols
                    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
                        return { success: false, error: 'SSRF protection: Only HTTP and HTTPS protocols are allowed' };
                    }
                    
                    return new Promise((resolve) => {
                        const client = url.protocol === 'https:' ? https : http;
                        const req = client.get(args.URL, (res) => {
                            let data = '';
                            res.on('data', chunk => data += chunk);
                            res.on('end', () => {
                                resolve({ success: true, result: { status: res.statusCode, data } });
                            });
                        });
                        req.on('error', err => {
                            resolve({ success: false, error: err.message });
                        });
                    });
                } catch (err) {
                    console.error('[Gemini] Error in get_web:', err);
                    return { success: false, error: err.message };
                }
            }
            
            default:
                return { success: false, error: `Unknown function: ${functionName}` };
        }
    } catch (error) {
        console.error(`[Gemini] Tool execution error:`, error);
        return { success: false, error: error.message };
    }
}

module.exports = {
    generateResponse,
    buildMessages,
    getSystemPrompt,
    buildSystemInstruction,
    buildHistory,
    executeTool,
    DEFAULT_TOOL_DEFINITIONS
};
