const { parseCalendarTime, zonedTimeToUtcDate } = require("./date-time-parsers")

function parseAvailabilityJson(value) {
    if (!value) return null
    try {
        const parsed = JSON.parse(value)
        return parsed && typeof parsed === "object" ? parsed : null
    } catch {
        return null
    }
}

function getWeekdayKey(date, timeZone) {
    const formatter = new Intl.DateTimeFormat("en-US", { timeZone, weekday: "short" })
    const day = formatter.format(date).toLowerCase()
    const map = {
        mon: "mon",
        tue: "tue",
        wed: "wed",
        thu: "thu",
        fri: "fri",
        sat: "sat",
        sun: "sun"
    }
    const normalized = day.slice(0, 3)
    return map[normalized] || normalized
}

function getLocalTimeParts(date, timeZone) {
    const formatter = new Intl.DateTimeFormat("en-US", {
        timeZone,
        hour12: false,
        hour: "2-digit",
        minute: "2-digit"
    })
    const parts = formatter.formatToParts(date)
    const values = Object.fromEntries(parts.map(part => [part.type, part.value]))
    return { hour: Number(values.hour), minute: Number(values.minute) }
}

function toMinutesOfDay({ hour, minute }) {
    return hour * 60 + minute
}

function normalizeAvailability(availability) {
    if (!availability || typeof availability !== "object") {
        return null
    }
    const days = availability.days && typeof availability.days === "object" ? availability.days : null
    if (!days) {
        return null
    }
    return {
        timezone: availability.timezone || null,
        buffer_minutes: Number(availability.buffer_minutes || 0),
        min_notice_minutes: Number(availability.min_notice_minutes || 0),
        step_minutes: Number(availability.step_minutes || 30),
        days
    }
}

function isSlotWithinAvailability(startUtc, endUtc, availability, fallbackTimeZone) {
    const normalized = normalizeAvailability(availability)
    if (!normalized) return true
    const timeZone = normalized.timezone || fallbackTimeZone || "America/Fortaleza"
    const weekday = getWeekdayKey(startUtc, timeZone)
    const windows = normalized.days[weekday] || normalized.days.all || null
    if (!Array.isArray(windows) || windows.length === 0) {
        return false
    }
    const startParts = getLocalTimeParts(startUtc, timeZone)
    const endParts = getLocalTimeParts(endUtc, timeZone)
    const startMinutes = toMinutesOfDay(startParts)
    const endMinutes = toMinutesOfDay(endParts)
    const buffer = Math.max(0, normalized.buffer_minutes || 0)
    if (normalized.min_notice_minutes && startUtc.getTime() < Date.now() + normalized.min_notice_minutes * 60000) {
        return false
    }
    for (const window of windows) {
        let startWindow
        let endWindow
        try {
            startWindow = parseCalendarTime(window.start || "")
            endWindow = parseCalendarTime(window.end || "")
        } catch {
            continue
        }
        const windowStart = toMinutesOfDay(startWindow) + buffer
        const windowEnd = toMinutesOfDay(endWindow) - buffer
        if (startMinutes >= windowStart && endMinutes <= windowEnd) {
            return true
        }
    }
    return false
}

function slotsOverlap(slotStart, slotEnd, busyStart, busyEnd) {
    return slotStart < busyEnd && slotEnd > busyStart
}

function parseWindowRange(windowStr) {
    const trimmed = (windowStr || "").trim()
    const match = /^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/.exec(trimmed)
    if (!match) {
        throw new Error("calendar: janela inválida (use HH:MM-HH:MM)")
    }
    return { start: match[1], end: match[2] }
}


module.exports = {
    parseAvailabilityJson,
    getWeekdayKey,
    getLocalTimeParts,
    toMinutesOfDay,
    normalizeAvailability,
    isSlotWithinAvailability,
    slotsOverlap,
    parseWindowRange
}