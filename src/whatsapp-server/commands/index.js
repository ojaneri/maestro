/**
 * @fileoverview Command registry and routing - handles AI command execution
 * @module whatsapp-server/commands
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Registers commands and routes to appropriate handlers
 */

const parser = require('./parser');
const mailHandler = require('./handlers/mail');
const whatsappHandler = require('./handlers/whatsapp');
const dadosHandler = require('./handlers/dados');
const calendarHandler = require('./handlers/calendar');
const schedulingHandler = require('./handlers/scheduling');
const contextHandler = require('./handlers/context');
const templateHandler = require('./handlers/template');

/**
 * Command function names for parsing
 */
const assistantFunctionNames = [
    'mail',
    'whatsapp',
    'get_web',
    'dados',
    'agendar',
    'agendar2',
    'agendar3',
    'boomerang',
    'listar_agendamentos',
    'apagar_agenda',
    'apagar_agendas_por_tag',
    'apagar_agendas_por_tipo',
    'cancelar_e_agendar2',
    'cancelar_e_agendar3',
    'verificar_disponibilidade',
    'sugerir_horarios',
    'marcar_evento',
    'remarcar_evento',
    'desmarcar_evento',
    'listar_eventos',
    'set_estado',
    'get_estado',
    'set_contexto',
    'get_contexto',
    'limpar_contexto',
    'set_variavel',
    'get_variavel',
    'optout',
    'status_followup',
    'log_evento',
    'tempo_sem_interacao',
    'template'
];

/**
 * Command registry
 */
const COMMANDS = {
    // Mail commands
    mail: mailHandler.sendMail,
    
    // WhatsApp commands
    whatsapp: whatsappHandler.sendWhatsApp,
    boomerang: whatsappHandler.boomerang,
    
    // Customer data commands
    dados: dadosHandler.getCustomerData,
    get_customer: dadosHandler.getCustomerData,
    update_customer: dadosHandler.updateCustomerData,
    
    // Calendar commands
    marcar_evento: calendarHandler.marcarEvento,
    remarcar_evento: calendarHandler.remarcarEvento,
    desmarcar_evento: calendarHandler.desmarcarEvento,
    listar_eventos: calendarHandler.listarEventos,
    
    // Scheduling commands
    agendar: schedulingHandler.agendar,
    agendar2: schedulingHandler.agendar2,
    agendar3: schedulingHandler.agendar3,
    cancelar: schedulingHandler.cancelar,
    listar_agendamentos: schedulingHandler.listarAgendamentos,
    apagar_agenda: schedulingHandler.apagarAgenda,
    apagar_agendas_por_tag: schedulingHandler.apagarAgendasPorTag,
    apagar_agendas_por_tipo: schedulingHandler.apagar_agendas_por_tipo,
    cancelar_e_agendar2: schedulingHandler.cancelarEAgendar2,
    cancelar_e_agendar3: schedulingHandler.cancelar_e_agendar3,
    
    // Availability commands
    verificar_disponibilidade: schedulingHandler.verificarDisponibilidade,
    sugerir_horarios: schedulingHandler.sugerirHorarios,
    
    // Context commands
    set_contexto: contextHandler.setContexto,
    get_contexto: contextHandler.getContexto,
    limpar_contexto: contextHandler.limparContexto,
    set_variavel: contextHandler.setVariavel,
    get_variavel: contextHandler.getVariavel,
    set_estado: contextHandler.setEstado,
    get_estado: contextHandler.getEstado,
    
    // Template commands
    template: templateHandler.sendTemplate,
    send_template: templateHandler.sendTemplate,
    
    // Utility commands
    optout: dadosHandler.optOut,
    status_followup: dadosHandler.statusFollowup,
    log_evento: dadosHandler.logEvent,
    tempo_sem_interacao: dadosHandler.tempoSemInteracao,
};

/**
 * Process text for commands and execute
 * @param {string} text - AI response text
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function processCommands(text, context = {}) {
    const extractedCommands = parser.extractAssistantCommands(text);
    
    if (extractedCommands.length === 0) {
        return { text, executedCommands: [] };
    }
    
    const results = [];
    
    for (const command of extractedCommands) {
        try {
            const commandName = command.name || command.type;
            const handler = COMMANDS[commandName];
            
            if (!handler) {
                results.push({
                    command: commandName,
                    success: false,
                    error: `Unknown command: ${commandName}`
                });
                continue;
            }
            
            const args = typeof command.args === 'object' && !Array.isArray(command.args) 
                ? command.args 
                : parser.parseFunctionArgs(command.args);
            const result = await handler(args, context);
            
            results.push({
                type: commandName,
                args: args,
                success: true,
                result
            });
        } catch (error) {
            console.error(`[Commands] Error executing command:`, error);
            results.push({
                command: command.name || 'unknown',
                success: false,
                error: error.message
            });
        }
    }
    
    // Remove commands from text
    const cleanedText = parser.removeCommandsFromText(text, extractedCommands);
    
    return { text: cleanedText, executedCommands: results };
}

/**
 * Register a new command handler
 * @param {string} name - Command name
 * @param {Function} handler - Handler function
 */
function registerCommand(name, handler) {
    if (COMMANDS[name]) {
        console.warn(`[Commands] Command ${name} already exists, overwriting`);
    }
    COMMANDS[name] = handler;
}

/**
 * Unregister a command
 * @param {string} name - Command name
 */
function unregisterCommand(name) {
    delete COMMANDS[name];
}

/**
 * Get all registered commands
 * @returns {Array<string>}
 */
function getRegisteredCommands() {
    return Object.keys(COMMANDS);
}

/**
 * Get all assistant function names
 * @returns {Array<string>}
 */
function getAssistantFunctionNames() {
    return [...assistantFunctionNames];
}

/**
 * Check if a command is registered
 * @param {string} name - Command name
 * @returns {boolean}
 */
function hasCommand(name) {
    return COMMANDS[name] !== undefined;
}

/**
 * Create assistant command regex pattern
 * @returns {RegExp}
 */
function createAssistantCommandRegex() {
    const pattern = assistantFunctionNames.join('|');
    return new RegExp(`\\b(${pattern})\\s*\\(`, 'gi');
}

/**
 * Escape command string for safe parsing
 * @param {string} str - String to escape
 * @returns {string}
 */
function escapeCommandString(str) {
    return str
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"')
        .replace(/'/g, "\\'")
        .replace(/\n/g, '\\n')
        .replace(/\r/g, '\\r')
        .replace(/\t/g, '\\t');
}

/**
 * Unescape command string
 * @param {string} str - String to unescape
 * @returns {string}
 */
function unescapeCommandString(str) {
    return str
        .replace(/\\n/g, '\n')
        .replace(/\\r/g, '\r')
        .replace(/\\t/g, '\t')
        .replace(/\\'/g, "'")
        .replace(/\\"/g, '"')
        .replace(/\\\\/g, '\\');
}

module.exports = {
    processCommands,
    registerCommand,
    unregisterCommand,
    getRegisteredCommands,
    hasCommand,
    getAssistantFunctionNames,
    createAssistantCommandRegex,
    escapeCommandString,
    unescapeCommandString,
    COMMANDS,
    assistantFunctionNames,
};
