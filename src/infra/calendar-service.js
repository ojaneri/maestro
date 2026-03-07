const { googleApis } = require("googleapis")
const { GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET, GOOGLE_OAUTH_REDIRECT_URL, CALENDAR_TOKEN_SECRET, GOOGLE_CALENDAR_SCOPES } = require("../config/config")
const { encryptCalendarToken, decryptCalendarToken, parseCalendarSlotArgs, parseWindowRange, zonedTimeToUtcDate, parseCalendarDate, parseCalendarTime, formatDateTimeInTimeZone, formatCalendarDateTime, isSlotWithinAvailability, slotsOverlap } = require("../utils/utils")
const { log } = require("../config/config")

function assertCalendarSdk() {
    if (!googleApis || !googleApis.google) {
        throw new Error("Google Calendar SDK não instalado")
    }
    if (!GOOGLE_OAUTH_CLIENT_ID || !GOOGLE_OAUTH_CLIENT_SECRET) {
        throw new Error("Credenciais Google OAuth ausentes (GOOGLE_OAUTH_CLIENT_ID/SECRET)")
    }
    if (!GOOGLE_OAUTH_REDIRECT_URL) {
        throw new Error("GOOGLE_OAUTH_REDIRECT_URL não configurada")
    }
}

function buildGoogleOAuthClient() {
    assertCalendarSdk()
    return new googleApis.google.auth.OAuth2(
        GOOGLE_OAUTH_CLIENT_ID,
        GOOGLE_OAUTH_CLIENT_SECRET,
        GOOGLE_OAUTH_REDIRECT_URL
    )
}

async function loadCalendarAccount(instanceId) {
    // Placeholder, needs db
    throw new Error("loadCalendarAccount needs db integration")
}

async function resolveCalendarConfig(instanceId, calendarIdArg) {
    // Placeholder
    throw new Error("resolveCalendarConfig needs db integration")
}

async function getCalendarService(instanceId) {
    const account = await loadCalendarAccount(instanceId)
    const oauth2Client = buildGoogleOAuthClient()
    const credentials = {
        refresh_token: decryptCalendarToken(account.refresh_token),
        access_token: decryptCalendarToken(account.access_token),
        expiry_date: account.token_expiry || undefined
    }
    oauth2Client.setCredentials(credentials)
    oauth2Client.on("tokens", async tokens => {
        const payload = {
            calendar_email: account.calendar_email || null,
            scope: account.scope || null
        }
        if (tokens.access_token) {
            payload.access_token = encryptCalendarToken(tokens.access_token)
        }
        if (tokens.refresh_token) {
            payload.refresh_token = encryptCalendarToken(tokens.refresh_token)
        }
        if (tokens.expiry_date) {
            payload.token_expiry = tokens.expiry_date
        }
        try {
            // await db.upsertCalendarAccount(instanceId, payload)
        } catch (err) {
            log("calendar token refresh save error:", err.message)
        }
    })
    const calendar = googleApis.google.calendar({ version: "v3", auth: oauth2Client })
    return { calendar, oauth2Client, account }
}

async function fetchBusySlots(calendar, calendarId, timeMin, timeMax) {
    const response = await calendar.freebusy.query({
        requestBody: {
            timeMin: timeMin.toISOString(),
            timeMax: timeMax.toISOString(),
            items: [{ id: calendarId }]
        }
    })
    const calendars = response.data.calendars || {}
    const busy = calendars[calendarId]?.busy || []
    return busy.map(entry => ({
        start: new Date(entry.start),
        end: new Date(entry.end)
    }))
}

async function ensureCalendarConnection(instanceId) {
    if (!CALENDAR_TOKEN_SECRET) {
        throw new Error("calendar: CALENDAR_TOKEN_SECRET não configurada")
    }
    if (!googleApis || !googleApis.google) {
        throw new Error("calendar: Google SDK não disponível")
    }
    await loadCalendarAccount(instanceId)
}

async function calendarCheckAvailability(instanceId, start, end, calendarIdArg, timeZoneArg) {
    const { calendar } = await getCalendarService(instanceId)
    const config = await resolveCalendarConfig(instanceId, calendarIdArg)
    const timeZone = timeZoneArg || config.timezone || "America/Sao_Paulo"
    const busySlots = await fetchBusySlots(calendar, config.calendar_id, start, end)
    const hasBusy = busySlots.some(entry => slotsOverlap(start, end, entry.start, entry.end))
    const allowed = isSlotWithinAvailability(start, end, config.availability, timeZone)
    return {
        available: !hasBusy && allowed,
        timeZone,
        calendarId: config.calendar_id,
        busyCount: busySlots.length,
        busySlots
    }
}

async function calendarSuggestSlots(instanceId, dateArg, windowArg, durationMinutes, limitArg, calendarIdArg, timeZoneArg) {
    const config = await resolveCalendarConfig(instanceId, calendarIdArg)
    const timeZone = timeZoneArg || config.timezone || "America/Sao_Paulo"
    const { start: windowStart, end: windowEnd } = parseWindowRange(windowArg)
    const { day, month, year } = parseCalendarDate(dateArg)
    const startTime = parseCalendarTime(windowStart)
    const endTime = parseCalendarTime(windowEnd)
    const windowStartDate = zonedTimeToUtcDate({ year, month, day, hour: startTime.hour, minute: startTime.minute }, timeZone)
    const windowEndDate = zonedTimeToUtcDate({ year, month, day, hour: endTime.hour, minute: endTime.minute }, timeZone)
    if (windowEndDate <= windowStartDate) {
        throw new Error("calendar: janela inválida")
    }
    const duration = Math.max(1, Number(durationMinutes || 30))
    const limit = Math.max(1, Number(limitArg || 5))
    const availability = config.availability
    const stepMinutes = availability?.step_minutes || 30
    const { calendar } = await getCalendarService(instanceId)
    const busySlots = await fetchBusySlots(calendar, config.calendar_id, windowStartDate, windowEndDate)
    const suggestions = []
    for (let cursor = new Date(windowStartDate); cursor.getTime() + duration * 60000 <= windowEndDate.getTime(); cursor = new Date(cursor.getTime() + stepMinutes * 60000)) {
        const slotStart = new Date(cursor)
        const slotEnd = new Date(cursor.getTime() + duration * 60000)
        if (!isSlotWithinAvailability(slotStart, slotEnd, availability, timeZone)) {
            continue
        }
        const overlaps = busySlots.some(entry => slotsOverlap(slotStart, slotEnd, entry.start, entry.end))
        if (overlaps) {
            continue
        }
        suggestions.push({
            start: slotStart.toISOString(),
            end: slotEnd.toISOString(),
            local_start: formatDateTimeInTimeZone(slotStart, timeZone),
            local_end: formatDateTimeInTimeZone(slotEnd, timeZone)
        })
        if (suggestions.length >= limit) {
            break
        }
    }
    return {
        timeZone,
        calendarId: config.calendar_id,
        durationMinutes: duration,
        suggestions
    }
}

function parseAttendees(input) {
    if (!input) return []
    const raw = String(input)
    const parts = raw.split(/[,;]+/).map(item => item.trim()).filter(Boolean)
    return parts.map(email => ({ email }))
}

async function calendarCreateEvent(instanceId, title, startArg, endArg, attendeesArg, description, calendarIdArg, timeZoneArg) {
    await ensureCalendarConnection(instanceId)
    const config = await resolveCalendarConfig(instanceId, calendarIdArg)
    const timeZone = timeZoneArg || config.timezone || "America/Sao_Paulo"
    const start = parseCalendarDateTime(startArg, null, timeZone)
    const end = parseCalendarDateTime(endArg, null, timeZone)
    if (end <= start) {
        throw new Error("calendar: horário inválido")
    }
    if (!isSlotWithinAvailability(start, end, config.availability, timeZone)) {
        throw new Error("calendar: horário fora da disponibilidade configurada")
    }
    const { calendar } = await getCalendarService(instanceId)
    const event = {
        summary: title,
        description: description || undefined,
        start: {
            dateTime: formatCalendarDateTime(start, timeZone),
            timeZone
        },
        end: {
            dateTime: formatCalendarDateTime(end, timeZone),
            timeZone
        },
        attendees: parseAttendees(attendeesArg)
    }
    const response = await calendar.events.insert({
        calendarId: config.calendar_id,
        requestBody: event
    })
    const created = response.data || {}
    return {
        calendarId: config.calendar_id,
        eventId: created.id,
        htmlLink: created.htmlLink || null,
        start: start.toISOString(),
        end: end.toISOString(),
        local_start: formatDateTimeInTimeZone(start, timeZone),
        local_end: formatDateTimeInTimeZone(end, timeZone)
    }
}

async function calendarUpdateEvent(instanceId, eventId, startArg, endArg, calendarIdArg, timeZoneArg) {
    await ensureCalendarConnection(instanceId)
    const config = await resolveCalendarConfig(instanceId, calendarIdArg)
    const timeZone = timeZoneArg || config.timezone || "America/Sao_Paulo"
    const start = parseCalendarDateTime(startArg, null, timeZone)
    const end = parseCalendarDateTime(endArg, null, timeZone)
    if (end <= start) {
        throw new Error("calendar: horário inválido")
    }
    if (!isSlotWithinAvailability(start, end, config.availability, timeZone)) {
        throw new Error("calendar: horário fora da disponibilidade configurada")
    }
    const { calendar } = await getCalendarService(instanceId)
    const response = await calendar.events.patch({
        calendarId: config.calendar_id,
        eventId,
        requestBody: {
            start: { dateTime: formatCalendarDateTime(start, timeZone), timeZone },
            end: { dateTime: formatCalendarDateTime(end, timeZone), timeZone }
        }
    })
    const updated = response.data || {}
    return {
        calendarId: config.calendar_id,
        eventId: updated.id,
        htmlLink: updated.htmlLink || null,
        start: start.toISOString(),
        end: end.toISOString(),
        local_start: formatDateTimeInTimeZone(start, timeZone),
        local_end: formatDateTimeInTimeZone(end, timeZone)
    }
}

async function calendarDeleteEvent(instanceId, eventId, calendarIdArg) {
    await ensureCalendarConnection(instanceId)
    const config = await resolveCalendarConfig(instanceId, calendarIdArg)
    const { calendar } = await getCalendarService(instanceId)
    await calendar.events.delete({
        calendarId: config.calendar_id,
        eventId
    })
    return {
        calendarId: config.calendar_id,
        eventId
    }
}

async function calendarListEvents(instanceId, startArg, endArg, calendarIdArg, timeZoneArg) {
    await ensureCalendarConnection(instanceId)
    const config = await resolveCalendarConfig(instanceId, calendarIdArg)
    const timeZone = timeZoneArg || config.timezone || "America/Sao_Paulo"
    const start = parseCalendarDateTime(startArg, null, timeZone)
    const end = parseCalendarDateTime(endArg, null, timeZone)
    if (end <= start) {
        throw new Error("calendar: intervalo inválido")
    }
    const { calendar } = await getCalendarService(instanceId)
    const response = await calendar.events.list({
        calendarId: config.calendar_id,
        timeMin: start.toISOString(),
        timeMax: end.toISOString(),
        singleEvents: true,
        orderBy: "startTime"
    })
    const items = Array.isArray(response.data.items) ? response.data.items : []
    const events = items.map(item => ({
        id: item.id,
        summary: item.summary || "",
        start: item.start?.dateTime || item.start?.date || null,
        end: item.end?.dateTime || item.end?.date || null,
        htmlLink: item.htmlLink || null
    }))
    return {
        calendarId: config.calendar_id,
        timeZone,
        events
    }
}

module.exports = {
    assertCalendarSdk,
    buildGoogleOAuthClient,
    loadCalendarAccount,
    resolveCalendarConfig,
    getCalendarService,
    fetchBusySlots,
    ensureCalendarConnection,
    calendarCheckAvailability,
    calendarSuggestSlots,
    calendarCreateEvent,
    calendarUpdateEvent,
    calendarDeleteEvent,
    calendarListEvents
}