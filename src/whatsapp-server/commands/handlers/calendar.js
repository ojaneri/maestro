/**
 * @fileoverview Calendar command handler - CRUD operations for calendar events
 * @module whatsapp-server/commands/handlers/calendar
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Handles calendar event creation, listing, and cancellation via Google Calendar
 */

const calendarService = require('../../calendar');

/**
 * Create calendar event
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function marcarEvento(args, context = {}) {
    const { 
        titulo, 
        descricao, 
        data, 
        hora, 
        duracao, 
        participantes,
        timezone = 'America/Sao_Paulo'
    } = args;
    
    const { db, instanceId } = context;

    if (!titulo) {
        throw new Error('Título do evento é obrigatório');
    }

    if (!data || !hora) {
        throw new Error('Data e hora do evento são obrigatórios');
    }

    try {
        // Build start datetime
        const startDateTime = `${data}T${hora}:00`;
        
        const event = await calendarService.createEvent({
            summary: titulo,
            description: descricao || '',
            start: startDateTime,
            duration: duracao || 60,
            attendees: participantes ? (Array.isArray(participantes) ? participantes : [participantes]) : [],
            timezone,
            instanceId: instanceId || 'default'
        });

        console.log('[Calendar] Evento criado:', event.id);

        return {
            success: true,
            eventId: event.id,
            htmlLink: event.htmlLink,
            message: `Evento "${titulo}" criado com sucesso para ${data} às ${hora}`
        };
    } catch (error) {
        console.error('[Calendar] Error creating event:', error);
        throw error;
    }
}

/**
 * Reschedule calendar event
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function remarcarEvento(args, context = {}) {
    const { evento_id, nova_data, nova_hora, titulo, descricao } = args;
    
    if (!evento_id) {
        throw new Error('ID do evento é obrigatório');
    }

    if (!nova_data || !nova_hora) {
        throw new Error('Nova data e hora são obrigatórias');
    }

    try {
        // First get the existing event
        const existingEvent = await calendarService.getEvent(evento_id, context.instanceId);
        
        if (!existingEvent) {
            throw new Error('Evento não encontrado');
        }

        // Build update payload
        const updateData = {
            summary: titulo || existingEvent.summary,
            description: descricao || existingEvent.description,
            start: `${nova_data}T${nova_hora}:00`,
            duration: existingEvent.duration || 60
        };

        const updatedEvent = await calendarService.updateEvent(evento_id, updateData, context.instanceId);

        console.log('[Calendar] Evento remarcado:', updatedEvent.id);

        return {
            success: true,
            eventId: updatedEvent.id,
            message: `Evento remarcado para ${nova_data} às ${nova_hora}`
        };
    } catch (error) {
        console.error('[Calendar] Error rescheduling event:', error);
        throw error;
    }
}

/**
 * Cancel/delete calendar event
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function desmarcarEvento(args, context = {}) {
    const { evento_id } = args;
    
    if (!evento_id) {
        throw new Error('ID do evento é obrigatório');
    }

    try {
        await calendarService.deleteEvent(evento_id, context.instanceId);

        console.log('[Calendar] Evento cancelado:', evento_id);

        return {
            success: true,
            eventId: evento_id,
            message: 'Evento cancelado com sucesso'
        };
    } catch (error) {
        console.error('[Calendar] Error canceling event:', error);
        throw error;
    }
}

/**
 * List calendar events
 * @param {Object} args - Command arguments
 * @param {Object} context - Command context
 * @returns {Promise<Object>}
 */
async function listarEventos(args, context = {}) {
    const { data_inicio, data_fim, limite = 10 } = args;
    const { instanceId } = context;

    try {
        const options = {
            maxResults: parseInt(limite, 10) || 10,
            timeMin: data_inicio || new Date().toISOString(),
            timeMax: data_fim || null,
            instanceId: instanceId || 'default'
        };

        const events = await calendarService.listEvents(options);

        const formattedEvents = events.map((event, index) => ({
            index: index + 1,
            id: event.id,
            title: event.summary,
            start: event.start?.dateTime || event.start?.date,
            end: event.end?.dateTime || event.end?.date,
            description: event.description
        }));

        return {
            success: true,
            count: events.length,
            events: formattedEvents,
            message: events.length > 0 
                ? `Encontrados ${events.length} eventos`
                : 'Nenhum evento encontrado'
        };
    } catch (error) {
        console.error('[Calendar] Error listing events:', error);
        throw error;
    }
}

/**
 * Get single event details
 * @param {string} eventId - Event ID
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object|null>}
 */
async function getEvent(eventId, instanceId) {
    try {
        const events = await calendarService.listEvents({
            instanceId: instanceId || 'default',
            maxResults: 100,
            timeMin: new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString(),
            timeMax: new Date(Date.now() + 365 * 24 * 60 * 60 * 1000).toISOString()
        });

        return events.find(e => e.id === eventId) || null;
    } catch (error) {
        console.error('[Calendar] Error getting event:', error);
        return null;
    }
}

module.exports = {
    marcarEvento,
    remarcarEvento,
    desmarcarEvento,
    listarEventos,
    getEvent,
};
