/**
 * @fileoverview Calendar service orchestration
 * @module whatsapp-server/calendar
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~1297-1970)
 * Coordinates calendar operations, OAuth, and availability checking
 */

const oauth = require('./oauth');
const availability = require('./availability');

let db = null;
let googleApis = null;
const CALENDAR_TOKEN_SECRET = process.env.CALENDAR_TOKEN_SECRET || "";

/**
 * Initialize calendar module
 * @param {Object} dependencies - Dependencies
 */
function initialize(dependencies) {
    db = dependencies.db || null;
    googleApis = dependencies.googleApis || null;
}

/**
 * Load calendar account from database
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object>}
 */
async function loadCalendarAccount(instanceId) {
    if (!db || typeof db.getCalendarAccount !== "function") {
        throw new Error("calendar: banco indisponível");
    }
    const account = await db.getCalendarAccount(instanceId);
    if (!account || !account.refresh_token) {
        throw new Error("calendar: integração não conectada");
    }
    return account;
}

/**
 * Resolve calendar configuration
 * @param {string} instanceId - Instance ID
 * @param {string|null} calendarIdArg - Calendar ID or null
 * @returns {Promise<Object>}
 */
async function resolveCalendarConfig(instanceId, calendarIdArg) {
    if (!db || typeof db.listCalendarConfigs !== "function") {
        throw new Error("calendar: banco indisponível");
    }
    const calendarId = (calendarIdArg || "").trim();
    const configs = await db.listCalendarConfigs(instanceId);
    let config = null;
    
    if (calendarId) {
        if (calendarId === 'primary') {
            config = configs.find(item => item.is_default) || configs[0] || null;
            if (!config) {
                throw new Error(`calendar: nenhum calendário configurado`);
            }
        } else {
            config = configs.find(item => item.calendar_id === calendarId) || 
                     configs.find(item => item.is_default) || 
                     configs[0] || null;
            if (!config) {
                throw new Error(`calendar: nenhum calendário configurado`);
            }
        }
    } else {
        config = configs.find(item => item.is_default) || configs[0] || null;
    }
    
    if (!config) {
        return {
            calendar_id: "primary",
            timezone: null,
            availability: null
        };
    }
    
    return {
        calendar_id: config.calendar_id,
        timezone: config.timezone || null,
        availability: availability.parseAvailabilityJson(config.availability_json)
    };
}

/**
 * Get calendar service with OAuth
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object>}
 */
async function getCalendarService(instanceId) {
    const account = await loadCalendarAccount(instanceId);
    const oauth2Client = oauth.buildGoogleOAuthClient();
    
    const credentials = {
        refresh_token: oauth.decryptCalendarToken(account.refresh_token),
        access_token: oauth.decryptCalendarToken(account.access_token),
        expiry_date: account.token_expiry || undefined
    };
    
    oauth2Client.setCredentials(credentials);
    
    oauth2Client.on("tokens", async (tokens) => {
        const payload = {
            calendar_email: account.calendar_email || null,
            scope: account.scope || null
        };
        if (tokens.access_token) {
            payload.access_token = oauth.encryptCalendarToken(tokens.access_token);
        }
        if (tokens.refresh_token) {
            payload.refresh_token = oauth.encryptCalendarToken(tokens.refresh_token);
        }
        if (tokens.expiry_date) {
            payload.token_expiry = tokens.expiry_date;
        }
        try {
            await db.upsertCalendarAccount(instanceId, payload);
        } catch (err) {
            console.log("calendar token refresh save error:", err.message);
        }
    });
    
    const calendar = googleApis.google.calendar({ version: "v3", auth: oauth2Client });
    return { calendar, oauth2Client, account };
}

/**
 * Fetch busy slots from calendar
 * @param {Object} calendar - Calendar API
 * @param {string} calendarId - Calendar ID
 * @param {Date} timeMin - Start time
 * @param {Date} timeMax - End time
 * @returns {Promise<Array>}
 */
async function fetchBusySlots(calendar, calendarId, timeMin, timeMax) {
    const response = await calendar.freebusy.query({
        requestBody: {
            timeMin: timeMin.toISOString(),
            timeMax: timeMax.toISOString(),
            items: [{ id: calendarId }]
        }
    });
    const calendars = response.data.calendars || {};
    const busy = calendars[calendarId]?.busy || [];
    return busy.map(entry => ({
        start: new Date(entry.start),
        end: new Date(entry.end)
    }));
}

/**
 * Ensure calendar connection is valid
 * @param {string} instanceId - Instance ID
 */
async function ensureCalendarConnection(instanceId) {
    if (!CALENDAR_TOKEN_SECRET) {
        throw new Error("calendar: CALENDAR_TOKEN_SECRET não configurada");
    }
    if (!googleApis || !googleApis.google) {
        throw new Error("calendar: Google SDK não disponível");
    }
    await loadCalendarAccount(instanceId);
}

/**
 * Check availability for a time slot
 * @param {string} instanceId - Instance ID
 * @param {Date} start - Start time
 * @param {Date} end - End time
 * @param {string|null} calendarIdArg - Calendar ID
 * @param {string|null} timeZoneArg - Timezone
 * @returns {Promise<Object>}
 */
async function checkAvailability(instanceId, start, end, calendarIdArg = null, timeZoneArg = null) {
    const { calendar } = await getCalendarService(instanceId);
    const config = await resolveCalendarConfig(instanceId, calendarIdArg);
    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza";
    const busySlots = await fetchBusySlots(calendar, config.calendar_id, start, end);
    const hasBusy = busySlots.some(entry => 
        availability.slotsOverlap(start, end, entry.start, entry.end)
    );
    const allowed = availability.isSlotWithinAvailability(start, end, config.availability, timeZone);
    
    return {
        available: !hasBusy && allowed,
        timeZone,
        calendarId: config.calendar_id,
        busyCount: busySlots.length,
        busySlots
    };
}

/**
 * Suggest available time slots
 * @param {string} instanceId - Instance ID
 * @param {string} dateArg - Date string
 * @param {string} windowArg - Time window
 * @param {number} durationMinutes - Required duration
 * @param {number} limitArg - Max suggestions
 * @param {string|null} calendarIdArg - Calendar ID
 * @param {string|null} timeZoneArg - Timezone
 * @returns {Promise<Object>}
 */
async function suggestSlots(instanceId, dateArg, windowArg, durationMinutes, limitArg, calendarIdArg = null, timeZoneArg = null) {
    const config = await resolveCalendarConfig(instanceId, calendarIdArg);
    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza";
    const { start: windowStart, end: windowEnd } = availability.parseWindowRange(windowArg);
    const { day, month, year } = availability.parseCalendarDate(dateArg);
    const startTime = availability.parseCalendarTime(windowStart);
    const endTime = availability.parseCalendarTime(windowEnd);
    
    const windowStartDate = availability.zonedTimeToUtcDate(
        { year, month, day, hour: startTime.hour, minute: startTime.minute }, 
        timeZone
    );
    const windowEndDate = availability.zonedTimeToUtcDate(
        { year, month, day, hour: endTime.hour, minute: endTime.minute }, 
        timeZone
    );
    
    if (windowEndDate <= windowStartDate) {
        throw new Error("calendar: janela inválida");
    }
    
    const duration = Math.max(1, Number(durationMinutes || 30));
    const limit = Math.max(1, Number(limitArg || 5));
    const availConfig = config.availability;
    const stepMinutes = availability.normalizeAvailability(availConfig)?.step_minutes || 30;
    
    const { calendar } = await getCalendarService(instanceId);
    const busySlots = await fetchBusySlots(calendar, config.calendar_id, windowStartDate, windowEndDate);
    
    const suggestions = [];
    for (let cursor = new Date(windowStartDate); 
         cursor.getTime() + duration * 60000 <= windowEndDate.getTime(); 
         cursor = new Date(cursor.getTime() + stepMinutes * 60000)) {
        
        const slotStart = new Date(cursor);
        const slotEnd = new Date(cursor.getTime() + duration * 60000);
        
        if (!availability.isSlotWithinAvailability(slotStart, slotEnd, availConfig, timeZone)) {
            continue;
        }
        
        const overlaps = busySlots.some(entry => 
            availability.slotsOverlap(slotStart, slotEnd, entry.start, entry.end)
        );
        if (overlaps) continue;
        
        suggestions.push({
            start: slotStart.toISOString(),
            end: slotEnd.toISOString(),
            local_start: availability.formatDateTimeInTimeZone(slotStart, timeZone),
            local_end: availability.formatDateTimeInTimeZone(slotEnd, timeZone)
        });
        
        if (suggestions.length >= limit) break;
    }
    
    return {
        timeZone,
        calendarId: config.calendar_id,
        durationMinutes: duration,
        suggestions
    };
}

/**
 * Create a calendar event
 * @param {string} instanceId - Instance ID
 * @param {Object} eventData - Event data
 * @returns {Promise<Object>}
 */
async function createEvent(instanceId, eventData) {
    const { title, startArg, endArg, attendeesArg, description = "", calendarIdArg = null, timeZoneArg = null } = eventData;
    
    await ensureCalendarConnection(instanceId);
    const config = await resolveCalendarConfig(instanceId, calendarIdArg);
    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza";
    
    const start = availability.parseCalendarDateTime(startArg, null, timeZone);
    const end = availability.parseCalendarDateTime(endArg, null, timeZone);
    
    if (end <= start) {
        throw new Error("marcar_evento(): horário inválido");
    }
    if (!availability.isSlotWithinAvailability(start, end, config.availability, timeZone)) {
        throw new Error("marcar_evento(): horário fora da disponibilidade configurada");
    }
    
    const { calendar } = await getCalendarService(instanceId);
    const event = {
        summary: title,
        description: description || undefined,
        start: {
            dateTime: availability.formatCalendarDateTime(start, timeZone),
            timeZone
        },
        end: {
            dateTime: availability.formatCalendarDateTime(end, timeZone),
            timeZone
        },
        attendees: availability.parseAttendees(attendeesArg)
    };
    
    const response = await calendar.events.insert({
        calendarId: config.calendar_id,
        requestBody: event
    });
    
    const created = response.data || {};
    return {
        calendarId: config.calendar_id,
        eventId: created.id,
        htmlLink: created.htmlLink || null,
        start: start.toISOString(),
        end: end.toISOString(),
        local_start: availability.formatDateTimeInTimeZone(start, timeZone),
        local_end: availability.formatDateTimeInTimeZone(end, timeZone)
    };
}

/**
 * Update a calendar event
 * @param {string} instanceId - Instance ID
 * @param {string} eventId - Event ID
 * @param {Object} eventData - Updated event data
 * @returns {Promise<Object>}
 */
async function updateEvent(instanceId, eventId, eventData) {
    const { startArg, endArg, calendarIdArg = null, timeZoneArg = null } = eventData;
    
    await ensureCalendarConnection(instanceId);
    const config = await resolveCalendarConfig(instanceId, calendarIdArg);
    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza";
    
    const start = availability.parseCalendarDateTime(startArg, null, timeZone);
    const end = availability.parseCalendarDateTime(endArg, null, timeZone);
    
    if (end <= start) {
        throw new Error("remarcar_evento(): horário inválido");
    }
    if (!availability.isSlotWithinAvailability(start, end, config.availability, timeZone)) {
        throw new Error("remarcar_evento(): horário fora da disponibilidade configurada");
    }
    
    const { calendar } = await getCalendarService(instanceId);
    const response = await calendar.events.patch({
        calendarId: config.calendar_id,
        eventId,
        requestBody: {
            start: { dateTime: availability.formatCalendarDateTime(start, timeZone), timeZone },
            end: { dateTime: availability.formatCalendarDateTime(end, timeZone), timeZone }
        }
    });
    
    const updated = response.data || {};
    return {
        calendarId: config.calendar_id,
        eventId: updated.id,
        htmlLink: updated.htmlLink || null,
        start: start.toISOString(),
        end: end.toISOString(),
        local_start: availability.formatDateTimeInTimeZone(start, timeZone),
        local_end: availability.formatDateTimeInTimeZone(end, timeZone)
    };
}

/**
 * Delete a calendar event
 * @param {string} instanceId - Instance ID
 * @param {string} eventId - Event ID
 * @param {string|null} calendarIdArg - Calendar ID
 * @returns {Promise<Object>}
 */
async function deleteEvent(instanceId, eventId, calendarIdArg = null) {
    await ensureCalendarConnection(instanceId);
    const config = await resolveCalendarConfig(instanceId, calendarIdArg);
    const { calendar } = await getCalendarService(instanceId);
    
    await calendar.events.delete({
        calendarId: config.calendar_id,
        eventId
    });
    
    return {
        calendarId: config.calendar_id,
        eventId
    };
}

/**
 * List calendar events
 * @param {string} instanceId - Instance ID
 * @param {string} startArg - Start date
 * @param {string} endArg - End date
 * @param {string|null} calendarIdArg - Calendar ID
 * @param {string|null} timeZoneArg - Timezone
 * @returns {Promise<Object>}
 */
async function listEvents(instanceId, startArg, endArg, calendarIdArg = null, timeZoneArg = null) {
    await ensureCalendarConnection(instanceId);
    const config = await resolveCalendarConfig(instanceId, calendarIdArg);
    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza";
    
    const start = availability.parseCalendarDateTime(startArg, null, timeZone);
    const end = availability.parseCalendarDateTime(endArg, null, timeZone);
    
    if (end <= start) {
        throw new Error("listar_eventos(): intervalo inválido");
    }
    
    const { calendar } = await getCalendarService(instanceId);
    const response = await calendar.events.list({
        calendarId: config.calendar_id,
        timeMin: start.toISOString(),
        timeMax: end.toISOString(),
        singleEvents: true,
        orderBy: "startTime"
    });
    
    const items = Array.isArray(response.data.items) ? response.data.items : [];
    const events = items.map(item => ({
        id: item.id,
        summary: item.summary || "",
        start: item.start?.dateTime || item.start?.date || null,
        end: item.end?.dateTime || item.end?.date || null,
        htmlLink: item.htmlLink || null
    }));
    
    return {
        calendarId: config.calendar_id,
        timeZone,
        events
    };
}

/**
 * Get authorization URL for OAuth
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object>}
 */
async function getAuthUrl(instanceId) {
    oauth.cleanupExpiredCalendarStates();
    const state = oauth.generateOAuthState(instanceId);
    await oauth.persistPendingCalendarAuth(instanceId, state, db);
    const url = oauth.getAuthUrl(instanceId);
    
    return { instanceId, url, state };
}

/**
 * Handle OAuth callback
 * @param {string} instanceId - Instance ID
 * @param {string} code - Authorization code
 * @param {string} state - OAuth state
 * @returns {Promise<Object>}
 */
async function handleOAuthCallback(instanceId, code, state) {
    const meta = oauth.validateOAuthState(state);
    if (!meta) {
        throw new Error("state inválido ou expirado");
    }
    
    oauth.consumeOAuthState(state);
    const oauth2Client = oauth.buildGoogleOAuthClient();
    const tokens = await oauth.exchangeCodeForTokens(code);
    
    if (!tokens || !tokens.refresh_token) {
        throw new Error("refresh_token ausente. Revogue acesso e reconecte com consentimento.");
    }
    
    oauth2Client.setCredentials(tokens);
    const calendarEmail = await oauth.getUserEmail(oauth2Client);
    
    await db.upsertCalendarAccount(instanceId, {
        calendar_email: calendarEmail,
        access_token: oauth.encryptCalendarToken(tokens.access_token),
        refresh_token: oauth.encryptCalendarToken(tokens.refresh_token),
        token_expiry: tokens.expiry_date || null,
        scope: tokens.scope || null
    });
    
    return { ok: true, instanceId, calendar_email: calendarEmail };
}

/**
 * Disconnect calendar account
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object>}
 */
async function disconnect(instanceId) {
    await db.clearCalendarAccount(instanceId);
    return { ok: true, instanceId };
}

/**
 * Get calendar configuration
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object>}
 */
async function getConfig(instanceId) {
    oauth.cleanupExpiredCalendarStates();
    const account = await db.getCalendarAccount(instanceId);
    let pendingAuth = await oauth.loadPendingCalendarAuth(instanceId, db);
    
    if (pendingAuth?.state && !oauth.calendarOauthStates.has(pendingAuth.state)) {
        pendingAuth = null;
        await oauth.clearPendingCalendarAuth(instanceId, db);
    }
    
    const calendars = await db.listCalendarConfigs(instanceId);
    const normalized = calendars.map(item => ({
        ...item,
        availability: availability.parseAvailabilityJson(item.availability_json)
    }));
    
    return {
        instanceId,
        connected: Boolean(account && account.refresh_token),
        account: account ? { calendar_email: account.calendar_email } : null,
        calendars: normalized,
        pending_auth: pendingAuth ? {
            state: pendingAuth.state,
            createdAt: pendingAuth.createdAt
        } : null
    };
}

/**
 * List Google calendars
 * @param {string} instanceId - Instance ID
 * @returns {Promise<Object>}
 */
async function listGoogleCalendars(instanceId) {
    await ensureCalendarConnection(instanceId);
    const { calendar } = await getCalendarService(instanceId);
    
    const response = await calendar.calendarList.list();
    const items = Array.isArray(response.data.items) ? response.data.items : [];
    const calendars = items.map(item => ({
        id: item.id,
        summary: item.summary || "",
        timezone: item.timeZone || null,
        accessRole: item.accessRole || null,
        primary: Boolean(item.primary)
    }));
    
    return { ok: true, instanceId, calendars };
}

/**
 * Save calendar configuration
 * @param {string} instanceId - Instance ID
 * @param {Object} payload - Calendar config
 * @returns {Promise<Object>}
 */
async function saveCalendarConfig(instanceId, payload) {
    const calendarId = (payload.calendar_id || "").trim();
    if (!calendarId) {
        throw new Error("calendar_id é obrigatório");
    }
    
    const existing = await db.getCalendarConfig(instanceId, calendarId);
    const availability = payload.availability || payload.availability_json || null;
    const availabilityJson = availability ? JSON.stringify(availability) : existing?.availability_json || null;
    const isDefault = payload.is_default === undefined 
        ? (existing?.is_default ? 1 : 0) 
        : (payload.is_default ? 1 : 0);
    
    await db.upsertCalendarConfig(instanceId, calendarId, {
        summary: payload.summary || existing?.summary || null,
        timezone: payload.timezone || existing?.timezone || null,
        availability_json: availabilityJson,
        is_default: isDefault
    });
    
    if (isDefault) {
        await db.setDefaultCalendarConfig(instanceId, calendarId);
    }
    
    return { ok: true, instanceId, calendar_id: calendarId };
}

/**
 * Delete calendar configuration
 * @param {string} instanceId - Instance ID
 * @param {string} calendarId - Calendar ID
 * @returns {Promise<Object>}
 */
async function deleteCalendarConfig(instanceId, calendarId) {
    await db.deleteCalendarConfig(instanceId, calendarId);
    return { ok: true, instanceId, calendar_id: calendarId };
}

/**
 * Set default calendar
 * @param {string} instanceId - Instance ID
 * @param {string} calendarId - Calendar ID
 * @returns {Promise<Object>}
 */
async function setDefaultCalendar(instanceId, calendarId) {
    await db.setDefaultCalendarConfig(instanceId, calendarId);
    return { ok: true, instanceId, calendar_id: calendarId };
}

module.exports = {
    initialize,
    loadCalendarAccount,
    resolveCalendarConfig,
    getCalendarService,
    fetchBusySlots,
    ensureCalendarConnection,
    checkAvailability,
    suggestSlots,
    createEvent,
    updateEvent,
    deleteEvent,
    listEvents,
    getAuthUrl,
    handleOAuthCallback,
    disconnect,
    getConfig,
    listGoogleCalendars,
    saveCalendarConfig,
    deleteCalendarConfig,
    setDefaultCalendar,
    oauth,
    availability
};
