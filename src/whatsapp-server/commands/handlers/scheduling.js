/**
 * @fileoverview Scheduling command handler - schedules WhatsApp messages
 * @module whatsapp-server/commands/handlers/scheduling
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles agendar, agendar2, and cancelar commands
 */

const schedulerService = require('../../scheduler');
const dbModule = require('../../../../db-updated');

/**
 * Schedule a message (agendar command)
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function agendar(args, context = {}) {
    const { destinatario, mensagem, data, hora, tag } = args;
    const { instanceId, db } = context;

    if (!destinatario || !mensagem || !data || !hora) {
        throw new Error('Parâmetros obrigatórios: destinatario, mensagem, data, hora');
    }

    try {
        const scheduledMessage = await schedulerService.scheduleMessage({
            recipient: destinatario,
            message: mensagem,
            scheduledFor: `${data}T${hora}:00`,
            tag: tag || null,
            instanceId: instanceId || 'default'
        });

        console.log('[Scheduling] Mensagem agendada:', scheduledMessage.id);

        return {
            success: true,
            scheduledId: scheduledMessage.id,
            scheduledFor: scheduledMessage.scheduledFor,
            message: 'Mensagem agendada com sucesso'
        };
    } catch (error) {
        console.error('[Scheduling] Error scheduling message:', error);
        throw error;
    }
}

/**
 * Schedule a message with confirmation (agendar2 command)
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function agendar2(args, context = {}) {
    const { destinatario, mensagem, texto, data, hora, tempo, confirmar = true, tag, tipo, interno = false } = args;
    const { instanceId, db, remoteJid } = context;

    // Support both 'mensagem' and 'texto' parameter names
    const messageText = mensagem || texto;
    
    // If no destinatario provided, try to use remoteJid from context
    const recipient = destinatario || remoteJid;

    // Support relative time (e.g., "+1h", "+24h", "+5m")
    let finalData = data;
    let finalHora = hora;
    
    if (tempo && !data && !hora) {
        // Parse relative time
        const scheduler = require('../../scheduler');
        const parsedDate = scheduler.parseRelativeTime(tempo);
        if (parsedDate) {
            finalData = parsedDate.toISOString().split('T')[0]; // YYYY-MM-DD
            finalHora = parsedDate.toTimeString().substring(0, 5); // HH:MM
            console.log('[Scheduling] Parsed relative time:', tempo, '->', finalData, finalHora);
        }
    }

    if (!recipient || !messageText || !finalData || !finalHora) {
        throw new Error('Parâmetros obrigatórios: destinatario, mensagem, data, hora (ou tempo como +1h)');
    }

    try {
        // First check availability
        const availability = await schedulerService.checkAvailability(data, hora);

        if (!availability.available) {
            return {
                success: false,
                message: 'Horário não disponível',
                suggestedSlots: availability.suggestions || []
            };
        }

        const scheduledMessage = await schedulerService.scheduleMessage({
            recipient: recipient,
            message: messageText,
            scheduledFor: `${finalData}T${finalHora}:00`,
            requireConfirmation: confirmar,
            tag: tag || null,
            instanceId: instanceId || 'default'
        });

        console.log('[Scheduling] Mensagem agendada (agendar2):', scheduledMessage.id);

        return {
            success: true,
            scheduledId: scheduledMessage.id,
            scheduledFor: scheduledMessage.scheduledFor,
            message: confirmar !== false
                ? 'Mensagem agendada. Você receberá uma confirmação.'
                : 'Mensagem agendada com sucesso'
        };
    } catch (error) {
        console.error('[Scheduling] Error scheduling message (agendar2):', error);
        throw error;
    }
}

/**
 * Cancel a scheduled message
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function cancelar(args, context = {}) {
    const { mensagem_id } = args;
    const { instanceId } = context;

    if (!mensagem_id) {
        throw new Error('ID da mensagem é obrigatório');
    }

    try {
        await schedulerService.cancelScheduledMessage(mensagem_id, instanceId || 'default');

        console.log('[Scheduling] Mensagem cancelada:', mensagem_id);

        return {
            success: true,
            message: 'Mensagem cancelada com sucesso'
        };
    } catch (error) {
        console.error('[Scheduling] Error canceling scheduled message:', error);
        throw error;
    }
}

/**
 * List scheduled messages
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function listarAgendamentos(args, context = {}) {
    const { instanceId } = context;

    try {
        const agendamentos = await dbModule.listScheduledMessages(instanceId || 'default');
        return {
            ok: true,
            agendamentos: agendamentos || [],
            total: agendamentos?.length || 0
        };
    } catch (error) {
        console.error('[Scheduling] Error listing schedules:', error.message);
        return { ok: false, error: error.message };
    }
}

/**
 * Delete a scheduled message by ID
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function apagarAgenda(args, context = {}) {
    const { mensagem_id } = args;
    const { instanceId } = context;

    if (!mensagem_id) {
        throw new Error('ID da mensagem é obrigatório');
    }

    try {
        await schedulerService.deleteScheduledMessage(mensagem_id, instanceId || 'default');

        return {
            success: true,
            message: 'Agendamento excluído com sucesso'
        };
    } catch (error) {
        console.error('[Scheduling] Error deleting scheduled message:', error);
        throw error;
    }
}

/**
 * Delete scheduled messages by tag
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function apagarAgendasPorTag(args, context = {}) {
    const { tag } = args;
    const { instanceId } = context;

    if (!tag) {
        throw new Error('Tag é obrigatória');
    }

    try {
        const deleted = await schedulerService.deleteScheduledMessagesByTag(tag, instanceId || 'default');

        return {
            success: true,
            deletedCount: deleted,
            message: `${deleted} agendamento(s) excluído(s) com a tag "${tag}"`
        };
    } catch (error) {
        console.error('[Scheduling] Error deleting by tag:', error);
        throw error;
    }
}

/**
 * Cancel existing and schedule new message
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function cancelarEAgendar2(args, context = {}) {
    const { destinatario, mensagem, tempo, data, hora, tag, tipo, confirmar = true } = args;
    const { instanceId, db, remoteJid } = context;
    
    const recipient = destinatario || remoteJid;
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';

    if (!recipient || !mensagem) {
        throw new Error('Parâmetros obrigatórios: destinatario (ou remoteJid) e mensagem');
    }

    try {
        // First cancel all pending messages for this recipient
        let canceledCount = 0;
        if (db && typeof db.markPendingScheduledMessagesFailed === 'function') {
            const result = await db.markPendingScheduledMessagesFailed(currentInstanceId, recipient, "cancelar_e_agendar2");
            canceledCount = result?.changes || result?.affectedRows || 0;
        }

        // Parse relative time or use absolute
        let finalData = data;
        let finalHora = hora;
        
        if (tempo && !data && !hora) {
            const scheduler = require('../../scheduler');
            const parsedDate = scheduler.parseRelativeTime(tempo);
            if (parsedDate) {
                finalData = parsedDate.toISOString().split('T')[0];
                finalHora = parsedDate.toTimeString().substring(0, 5);
            }
        }

        if (!finalData || !finalHora) {
            throw new Error('Data e hora (ou tempo como +1h) são obrigatórios');
        }

        // Then schedule the new one
        const scheduledMessage = await schedulerService.scheduleMessage({
            recipient: recipient,
            message: mensagem,
            scheduledFor: `${finalData}T${finalHora}:00`,
            tag: tag || 'default',
            tipo: tipo || 'fixed',
            instanceId: currentInstanceId,
            requireConfirmation: confirmar
        });

        return {
            success: true,
            canceledCount: canceledCount,
            newScheduledId: scheduledMessage.id,
            message: `Cancelados ${canceledCount} agendamento(s) e criado novo para ${finalData} ${finalHora}`
        };
    } catch (error) {
        console.error('[Scheduling] Error in cancelarEAgendar2:', error);
        throw error;
    }
}

/**
 * Check availability for scheduling
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function verificarDisponibilidade(args, context = {}) {
    const { data, hora } = args;

    if (!data || !hora) {
        throw new Error('Data e hora são obrigatórias');
    }

    try {
        const availability = await schedulerService.checkAvailability(data, hora);

        return {
            success: true,
            available: availability.available,
            message: availability.available
                ? 'Horário disponível'
                : 'Horário não disponível',
            details: availability
        };
    } catch (error) {
        console.error('[Scheduling] Error checking availability:', error);
        throw error;
    }
}

/**
 * Suggest available time slots
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function sugerirHorarios(args, context = {}) {
    const { data, quantidade = 5 } = args;

    if (!data) {
        throw new Error('Data é obrigatória');
    }

    try {
        const suggestions = await schedulerService.suggestAvailableSlots({
            date: data,
            quantity: parseInt(quantidade, 10) || 5,
            instanceId: context.instanceId || 'default'
        });

        return {
            success: true,
            count: suggestions.length,
            suggestions: suggestions,
            message: `${suggestions.length} horários sugeridos`
        };
    } catch (error) {
        console.error('[Scheduling] Error suggesting slots:', error);
        throw error;
    }
}

module.exports = {
    agendar,
    agendar2,
    cancelar,
    listarAgendamentos,
    apagarAgenda,
    apagarAgendasPorTag,
    cancelarEAgendar2,
    verificarDisponibilidade,
    sugerirHorarios,
};
