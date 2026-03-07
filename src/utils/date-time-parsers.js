const { SCHEDULE_TIMEZONE_OFFSET_HOURS, SCHEDULE_TIMEZONE_LABEL } = require("../config/globals")

function parseCalendarDate(dateStr) {
    const trimmed = (dateStr || "").trim()
    if (!trimmed) {
        throw new Error("calendar: data obrigatória")
    }
    let match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(trimmed)
    if (match) {
        const day = Number(match[1])
        const month = Number(match[2])
        const year = Number(match[3])
        return { day, month, year }
    }
    match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(trimmed)
    if (match) {
        const year = Number(match[1])
        const month = Number(match[2])
        const day = Number(match[3])
        return { day, month, year }
    }
    throw new Error("calendar: data inválida (use DD/MM/AAAA ou AAAA-MM-DD)")
}

function parseCalendarTime(timeStr) {
    const trimmed = (timeStr || "").trim()
    if (!trimmed) {
        throw new Error("calendar: hora obrigatória")
    }
    const match = /^(\d{2}):(\d{2})$/.exec(trimmed)
    if (!match) {
        throw new Error("calendar: hora inválida (use HH:MM)")
    }
    const hour = Number(match[1])
    const minute = Number(match[2])
    if (!Number.isFinite(hour) || !Number.isFinite(minute) || hour < 0 || hour > 23 || minute < 0 || minute > 59) {
        throw new Error("calendar: hora inválida")
    }
    return { hour, minute }
}

function getTimeZoneOffsetMs(date, timeZone) {
    const dtf = new Intl.DateTimeFormat("en-US", {
        timeZone,
        hour12: false,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
    })
    const parts = dtf.formatToParts(date)
    const values = Object.fromEntries(parts.map(part => [part.type, part.value]))
    const asUtc = Date.UTC(
        Number(values.year),
        Number(values.month) - 1,
        Number(values.day),
        Number(values.hour),
        Number(values.minute),
        Number(values.second)
    )
    return asUtc - date.getTime()
}

function zonedTimeToUtcDate({ year, month, day, hour, minute, second = 0 }, timeZone) {
    const utcDate = new Date(Date.UTC(year, month - 1, day, hour, minute, second))
    const offsetMs = getTimeZoneOffsetMs(utcDate, timeZone)
    return new Date(utcDate.getTime() - offsetMs)
}

function parseCalendarDateTime(dateArg, timeArg, timeZone) {
    const trimmed = (dateArg || "").trim()
    if (!trimmed) {
        throw new Error("calendar: data/hora obrigatória")
    }
    const hasTime = /\d{2}:\d{2}/.test(trimmed)
    if (hasTime && !timeArg) {
        let match = /^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})$/.exec(trimmed)
        if (match) {
            const day = Number(match[1])
            const month = Number(match[2])
            const year = Number(match[3])
            const hour = Number(match[4])
            const minute = Number(match[5])
            const tz = timeZone || "America/Fortaleza"
            return zonedTimeToUtcDate({ year, month, day, hour, minute }, tz)
        }
        match = /^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})$/.exec(trimmed)
        if (match) {
            const year = Number(match[1])
            const month = Number(match[2])
            const day = Number(match[3])
            const hour = Number(match[4])
            const minute = Number(match[5])
            const tz = timeZone || "America/Fortaleza"
            return zonedTimeToUtcDate({ year, month, day, hour, minute }, tz)
        }
        const parsed = new Date(trimmed)
        if (!Number.isNaN(parsed.getTime())) {
            return parsed
        }
    }
    const { day, month, year } = parseCalendarDate(trimmed)
    const { hour, minute } = parseCalendarTime(timeArg)
    const tz = timeZone || "America/Fortaleza"
    return zonedTimeToUtcDate({ year, month, day, hour, minute }, tz)
}

function parseScheduleDate(dateStr) {
    const raw = (dateStr || "").trim()
    if (!raw) {
        throw new Error("agendar(): data obrigatória")
    }
    const parts = raw.split(/[\/-]/).map(part => part.trim())
    if (parts.length !== 3) {
        throw new Error("agendar(): data deve estar no formato DD/MM/AAAA ou AAAA-MM-DD")
    }

    let day, month, year
    if (/^\d{4}$/.test(parts[0])) {
        year = Number(parts[0])
        month = Number(parts[1])
        day = Number(parts[2])
    } else {
        day = Number(parts[0])
        month = Number(parts[1])
        year = Number(parts[2])
        if (year > 0 && year < 100) {
            year += 2000
        }
    }

    if (![day, month, year].every(Number.isFinite)) {
        throw new Error("agendar(): data inválida")
    }
    if (month < 1 || month > 12) {
        throw new Error("agendar(): mês inválido")
    }
    const maxDay = new Date(year, month, 0).getDate()
    if (day < 1 || day > maxDay) {
        throw new Error("agendar(): dia inválido")
    }

    return { day, month, year }
}

function parseScheduleTime(timeStr) {
    const raw = (timeStr || "").trim()
    if (!raw) {
        throw new Error("agendar(): hora obrigatória")
    }
    const parts = raw.split(":").map(part => part.trim())
    if (parts.length < 2) {
        throw new Error("agendar(): hora deve ser no formato HH:MM")
    }

    const hour = Number(parts[0])
    const minute = Number(parts[1])
    if (!Number.isFinite(hour) || !Number.isFinite(minute)) {
        throw new Error("agendar(): hora inválida")
    }
    if (hour < 0 || hour > 23 || minute < 0 || minute > 59) {
        throw new Error("agendar(): hora deve ter valores entre 00:00 e 23:59")
    }

    return { hour, minute }
}

function parseRelativeToken(value) {
    const trimmed = (value || "").trim()
    const match = /^([+-])\s*(\d+)\s*([mhd])$/i.exec(trimmed)
    if (!match) {
        return null
    }
    const [, sign, amount, unit] = match
    const multiplier = {
        m: 60 * 1000,
        h: 60 * 60 * 1000,
        d: 24 * 60 * 60 * 1000
    }[unit.toLowerCase()]
    if (!multiplier) {
        return null
    }
    const offset = Number(amount) * multiplier * (sign === "-" ? -1 : 1)
    return { offset, unit }
}

function buildRelativeDate(relativeToken) {
    const parsed = parseRelativeToken(relativeToken)
    if (!parsed) {
        throw new Error("agendar(): formato relativo inválido")
    }
    const scheduledDate = new Date(Date.now() + parsed.offset)
    if (scheduledDate.getTime() <= Date.now()) {
        throw new Error("agendar(): horário precisa ser no futuro")
    }
    return scheduledDate
}

function buildScheduledDate(dateStr, timeStr) {
    const { day, month, year } = parseScheduleDate(dateStr)
    const { hour, minute } = parseScheduleTime(timeStr)

    const localUtcMs = Date.UTC(year, month - 1, day, hour, minute)
    const offsetMs = SCHEDULE_TIMEZONE_OFFSET_HOURS * 60 * 60 * 1000
    const utcMs = localUtcMs - offsetMs
    const scheduledDate = new Date(utcMs)

    if (Number.isNaN(scheduledDate.getTime())) {
        throw new Error("agendar(): data e hora inválidas")
    }
    if (scheduledDate.getTime() <= Date.now()) {
        throw new Error("agendar(): horário precisa ser no futuro")
    }

    return scheduledDate
}

function formatScheduledForResponse(date) {
    return date.toLocaleString("pt-BR", {
        timeZone: SCHEDULE_TIMEZONE_LABEL,
        hour12: false
    })
}

function formatCurrentDateTimeUtc3() {
    try {
        const formatter = new Intl.DateTimeFormat("pt-BR", {
            timeZone: "America/Fortaleza",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit",
            hour12: false
        })
        const parts = formatter.formatToParts(new Date())
        const map = Object.fromEntries(parts.map(part => [part.type, part.value]))
        const date = `${map.year}-${map.month}-${map.day}`
        const time = `${map.hour}:${map.minute}:${map.second}`
        return `${date} ${time}`
    } catch (err) {
        return new Date().toISOString()
    }
}


module.exports = {
    parseCalendarDate,
    parseCalendarTime,
    getTimeZoneOffsetMs,
    zonedTimeToUtcDate,
    parseCalendarDateTime,
    parseScheduleDate,
    parseScheduleTime,
    parseRelativeToken,
    buildRelativeDate,
    buildScheduledDate,
    formatScheduledForResponse,
    formatCurrentDateTimeUtc3
}