const nodeCrypto = require("crypto")
const fs = require("fs")
const path = require("path")
const { QR_TOKEN_DIR, CALENDAR_TOKEN_SECRET, CALENDAR_STATE_TTL_MS, BAILEYS_PANEL_PREFIXES, BAILEYS_PANEL_CONTEXTS, BAILEYS_PANEL_SUFFIXES, APP_TIMEZONE, SCHEDULE_TIMEZONE_OFFSET_HOURS, SCHEDULE_TIMEZONE_LABEL, WHATSAPP_CACHE_TTL_DAYS, log } = require("../config/config")

// Logging function (already in config, but keep for now)
function logUtils(...args) {
    console.log(`[UTILS]`, ...args)
}

// QR token management functions
function ensureQrTokenDir() {
    if (!fs.existsSync(QR_TOKEN_DIR)) {
        fs.mkdirSync(QR_TOKEN_DIR, { recursive: true })
    }
}

function toBase64Url(buffer) {
    return buffer
        .toString("base64")
        .replace(/\+/g, "-")
        .replace(/\//g, "_")
        .replace(/=+$/, "")
}

function cleanupExpiredQrTokens(now = Date.now()) {
    try {
        ensureQrTokenDir()
        const entries = fs.readdirSync(QR_TOKEN_DIR)
        entries.forEach(fileName => {
            if (!fileName.endsWith(".json")) return
            const fullPath = path.join(QR_TOKEN_DIR, fileName)
            try {
                const raw = fs.readFileSync(fullPath, "utf8")
                const data = JSON.parse(raw)
                const expiresAt = Number(data?.expires_at_ms || 0)
                if (expiresAt && expiresAt <= now) {
                    fs.unlinkSync(fullPath)
                }
            } catch (err) {
                fs.unlinkSync(fullPath)
            }
        })
    } catch (err) {
        log("Erro ao limpar tokens QR:", err.message)
    }
}

function generateQrAccessToken(instanceId) {
    ensureQrTokenDir()
    cleanupExpiredQrTokens()
    const token = toBase64Url(nodeCrypto.randomBytes(32))
    const createdAt = new Date()
    const expiresAt = new Date(Date.now() + 24 * 60 * 60 * 1000)
    const payload = {
        token,
        instance_id: instanceId,
        created_at: createdAt.toISOString(),
        expires_at: expiresAt.toISOString(),
        expires_at_ms: expiresAt.getTime()
    }
    const filePath = path.join(QR_TOKEN_DIR, `${token}.json`)
    fs.writeFileSync(filePath, JSON.stringify(payload, null, 2), { mode: 0o600 })
    return payload
}

// Calendar OAuth states
const calendarOauthStates = new Map()

function getCalendarEncryptionKey() {
    if (!CALENDAR_TOKEN_SECRET) {
        return null
    }
    return nodeCrypto.createHash("sha256").update(CALENDAR_TOKEN_SECRET, "utf8").digest()
}

function encryptCalendarToken(value) {
    if (!value) return null
    const key = getCalendarEncryptionKey()
    if (!key) {
        throw new Error("Chave CALENDAR_TOKEN_SECRET não configurada")
    }
    const iv = nodeCrypto.randomBytes(12)
    const cipher = nodeCrypto.createCipheriv("aes-256-gcm", key, iv)
    const encrypted = Buffer.concat([cipher.update(String(value), "utf8"), cipher.final()])
    const tag = cipher.getAuthTag()
    return `v1:${iv.toString("base64")}:${tag.toString("base64")}:${encrypted.toString("base64")}`
}

function decryptCalendarToken(value) {
    if (!value) return null
    const text = String(value)
    if (!text.startsWith("v1:")) {
        return text
    }
    const key = getCalendarEncryptionKey()
    if (!key) {
        throw new Error("Chave CALENDAR_TOKEN_SECRET não configurada")
    }
    const [, ivB64, tagB64, dataB64] = text.split(":")
    const iv = Buffer.from(ivB64, "base64")
    const tag = Buffer.from(tagB64, "base64")
    const data = Buffer.from(dataB64, "base64")
    const decipher = nodeCrypto.createDecipheriv("aes-256-gcm", key, iv)
    decipher.setAuthTag(tag)
    const decrypted = Buffer.concat([decipher.update(data), decipher.final()])
    return decrypted.toString("utf8")
}

function cleanupExpiredCalendarStates(now = Date.now()) {
    for (const [state, meta] of calendarOauthStates.entries()) {
        if (!meta?.createdAt || now - meta.createdAt > CALENDAR_STATE_TTL_MS) {
            calendarOauthStates.delete(state)
        }
    }
}

// Panel name functions
function pickRandomPanelName() {
    const prefix = BAILEYS_PANEL_PREFIXES[nodeCrypto.randomInt(BAILEYS_PANEL_PREFIXES.length)]
    const context = BAILEYS_PANEL_CONTEXTS[nodeCrypto.randomInt(BAILEYS_PANEL_CONTEXTS.length)]
    const suffixRoll = nodeCrypto.randomInt(100)
    const suffix = suffixRoll < 60
        ? BAILEYS_PANEL_SUFFIXES[nodeCrypto.randomInt(BAILEYS_PANEL_SUFFIXES.length)]
        : ""
    return suffix ? `${prefix} ${context} ${suffix}` : `${prefix} ${context}`
}

function getConsistentPanelName(instanceId) {
    // Use instance ID to generate a consistent browser name to avoid session conflicts
    return 'Consistent Panel'
}

function pickRandomUserAgent() {
    const { BAILEYS_USER_AGENTS } = require("../config/config")
    return BAILEYS_USER_AGENTS[nodeCrypto.randomInt(BAILEYS_USER_AGENTS.length)]
}

// String manipulation
function escapeHtml(value) {
    if (value === null || value === undefined) return ""
    return String(value)
        .replace(/&/g, "&")
        .replace(/</g, "<")
        .replace(/>/g, ">")
        .replace(/"/g, """)
        .replace(/'/g, "'")
}

function formatIntervalMinutes(value) {
    const minutes = Number(value || 0)
    if (!Number.isFinite(minutes) || minutes <= 0) {
        return "N/A"
    }
    if (minutes < 60) {
        return `${minutes} min`
    }
    const hours = Math.floor(minutes / 60)
    const remainder = minutes % 60
    if (!remainder) {
        return `${hours}h`
    }
    return `${hours}h ${remainder}m`
}

// Calendar date/time parsing
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
            const tz = timeZone || APP_TIMEZONE
            return zonedTimeToUtcDate({ year, month, day, hour, minute }, tz)
        }
        match = /^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})$/.exec(trimmed)
        if (match) {
            const year = Number(match[1])
            const month = Number(match[2])
            const day = Number(match[3])
            const hour = Number(match[4])
            const minute = Number(match[5])
            const tz = timeZone || APP_TIMEZONE
            return zonedTimeToUtcDate({ year, month, day, hour, minute }, tz)
        }
        const parsed = new Date(trimmed)
        if (!Number.isNaN(parsed.getTime())) {
            return parsed
        }
    }
    const { day, month, year } = parseCalendarDate(trimmed)
    const { hour, minute } = parseCalendarTime(timeArg)
    const tz = timeZone || APP_TIMEZONE
    return zonedTimeToUtcDate({ year, month, day, hour, minute }, tz)
}

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
    const timeZone = normalized.timezone || fallbackTimeZone || APP_TIMEZONE
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

// Schedule functions
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

// Other utilities
function formatDateForBrazil(value) {
    if (!value) return ""
    const date = value instanceof Date ? value : new Date(value)
    if (Number.isNaN(date.getTime())) {
        return String(value)
    }
    return date.toLocaleDateString("pt-BR")
}

function normalizeMetaField(value) {
    if (value === undefined || value === null) return null
    const text = typeof value === "string" ? value : String(value)
    const trimmed = text.trim()
    return trimmed === "" ? null : trimmed
}

function isIndividualJid(remoteJid) {
    if (!remoteJid || typeof remoteJid !== "string") return false
    return !remoteJid.includes("@g.us") && !remoteJid.includes("@broadcast")
}

function isGroupJid(remoteJid) {
    return typeof remoteJid === "string" && remoteJid.includes("@g.us")
}

function extractInboundMessageText(message) {
    if (!message) return ""
    const text = message.conversation
        || message.extendedTextMessage?.text
        || message.imageMessage?.caption
        || message.videoMessage?.caption
        || message.documentMessage?.caption
        || ""
    if (text) return String(text).trim()
    if (message.audioMessage) return "ÁUDIO RECEBIDO"
    if (message.imageMessage) return "IMAGEM RECEBIDA"
    if (message.videoMessage) return "VÍDEO RECEBIDO"
    if (message.documentMessage) return "DOCUMENTO RECEBIDO"
    return ""
}

function getMentionedJids(message) {
    if (!message) return []
    const context = message.extendedTextMessage?.contextInfo
        || message.imageMessage?.contextInfo
        || message.videoMessage?.contextInfo
        || message.documentMessage?.contextInfo
        || message.contextInfo
    const mentioned = context?.mentionedJid
    return Array.isArray(mentioned) ? mentioned : []
}

function unescapeCommandString(value) {
    return value.replace(/\\(.)/g, (_, char) => {
        switch (char) {
            case "n":
                return "\n"
            case "r":
                return "\r"
            case "t":
                return "\t"
            case "\\":
                return "\\"
            case '"':
                return '"'
            case "'":
                return "'"
            default:
                return char
        }
    })
}

function parseFunctionArgs(rawArgs) {
    if (!rawArgs) return []

    const args = []
    let buffer = ""
    let quote = null
    let escape = false

    const pushBuffer = () => {
        const trimmed = buffer.trim()
        args.push(trimmed)
        buffer = ""
    }

    for (let i = 0; i < rawArgs.length; i++) {
        const char = rawArgs[i]

        if (escape) {
            buffer += char
            escape = false
            continue
        }

        if (char === "\\") {
            buffer += char
            escape = true
            continue
        }

        if (quote) {
            if (char === quote) {
                quote = null
                pushBuffer()
                continue
            }
            buffer += char
            continue
        }

        if (char === '"' || char === "'") {
            quote = char
            continue
        }

        if (char === ",") {
            pushBuffer()
            continue
        }

        buffer += char
    }

    if (buffer.trim() !== "" || rawArgs.trim().endsWith(",") || quote !== null) {
        pushBuffer()
    }

    return args.map(arg => unescapeCommandString(arg))
}

function normalizeScheduleTag(value) {
    const trimmed = (value || "").trim()
    return trimmed || "default"
}

function normalizeScheduleTipo(value) {
    const trimmed = (value || "").trim()
    return trimmed || "followup"
}

function buildFunctionResult(ok, code, message, data = {}) {
    return { ok, code, message, data }
}

function formatContextEntries(entries) {
    if (!Array.isArray(entries) || entries.length === 0) {
        return "vazio"
    }
    const pairs = entries
        .map(entry => {
            const key = (entry?.key || "").trim()
            const value = entry?.value === null || entry?.value === undefined ? "" : String(entry.value).trim()
            if (!key) return null
            return `${key}=${value}`
        })
        .filter(Boolean)
    return pairs.length ? pairs.join("; ") : "vazio"
}

function formatCurrentDateTimeUtc3() {
    try {
        const formatter = new Intl.DateTimeFormat("pt-BR", {
            timeZone: APP_TIMEZONE,
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

function ensureBrazilCountryCode(digits) {
    if (!digits) return ''
    if (digits.startsWith('55')) {
        return digits
    }
    if (digits.length >= 10 && digits.length <= 11) {
        return `55${digits}`
    }
    return digits
}

function formatOutgoingJid(value) {
    if (!value) return null
    if (value.includes("@")) return value
    const digits = String(value).replace(/\D/g, "")
    const normalized = ensureBrazilCountryCode(digits)
    return normalized ? `${normalized}@s.whatsapp.net` : null
}

function shouldCheckWhatsAppRecipient(jid) {
    if (!jid || typeof jid !== "string") {
        return false
    }
    const lower = jid.toLowerCase()
    if (lower.startsWith("status@broadcast")) {
        return false
    }
    return lower.endsWith("@s.whatsapp.net")
}

function extractPhoneFromJid(jid) {
    if (!jid || typeof jid !== "string") {
        return ""
    }
    return jid.split("@")[0].replace(/\D/g, "")
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, Math.max(0, Math.floor(ms))))
}

function isNighttime() {
    const now = new Date();
    const saoPauloFormatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Sao_Paulo',
        hour: 'numeric',
        hour12: false
    });
    const hour = parseInt(saoPauloFormatter.format(now));
    return hour >= 20 || hour < 7;
}

function getNextNightActionTime() {
    const now = new Date();
    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Sao_Paulo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        hour12: false
    });
    const parts = formatter.formatToParts(now);
    const year = parseInt(parts.find(p => p.type === 'year').value);
    const month = parseInt(parts.find(p => p.type === 'month').value);
    const day = parseInt(parts.find(p => p.type === 'day').value);
    const hour = parseInt(parts.find(p => p.type === 'hour').value);
    let targetHour;
    if (hour >= 20 || hour < 7) {
        targetHour = 7;
    } else {
        targetHour = 20;
    }
    let utcTarget = zonedTimeToUtcDate({ year, month, day, hour: targetHour, minute: 0, second: 0 }, 'America/Sao_Paulo');
    if (utcTarget <= now) {
        const nextDay = new Date(now);
        nextDay.setDate(now.getDate() + 1);
        const nextParts = formatter.formatToParts(nextDay);
        const nextYear = parseInt(nextParts.find(p => p.type === 'year').value);
        const nextMonth = parseInt(nextParts.find(p => p.type === 'month').value);
        const nextDayNum = parseInt(nextParts.find(p => p.type === 'day').value);
        utcTarget = zonedTimeToUtcDate({ year: nextYear, month: nextMonth, day: nextDayNum, hour: targetHour, minute: 0, second: 0 }, 'America/Sao_Paulo');
    }
    return utcTarget;
}

function computeTypingDelayMs(text) {
    const normalized = (text || "").trim();
    if (!normalized) {
        return 0;
    }
    const charCount = normalized.length;
    const seconds = Math.min(10, Math.max(1, Math.ceil(charCount / 20)));
    // Adiciona um fator aleatório de até 1.2 segundos para simular digitação humana
    const randomFactor = Math.random() * 1200 + 200;
    return seconds * 1000 + randomFactor;
}

function calculateTemperatureDelay(temperature) {
    let baseDelay = 0;
    let randomDelay = 0;

    switch (temperature) {
        case 'cold':
            baseDelay = 5000; // 5 seconds base delay
            randomDelay = Math.random() * 3000 + 1000; // 1 to 4 seconds random
            break;
        case 'warm':
            baseDelay = 2000; // 2 seconds base delay
            randomDelay = Math.random() * 1500 + 500; // 0.5 to 2 seconds random
            break;
        case 'hot':
            baseDelay = 500; // 0.5 seconds base delay
            randomDelay = Math.random() * 500 + 100; // 0.1 to 0.6 seconds random
            break;
        default:
            baseDelay = 1000; // Default to warm if not set
            randomDelay = Math.random() * 1000 + 200; // 0.2 to 1.2 seconds random
            break;
    }
    return baseDelay + randomDelay;
}

function determineTemperature(inboundCount, outboundCount) {
    if (outboundCount === 0) {
        return 'cold'; // Cannot calculate taxar if no messages sent, default to cold
    }
    const taxar = (inboundCount / outboundCount) * 100;

    if (taxar >= 70) {
        return 'hot';
    } else if (taxar >= 30) {
        return 'warm';
    } else {
        return 'cold';
    }
}

function resolveContactStatusName(remoteJid) {
    // This will be implemented in the service
    return ""
}

function snippet(value, limit = 120) {
    if (!value) return ""
    const cleaned = String(value).replace(/\s+/g, " ").trim()
    if (!cleaned) return ""
    return cleaned.length <= limit ? cleaned : `${cleaned.slice(0, limit)}...`
}

function replaceStatusPlaceholder(text, statusName) {
    if (!text) {
        return text
    }
    const replacement = statusName ? statusName.trim() : ""
    return text.replace(/%statusname%/gi, replacement)
}

function splitHashSegments(value) {
    if (!value) {
        return []
    }
    return String(value)
        .split("#")
        .map(part => part.trim())
        .filter(Boolean)
}

function toNumber(value, fallback) {
    const num = typeof value === "number" ? value : parseFloat(value)
    return Number.isFinite(num) ? num : fallback
}

function collectAlarmDebugInfo(eventKey, meta) {
    const now = new Date().toISOString()
    const instanceName = "instance" // placeholder
    const port = 3000 // placeholder
    const host = require("os").hostname() || "desconhecido"
    const errorDetail = "sem erros registrados" // placeholder
    return {
        instanceName,
        instanceId: "instance", // placeholder
        eventKey,
        port,
        detectedAt: now,
        connectionStatus: "unknown", // placeholder
        whatsappConnected: false, // placeholder
        lastError: errorDetail,
        process: `pid=${process.pid}, host=${host}`,
        node: `${process.version} (${process.platform})`,
        intervalMinutes: meta?.interval ?? "N/A",
        intervalLabel: formatIntervalMinutes(meta?.interval),
        lastSent: meta?.lastSent || "nunca"
    }
}

function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes'
    const k = 1024
    const dm = decimals < 0 ? 0 : decimals
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i]
}

function pickRandomErrorResponse() {
    const ERROR_RESPONSE_OPTIONS = [
        "Ops, acho que encontrei um erro aqui...me dá uns minutinhos",
        "Me chama em alguns minutos, me apareceu um problema aqui na minha programação ...",
        "Me chamaram aqui, parece que vai ter manutenção no sistema. Me chama daqui um pedacinho...",
        "Hmmm, deu problema aqui. Mas não se preocupe, me chama daqui a pouco que estarei bem",
        "Vou pedir uma pausa porque me deu um erro aqui no sistema, mas já já você pode me chamar, eu ficarei bem!"
    ]
    const index = Math.floor(Math.random() * ERROR_RESPONSE_OPTIONS.length)
    return ERROR_RESPONSE_OPTIONS[index]
}

function classifyError(err) {
    const message = String(err?.message || err || '').toLowerCase();
    
    if (message.includes('connection') || message.includes('timeout') || message.includes('network')) {
        return "NETWORK_ERROR"
    }
    
    if (message.includes('auth') || message.includes('unauthorized') || message.includes('login')) {
        return "AUTHENTICATION_ERROR"
    }
    
    if (message.includes('rate') || message.includes('limit') || message.includes('too many')) {
        return "RATE_LIMIT_ERROR"
    }
    
    if (message.includes('server') || message.includes('internal')) {
        return "SERVER_ERROR"
    }
    
    return "UNKNOWN_ERROR"
}

function logError(err, context) {
    const errorType = classifyError(err)
    const errorMessage = err?.message || String(err)
    const stackTrace = err?.stack || 'No stack trace available'
    
    log(`[${errorType}] ${errorMessage}`)
    log(`Context: ${context}`)
    log(`Stack Trace: ${stackTrace}`)
    
    // Optionally, you could save the error to a database or file here
    // For example: db.logError(INSTANCE_ID, errorType, errorMessage, context, stackTrace);
}

function normalizeContactPhoneNumber(value) {
    if (!value) return null
    const digits = String(value).replace(/\D/g, "")
    if (!digits) return null
    const normalized = ensureBrazilCountryCode(digits)
    return normalized || null
}

function formatContactPhoneLabel(value) {
    if (!value) return null
    const trimmed = String(value).trim()
    if (trimmed === "") return null
    if (trimmed.startsWith("+")) return trimmed
    return `+${trimmed}`
}

function extractPhonesFromVcard(vcard) {
    if (!vcard) return []
    const lines = vcard.split(/\r?\n/)
    const phones = []
    for (const line of lines) {
        const trimmed = line.trim()
        if (!trimmed.toUpperCase().startsWith("TEL")) continue
        const parts = trimmed.split(":")
        if (parts.length < 2) continue
        const rawValue = parts.slice(1).join(":").trim()
        const normalized = normalizeContactPhoneNumber(rawValue)
        if (!normalized) continue
        phones.push({
            raw: rawValue,
            normalized
        })
    }
    return phones
}

function buildContactPrompt(payload) {
    if (!payload) return ""
    const lines = ["CONTATO RECEBIDO:"]
    if (payload.displayName) {
        lines.push(`Nome: ${payload.displayName}`)
    }
    if (payload.note) {
        lines.push(`Descrição: ${payload.note}`)
    }
    if (payload.phones?.length) {
        const formatted = payload.phones
            .map(entry => formatContactPhoneLabel(entry.normalized) || entry.raw)
            .filter(Boolean)
        if (formatted.length) {
            lines.push(`Telefone(s): ${formatted.join(", ")}`)
        }
    }
    const summaryLines = (payload.vcard || "")
        .split(/\r?\n/)
        .map(line => line.trim())
        .filter(Boolean)
        .slice(0, 4)
    if (summaryLines.length) {
        lines.push(`vCard: ${summaryLines.join(" | ")}`)
    }
    return lines.join("\n")
}

function formatDateTimeInTimeZone(date, timeZone) {
    const formatter = new Intl.DateTimeFormat("pt-BR", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false
    })
    return formatter.format(date)
}

function formatCalendarDateTime(date, timeZone) {
    const formatter = new Intl.DateTimeFormat("en-CA", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false
    })
    const parts = formatter.formatToParts(date)
    const values = Object.fromEntries(parts.map(part => [part.type, part.value]))
    return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}:${values.second}`
}

function parseWindowRange(windowStr) {
    const trimmed = (windowStr || "").trim()
    const match = /^(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})$/.exec(trimmed)
    if (!match) {
        throw new Error("calendar: janela inválida (use HH:MM-HH:MM)")
    }
    return { start: match[1], end: match[2] }
}

function parseCalendarSlotArgs(args, timeZoneFallback) {
    const [arg1, arg2, arg3, arg4, arg5] = args
    const a1 = (arg1 || "").trim()
    const a2 = (arg2 || "").trim()
    const a3 = (arg3 || "").trim()
    const a4 = (arg4 || "").trim()
    const a5 = (arg5 || "").trim()
    if (a1 && a2 && /^[0-9]+$/.test(a2)) {
        const timeZone = a4 || timeZoneFallback
        const start = parseCalendarDateTime(a1, null, timeZone)
        const duration = Number(a2)
        return {
            start,
            end: new Date(start.getTime() + duration * 60000),
            duration,
            calendarId: a3 || null,
            timeZone: timeZone || null
        }
    }
    if (a1 && a2 && a3 && /^[0-9]+$/.test(a3)) {
        const timeZone = a5 || timeZoneFallback
        const start = parseCalendarDateTime(a1, a2, timeZone)
        const duration = Number(a3)
        return {
            start,
            end: new Date(start.getTime() + duration * 60000),
            duration,
            calendarId: a4 || null,
            timeZone: timeZone || null
        }
    }
    if (a1 && a2) {
        const timeZone = a4 || timeZoneFallback
        const start = parseCalendarDateTime(a1, null, timeZone)
        const end = parseCalendarDateTime(a2, null, timeZone)
        return {
            start,
            end,
            duration: null,
            calendarId: a3 || null,
            timeZone: timeZone || null
        }
    }
    throw new Error("calendar: informe inicio e fim ou duração")
}

function normalizeAlarmBoolean(value) {
    if (typeof value !== "string" && typeof value !== "number") {
        return false
    }
    const normalized = String(value || "").trim().toLowerCase()
    return ["1", "true", "yes", "on"].includes(normalized)
}

function normalizeAlarmRecipients(value) {
    if (!value) return []
    const parts = String(value).split(/[\s,;]+/).map(part => part.trim()).filter(Boolean)
    const unique = []
    for (const part of parts) {
        const candidate = part.toLowerCase()
        if (!/^.+@.+\..+$/.test(candidate)) {
            continue
        }
        if (!unique.includes(candidate)) {
            unique.push(candidate)
        }
    }
    return unique
}

function normalizeAlarmInterval(value, unit) {
    const num = Number(value)
    if (!Number.isFinite(num) || num <= 0) {
        return 120
    }
    const normalizedUnit = String(unit || "").trim().toLowerCase()
    if (normalizedUnit === "minutes" || normalizedUnit === "min") {
        return Math.min(1440, Math.max(1, Math.floor(num)))
    }
    if (num === 2 || num === 24) {
        return num * 60
    }
    return Math.min(1440, Math.max(1, Math.floor(num)))
}

function buildAlarmConfig(settings) {
    const events = ["whatsapp", "server", "error"]
    const payload = {}
    for (const event of events) {
        const prefix = `alarm_${event}_`
        const unit = settings[`${prefix}interval_unit`]
        payload[event] = {
            enabled: normalizeAlarmBoolean(settings[`${prefix}enabled`]),
            recipients: normalizeAlarmRecipients(settings[`${prefix}recipients`]),
            interval: normalizeAlarmInterval(settings[`${prefix}interval`], unit),
            intervalUnit: unit || "minutes",
            lastSent: settings[`${prefix}last_sent`] || "",
            lastSentKey: `${prefix}last_sent`
        }
    }
    return payload
}

function shouldSendAlarm(meta) {
    if (!meta) return false
    if (!meta.enabled) return false
    if (!meta.recipients.length) return false
    if (!meta.lastSent) {
        return true
    }
    const lastStamp = Date.parse(meta.lastSent)
    if (Number.isNaN(lastStamp)) {
        return true
    }
    const intervalMs = Math.max(1, meta.interval || 120) * 60 * 1000
    return (Date.now() - lastStamp) >= intervalMs
}

function buildAlarmDebugContext(eventKey, meta) {
    const info = collectAlarmDebugInfo(eventKey, meta)
    const lines = [
        `Instância: ${info.instanceName}`,
        `ID: ${info.instanceId}`,
        `Evento: ${info.eventKey}`,
        `Porta: ${info.port}`,
        `Detectado em: ${info.detectedAt}`,
        `Conexão WhatsApp: ${info.connectionStatus}`,
        `WhatsApp conectado: ${info.whatsappConnected}`,
        `Último erro: ${info.lastError}`,
        `Processo: ${info.process}`,
        `Node: ${info.node}`,
        `Intervalo configurado: ${info.intervalLabel}`,
        `Último envio: ${info.lastSent}`
    ]
    return "Detalhes adicionais:\n" + lines.map(line => `- ${line}`).join("\n")
}


module.exports = {
    ensureQrTokenDir,
    toBase64Url,
    cleanupExpiredQrTokens,
    generateQrAccessToken,
    calendarOauthStates,
    getCalendarEncryptionKey,
    encryptCalendarToken,
    decryptCalendarToken,
    cleanupExpiredCalendarStates,
    pickRandomPanelName,
    getConsistentPanelName,
    pickRandomUserAgent,
    escapeHtml,
    formatIntervalMinutes,
    parseCalendarDate,
    parseCalendarTime,
    getTimeZoneOffsetMs,
    zonedTimeToUtcDate,
    parseCalendarDateTime,
    parseAvailabilityJson,
    getWeekdayKey,
    getLocalTimeParts,
    toMinutesOfDay,
    normalizeAvailability,
    isSlotWithinAvailability,
    slotsOverlap,
    parseScheduleDate,
    parseScheduleTime,
    parseRelativeToken,
    buildRelativeDate,
    buildScheduledDate,
    formatScheduledForResponse,
    formatDateForBrazil,
    normalizeMetaField,
    isIndividualJid,
    isGroupJid,
    extractInboundMessageText,
    getMentionedJids,
    unescapeCommandString,
    parseFunctionArgs,
    normalizeScheduleTag,
    normalizeScheduleTipo,
    buildFunctionResult,
    formatContextEntries,
    formatCurrentDateTimeUtc3,
    ensureBrazilCountryCode,
    formatOutgoingJid,
    shouldCheckWhatsAppRecipient,
    extractPhoneFromJid,
    sleep,
    isNighttime,
    getNextNightActionTime,
    computeTypingDelayMs,
    calculateTemperatureDelay,
    determineTemperature,
    resolveContactStatusName,
    snippet,
    replaceStatusPlaceholder,
    splitHashSegments,
    toNumber,
    collectAlarmDebugInfo,
    formatBytes,
    pickRandomErrorResponse,
    classifyError,
    logError,
    normalizeContactPhoneNumber,
    formatContactPhoneLabel,
    extractPhonesFromVcard,
    buildContactPrompt,
    formatDateTimeInTimeZone,
    formatCalendarDateTime,
    parseWindowRange,
    parseCalendarSlotArgs,
    normalizeAlarmBoolean,
    normalizeAlarmRecipients,
    normalizeAlarmInterval,
    buildAlarmConfig,
    shouldSendAlarm,
    buildAlarmDebugContext
}