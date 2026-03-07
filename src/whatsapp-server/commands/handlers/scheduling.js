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

/**
 * Schedule a message for exact datetime (agendar3)
 * Uses exact datetime provided (YYYY-MM-DD HH:mm:ss), ignores time until now
 * @param {string} exactTime - Exact datetime in YYYY-MM-DD HH:mm:ss format
 * @param {string} text - Message text
 * @param {string} tag - Tag for the schedule
 * @param {string} tipo - Type of schedule
 * @param {boolean} interno - If true, only log internally without user notification
 * @returns {Promise<Object>}
 */
async function agendar3(exactTime, text, tag = 'default', tipo = 'followup', interno = false) {
    const { instanceId, db, remoteJid } = this || {};
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
    const recipient = remoteJid || null;

    if (!exactTime || !text) {
        return {
            ok: false,
            message: 'Parâmetros obrigatórios: exactTime e text'
        };
    }

    try {
        // Parse exactTime (YYYY-MM-DD HH:mm:ss)
        const scheduledDate = new Date(exactTime.replace(' ', 'T'));
        if (isNaN(scheduledDate.getTime())) {
            return {
                ok: false,
                message: 'Formato de data inválido. Use YYYY-MM-DD HH:mm:ss'
            };
        }

        // Use the exact time provided (ignores if in the past - lets scheduler handle it)
        const scheduledFor = scheduledDate.toISOString().replace('T', ' ').substring(0, 19);

        console.log(`[agendar3] Scheduling for exact time: ${scheduledFor}, interno: ${interno}`);

        const scheduledMessage = await schedulerService.scheduleMessage({
            recipient: recipient,
            message: text,
            scheduledFor: scheduledFor,
            tag: tag || 'default',
            tipo: tipo || 'followup',
            instanceId: currentInstanceId
        });

        const responseData = {
            scheduledId: scheduledMessage.scheduledId,
            scheduledTime: scheduledMessage.scheduledAt || scheduledFor
        };

        // If interno=true, don't show to user, just return internal response
        if (interno) {
            console.log(`[agendar3] Internal schedule created: ${scheduledMessage.scheduledId}`);
            return {
                ok: true,
                message: 'Agendamento interno criado',
                data: responseData
            };
        }

        return {
            ok: true,
            message: `Mensagem agendada para ${scheduledFor}`,
            data: responseData
        };
    } catch (error) {
        console.error('[agendar3] Error scheduling message:', error);
        return {
            ok: false,
            message: `Erro ao agendar: ${error.message}`
        };
    }
}

/**
 * Cancel existing schedules by tag and schedule new message (cancelar_e_agendar3)
 * @param {string} exactTime - Exact datetime in YYYY-MM-DD HH:mm:ss format
 * @param {string} text - Message text
 * @param {string} tag - Tag to cancel and schedule
 * @param {string} tipo - Type of schedule
 * @param {boolean} interno - If true, only log internally without user notification
 * @returns {Promise<Object>}
 */
async function cancelar_e_agendar3(exactTime, text, tag = 'default', tipo = 'followup', interno = false) {
    const { instanceId, db, remoteJid } = this || {};
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';
    const recipient = remoteJid || null;

    if (!exactTime || !text || !tag) {
        return {
            ok: false,
            message: 'Parâmetros obrigatórios: exactTime, text e tag'
        };
    }

    try {
        // First cancel all pending messages with the given tag
        let cancelledCount = 0;
        if (db && typeof db.deleteScheduledMessagesByTag === 'function') {
            const cancelResult = await db.deleteScheduledMessagesByTag(currentInstanceId, recipient, tag);
            cancelledCount = cancelResult?.deleted || 0;
            console.log(`[cancelar_e_agendar3] Cancelled ${cancelledCount} schedules with tag: ${tag}`);
        }

        // Parse exactTime (YYYY-MM-DD HH:mm:ss)
        const scheduledDate = new Date(exactTime.replace(' ', 'T'));
        if (isNaN(scheduledDate.getTime())) {
            return {
                ok: false,
                message: 'Formato de data inválido. Use YYYY-MM-DD HH:mm:ss'
            };
        }

        const scheduledFor = scheduledDate.toISOString().replace('T', ' ').substring(0, 19);

        console.log(`[cancelar_e_agendar3] Scheduling for exact time: ${scheduledFor}, tag: ${tag}, interno: ${interno}`);

        // Schedule new message
        const scheduledMessage = await schedulerService.scheduleMessage({
            recipient: recipient,
            message: text,
            scheduledFor: scheduledFor,
            tag: tag,
            tipo: tipo || 'followup',
            instanceId: currentInstanceId
        });

        const responseData = {
            cancelledCount: cancelledCount,
            scheduledId: scheduledMessage.scheduledId,
            scheduledTime: scheduledMessage.scheduledAt || scheduledFor
        };

        // If interno=true, don't show to user
        if (interno) {
            console.log(`[cancelar_e_agendar3] Internal reschedule created: ${scheduledMessage.scheduledId}`);
            return {
                ok: true,
                message: 'Reagendamento interno realizado',
                data: responseData
            };
        }

        return {
            ok: true,
            message: `Cancelados ${cancelledCount} agendamento(s) e criado novo para ${scheduledFor}`,
            data: responseData
        };
    } catch (error) {
        console.error('[cancelar_e_agendar3] Error:', error);
        return {
            ok: false,
            message: `Erro ao reagendar: ${error.message}`
        };
    }
}

/**
 * Delete all scheduled messages by type (apagar_agendas_por_tipo)
 * @param {string} tipo - Type of schedules to delete
 * @param {boolean} interno - If true, only log internally without user notification
 * @returns {Promise<Object>}
 */
async function apagar_agendas_por_tipo(tipo, interno = false) {
    const { instanceId, db } = this || {};
    const currentInstanceId = instanceId || global.INSTANCE_ID || 'default';

    if (!tipo) {
        return {
            ok: false,
            message: 'Parâmetro obrigatório: tipo'
        };
    }

    try {
        let deletedCount = 0;
        
        if (db && typeof db.deleteScheduledMessagesByTipo === 'function') {
            const result = await db.deleteScheduledMessagesByTipo(currentInstanceId, null, tipo);
            deletedCount = result?.deleted || 0;
            console.log(`[apagar_agendas_por_tipo] Deleted ${deletedCount} schedules of tipo: ${tipo}`);
        } else {
            // Fallback: use schedulerService if db method not available
            const scheduledMessages = await schedulerService.listScheduledMessages({
                tipo: tipo
            });
            
            for (const msg of scheduledMessages) {
                try {
                    await schedulerService.cancelScheduledMessage(msg.id);
                    deletedCount++;
                } catch (e) {
                    console.warn(`[apagar_agendas_por_tipo] Failed to delete ${msg.id}:`, e.message);
                }
            }
        }

        const responseData = {
            deletedCount: deletedCount,
            tipo: tipo
        };

        // If interno=true, don't show to user
        if (interno) {
            console.log(`[apagar_agendas_por_tipo] Internal deletion of tipo ${tipo}: ${deletedCount}`);
            return {
                ok: true,
                message: 'Exclusão interna realizada',
                data: responseData
            };
        }

        return {
            ok: true,
            message: `Excluído(s) ${deletedCount} agendamento(s) do tipo "${tipo}"`,
            data: responseData
        };
    } catch (error) {
        console.error('[apagar_agendas_por_tipo] Error:', error);
        return {
            ok: false,
            message: `Erro ao excluir agendamentos: ${error.message}`
        };
    }
}

module.exports = {
    agendar,
    agendar2,
    agendar3,
    cancelar,
    cancelar_e_agendar3,
    listarAgendamentos,
    apagarAgenda,
    apagarAgendasPorTag,
    apagar_agendas_por_tipo,
    cancelarEAgendar2,
    verificarDisponibilidade,
    sugerirHorarios,
};
