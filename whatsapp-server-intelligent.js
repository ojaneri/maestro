// whatsapp-server-intelligent.js - Intelligent Chat System with AI Integration
// Enhanced version with persistent chat history and AI responses

process.env.TZ = "America/Fortaleza"

const express = require("express")
const bodyParser = require("body-parser")
const http = require("http")
const WebSocket = require("ws")
const { v4: uuidv4 } = require("uuid")
const argv = require("minimist")(process.argv.slice(2))
const path = require("path")
const fs = require("fs")
const os = require("os")
const mime = require("mime-types")
const nodeCrypto = require("crypto")
const { exec } = require("child_process")
const { fetch, Headers, Request, Response } = require("undici")
const mysql = require("mysql2/promise")
const { Readable } = require("stream")
try {
    const dotenv = require("dotenv")
    dotenv.config()
} catch (err) {
    console.warn("[GLOBAL] dotenv não disponível:", err.message)
}
let googleApis = null
try {
    googleApis = require("googleapis")
} catch (err) {
    console.warn("[GLOBAL] Google APIs SDK não disponível:", err.message)
}

if (!Readable.fromWeb) {
    Readable.fromWeb = function (webStream) {
        if (!webStream || typeof webStream.getReader !== "function") {
            throw new Error("Readable.fromWeb polyfill requires a Web ReadableStream")
        }
        const reader = webStream.getReader()
        return new Readable({
            async read() {
                try {
                    const { value, done } = await reader.read()
                    if (done) {
                        this.push(null)
                        return
                    }
                    this.push(Buffer.from(value || []))
                } catch (err) {
                    this.destroy(err)
                }
            }
        }).on("close", () => {
            reader.cancel().catch(() => {})
        })
    }
}

const UPLOADS_DIR = path.join(__dirname, "uploads")
const ASSETS_UPLOADS_DIR = path.resolve(__dirname, "assets", "uploads")
const REMOTE_CACHE_DIR = path.join(ASSETS_UPLOADS_DIR, "remote-cache")
if (!fs.existsSync(UPLOADS_DIR)) {
    fs.mkdirSync(UPLOADS_DIR, { recursive: true })
}
if (!fs.existsSync(REMOTE_CACHE_DIR)) {
    fs.mkdirSync(REMOTE_CACHE_DIR, { recursive: true })
}

const QR_TOKEN_DIR = path.join(__dirname, "storage", "qr_tokens")
const PUBLIC_BASE_URL = (process.env.PUBLIC_BASE_URL || "https://janeri.com.br/api/envio/wpp").replace(/\/+$/, "")
const MAESTRO_LOGO_URL = `${PUBLIC_BASE_URL}/assets/maestro-logo.png`
const GOOGLE_OAUTH_CLIENT_ID = process.env.GOOGLE_OAUTH_CLIENT_ID || ""
const GOOGLE_OAUTH_CLIENT_SECRET = process.env.GOOGLE_OAUTH_CLIENT_SECRET || ""
const GOOGLE_OAUTH_REDIRECT_URL = (process.env.GOOGLE_OAUTH_REDIRECT_URL || `${PUBLIC_BASE_URL}/api/calendar/oauth2/callback`).trim()
const CALENDAR_TOKEN_SECRET = process.env.CALENDAR_TOKEN_SECRET || ""
const CALENDAR_STATE_TTL_MS = 10 * 60 * 1000
const GOOGLE_CALENDAR_SCOPES = [
    "https://www.googleapis.com/auth/calendar",
    "https://www.googleapis.com/auth/userinfo.email"
]
const CALENDAR_PENDING_VARIABLE_KEY = "calendar_pending_auth"

// ===== FIX: garantir globalThis.crypto (Baileys exige WebCrypto) =====
if (!globalThis.crypto) {
    if (nodeCrypto.webcrypto) {
        globalThis.crypto = nodeCrypto.webcrypto
    } else {
        console.error(
            "[GLOBAL] crypto.webcrypto não disponível. " +
            "Atualize o Node para 18+ (você já está em 20.x) ou verifique build."
        )
    }
}

if (!globalThis.fetch) {
    globalThis.fetch = fetch
}
globalThis.Headers = globalThis.Headers || Headers
globalThis.Request = globalThis.Request || Request
globalThis.Response = globalThis.Response || Response

// ===== PARÂMETROS DA INSTÂNCIA =====
const INSTANCE_ID = argv.id || process.env.INSTANCE_ID
const PORT = Number(argv.port || process.env.PORT || 3000)

if (!INSTANCE_ID) {
    console.error("Faltou parâmetro --id=INSTANCE_ID ou variável INSTANCE_ID")
    process.exit(1)
}

function log(...args) {
    console.log(`[${INSTANCE_ID}]`, ...args)
}

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

async function persistPendingCalendarAuth(instanceId, state) {
    if (!instanceId || !state || !db) {
        return
    }
    const tasks = []
    if (typeof db.setPersistentVariable === "function") {
        tasks.push(db.setPersistentVariable(instanceId, CALENDAR_PENDING_VARIABLE_KEY, JSON.stringify({
            state: String(state),
            createdAt: Date.now()
        })))
    }
    if (typeof db.insertCalendarPendingState === "function") {
        tasks.push(db.insertCalendarPendingState(instanceId, String(state), Date.now()))
    }
    if (typeof db.deleteExpiredCalendarPendingStates === "function") {
        const cutoff = Date.now() - CALENDAR_STATE_TTL_MS
        tasks.push(db.deleteExpiredCalendarPendingStates(cutoff))
    }
    try {
        await Promise.all(tasks)
    } catch (err) {
        log("calendar pending persist error:", err.message)
    }
}

async function clearPendingCalendarAuth(instanceId, state = null) {
    if (!instanceId || !db) {
        return
    }
    const tasks = []
    if (typeof db.deletePersistentVariable === "function") {
        tasks.push(db.deletePersistentVariable(instanceId, CALENDAR_PENDING_VARIABLE_KEY))
    }
    if (state && typeof db.deleteCalendarPendingState === "function") {
        tasks.push(db.deleteCalendarPendingState(String(state)))
    }
    try {
        await Promise.all(tasks)
    } catch (err) {
        log("calendar pending clear error:", err.message)
    }
}

async function loadPendingCalendarAuth(instanceId) {
    if (!instanceId || !db || typeof db.getPersistentVariable !== "function") {
        return null
    }
    try {
        const raw = await db.getPersistentVariable(instanceId, CALENDAR_PENDING_VARIABLE_KEY)
        if (!raw) {
            return null
        }
        const parsed = typeof raw === "string" ? JSON.parse(raw) : raw
        if (parsed && parsed.state) {
            const createdAt = Number(parsed.createdAt) || null
            if (createdAt && Date.now() - createdAt > CALENDAR_STATE_TTL_MS) {
                await clearPendingCalendarAuth(instanceId, parsed.state)
                return null
            }
            return {
                state: String(parsed.state),
                createdAt
            }
        }
        await clearPendingCalendarAuth(instanceId)
        return null
    } catch (err) {
        log("calendar pending load error:", err.message)
        await clearPendingCalendarAuth(instanceId)
        return null
    }
}

function escapeHtml(value) {
    if (value === null || value === undefined) return ""
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;")
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

function collectAlarmDebugInfo(eventKey, meta) {
    const now = new Date().toISOString()
    const instanceName = (instanceConfig?.name && instanceConfig.name.trim()) || INSTANCE_ID
    const port = instanceConfig?.port || PORT
    const host = os.hostname() || "desconhecido"
    const errorDetail = lastConnectionError || "sem erros registrados"
    return {
        instanceName,
        instanceId: INSTANCE_ID,
        eventKey,
        port,
        detectedAt: now,
        connectionStatus,
        whatsappConnected: whatsappConnected ? "sim" : "não",
        lastError: errorDetail,
        process: `pid=${process.pid}, host=${host}`,
        node: `${process.version} (${process.platform})`,
        intervalMinutes: meta?.interval ?? "N/A",
        intervalLabel: formatIntervalMinutes(meta?.interval),
        lastSent: meta?.lastSent || "nunca"
    }
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

let nextScheduledAction = null;
let automationPausedUntil = 0;

function scheduleNightAction() {
    const targetTime = getNextNightActionTime();
    const delay = targetTime - Date.now();
    if (nextScheduledAction) clearTimeout(nextScheduledAction);
    nextScheduledAction = setTimeout(() => {
        if (isNighttime()) {
            // During night (20:00-07:00), reduce activity and go offline
            if (whatsappConnected) {
                logoutWhatsApp().catch(err => log("Erro ao desconectar no horário noturno:", err.message));
            }
        } else {
            // During day (07:00-20:00), connect and respond
            if (!whatsappConnected) {
                startWhatsApp().catch(err => log("Erro ao reconectar no horário:", err.message));
            }
        }
        scheduleNightAction();
    }, delay);
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

async function sendPresenceSafely(status, remoteJid) {
    if (!remoteJid || !sock || !whatsappConnected) {
        return
    }
    if (typeof sock.sendPresenceUpdate !== "function") {
        return
    }
    try {
        await sock.sendPresenceUpdate(status, remoteJid)
    } catch {
        // ignore presence errors
    }
}

async function simulateTypingIndicator(remoteJid, text, temperature = 'warm') {
    if (!remoteJid || !sock || !whatsappConnected) {
        return
    }
    const typingDelay = computeTypingDelayMs(text);
    const temperatureDelay = calculateTemperatureDelay(temperature);
    const totalDelayMs = typingDelay + temperatureDelay;

    if (totalDelayMs <= 0) {
        return
    }
    await sendPresenceSafely("available", remoteJid)
    await sendPresenceSafely("composing", remoteJid)
    await sleep(totalDelayMs) // Use totalDelayMs here
    await sendPresenceSafely("paused", remoteJid)
    await sendPresenceSafely("available", remoteJid)
}

async function resolveContactStatusName(remoteJid) {
    if (!db || typeof db.getContactMetadata !== "function") return ""
    if (!remoteJid) return ""
    try {
        const metadata = await db.getContactMetadata(INSTANCE_ID, remoteJid)
        return (metadata?.status_name || "").trim()
    } catch (err) {
        log("resolveContactStatusName error:", err.message)
        return ""
    }
}

const SCHEDULE_TIMEZONE_OFFSET_HOURS = -3 // UTC-3 fixed
const SCHEDULE_TIMEZONE_LABEL = "America/Fortaleza"
const SCHEDULE_CHECK_INTERVAL_MS = 30 * 1000
const SCHEDULE_FETCH_LIMIT = 10
const WHATSAPP_ALARM_VERIFY_DELAY_MS = Number(process.env.WHATSAPP_ALARM_VERIFY_DELAY_MS || 15000)

const GEMINI_ALLOWED_MEDIA_MIME_TYPES = new Set([
    "image/jpeg",
    "image/png",
    "image/gif",
    "image/webp",
    "image/bmp",
    "audio/mpeg",
    "audio/mp3",
    "audio/ogg",
    "audio/wav",
    "audio/webm",
    "audio/x-wav",
    "audio/x-m4a",
    "application/pdf"
])

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

const CUSTOMER_DB_CONFIG = {
    host: process.env.CUSTOMER_DB_HOST || "localhost",
    port: Number(process.env.CUSTOMER_DB_PORT || 3306),
    user: process.env.CUSTOMER_DB_USER || "kitpericia",
    password: process.env.CUSTOMER_DB_PASSWORD || "kitpericia",
    database: process.env.CUSTOMER_DB_NAME || "kitpericia",
    charset: "utf8mb4"
}
let customerDbPool = null

async function getCustomerDbPool() {
    if (!customerDbPool) {
        customerDbPool = mysql.createPool({
            ...CUSTOMER_DB_CONFIG,
            waitForConnections: true,
            connectionLimit: 2,
            queueLimit: 0
        })
    }
    return customerDbPool
}

function formatDateForBrazil(value) {
    if (!value) return ""
    const date = value instanceof Date ? value : new Date(value)
    if (Number.isNaN(date.getTime())) {
        return String(value)
    }
    return date.toLocaleDateString("pt-BR")
}

async function fetchCustomerProfileByEmail(email) {
    const normalizedEmail = (email || "").trim()
    if (!normalizedEmail) {
        throw new Error("dados(): email obrigatório")
    }

    const pool = await getCustomerDbPool()
    const sql = `
        SELECT 
            username,
            email,
            phone,
            expiration_date,
            DATEDIFF(expiration_date, CURDATE()) AS dias_restantes
        FROM users2
        WHERE email = ?
        LIMIT 1
    `
    const [rows] = await pool.execute(sql, [normalizedEmail])
    const row = Array.isArray(rows) ? rows[0] : null
    if (!row) {
        throw new Error(`dados(): usuário não encontrado para ${normalizedEmail}`)
    }

    const diasRestantes = Number(row.dias_restantes ?? 0)
    const status = diasRestantes >= 0 ? "ATIVO" : "EXPIRADO"
    const assinaturaInfo = diasRestantes >= 0
        ? `${diasRestantes} dias restantes`
        : `${Math.abs(diasRestantes)} dias vencidos`
    return {
        nome: row.username || "",
        email: row.email || normalizedEmail,
        telefone: row.phone || "",
        status,
        assinatura_info: assinaturaInfo,
        data_expiracao: formatDateForBrazil(row.expiration_date)
    }
}

log("Iniciando instância inteligente:", INSTANCE_ID, "Porta:", PORT)

// ===== CARREGAR CONFIGURAÇÕES DA INSTÂNCIA =====
let instanceConfig = {
    name: null,
    port: PORT,
    api_key: null,
    base_url: `http://127.0.0.1:${PORT}`
};


function getInstanceBasePayload() {
    const baseUrl = (instanceConfig?.base_url && instanceConfig.base_url.trim())
        ? instanceConfig.base_url.trim()
        : `http://127.0.0.1:${PORT}`

    return {
        name: instanceConfig?.name || null,
        port: instanceConfig?.port || PORT,
        api_key: instanceConfig?.api_key || null,
        base_url: baseUrl,
        status: connectionStatus || "starting",
        connection_status: connectionStatus || "starting"
    }
}

function persistInstanceData(payload = {}) {
    if (!db || !dbReadyPromise) {
        return
    }
    const data = { ...getInstanceBasePayload(), ...payload }
    dbReadyPromise
        .then(() => db.saveInstanceRecord(INSTANCE_ID, data))
        .catch(err => log("Erro ao persistir dados da instância:", err.message))
}

function persistInstanceStatus(statusValue = null, connectionState = null) {
    const base = {}
    if (statusValue) base.status = statusValue
    if (connectionState) base.connection_status = connectionState
    persistInstanceData(base)
}

function updateInstancePhoneNumber(phoneJid) {
    const normalized = (phoneJid || "").trim()
    if (!normalized) {
        return
    }

    persistInstanceData({ phone: normalized })
    log("Instância vinculada ao telefone", normalized)
}

// ===== ESTADO GLOBAL =====
let clientConnections = []
let whatsappConnected = false
let qrCodeData = null
let connectionStatus = "starting" // "starting" | "qr" | "connected" | "disconnected" | "error"
let lastConnectionError = null
let sock = null
let restarting = false
const groupMetadataCache = new Map()
const recentOutgoingText = new Map()
const RECENT_OUTGOING_TTL_MS = 15000
const RECENT_OUTGOING_LIMIT = 1000

// ===== EXPRESS + HTTP + WS =====
const app = express()
app.use(bodyParser.json())

// CORS simples
app.use((req, res, next) => {
    res.setHeader("Access-Control-Allow-Origin", "*")
    res.setHeader("Access-Control-Allow-Methods", "GET,POST,OPTIONS")
    res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization")
    if (req.method === "OPTIONS") {
        return res.sendStatus(200)
    }
    next()
})

const server = http.createServer(app)
const wss = new WebSocket.Server({ server, path: "/ws" })

// ===== GERENCIAMENTO DE WEBSOCKET =====
function broadcastToClients(type, data) {
    const payload = JSON.stringify({ type, data })
    clientConnections = clientConnections.filter(c => {
        if (c.ws.readyState === WebSocket.OPEN) {
            try {
                c.ws.send(payload)
                return true
            } catch (e) {
                return false
            }
        }
        return false
    })
}

function getInstanceFallbackMultiInputDelay() {
    const aiBlock = instanceConfig?.ai || {}
    const legacyBlock = instanceConfig?.openai || {}
    const candidate = aiBlock.multi_input_delay ?? legacyBlock.multi_input_delay ?? DEFAULT_MULTI_INPUT_DELAY
    return Math.max(0, toNumber(candidate, DEFAULT_MULTI_INPUT_DELAY))
}

wss.on("connection", ws => {
    const clientId = uuidv4()
    log("Novo cliente WebSocket conectado:", clientId)

    clientConnections.push({ id: clientId, ws })

    // Estado inicial
    try {
        ws.send(JSON.stringify({
            type: "status",
            data: {
                instanceId: INSTANCE_ID,
                connectionStatus,
                whatsappConnected,
                hasQR: !!qrCodeData,
                lastConnectionError
            }
        }))
        if (qrCodeData) {
            ws.send(JSON.stringify({
                type: "qr",
                data: { qr: qrCodeData }
            }))
        }
    } catch (e) {
        log("Erro ao enviar estado inicial WS:", e.message)
    }

    ws.on("close", () => {
        log("Cliente WebSocket desconectado:", clientId)
        clientConnections = clientConnections.filter(c => c.id !== clientId)
    })

    ws.on("error", err => {
        log("Erro no WebSocket do cliente", clientId + ":", err.message)
    })
})

// ===== IMPORT DINÂMICO DO BAILEYS (ESM) =====
let baileysModulePromise = null
let baileysModule = null

function getBaileys() {
    if (!baileysModulePromise) {
        baileysModulePromise = import("@whiskeysockets/baileys")
    }
    return baileysModulePromise
}

// ===== IMPORT OPENAI =====
let OpenAI = null
try {
    OpenAI = require("openai")
    log("OpenAI SDK carregado")
} catch (err) {
    log("Erro ao carregar OpenAI SDK:", err.message)
}

// ===== IMPORT DATABASE MODULE =====
let db = null
let dbReadyPromise = Promise.resolve()
try {
    const dbModule = require("./db-updated")
    log("Database module carregado")
    db = dbModule

    // Initialize database
    dbReadyPromise = db.initDatabase()
        .then(() => {
            log("Database initialized successfully")
            return db.getInstanceRecord(INSTANCE_ID)
        })
        .then(record => {
            if (record) {
                instanceConfig = { ...instanceConfig, ...record }
                log("Instance metadata loaded from database")
            }
            persistInstanceData()
        })
        .catch(err => {
            log("Database initialization error:", err.message)
            throw err
        })
} catch (err) {
    log("Erro ao carregar database module:", err.message)
}

// ===== CUSTOM AUTH STORE =====
let authStore = null
if (db) {
    authStore = new db.DatabaseAuthStore(INSTANCE_ID)
}

const DEFAULT_HISTORY_LIMIT = 15
const DEFAULT_TEMPERATURE = 0.3
const DEFAULT_MAX_TOKENS = 600
const DEFAULT_PROVIDER = "openai"
const DEFAULT_GEMINI_INSTRUCTION = "Você é um assistente atencioso e prestativo. Mantenha o tom profissional e informal. Sempre separe claramente o texto visível ao usuário do bloco de instruções/funções usando o marcador lógico &&& antes de iniciar os comandos."
const DEFAULT_MULTI_INPUT_DELAY = 0
const DEFAULT_AUDIO_TRANSCRIPTION_PREFIX = "🔊"
const DEFAULT_SCHEDULE_TAG = "default"
const DEFAULT_SCHEDULE_TIPO = "followup"
const AI_SETTING_KEYS = [
    "ai_enabled",
    "ai_provider",
    "openai_api_key",
    "openai_mode",
    "ai_model",
    "ai_system_prompt",
    "ai_assistant_prompt",
    "ai_assistant_id",
    "ai_history_limit",
    "ai_temperature",
    "ai_max_tokens",
    "gemini_api_key",
    "gemini_instruction",
    "ai_multi_input_delay",
    "auto_pause_enabled",
    "auto_pause_minutes"
]
const AUDIO_TRANSCRIPTION_SETTING_KEYS = [
    "audio_transcription_enabled",
    "audio_transcription_gemini_api_key",
    "audio_transcription_prefix"
]
const SECRETARY_SETTING_KEYS = [
    "secretary_enabled",
    "secretary_idle_hours",
    "secretary_initial_response",
    "secretary_term_1",
    "secretary_response_1",
    "secretary_term_2",
    "secretary_response_2",
    "secretary_quick_replies"
]
const ALARM_SETTING_KEYS = [
    "alarm_whatsapp_enabled",
    "alarm_whatsapp_recipients",
    "alarm_whatsapp_interval",
    "alarm_whatsapp_interval_unit",
    "alarm_whatsapp_last_sent",
    "alarm_server_enabled",
    "alarm_server_recipients",
    "alarm_server_interval",
    "alarm_server_interval_unit",
    "alarm_server_last_sent",
    "alarm_error_enabled",
    "alarm_error_recipients",
    "alarm_error_interval",
    "alarm_error_interval_unit",
    "alarm_error_last_sent"
]

const ERROR_RESPONSE_OPTIONS = [
    "Ops, acho que encontrei um erro aqui...me dá uns minutinhos",
    "Me chama em alguns minutos, me apareceu um problema aqui na minha programação ...",
    "Me chamaram aqui, parece que vai ter manutenção no sistema. Me chama daqui um pedacinho...",
    "Hmmm, deu problema aqui. Mas não se preocupe, me chama daqui a pouco que estarei bem",
    "Vou pedir uma pausa porque me deu um erro aqui no sistema, mas já já você pode me chamar, eu ficarei bem!"
]

function pickRandomErrorResponse() {
    const index = Math.floor(Math.random() * ERROR_RESPONSE_OPTIONS.length)
    return ERROR_RESPONSE_OPTIONS[index]
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

function getSelfJid() {
    const raw = sock?.user?.id || ""
    return raw ? raw.split(":")[0] : ""
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

async function getGroupMetadata(groupJid) {
    if (!groupJid) return null
    if (groupMetadataCache.has(groupJid)) {
        return groupMetadataCache.get(groupJid)
    }
    if (!sock || typeof sock.groupMetadata !== "function") {
        return null
    }
    try {
        const meta = await sock.groupMetadata(groupJid)
        if (meta) {
            groupMetadataCache.set(groupJid, meta)
        }
        return meta || null
    } catch (err) {
        return null
    }
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

const assistantFunctionNames = [
    "mail",
    "whatsapp",
    "get_web",
    "dados",
    "agendar",
    "agendar2",
    "boomerang",
    "listar_agendamentos",
    "apagar_agenda",
    "apagar_agendas_por_tag",
    "apagar_agendas_por_tipo",
    "cancelar_e_agendar2",
    "verificar_disponibilidade",
    "sugerir_horarios",
    "marcar_evento",
    "remarcar_evento",
    "desmarcar_evento",
    "listar_eventos",
    "set_estado",
    "get_estado",
    "set_contexto",
    "get_contexto",
    "limpar_contexto",
    "set_variavel",
    "get_variavel",
    "optout",
    "status_followup",
    "log_evento",
    "tempo_sem_interacao"
]

const assistantCommandPattern = `\\b(${assistantFunctionNames.join("|")})\\s*\\(`

function createAssistantCommandRegex() {
    return new RegExp(assistantCommandPattern, "gi")
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

function extractAssistantCommands(text) {
    const commands = []
    const ranges = []
    const regex = createAssistantCommandRegex()
    let match
    while ((match = regex.exec(text)) !== null) {
        const type = match[1]
        const start = match.index
        const openIndex = text.indexOf("(", match.index)
        if (openIndex === -1) {
            continue
        }
        let quote = null
        let escape = false
        let depth = 1
        let endIndex = -1
        for (let i = openIndex + 1; i < text.length; i++) {
            const char = text[i]
            if (escape) {
                escape = false
                continue
            }
            if (char === "\\") {
                escape = true
                continue
            }
            if (quote) {
                if (char === quote) {
                    quote = null
                }
                continue
            }
            if (char === '"' || char === "'") {
                quote = char
                continue
            }
            if (char === "(") {
                depth += 1
                continue
            }
            if (char === ")") {
                depth -= 1
                if (depth === 0) {
                    endIndex = i + 1
                    break
                }
            }
        }
        if (endIndex === -1) {
            continue
        }
        const rawArgs = text.slice(openIndex + 1, endIndex - 1)
        commands.push({
            type: type.toLowerCase(),
            args: parseFunctionArgs(rawArgs),
            position: {
                start,
                end: endIndex
            }
        })
        ranges.push({ start, end: endIndex })
        regex.lastIndex = endIndex
    }

    if (!commands.length) {
        return { commands: [], cleanedText: text }
    }

    ranges.sort((a, b) => a.start - b.start)
    let cleaned = ""
    let cursor = 0
    for (const range of ranges) {
        cleaned += text.slice(cursor, range.start)
        cursor = range.end
    }
    cleaned += text.slice(cursor)
    cleaned = cleaned
        .replace(/\s{2,}/g, " ")
        .replace(/\n{3,}/g, "\n\n")
        .trim()

    return { commands, cleanedText: cleaned }
}

function stripAssistantCalls(text) {
    if (!text) return text
    const { cleanedText } = extractAssistantCommands(text)
    return (cleanedText || "").trim()
}

function normalizeScheduleTag(value) {
    const trimmed = (value || "").trim()
    return trimmed || DEFAULT_SCHEDULE_TAG
}

function normalizeScheduleTipo(value) {
    const trimmed = (value || "").trim()
    return trimmed || DEFAULT_SCHEDULE_TIPO
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

async function buildInjectedPromptContext(remoteJid) {
    if (!db || !remoteJid) {
        return ""
    }

    let estado = null
    let contextEntries = []
    let persistentEntries = []
    let statusSummary = "pendentes=0; trilhas=nenhuma; proximo=nenhum"
    let tagsSummary = "nenhuma"

    try {
        estado = await db.getContactContext(INSTANCE_ID, remoteJid, "estado")
    } catch (err) {
        log("Erro ao obter estado do funil:", err.message)
    }

    try {
        contextEntries = await db.listContactContext(INSTANCE_ID, remoteJid)
    } catch (err) {
        log("Erro ao listar contexto do contato:", err.message)
    }

    try {
        const scheduled = await db.listScheduledMessages(INSTANCE_ID, remoteJid)
        const upcoming = scheduled.filter(msg => msg.status === "pending")
        const tracks = Array.from(new Set(upcoming.map(msg => `${msg.tag}:${msg.tipo}`)))
        const tags = Array.from(new Set(scheduled.map(msg => msg.tag).filter(Boolean)))
        const next = upcoming[0]
        statusSummary = `pendentes=${upcoming.length}; trilhas=${tracks.length ? tracks.join(", ") : "nenhuma"}; proximo=${next ? next.scheduled_at : "nenhum"}`
        tagsSummary = tags.length ? tags.join(", ") : "nenhuma"
    } catch (err) {
        log("Erro ao obter status de follow-up:", err.message)
    }

    try {
        if (typeof db.listPersistentVariables === "function") {
            persistentEntries = await db.listPersistentVariables(INSTANCE_ID)
        }
    } catch (err) {
        log("Erro ao listar variáveis persistentes:", err.message)
    }

    const filteredContext = contextEntries.filter(entry => entry?.key !== "estado")
    const estadoLabel = estado ? estado.trim() : "não definido"
    const contextLabel = formatContextEntries(filteredContext)
    const persistentLabel = formatContextEntries(persistentEntries)
    const nowLabel = formatCurrentDateTimeUtc3()

    return [
        `data_hora_utc3: ${nowLabel}`,
        `estado: ${estadoLabel}`,
        `contexto: ${contextLabel}`,
        `variaveis: ${persistentLabel}`,
        `tags: ${tagsSummary}`,
        `status_followup: ${statusSummary}`
    ].join("\n")
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

const WHATSAPP_CACHE_TTL_DAYS = 90

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

async function ensureContactMetadata(jid) {
    if (!db || !jid) {
        return
    }
    try {
        const metadata = await db.getContactMetadata(INSTANCE_ID, jid)
        const needsStatus = !metadata?.status_name
        const needsPicture = !metadata?.profile_picture
        if (!needsStatus && !needsPicture) {
            return
        }
        const [statusName, profilePicture] = await Promise.all([
            needsStatus ? fetchStatusName(jid) : Promise.resolve(null),
            needsPicture ? fetchProfilePictureUrl(jid) : Promise.resolve(null)
        ])
        await updateContactMetadata(jid, { statusName, profilePicture })
    } catch (err) {
        log("Erro ao atualizar metadata do contato:", err.message)
    }
}

async function ensureWhatsAppRecipientExists(jid) {
    if (!shouldCheckWhatsAppRecipient(jid)) {
        return true
    }
    if (!sock || !whatsappConnected) {
        throw new Error("whatsapp(): WhatsApp não conectado")
    }
    const phone = extractPhoneFromJid(jid)
    if (!phone) {
        throw new Error("whatsapp(): número inválido")
    }
    if (db?.getWhatsAppNumberCache) {
        try {
            const cached = await db.getWhatsAppNumberCache(phone, WHATSAPP_CACHE_TTL_DAYS)
            if (cached) {
                if (cached.exists) {
                    await ensureContactMetadata(`${phone}@s.whatsapp.net`)
                    return true
                }
                throw new Error("whatsapp(): número não existe no WhatsApp")
            }
        } catch (err) {
            log("Erro ao ler cache WhatsApp:", err.message)
        }
    }
    const result = await sock.onWhatsApp(phone)
    const resolvedJid = (Array.isArray(result) && result[0]?.jid) ? result[0].jid : jid
    const exists = Array.isArray(result) && result[0]?.exists === true
    if (db?.setWhatsAppNumberCache) {
        try {
            await db.setWhatsAppNumberCache(phone, exists)
        } catch (err) {
            log("Erro ao salvar cache WhatsApp:", err.message)
        }
    }
    if (!exists) {
        throw new Error("whatsapp(): número não existe no WhatsApp")
    }
    await ensureContactMetadata(resolvedJid)
    return true
}

async function sendWhatsAppMessage(jid, payload) {
    if (!sock || !whatsappConnected) {
        throw new Error("WhatsApp não conectado")
    }
    log("flow.send.request", {
        jid,
        kind: payload?.text
            ? "text"
            : payload?.image
                ? "image"
                : payload?.audio
                    ? "audio"
                : payload?.video
                    ? "video"
                    : payload?.contacts
                        ? "contact"
                        : "other"
    })
    const textPayload = (payload?.text || "").trim()
    if (textPayload) {
        let contactTemperature = 'warm'; // Default temperature
        try {
            const contactMetadata = await db.getContactMetadata(INSTANCE_ID, jid);
            if (contactMetadata && contactMetadata.temperature) {
                contactTemperature = contactMetadata.temperature;
            } else {
                // If temperature not explicitly set, calculate based on taxar
                const inboundCount = await db.getInboundMessageCount(INSTANCE_ID, jid);
                const outboundCount = await db.getOutboundMessageCount(INSTANCE_ID, jid);
                contactTemperature = determineTemperature(inboundCount, outboundCount);
                // Optionally, save the determined temperature back to the database
                if (db && typeof db.saveContactMetadata === 'function') {
                    await db.saveContactMetadata(INSTANCE_ID, jid, null, null, null, contactTemperature);
                }
            }
        } catch (err) {
            log("Error fetching or determining contact temperature:", err.message);
            // Fallback to default 'warm' temperature if there's an error
            contactTemperature = 'warm';
        }
        await simulateTypingIndicator(jid, textPayload, contactTemperature);
    }
    await ensureWhatsAppRecipientExists(jid)
    const result = await sock.sendMessage(jid, payload)
    const text = (payload?.text || "").trim()
    if (text) {
        const key = `${jid}|${text}`
        recentOutgoingText.set(key, Date.now())
        if (recentOutgoingText.size > RECENT_OUTGOING_LIMIT) {
            const cutoff = Date.now() - RECENT_OUTGOING_TTL_MS
            for (const [entryKey, ts] of recentOutgoingText.entries()) {
                if (ts < cutoff) {
                    recentOutgoingText.delete(entryKey)
                }
                if (recentOutgoingText.size <= RECENT_OUTGOING_LIMIT) {
                    break
                }
            }
        }
    }
    log("flow.send.done", { jid })
    return result
}

async function sendMailCommand(to, subject, body, from, isHtml = null) {
    if (!to) {
        throw new Error("mail(): endereço de destino ausente")
    }

    if (isHtml === null || isHtml === undefined) {
        const rawBody = body || ""
        const htmlHint = /<!doctype\s+html|<html\b|<body\b|<table\b|<div\b|<span\b|<p\b|<br\b/i
        isHtml = htmlHint.test(rawBody)
    }

    const sender = (from || "noreply@janeri.com.br").trim() || "noreply@janeri.com.br"
    const headers = [
        `From: ${sender}`,
        `To: ${to}`,
        `Subject: ${subject || "Sem assunto"}`
    ]
    if (isHtml) {
        headers.push("MIME-Version: 1.0")
        headers.push("Content-Type: text/html; charset=UTF-8")
        headers.push("Content-Transfer-Encoding: 8bit")
    }
    const mailData = `${headers.join("\n")}\n\n${body || ""}\n`
    return new Promise((resolve, reject) => {
        const child = exec("sendmail -t", (err, stdout, stderr) => {
            if (err) {
                reject(new Error(stderr?.trim() || err.message))
            } else {
                resolve(stdout?.trim() || "ok")
            }
        })
        child.stdin.write(mailData)
        child.stdin.end()
    })
}

async function sendWhatsAppCommand(remoteJid, message) {
    const jid = formatOutgoingJid(remoteJid)
    if (!jid) {
        throw new Error("whatsapp(): número inválido")
    }
    if (!sock || !whatsappConnected) {
        throw new Error("whatsapp(): WhatsApp não conectado")
    }

    await sendWhatsAppMessage(jid, { text: message })
    if (db) {
        await db.saveMessage(INSTANCE_ID, jid, "assistant", message, "outbound")
    }
    return jid
}

async function fetchWebCommand(url) {
    if (!url) {
        throw new Error("get_web(): URL obrigatória")
    }
    const response = await fetch(url, { headers: { "User-Agent": "Janeri Bot/1.0" }, method: "GET" })
    if (!response.ok) {
        throw new Error(`get_web(): HTTP ${response.status}`)
    }
    const text = await response.text()
    return text.slice(0, 1200)
}

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
    if (!db || typeof db.getCalendarAccount !== "function") {
        throw new Error("calendar: banco indisponível")
    }
    const account = await db.getCalendarAccount(instanceId)
    if (!account || !account.refresh_token) {
        throw new Error("calendar: integração não conectada")
    }
    return account
}

async function resolveCalendarConfig(instanceId, calendarIdArg) {
    if (!db || typeof db.listCalendarConfigs !== "function") {
        throw new Error("calendar: banco indisponível")
    }
    const calendarId = (calendarIdArg || "").trim()
    const configs = await db.listCalendarConfigs(instanceId)
    let config = null
    if (calendarId) {
        if (calendarId === 'primary') {
            config = configs.find(item => item.is_default) || configs[0] || null
            if (!config) {
                throw new Error(`calendar: nenhum calendário configurado`)
            }
        } else {
            config = configs.find(item => item.calendar_id === calendarId) || null
            if (!config) {
                throw new Error(`calendar: calendar_id ${calendarId} não configurado`)
            }
        }
    } else {
        config = configs.find(item => item.is_default) || configs[0] || null
    }
    if (!config) {
        return {
            calendar_id: "primary",
            timezone: null,
            availability: null
        }
    }
    return {
        calendar_id: config.calendar_id,
        timezone: config.timezone || null,
        availability: parseAvailabilityJson(config.availability_json)
    }
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
            await db.upsertCalendarAccount(instanceId, payload)
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

async function calendarCheckAvailability(instanceId, start, end, calendarIdArg, timeZoneArg) {
    const { calendar } = await getCalendarService(instanceId)
    const config = await resolveCalendarConfig(instanceId, calendarIdArg)
    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza"
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
    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza"
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
    const stepMinutes = normalizeAvailability(availability)?.step_minutes || 30
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

async function handleAssistantCommands(remoteJid, aiText, providedConfig = null, options = { allowBoomerang: true }) {
    const { commands, cleanedText } = extractAssistantCommands(aiText)
    if (!commands.length) {
        return { text: aiText }
    }

    const functionNotes = []

    for (const command of commands) {
        try {
            let result
            switch (command.type) {
                case "mail": {
                    await sendMailCommand(command.args[0], command.args[1], command.args[2], command.args[3])
                    log("Executor mail() acionado para", command.args[0])
                    result = buildFunctionResult(true, "OK", "email enviado", {
                        to: command.args[0] || "",
                        from: command.args[3] || "noreply@janeri.com.br"
                    })
                    break
                }
                case "whatsapp": {
                    const jid = await sendWhatsAppCommand(command.args[0], command.args[1])
                    log("Executor whatsapp() acionado para", command.args[0])
                    result = buildFunctionResult(true, "OK", "mensagem enviada", { jid })
                    break
                }
                case "get_web": {
                    const snippet = await fetchWebCommand(command.args[0])
                    log(`get_web() trouxe ${Math.min(snippet.length, 1200)} caracteres para`, command.args[0])
                    functionNotes.push(`get_web trouxe ${Math.min(snippet.length, 1200)} caracteres`)
                    result = buildFunctionResult(true, "OK", "conteúdo obtido", { snippet })
                    break
                }
                case "dados": {
                    const emailArg = (command.args[0] || "").trim()
                    if (!emailArg) {
                        throw new Error("dados(): email obrigatório")
                    }
                    try {
                        const profile = await fetchCustomerProfileByEmail(emailArg)
                        const note = `${profile.nome || profile.email} está ${profile.status}. Assinatura: ${profile.assinatura_info}${profile.data_expiracao ? ` • expira em ${profile.data_expiracao}` : ""}`
                        functionNotes.push(note)
                        log("dados() resultado", profile.email, profile.status)
                        command.result = profile
                    } catch (err) {
                        const message = err.message || "Usuário não encontrado"
                        const note = `Não encontrei cadastro para ${emailArg}.`
                        functionNotes.push(note)
                        log("dados() falhou", emailArg, message)
                        command.result = { error: message }
                    }
                    break
                }
                case "agendar": {
                    const dateArg = (command.args[0] || "").trim()
                    const timeArg = (command.args[1] || "").trim()
                    const messageArg = (command.args[2] || "").trim()
                    if (!dateArg || !timeArg || !messageArg) {
                        throw new Error("agendar(): data, hora e mensagem são obrigatórios")
                    }
                    const scheduledDate = buildScheduledDate(dateArg, timeArg)
                    if (!db) {
                        throw new Error("agendar(): banco de dados indisponível")
                    }
                    const tag = normalizeScheduleTag(command.args[3])
                    const tipo = normalizeScheduleTipo(command.args[4])
                    const annotation = await db.enqueueScheduledMessage(INSTANCE_ID, remoteJid, messageArg, scheduledDate, tag, tipo)
                    const preview = formatScheduledForResponse(scheduledDate)
                    const note = `Mensagem agendada para ${preview} (UTC-3) [${tag}/${tipo}]`
                    functionNotes.push(note)
                    log("agendar() inserido", annotation.scheduledId, remoteJid, preview, tag, tipo)
                    result = buildFunctionResult(true, "OK", "agendamento fixo criado", {
                        scheduledId: annotation.scheduledId,
                        scheduledAt: annotation.scheduledAt,
                        tag,
                        tipo
                    })
                    break
                }
                case "agendar2": {
                    const relativeArg = (command.args[0] || "").trim()
                    const messageArg = (command.args[1] || "").trim()
                    if (!relativeArg || !messageArg) {
                        throw new Error("agendar2(): tempo relativo e mensagem são obrigatórios")
                    }
                    const scheduledDate = buildRelativeDate(relativeArg)
                    if (!db) {
                        throw new Error("agendar2(): banco de dados indisponível")
                    }
                    const tag = normalizeScheduleTag(command.args[2])
                    const tipo = normalizeScheduleTipo(command.args[3])
                    const annotation = await db.enqueueScheduledMessage(INSTANCE_ID, remoteJid, messageArg, scheduledDate, tag, tipo)
                    const preview = formatScheduledForResponse(scheduledDate)
                    const note = `Mensagem agendada para ${preview} (UTC-3) via agendar2 [${tag}/${tipo}]`
                    functionNotes.push(note)
                    log("agendar2() inserido", annotation.scheduledId, remoteJid, preview, tag, tipo)
                    result = buildFunctionResult(true, "OK", "agendamento relativo criado", {
                        scheduledId: annotation.scheduledId,
                        scheduledAt: annotation.scheduledAt,
                        tag,
                        tipo
                    })
                    break
                }
                case "listar_agendamentos": {
                    if (!db) {
                        throw new Error("listar_agendamentos(): banco de dados indisponível")
                    }
                    const tagFilter = (command.args[0] || "").trim() || null
                    const tipoFilter = (command.args[1] || "").trim() || null
                    const list = await db.listScheduledMessages(INSTANCE_ID, remoteJid, tagFilter, tipoFilter)
                    functionNotes.push(`Listou ${list.length} agendamento(s)${tagFilter ? ` com tag ${tagFilter}` : ""}${tipoFilter ? ` tipo ${tipoFilter}` : ""}`)
                    result = buildFunctionResult(true, "OK", "lista de agendamentos", {
                        list,
                        filters: { tag: tagFilter, tipo: tipoFilter }
                    })
                    break
                }
                case "apagar_agenda": {
                    if (!db) {
                        throw new Error("apagar_agenda(): banco de dados indisponível")
                    }
                    const scheduledId = Number(command.args[0])
                    if (!Number.isFinite(scheduledId) || scheduledId <= 0) {
                        throw new Error("apagar_agenda(): scheduledId inválido")
                    }
                    const resultInfo = await db.deleteScheduledMessage(scheduledId)
                    functionNotes.push(`Agendamento ${scheduledId} removido`)
                    result = buildFunctionResult(true, "OK", "agendamento removido", resultInfo)
                    break
                }
                case "apagar_agendas_por_tag": {
                    if (!db) {
                        throw new Error("apagar_agendas_por_tag(): banco de dados indisponível")
                    }
                    const tagArg = (command.args[0] || "").trim()
                    if (!tagArg) {
                        throw new Error("apagar_agendas_por_tag(): tag obrigatória")
                    }
                    const removed = await db.deleteScheduledMessagesByTag(INSTANCE_ID, remoteJid, tagArg)
                    functionNotes.push(`Apagou ${removed.deleted} agendamento(s) com tag ${tagArg}`)
                    result = buildFunctionResult(true, "OK", "agendamentos por tag apagados", { deleted: removed.deleted, tag: tagArg })
                    break
                }
                case "apagar_agendas_por_tipo": {
                    if (!db) {
                        throw new Error("apagar_agendas_por_tipo(): banco de dados indisponível")
                    }
                    const tipoArg = (command.args[0] || "").trim()
                    if (!tipoArg) {
                        throw new Error("apagar_agendas_por_tipo(): tipo obrigatório")
                    }
                    const removed = await db.deleteScheduledMessagesByTipo(INSTANCE_ID, remoteJid, tipoArg)
                    functionNotes.push(`Apagou ${removed.deleted} agendamento(s) do tipo ${tipoArg}`)
                    result = buildFunctionResult(true, "OK", "agendamentos por tipo apagados", { deleted: removed.deleted, tipo: tipoArg })
                    break
                }
                case "cancelar_e_agendar2": {
                    if (!db) {
                        throw new Error("cancelar_e_agendar2(): banco de dados indisponível")
                    }
                    const relativeArg = (command.args[0] || "").trim()
                    const messageArg = (command.args[1] || "").trim()
                    if (!relativeArg || !messageArg) {
                        throw new Error("cancelar_e_agendar2(): tempo e mensagem são obrigatórios")
                    }
                    const tag = normalizeScheduleTag(command.args[2])
                    const tipo = normalizeScheduleTipo(command.args[3])
                    const canceled = await db.markPendingScheduledMessagesFailed(INSTANCE_ID, remoteJid, "cancelar_e_agendar2")
                    const scheduledDate = buildRelativeDate(relativeArg)
                    const annotation = await db.enqueueScheduledMessage(INSTANCE_ID, remoteJid, messageArg, scheduledDate, tag, tipo)
                    const summary = `Canceladas ${canceled.canceled} agendamentos pendentes e criado novo para ${formatScheduledForResponse(scheduledDate)} (${tag}/${tipo})`
                    functionNotes.push(summary)
                    result = buildFunctionResult(true, "OK", "cadência resetada", {
                        canceledCount: canceled.canceled,
                        newScheduledId: annotation.scheduledId,
                        newScheduledAt: annotation.scheduledAt,
                        tag,
                        tipo
                    })
                    break
                }
                case "verificar_disponibilidade": {
                    await ensureCalendarConnection(INSTANCE_ID)
                    const slot = parseCalendarSlotArgs(command.args || [], null)
                    if (slot.end <= slot.start) {
                        throw new Error("calendar: horário inválido")
                    }
                    const availability = await calendarCheckAvailability(
                        INSTANCE_ID,
                        slot.start,
                        slot.end,
                        slot.calendarId,
                        slot.timeZone
                    )
                    const note = availability.available
                        ? "Horário disponível no Google Calendar"
                        : "Horário indisponível no Google Calendar"
                    functionNotes.push(note)
                    result = buildFunctionResult(true, "OK", note, {
                        available: availability.available,
                        calendarId: availability.calendarId,
                        timeZone: availability.timeZone,
                        start: slot.start.toISOString(),
                        end: slot.end.toISOString(),
                        local_start: formatDateTimeInTimeZone(slot.start, availability.timeZone),
                        local_end: formatDateTimeInTimeZone(slot.end, availability.timeZone),
                        busyCount: availability.busyCount
                    })
                    break
                }
                case "sugerir_horarios": {
                    await ensureCalendarConnection(INSTANCE_ID)
                    const dateArg = (command.args[0] || "").trim()
                    const windowArg = (command.args[1] || "").trim()
                    const durationArg = (command.args[2] || "").trim()
                    const limitArg = (command.args[3] || "").trim()
                    const calendarIdArg = (command.args[4] || "").trim()
                    const timeZoneArg = (command.args[5] || "").trim()
                    if (!dateArg || !windowArg) {
                        throw new Error("sugerir_horarios(): data e janela são obrigatórias")
                    }
                    const payload = await calendarSuggestSlots(
                        INSTANCE_ID,
                        dateArg,
                        windowArg,
                        durationArg,
                        limitArg,
                        calendarIdArg,
                        timeZoneArg
                    )
                    const note = payload.suggestions.length
                        ? `Sugestões encontradas: ${payload.suggestions.length}`
                        : "Nenhum horário disponível"
                    functionNotes.push(note)
                    result = buildFunctionResult(true, "OK", note, payload)
                    break
                }
                case "marcar_evento": {
                    await ensureCalendarConnection(INSTANCE_ID)
                    const title = (command.args[0] || "").trim()
                    const startArg = (command.args[1] || "").trim()
                    const endArg = (command.args[2] || "").trim()
                    const attendeesArg = (command.args[3] || "").trim()
                    const description = (command.args[4] || "").trim()
                    const calendarIdArg = (command.args[5] || "").trim()
                    const timeZoneArg = (command.args[6] || "").trim()
                    if (!title || !startArg || !endArg) {
                        throw new Error("marcar_evento(): título, início e fim são obrigatórios")
                    }
                    const config = await resolveCalendarConfig(INSTANCE_ID, calendarIdArg)
                    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza"
                    const start = parseCalendarDateTime(startArg, null, timeZone)
                    const end = parseCalendarDateTime(endArg, null, timeZone)
                    if (end <= start) {
                        throw new Error("marcar_evento(): horário inválido")
                    }
                    if (!isSlotWithinAvailability(start, end, config.availability, timeZone)) {
                        throw new Error("marcar_evento(): horário fora da disponibilidade configurada")
                    }
                    const { calendar } = await getCalendarService(INSTANCE_ID)
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
                    const note = "Evento criado no Google Calendar"
                    functionNotes.push(note)
                    result = buildFunctionResult(true, "OK", note, {
                        calendarId: config.calendar_id,
                        eventId: created.id,
                        htmlLink: created.htmlLink || null,
                        start: start.toISOString(),
                        end: end.toISOString(),
                        local_start: formatDateTimeInTimeZone(start, timeZone),
                        local_end: formatDateTimeInTimeZone(end, timeZone)
                    })
                    break
                }
                case "remarcar_evento": {
                    await ensureCalendarConnection(INSTANCE_ID)
                    const eventId = (command.args[0] || "").trim()
                    const startArg = (command.args[1] || "").trim()
                    const endArg = (command.args[2] || "").trim()
                    const calendarIdArg = (command.args[3] || "").trim()
                    const timeZoneArg = (command.args[4] || "").trim()
                    if (!eventId || !startArg || !endArg) {
                        throw new Error("remarcar_evento(): id, início e fim são obrigatórios")
                    }
                    const config = await resolveCalendarConfig(INSTANCE_ID, calendarIdArg)
                    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza"
                    const start = parseCalendarDateTime(startArg, null, timeZone)
                    const end = parseCalendarDateTime(endArg, null, timeZone)
                    if (end <= start) {
                        throw new Error("remarcar_evento(): horário inválido")
                    }
                    if (!isSlotWithinAvailability(start, end, config.availability, timeZone)) {
                        throw new Error("remarcar_evento(): horário fora da disponibilidade configurada")
                    }
                    const { calendar } = await getCalendarService(INSTANCE_ID)
                    const response = await calendar.events.patch({
                        calendarId: config.calendar_id,
                        eventId,
                        requestBody: {
                            start: { dateTime: formatCalendarDateTime(start, timeZone), timeZone },
                            end: { dateTime: formatCalendarDateTime(end, timeZone), timeZone }
                        }
                    })
                    const updated = response.data || {}
                    const note = "Evento remarcado no Google Calendar"
                    functionNotes.push(note)
                    result = buildFunctionResult(true, "OK", note, {
                        calendarId: config.calendar_id,
                        eventId: updated.id,
                        htmlLink: updated.htmlLink || null,
                        start: start.toISOString(),
                        end: end.toISOString(),
                        local_start: formatDateTimeInTimeZone(start, timeZone),
                        local_end: formatDateTimeInTimeZone(end, timeZone)
                    })
                    break
                }
                case "desmarcar_evento": {
                    await ensureCalendarConnection(INSTANCE_ID)
                    const eventId = (command.args[0] || "").trim()
                    const calendarIdArg = (command.args[1] || "").trim()
                    if (!eventId) {
                        throw new Error("desmarcar_evento(): id obrigatório")
                    }
                    const config = await resolveCalendarConfig(INSTANCE_ID, calendarIdArg)
                    const { calendar } = await getCalendarService(INSTANCE_ID)
                    await calendar.events.delete({
                        calendarId: config.calendar_id,
                        eventId
                    })
                    const note = "Evento cancelado no Google Calendar"
                    functionNotes.push(note)
                    result = buildFunctionResult(true, "OK", note, {
                        calendarId: config.calendar_id,
                        eventId
                    })
                    break
                }
                case "listar_eventos": {
                    await ensureCalendarConnection(INSTANCE_ID)
                    const startArg = (command.args[0] || "").trim()
                    const endArg = (command.args[1] || "").trim()
                    const calendarIdArg = (command.args[2] || "").trim()
                    const timeZoneArg = (command.args[3] || "").trim()
                    if (!startArg || !endArg) {
                        throw new Error("listar_eventos(): início e fim são obrigatórios")
                    }
                    const config = await resolveCalendarConfig(INSTANCE_ID, calendarIdArg)
                    const timeZone = timeZoneArg || config.timezone || "America/Fortaleza"
                    const start = parseCalendarDateTime(startArg, null, timeZone)
                    const end = parseCalendarDateTime(endArg, null, timeZone)
                    if (end <= start) {
                        throw new Error("listar_eventos(): intervalo inválido")
                    }
                    const { calendar } = await getCalendarService(INSTANCE_ID)
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
                    const note = `Eventos encontrados: ${events.length}`
                    functionNotes.push(note)
                    result = buildFunctionResult(true, "OK", note, {
                        calendarId: config.calendar_id,
                        timeZone,
                        events
                    })
                    break
                }
                case "set_estado": {
                    if (!db) {
                        throw new Error("set_estado(): banco de dados indisponível")
                    }
                    const state = (command.args[0] || "").trim()
                    if (!state) {
                        throw new Error("set_estado(): estado obrigatório")
                    }
                    await db.setContactContext(INSTANCE_ID, remoteJid, "estado", state)
                    functionNotes.push(`Estado do funil definido como ${state}`)
                    result = buildFunctionResult(true, "OK", "estado salvo", { estado: state })
                    break
                }
                case "get_estado": {
                    if (!db) {
                        throw new Error("get_estado(): banco de dados indisponível")
                    }
                    const stateValue = await db.getContactContext(INSTANCE_ID, remoteJid, "estado")
                    const message = stateValue ? `Estado atual: ${stateValue}` : "Estado ainda não definido"
                    functionNotes.push(message)
                    result = buildFunctionResult(true, "OK", message, { estado: stateValue })
                    break
                }
                case "set_contexto": {
                    if (!db) {
                        throw new Error("set_contexto(): banco de dados indisponível")
                    }
                    const key = (command.args[0] || "").trim()
                    const value = (command.args[1] || "").trim()
                    if (!key || value === "") {
                        throw new Error("set_contexto(): chave e valor obrigatórios")
                    }
                    await db.setContactContext(INSTANCE_ID, remoteJid, key, value)
                    functionNotes.push(`Contexto ${key} definido`)
                    result = buildFunctionResult(true, "OK", "contexto atualizado", { key, value })
                    break
                }
                case "get_contexto": {
                    if (!db) {
                        throw new Error("get_contexto(): banco de dados indisponível")
                    }
                    const key = (command.args[0] || "").trim()
                    if (!key) {
                        throw new Error("get_contexto(): chave obrigatória")
                    }
                    const value = await db.getContactContext(INSTANCE_ID, remoteJid, key)
                    const message = value ? `Contexto ${key}: ${value}` : `Contexto ${key} não encontrado`
                    functionNotes.push(message)
                    result = buildFunctionResult(true, "OK", message, { key, value })
                    break
                }
                case "limpar_contexto": {
                    if (!db) {
                        throw new Error("limpar_contexto(): banco de dados indisponível")
                    }
                    const raw = (command.args[0] || "").trim()
                    let keys = []
                    if (raw) {
                        try {
                            const parsed = JSON.parse(raw)
                            if (Array.isArray(parsed)) {
                                keys = parsed.map(item => String(item).trim()).filter(Boolean)
                            } else if (typeof parsed === "string") {
                                keys = [parsed.trim()]
                            }
                        } catch {
                            keys = [raw]
                        }
                    }
                    const deleted = await db.deleteContactContext(INSTANCE_ID, remoteJid, keys)
                    const target = keys.length ? keys.join(", ") : "todos"
                    functionNotes.push(`Contexto limpo (${target})`)
                    result = buildFunctionResult(true, "OK", "contexto limpo", { deleted: deleted.deleted, keys })
                    break
                }
                case "set_variavel": {
                    if (!db) {
                        throw new Error("set_variavel(): banco de dados indisponível")
                    }
                    const key = (command.args[0] || "").trim()
                    const value = (command.args[1] || "").trim()
                    if (!key || value === "") {
                        throw new Error("set_variavel(): chave e valor obrigatórios")
                    }
                    await db.setPersistentVariable(INSTANCE_ID, key, value)
                    functionNotes.push(`Variável ${key} salva`)
                    result = buildFunctionResult(true, "OK", "variável persistente salva", { key, value })
                    break
                }
                case "get_variavel": {
                    if (!db) {
                        throw new Error("get_variavel(): banco de dados indisponível")
                    }
                    const key = (command.args[0] || "").trim()
                    if (!key) {
                        throw new Error("get_variavel(): chave obrigatória")
                    }
                    const value = await db.getPersistentVariable(INSTANCE_ID, key)
                    const message = value ? `Variável ${key}: ${value}` : `Variável ${key} não encontrada`
                    functionNotes.push(message)
                    result = buildFunctionResult(true, "OK", message, { key, value })
                    break
                }
                case "optout": {
                    if (!db) {
                        throw new Error("optout(): banco de dados indisponível")
                    }
                    await db.setContactContext(INSTANCE_ID, remoteJid, "optout", "true")
                    const canceled = await db.markPendingScheduledMessagesFailed(INSTANCE_ID, remoteJid, "optout")
                    functionNotes.push(`Opt-out ativado, ${canceled.canceled} agendamento(s) cancelados`)
                    result = buildFunctionResult(true, "OK", "opt-out registrado", { canceled: canceled.canceled })
                    break
                }
                case "status_followup": {
                    if (!db) {
                        throw new Error("status_followup(): banco de dados indisponível")
                    }
                    const estado = await db.getContactContext(INSTANCE_ID, remoteJid, "estado")
                    const upcoming = (await db.listScheduledMessages(INSTANCE_ID, remoteJid)).filter(msg => msg.status === "pending")
                    const tracks = Array.from(new Set(upcoming.map(msg => `${msg.tag}:${msg.tipo}`)))
                    const next = upcoming[0]
                    const summary = {
                        estado,
                        trilhasAtivas: tracks,
                        pending: upcoming.length,
                        nextScheduled: next ? next.scheduled_at : null
                    }
                    functionNotes.push(`Status follow-up: ${upcoming.length} agendamentos pendentes`)
                    result = buildFunctionResult(true, "OK", "status de follow-up", summary)
                    break
                }
                case "log_evento": {
                    if (!db) {
                        throw new Error("log_evento(): banco de dados indisponível")
                    }
                    const category = (command.args[0] || "").trim()
                    const description = (command.args[1] || "").trim()
                    const metadata = (command.args[2] || "").trim() || null
                    if (!category || !description) {
                        throw new Error("log_evento(): categoria e descrição obrigatórias")
                    }
                    const logged = await db.logEvent(INSTANCE_ID, remoteJid, category, description, metadata)
                    functionNotes.push(`Evento ${category} registrado`)
                    result = buildFunctionResult(true, "OK", "evento logado", { loggedId: logged.loggedId })
                    break
                }
                case "tempo_sem_interacao": {
                    if (!db) {
                        throw new Error("tempo_sem_interacao(): banco de dados indisponível")
                    }
                    const lastInbound = await db.getTimeSinceLastInboundMessage(INSTANCE_ID, remoteJid)
                    let seconds = null
                    if (lastInbound) {
                        seconds = Math.max(0, Math.floor((Date.now() - lastInbound.getTime()) / 1000))
                    }
                    const message = lastInbound ? `Última interação há ${seconds}s` : "Sem registro de interação recente"
                    functionNotes.push(message)
                    result = buildFunctionResult(true, "OK", message, {
                        seconds,
                        lastTimestamp: lastInbound?.toISOString() || null
                    })
                    break
                }
                case "boomerang": {
                    const note = "Boomerang acionado"
                    functionNotes.push(note)
                    result = buildFunctionResult(true, "OK", note, { info: note })
                    if (options.allowBoomerang) {
                        try {
                            await dispatchAIResponse(remoteJid, "Boomerang acionado", providedConfig, { allowBoomerang: false })
                        } catch (err) {
                            log("Erro no boomerang:", err.message)
                        }
                    }
                    break
                }
                default: {
                    log("Função desconhecida:", command.type)
                }
            }

            if (result) {
                command.result = result
            }
        } catch (err) {
            log(`Erro ao executar função ${command.type}:`, err.message)
            command.result = buildFunctionResult(false, "ERR_EXECUTION", err.message || "Erro interno")
        }
    }

    const responseText = cleanedText?.trim() ? cleanedText.trim() : ""
    const notes = functionNotes
        .map(note => (note || "").trim())
        .filter(Boolean)

    return { text: stripAssistantCalls(responseText), commands, notes }
}

let scheduleProcessorHandle = null
let scheduleProcessorRunning = false

async function processScheduledMessages() {
    if (scheduleProcessorRunning) {
        return
    }
    if (!db || !sock || !whatsappConnected) {
        return
    }

    scheduleProcessorRunning = true

    try {
        const dueMessages = await db.fetchDueScheduledMessages(INSTANCE_ID, SCHEDULE_FETCH_LIMIT)
        const dueGroupMessages = await db.fetchDueGroupScheduledMessages(INSTANCE_ID, SCHEDULE_FETCH_LIMIT)
        if (!dueMessages.length && !dueGroupMessages.length) {
            return
        }

        for (const job of dueMessages) {
            try {
                await sendWhatsAppMessage(job.remote_jid, { text: job.message })
                await db.saveMessage(INSTANCE_ID, job.remote_jid, "assistant", job.message, "outbound")
                await db.updateScheduledMessageStatus(job.id, "sent")
                log("Mensagem agendada enviada para", job.remote_jid, job.scheduled_at)
            } catch (err) {
                await db.updateScheduledMessageStatus(job.id, "failed", err.message)
                log("Erro ao enviar mensagem agendada", job.id, err.message)
            }
        }

        for (const job of dueGroupMessages) {
            try {
                await sendWhatsAppMessage(job.group_jid, { text: job.message })
                await db.saveGroupMessage(INSTANCE_ID, job.group_jid, getSelfJid(), "outbound", job.message, JSON.stringify({ scheduled: true }))
                await db.updateGroupScheduledMessageStatus(job.id, "sent")
                log("Mensagem agendada enviada para grupo", job.group_jid, job.scheduled_at)
            } catch (err) {
                await db.updateGroupScheduledMessageStatus(job.id, "failed", err.message)
                log("Erro ao enviar mensagem agendada de grupo", job.id, err.message)
            }
        }
    } catch (err) {
        log("Erro ao processar agendamentos:", err.message)
    }
    finally {
        scheduleProcessorRunning = false
    }
}

function startScheduleProcessor() {
    if (scheduleProcessorHandle) return
    processScheduledMessages().catch(err => log("Erro inicial no scheduler:", err.message))
    scheduleProcessorHandle = setInterval(() => {
        processScheduledMessages().catch(err => log("Erro no scheduler:", err.message))
    }, SCHEDULE_CHECK_INTERVAL_MS)
}

async function fetchProfilePictureUrl(remoteJid) {
    if (!sock || !remoteJid) return null
    try {
        const url = await sock.profilePictureUrl(remoteJid, "image")
        return normalizeMetaField(url)
    } catch (err) {
        return null
    }
}

async function fetchStatusName(remoteJid) {
    if (!sock || !remoteJid) return null
    try {
        const statusData = await sock.fetchStatus(remoteJid)
        return normalizeMetaField(statusData?.status)
    } catch (err) {
        return null
    }
}

async function updateContactMetadata(remoteJid, updates = {}) {
    if (!db || !remoteJid) return
    const payload = {
        contactName: normalizeMetaField(updates.contactName),
        statusName: normalizeMetaField(updates.statusName),
        profilePicture: normalizeMetaField(updates.profilePicture)
    }
    if (!payload.contactName && !payload.statusName && !payload.profilePicture) {
        return
    }
    try {
        await db.saveContactMetadata(INSTANCE_ID, remoteJid, payload.contactName, payload.statusName, payload.profilePicture)
    } catch (err) {
        log("Error saving contact metadata:", err.message)
    }
}

async function handleContactUpsert(contact) {
    if (!contact) return
    const remoteJid = contact.id
    if (!isIndividualJid(remoteJid)) return

    const contactName = normalizeMetaField(contact.notify || contact.name)
    const statusName = normalizeMetaField(contact.status)

    let profilePicture = null
    const imgHint = normalizeMetaField(contact.imgUrl)
    if (imgHint && imgHint !== "changed") {
        profilePicture = imgHint
    } else {
        profilePicture = await fetchProfilePictureUrl(remoteJid)
    }

    await updateContactMetadata(remoteJid, { contactName, statusName, profilePicture })
}

async function handleContactFromMessage(msg) {
    const remoteJid = msg?.key?.remoteJid
    if (!isIndividualJid(remoteJid)) return
    const pushName = normalizeMetaField(msg.pushName)
    if (!pushName) return
    await updateContactMetadata(remoteJid, { contactName: pushName })
}

function toNumber(value, fallback) {
    const num = typeof value === "number" ? value : parseFloat(value)
    return Number.isFinite(num) ? num : fallback
}

function extractMessageContentText(message) {
    if (!message?.content?.length) return ""
    return message.content
        .map(block => {
            if (block.type === "text" && block.text && block.text.value) {
                return block.text.value
            }
            if (typeof block.text === "string") {
                return block.text
            }
            return ""
        })
        .filter(Boolean)
        .join(" ")
        .trim()
}

function appendInjectedInstruction(base, injected) {
    const baseText = (base || "").trim()
    const injectedText = (injected || "").trim()
    if (!injectedText) {
        return baseText
    }
    const header = "Contexto interno (não exibir ao usuário):"
    const block = `${header}\n${injectedText}`
    return baseText ? `${baseText}\n\n${block}` : block
}

function buildResponsesPayload(historyMessages, messageBody, config) {
    const conversation = []
    const systemPrompt = appendInjectedInstruction(config.system_prompt, config.injected_context)
    if (systemPrompt) {
        conversation.push({ role: "system", content: systemPrompt })
    }
    if (config.assistant_prompt) {
        conversation.push({ role: "assistant", content: config.assistant_prompt })
    }
    historyMessages.forEach(row => {
        conversation.push({ role: row.role, content: row.content })
    })
    conversation.push({ role: "user", content: messageBody })
    return conversation
}

function collectResponseText(response) {
    if (!response) return null
    if (typeof response.output_text === "string" && response.output_text.trim()) {
        return response.output_text.trim()
    }
    const fragments = []
    const outputs = Array.isArray(response.output) ? response.output : []
    for (const item of outputs) {
        const content = Array.isArray(item?.content) ? item.content : []
        for (const piece of content) {
            if (typeof piece.text === "string" && piece.text.trim()) {
                fragments.push(piece.text.trim())
            } else if (typeof piece.output_text === "string" && piece.output_text.trim()) {
                fragments.push(piece.output_text.trim())
            } else if (typeof piece.value === "string" && piece.value.trim()) {
                fragments.push(piece.value.trim())
            }
        }
    }
    return fragments.join(" ").trim() || null
}

const pendingMultiInputs = new Map()

async function fetchHistoryRows(remoteJid, limit) {
    if (!db) return []
    return db.getLastMessages(INSTANCE_ID, remoteJid, limit)
}

async function handleGroupMessage(msg) {
    if (!db || !msg?.message) return
    const groupJid = msg.key?.remoteJid
    if (!isGroupJid(groupJid)) return

    const groupConfig = await db.getMonitoredGroup(INSTANCE_ID, groupJid)
    if (!groupConfig) return

    const participantJid = msg.key?.participant
        || msg.participant
        || msg.message?.extendedTextMessage?.contextInfo?.participant
        || null
    const content = extractInboundMessageText(msg.message) || "Mensagem recebida"
    const meta = {
        messageId: msg.key?.id,
        pushName: msg.pushName || null,
        hasMedia: Boolean(detectMediaPayload(msg.message))
    }
    await db.saveGroupMessage(INSTANCE_ID, groupJid, participantJid, "inbound", content, JSON.stringify(meta))

    const mentionedJids = getMentionedJids(msg.message)
    const selfJid = getSelfJid()
    const normalizedSelf = selfJid ? selfJid.split(":")[0] : ""
    const wasMentioned = normalizedSelf && mentionedJids.some(jid => (jid || "").split(":")[0] === normalizedSelf)
    if (!wasMentioned) {
        return
    }

    const replyConfig = await db.getGroupAutoReplies(INSTANCE_ID, groupJid)
    if (!replyConfig?.enabled) {
        return
    }
    let replyPool = []
    try {
        replyPool = JSON.parse(replyConfig.replies_json || "[]")
    } catch {
        replyPool = []
    }
    const sanitizedPool = Array.isArray(replyPool)
        ? replyPool.map(entry => String(entry || "").trim()).filter(Boolean)
        : []
    if (!sanitizedPool.length) {
        return
    }
    const chosen = sanitizedPool[Math.floor(Math.random() * sanitizedPool.length)]
    if (!sock || !whatsappConnected) {
        return
    }
    try {
        await sendWhatsAppMessage(groupJid, { text: chosen })
        await db.saveGroupMessage(
            INSTANCE_ID,
            groupJid,
            normalizedSelf || null,
            "outbound",
            chosen,
            JSON.stringify({ auto_reply: true })
        )
    } catch (err) {
        log("Erro ao responder grupo automaticamente:", err.message)
    }
}

async function generateOpenAIResponse(aiConfig, remoteJid, messageBody) {
    if (!aiConfig.openai_api_key) {
        throw new Error("Chave OpenAI não configurada")
    }
    const openai = new OpenAI({ apiKey: aiConfig.openai_api_key })

    if (aiConfig.openai_mode === "assistants") {
        if (!aiConfig.assistant_id) {
            throw new Error("Assistant ID necessário para o modo Assistants")
        }
        const instructionText = appendInjectedInstruction(aiConfig.system_prompt, aiConfig.injected_context) || undefined

        const threadMeta = db ? await db.getThreadMetadata(INSTANCE_ID, remoteJid) : null
        let threadId = threadMeta?.threadId || null

        if (!threadId) {
            const run = await openai.beta.threads.createAndRun({
                assistant_id: aiConfig.assistant_id,
                model: aiConfig.model,
                temperature: aiConfig.temperature,
                max_completion_tokens: aiConfig.max_tokens,
                instructions: instructionText,
                additional_instructions: aiConfig.assistant_prompt || undefined,
                additional_messages: [{ role: "user", content: messageBody }],
                truncation_strategy: {
                    type: "last_messages",
                    last_messages: aiConfig.history_limit
                }
            })
            threadId = run.thread_id
        } else {
            await openai.beta.threads.runs.create(threadId, {
                assistant_id: aiConfig.assistant_id,
                model: aiConfig.model,
                temperature: aiConfig.temperature,
                max_completion_tokens: aiConfig.max_tokens,
                instructions: instructionText,
                additional_instructions: aiConfig.assistant_prompt || undefined,
                additional_messages: [{ role: "user", content: messageBody }],
                truncation_strategy: {
                    type: "last_messages",
                    last_messages: aiConfig.history_limit
                }
            })
        }

        if (!threadId) {
            throw new Error("Não foi possível obter thread_id do Assistants API")
        }

        const assistantMessage = await fetchAssistantMessageFromThread(
            openai.beta.threads,
            threadId,
            threadMeta?.lastMessageId,
            aiConfig.history_limit
        )

        if (!assistantMessage) {
            throw new Error("Assistants API não retornou resposta")
        }

        if (db && threadId && assistantMessage.messageId) {
            try {
                await db.saveThreadMetadata(
                    INSTANCE_ID,
                    remoteJid,
                    threadId,
                    assistantMessage.messageId
                )
            } catch (metaErr) {
                log("Error saving thread metadata:", metaErr.message)
            }
        }

        return {
            text: assistantMessage.text,
            threadId,
            lastMessageId: assistantMessage.messageId
        }
    }

    const historyMessages = await fetchHistoryRows(remoteJid, aiConfig.history_limit)
    log("generateOpenAIResponse (Responses mode)", {
        remoteJid,
        model: aiConfig.model,
        historyLength: historyMessages.length,
        snippet: snippet(messageBody, 120)
    })
    const payload = buildResponsesPayload(historyMessages, messageBody, aiConfig)
    const response = await openai.responses.create({
        model: aiConfig.model,
        input: payload,
        temperature: aiConfig.temperature,
        max_output_tokens: aiConfig.max_tokens
    })

    const text = collectResponseText(response)
    if (!text) {
        throw new Error("Resposta inválida da OpenAI Responses API")
    }

    return { text }
}

function buildGeminiConversationParts(historyRows, userMessage) {
    const parts = []
    const pushText = text => {
        const trimmed = (text || "").trim()
        if (!trimmed) return
        parts.push({ text: trimmed })
    }

    for (const row of Array.isArray(historyRows) ? historyRows : []) {
        const cleaned = (row.content || "").trim()
        if (!cleaned) continue
        const label = row.role === "assistant" ? "Assistente" : "Usuário"
        pushText(`${label}: ${cleaned}`)
    }

    if (userMessage) {
        pushText(`Usuário: ${userMessage}`)
    }

    return parts
}

async function callGeminiContent(aiConfig, parts) {
    if (!aiConfig.gemini_api_key) {
        throw new Error("Chave Gemini não configurada")
    }
    if (!Array.isArray(parts) || parts.length === 0) {
        throw new Error("Nenhum conteúdo válido para enviar ao Gemini")
    }

    const model = aiConfig.model || "gemini-2.5-flash"
    const endpoint = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent?key=${encodeURIComponent(aiConfig.gemini_api_key)}`
    const instructionText = appendInjectedInstruction(
        aiConfig.gemini_instruction || DEFAULT_GEMINI_INSTRUCTION,
        aiConfig.injected_context
    ).trim()
    const payloadParts = parts.slice()
    if (instructionText) {
        payloadParts.unshift({ text: instructionText })
    }

    const payload = {
        contents: [{ parts: payloadParts }]
    }

    const response = await fetch(endpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    })

    const responseText = await response.text()
    let json = null
    try {
        json = responseText ? JSON.parse(responseText) : null
    } catch (err) {
        throw new Error(`Resposta inválida da Gemini: ${err.message}`)
    }

    if (!response.ok) {
        const detail = json?.error?.message || response.statusText || "Erro desconhecido"
        throw new Error(`Gemini API error: ${detail}`)
    }

    const partsList = json?.candidates?.[0]?.content?.parts || []
    const resultText = partsList.find(part => typeof part.text === "string" && part.text.trim())?.text?.trim() || ""

    if (!resultText) {
        throw new Error("Gemini retornou resposta vazia")
    }

    return resultText
}

function normalizeGeminiMimeType(rawMime) {
    const cleaned = (rawMime || "").trim().toLowerCase()
    if (!cleaned) {
        return "application/octet-stream"
    }
    if (GEMINI_ALLOWED_MEDIA_MIME_TYPES.has(cleaned)) {
        return cleaned
    }
    if (cleaned.startsWith("image/")) {
        return "image/jpeg"
    }
    if (cleaned.startsWith("audio/")) {
        return "audio/mp3"
    }
    return "application/octet-stream"
}

function fileToGenerativePart(filePath) {
    const detectedMime = mime.lookup(filePath)
    const normalizedMime = normalizeGeminiMimeType(detectedMime)

    const buffer = fs.readFileSync(filePath)
    if (!buffer || buffer.length === 0) {
        throw new Error(`Arquivo vazio ou inválido: ${filePath}`)
    }

    return {
        inlineData: {
            mimeType: normalizedMime,
            data: buffer.toString("base64")
        }
    }
}

function buildMediaPromptText(mediaEntries, textEntries = []) {
    const textChunk = textEntries
        .map(entry => entry?.text || "")
        .map(text => text.trim())
        .filter(Boolean)
        .join("\n")

    const mediaLines = (mediaEntries || [])
        .map((entry, index) => {
            const payload = entry?.mediaPayload
            if (!payload) return null
            const captionText = (payload.node?.caption || "").trim()
            const fallbackDesc = payload.fallbackDescription || ""
            const description = captionText || fallbackDesc
            const label = payload.type === "imagem" ? "IMAGEM" : "MÍDIA"
            if (!description) {
                return `${label} ${index + 1}`
            }
            return `${label} ${index + 1}: ${description}`
        })
        .filter(Boolean)

    const parts = []
    if (textChunk) {
        parts.push(textChunk)
    }
    if (mediaLines.length) {
        parts.push(...mediaLines)
    }
    return parts.join("\n").trim()
}

function normalizeSecretaryTerm(value) {
    return (value || "").trim().toLowerCase()
}

function pickSecretaryTermResponse(messageBody, config) {
    const normalizedMessage = normalizeSecretaryTerm(messageBody)
    if (!normalizedMessage) {
        return null
    }
    const quickReplies = Array.isArray(config?.quick_replies) ? config.quick_replies : []
    for (const entry of quickReplies) {
        const term = normalizeSecretaryTerm(entry?.term)
        if (term && normalizedMessage === term) {
            return (entry?.response || "").trim() || null
        }
    }
    const term1 = normalizeSecretaryTerm(config?.term_1)
    if (term1 && normalizedMessage === term1) {
        return (config?.response_1 || "").trim() || null
    }
    const term2 = normalizeSecretaryTerm(config?.term_2)
    if (term2 && normalizedMessage === term2) {
        return (config?.response_2 || "").trim() || null
    }
    return null
}

function shouldTriggerSecretaryInitial(lastInboundAt, config) {
    if (!config?.enabled) return false
    const idleHours = Math.max(0, toNumber(config?.idle_hours, 0))
    if (idleHours <= 0) return false
    const initialText = (config?.initial_response || "").trim()
    if (!initialText) return false
    if (!lastInboundAt) return true
    const last = lastInboundAt instanceof Date ? lastInboundAt : new Date(lastInboundAt)
    if (Number.isNaN(last?.getTime?.())) return true
    const elapsedMs = Date.now() - last.getTime()
    return elapsedMs > idleHours * 60 * 60 * 1000
}

async function sendSecretaryReply(remoteJid, message, reason = "secretary") {
    const trimmed = (message || "").trim()
    if (!trimmed) {
        return
    }
    if (!sock) {
        throw new Error("WhatsApp não conectado")
    }
    const segments = splitHashSegments(trimmed)
    if (!segments.length) {
        segments.push(trimmed)
    }
    const metadata = JSON.stringify({ source: "secretary", reason })

    for (const segment of segments) {
        await sendWhatsAppMessage(remoteJid, { text: segment })
        if (db) {
            try {
                await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", segment, "outbound", metadata)
            } catch (err) {
                log("Error saving secretary reply:", err.message)
            }
        }
    }
}

async function processOwnerQuickReply(msg) {
    if (!msg?.key?.fromMe || !msg.message) {
        return
    }
    const remoteJid = msg.key.remoteJid
    if (!isIndividualJid(remoteJid)) return
    const remoteJidLower = remoteJid.toLowerCase()
    if (remoteJidLower.startsWith("status@broadcast")) {
        return
    }
    const messageBody = msg.message.conversation || msg.message.extendedTextMessage?.text || ""
    const trimmed = (messageBody || "").trim()
    if (!trimmed) return
    const dedupeKey = `${remoteJid}|${trimmed}`
    const recentTs = recentOutgoingText.get(dedupeKey)
    if (recentTs && Date.now() - recentTs < RECENT_OUTGOING_TTL_MS) {
        return
    }

    if (db) {
        try {
            const metadata = JSON.stringify({ source: "owner" })
            await db.saveMessage(INSTANCE_ID, remoteJid, "user", trimmed, "outbound", metadata)
        } catch (err) {
            log("Error saving owner outbound message:", err.message)
        }
    }

    const secretaryConfig = await loadSecretaryConfig()
    if (!secretaryConfig.enabled) return
    const reply = pickSecretaryTermResponse(trimmed, secretaryConfig)
    if (reply) {
        await sendSecretaryReply(remoteJid, reply, "term")
    }
}

async function generateGeminiResponse(aiConfig, remoteJid, messageBody) {
    const historyRows = await fetchHistoryRows(remoteJid, aiConfig.history_limit)
    const trimmedMessage = (messageBody || "").trim()
    const parts = buildGeminiConversationParts(historyRows, trimmedMessage)

    log("generateGeminiResponse", {
        remoteJid,
        model: aiConfig.model || "gemini-2.5-flash",
        historyLength: historyRows.length,
        parts: parts.length
    })

    const text = await callGeminiContent(aiConfig, parts)
    return { text }
}

async function generateGeminiMultimodalResponse(aiConfig, remoteJid, prompt, filePaths = []) {
    if (!Array.isArray(filePaths) || filePaths.length === 0) {
        throw new Error("Nenhum arquivo multimídia fornecido")
    }

    const historyRows = await fetchHistoryRows(remoteJid, aiConfig.history_limit)
    const normalizedPrompt = (prompt || "").trim()
    const promptText = normalizedPrompt || "Conteúdo multimídia recebido."
    const parts = buildGeminiConversationParts(historyRows, promptText)

    const validatedFilePaths = []
    for (const filePath of filePaths) {
        if (!filePath || !fs.existsSync(filePath)) {
            throw new Error(`Arquivo temporário ausente ou inacessível: ${filePath || "nulo"}`)
        }
        const stats = fs.statSync(filePath)
        if (!stats.isFile() || stats.size === 0) {
            throw new Error(`Arquivo multimídia inválido: ${filePath}`)
        }
        validatedFilePaths.push(filePath)
    }

    for (const filePath of validatedFilePaths) {
        parts.push(fileToGenerativePart(filePath))
    }

    log("generateGeminiMultimodalResponse", {
        remoteJid,
        fileCount: filePaths.length,
        model: aiConfig.model || "gemini-2.5-flash",
        historyLength: historyRows.length,
        parts: parts.length
    })

    const text = await callGeminiContent(aiConfig, parts)
    return { text }
}

async function handleMultimodalMedia(remoteJid, aiConfig, mediaEntries, promptText) {
    const tempPaths = []
    try {
        for (const entry of mediaEntries) {
            const payload = entry?.mediaPayload
            const message = entry?.message
            if (!payload || !message) {
                throw new Error("Entrada multimídia inválida")
            }
            const tempPath = await downloadMediaNodeToTemp(message, payload.node, payload.downloadType)
            tempPaths.push(tempPath)
        }
    } catch (downloadError) {
        log("Erro ao baixar mídia multimídia:", downloadError.message)
        if (sock) {
            const fallbackText = "Erro ao baixar a mídia para análise"
            const metadata = JSON.stringify({
                debug: true,
                severity: "error",
                error: downloadError?.message || "Falha desconhecida no download",
                user_message: fallbackText
            })
            try {
                await sendWhatsAppMessage(remoteJid, { text: fallbackText })
            } catch (uiError) {
                log("Erro ao notificar usuário sobre falha no download:", uiError.message)
            }
            if (db) {
                try {
                    await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", fallbackText, "outbound", metadata)
                } catch (saveError) {
                    log("Erro salvando mensagem de erro no banco:", saveError.message)
                }
            }
        }
        return
    }

    try {
        const response = await generateGeminiMultimodalResponse(aiConfig, remoteJid, promptText, tempPaths)
        const finalText = (response.text || "").trim()
        if (!finalText) {
            throw new Error("Resposta multimídia inválida")
        }

        const COMMAND_SEPARATOR = "&&&"
        const sanitizedMultimodalText = finalText.includes(COMMAND_SEPARATOR)
            ? finalText.split(COMMAND_SEPARATOR)[0].trim()
            : finalText
        const segments = sanitizedMultimodalText
            .split("#")
            .map(part => part.trim())
            .filter(Boolean)
        if (!segments.length) {
            log("multimodal dispatch dropped", {
                remoteJid,
                reason: "Nenhum texto visível após remover comandos"
            })
            throw new Error("IA retornou apenas comandos em resposta multimídia")
        }

        if (!sock) {
            throw new Error("WhatsApp não conectado")
        }

        for (const segment of segments) {
            await sendWhatsAppMessage(remoteJid, { text: segment })
        }
        if (db) {
            try {
                await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", sanitizedMultimodalText, "outbound")
            } catch (err) {
                log("Error saving assistant message:", err.message)
            }
        }
    } finally {
        for (const filePath of tempPaths) {
            if (filePath && fs.existsSync(filePath)) {
                try {
                    fs.unlinkSync(filePath)
                } catch (err) {
                    log("Erro limpando arquivo temporário:", err.message)
                }
            }
        }
    }
}

async function transcribeAudioWithGemini(transcriptionConfig, filePath) {
    if (!transcriptionConfig?.gemini_api_key) {
        throw new Error("Chave Gemini não configurada para transcrição de áudio")
    }
    const instruction = "Transcreva o áudio em português do Brasil. Responda apenas com o texto transcrito, sem aspas nem comentários."
    const parts = [fileToGenerativePart(filePath)]
    const text = await callGeminiContent({
        gemini_api_key: transcriptionConfig.gemini_api_key,
        gemini_instruction: instruction,
        injected_context: ""
    }, parts)
    return (text || "").trim()
}

async function generateAIResponse(remoteJid, messageBody, providedConfig = null) {
    const aiConfig = providedConfig || await loadAIConfig()
    if (!aiConfig.enabled) {
        throw new Error("Respostas automáticas estão desabilitadas")
    }
    const injectedContext = await buildInjectedPromptContext(remoteJid)
    const enrichedConfig = { ...aiConfig, injected_context: injectedContext }

    log("generateAIResponse", {
        remoteJid,
        provider: enrichedConfig.provider,
        model: enrichedConfig.model,
        historyLimit: enrichedConfig.history_limit,
        snippet: snippet(messageBody, 120)
    })

    if (enrichedConfig.provider === "gemini") {
        const response = await generateGeminiResponse(enrichedConfig, remoteJid, messageBody)
        return { ...response, provider: "gemini" }
    }

    const response = await generateOpenAIResponse(enrichedConfig, remoteJid, messageBody)
    return { ...response, provider: "openai" }
}

async function dispatchAIResponse(remoteJid, messageBody, providedConfig = null, options = { allowBoomerang: true }) {
    if (!messageBody || !messageBody.trim()) {
        throw new Error("Mensagem vazia para IA")
    }

    if (Date.now() < automationPausedUntil) {
        log("Automation paused, skipping AI response for", remoteJid)
        return { text: "", provider: "paused" }
    }

    log("flow.ai.request", {
        remoteJid,
        provider: providedConfig?.provider || DEFAULT_PROVIDER,
        inputLength: messageBody.length
    })
    const aiResponse = await generateAIResponse(remoteJid, messageBody, providedConfig)
    const aiText = aiResponse.text?.trim()
    if (!aiText) {
        throw new Error("IA retornou resposta vazia")
    }

    const sanitizedAiText = (stripAssistantCalls(aiText) || "").trim()
    const { text: processedText, commands, notes } = await handleAssistantCommands(remoteJid, aiText, providedConfig, options)
    const trimmedProcessedText = (processedText || "").trim()
    const notesText = (notes || []).join("\n").trim()
    const finalBaseText = trimmedProcessedText || notesText || sanitizedAiText

    const COMMAND_SEPARATOR = "&&&"
    const hasCommandSeparator = finalBaseText.includes(COMMAND_SEPARATOR)
    const stripBeforeSeparator = (value) => {
        if (!value) return ""
        return value.split(COMMAND_SEPARATOR)[0].trim()
    }
    const visibleFromFinal = stripBeforeSeparator(finalBaseText)
    const fallbackText = stripBeforeSeparator(notesText) || stripBeforeSeparator(sanitizedAiText)
    const visibleMessageText = visibleFromFinal || fallbackText
    const outputText = visibleMessageText

    if (!finalBaseText) {
        throw new Error("IA retornou resposta inválida")
    }

    if (!outputText) {
        log("dispatchAIResponse", {
            remoteJid,
            provider: aiResponse.provider,
            warning: "Resposta apenas com comandos; omitido texto visível",
            separator: hasCommandSeparator ? COMMAND_SEPARATOR : "none"
        })
        throw new Error("IA retornou apenas comandos; nenhum texto visível foi definido")
    }

    const sanitizeMediaSeparators = (value) => {
        if (!value) return value
        const text = String(value)
        const regex = /(video|img|audio):[^#\r\n]+/gi
        let result = ""
        let lastIndex = 0
        let match
        while ((match = regex.exec(text)) !== null) {
            const start = match.index
            const end = start + match[0].length
            const before = start > 0 ? text[start - 1] : ""
            const after = end < text.length ? text[end] : ""
            const needsBefore = start === 0 || before !== "#"
            const needsAfter = end === text.length || after !== "#"
            result += text.slice(lastIndex, start)
            if (needsBefore) {
                result += "# "
            }
            result += match[0]
            if (needsAfter) {
                result += " #"
            }
            lastIndex = end
        }
        result += text.slice(lastIndex)
        return result
    }

    const sanitizedOutputText = sanitizeMediaSeparators(stripBeforeSeparator(outputText || finalBaseText))
    const segments = splitHashSegments(sanitizedOutputText)

    const statusNameForContact = await resolveContactStatusName(remoteJid)
    const preparedSegments = segments.map(segment => replaceStatusPlaceholder(segment, statusNameForContact))

    if (!segments.length) {
        throw new Error("IA retornou apenas separadores de mensagem")
    }

    if (!sock || !whatsappConnected) {
        throw new Error("WhatsApp não conectado")
    }

    const metadata = commands?.length ? JSON.stringify({ commands }) : undefined
    log("flow.ai.response", {
        remoteJid,
        provider: aiResponse.provider,
        outputLength: sanitizedAiText.length,
        commands: commands?.length || 0
    })

    const segmentActions = preparedSegments.map(segment => {
        const contactDirective = parseContactSegment(segment)
        if (contactDirective) {
            return {
                type: "contact",
                raw: segment,
                contact: contactDirective
            }
        }
        const audioDirective = parseAudioSegment(segment)
        if (audioDirective) {
            return {
                type: "audio",
                raw: segment,
                url: audioDirective.url,
                caption: audioDirective.caption
            }
        }
        const videoDirective = parseVideoSegment(segment)
        if (videoDirective) {
            return {
                type: "video",
                raw: segment,
                url: videoDirective.url,
                caption: videoDirective.caption
            }
        }
        const imageDirective = parseImageSegment(segment)
        if (imageDirective) {
            return {
                type: "image",
                raw: segment,
                url: imageDirective.url,
                caption: imageDirective.caption
            }
        }
        return {
            type: "text",
            raw: segment
        }
    })

    for (const [index, action] of segmentActions.entries()) {
        const segmentMetadata = index === 0 ? metadata : undefined
        if (action.type === "audio") {
            try {
                const { buffer, mimeType } = await downloadAudioPayload(action.url)
                const payload = {
                    audio: buffer,
                    mimetype: mimeType,
                    ptt: false
                }
                await sendWhatsAppMessage(remoteJid, payload)
            } catch (err) {
                log("dispatchAIResponse audio send failed", {
                    remoteJid,
                    url: action.url,
                    error: err.message
                })
                if (isSilentMediaError(err)) {
                    await recordMediaAdminError(remoteJid, "audio", action.url, err)
                } else {
                    await sendWhatsAppMessage(remoteJid, {
                        text: `Áudio não pôde ser enviado: ${action.url}`
                    })
                }
            }
        } else if (action.type === "video") {
            try {
                const { buffer, mimeType } = await downloadVideoPayload(action.url)
                const payload = {
                    video: buffer,
                    mimetype: mimeType
                }
                if (action.caption) {
                    payload.caption = action.caption
                }
                await sendWhatsAppMessage(remoteJid, payload)
            } catch (err) {
                log("dispatchAIResponse video send failed", {
                    remoteJid,
                    url: action.url,
                    error: err.message
                })
                if (isSilentMediaError(err)) {
                    await recordMediaAdminError(remoteJid, "video", action.url, err)
                } else {
                    await sendWhatsAppMessage(remoteJid, {
                        text: `Vídeo não pôde ser enviado: ${action.url}`
                    })
                }
            }
        } else if (action.type === "image") {
            try {
                const { buffer, mimeType } = await downloadImagePayload(action.url)
                const payload = {
                    image: buffer,
                    mimetype: mimeType
                }
                if (action.caption) {
                    payload.caption = action.caption
                }
                await sendWhatsAppMessage(remoteJid, payload)
            } catch (err) {
                log("dispatchAIResponse image send failed", {
                    remoteJid,
                    url: action.url,
                    error: err.message
                })
                if (isSilentMediaError(err)) {
                    await recordMediaAdminError(remoteJid, "image", action.url, err)
                } else {
                    await sendWhatsAppMessage(remoteJid, {
                        text: `Imagem não pôde ser enviada: ${action.url}`
                    })
                }
            }
        } else if (action.type === "contact") {
            const contactInfo = action.contact
            const contactDisplayName = (contactInfo.displayName && contactInfo.displayName.trim()) || formatContactPhoneLabel(contactInfo.phone) || contactInfo.phone
            const sanitizedName = contactDisplayName.replace(/\r?\n/g, " ").trim() || "Contato"
            const note = contactInfo.note ? contactInfo.note.replace(/\r?\n/g, " ").trim() : null
            const phone = contactInfo.phone
            const vcardLines = [
                "BEGIN:VCARD",
                "VERSION:3.0",
                `FN:${sanitizedName}`,
                `TEL;type=CELL;waid=${phone}:${phone}`
            ]
            if (note) {
                vcardLines.push(`NOTE:${note}`)
            }
            vcardLines.push("END:VCARD")
            try {
                await sendWhatsAppMessage(remoteJid, {
                    contacts: {
                        displayName: sanitizedName,
                        contacts: [
                            {
                                vcard: vcardLines.join("\n")
                            }
                        ]
                    }
                })
            } catch (err) {
                log("dispatchAIResponse contact send failed", {
                    remoteJid,
                    contact: sanitizedName,
                    error: err.message
                })
                await sendWhatsAppMessage(remoteJid, {
                    text: `Contato não pôde ser enviado: ${contactInfo.phone}`
                })
            }
        } else {
            await sendWhatsAppMessage(remoteJid, { text: action.raw })
        }

        if (db) {
            try {
                await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", action.raw, "outbound", segmentMetadata)
                log("flow.persist", {
                    remoteJid,
                    role: "assistant",
                    direction: "outbound",
                    length: action.raw?.length || 0
                })
            } catch (err) {
                log("Error saving assistant message:", err.message)
            }
        }
    }

    log("dispatchAIResponse", {
        remoteJid,
        provider: aiResponse.provider,
        snippet: snippet(outputText, 120),
        segments: segments.length,
        separator: hasCommandSeparator ? "&&&" : "none"
    })
    return aiResponse
}

function detectMediaPayload(message) {
    if (message.imageMessage) {
        return {
            key: "imageMessage",
            node: message.imageMessage,
            type: "imagem",
            downloadType: "image",
            fallbackDescription: "Imagem recebida sem legenda."
        }
    }
    if (message.videoMessage) {
        return {
            key: "videoMessage",
            node: message.videoMessage,
            type: "vídeo",
            downloadType: "video",
            fallbackDescription: "Vídeo recebido sem legenda."
        }
    }
    if (message.audioMessage) {
        return {
            key: "audioMessage",
            node: message.audioMessage,
            type: "áudio",
            downloadType: "audio",
            fallbackDescription: "Áudio recebido sem legenda."
        }
    }
    return null
}

function sanitizeMimeType(value) {
    if (!value) return null
    return value.split(";")[0].trim().toLowerCase() || null
}

function getRemoteCacheKey(url) {
    const encoded = Buffer.from(url, "utf8").toString("base64")
    return encoded.replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "")
}

function getRemoteCachePaths(url) {
    const key = getRemoteCacheKey(url)
    return {
        filePath: path.join(REMOTE_CACHE_DIR, `${key}.bin`),
        metaPath: path.join(REMOTE_CACHE_DIR, `${key}.json`)
    }
}

async function loadRemoteCache(url, expectedPrefix, fallbackMime) {
    const { filePath, metaPath } = getRemoteCachePaths(url)
    if (!fs.existsSync(filePath) || !fs.existsSync(metaPath)) {
        return null
    }
    const rawMeta = await fs.promises.readFile(metaPath, "utf8").catch(() => "")
    if (!rawMeta) return null
    let meta
    try {
        meta = JSON.parse(rawMeta)
    } catch {
        return null
    }
    const mimeType = sanitizeMimeType(meta?.mimeType) || fallbackMime
    if (!mimeType || !mimeType.startsWith(expectedPrefix)) {
        return null
    }
    const buffer = await fs.promises.readFile(filePath)
    return { buffer, mimeType }
}

async function saveRemoteCache(url, buffer, mimeType) {
    const { filePath, metaPath } = getRemoteCachePaths(url)
    await fs.promises.writeFile(filePath, buffer)
    const payload = {
        url,
        mimeType,
        savedAt: new Date().toISOString()
    }
    await fs.promises.writeFile(metaPath, JSON.stringify(payload))
}

async function fetchMediaWithCache(url, expectedPrefix, fallbackMime, fetchLabel) {
    const cached = await loadRemoteCache(url, expectedPrefix, fallbackMime)
    if (cached) {
        return cached
    }
    const response = await fetch(url, {
        method: "GET",
        headers: { "User-Agent": "Janeri Bot/1.0" }
    })
    if (!response.ok) {
        throw new Error(`${fetchLabel} não pôde ser baixado (HTTP ${response.status})`)
    }
    const arrayBuffer = await response.arrayBuffer()
    const buffer = Buffer.from(arrayBuffer)
    const inferredMime = sanitizeMimeType(response.headers.get("content-type")) || sanitizeMimeType(mime.lookup(url))
    const mimeType = inferredMime || fallbackMime
    if (!mimeType || !mimeType.startsWith(expectedPrefix)) {
        throw new Error(`Conteúdo baixado não parece ser ${fetchLabel.toLowerCase()}`)
    }
    await saveRemoteCache(url, buffer, mimeType).catch(err => {
        log("Cache de mídia falhou:", { url, error: err.message })
    })
    return { buffer, mimeType }
}

function isLocalMediaPath(value) {
    if (typeof value !== "string") return false
    const trimmed = value.trim()
    return trimmed.startsWith("/") || trimmed.startsWith("uploads/")
}

function resolveLocalAssetPath(inputPath) {
    if (!inputPath || typeof inputPath !== "string") {
        throw new Error("Caminho local inválido")
    }
    const trimmed = inputPath.trim()
    const resolved = trimmed.startsWith("/")
        ? path.resolve(trimmed)
        : path.resolve(ASSETS_UPLOADS_DIR, trimmed.replace(/^uploads[\\/]+/i, ""))
    const allowedBase = ASSETS_UPLOADS_DIR.endsWith(path.sep)
        ? ASSETS_UPLOADS_DIR
        : `${ASSETS_UPLOADS_DIR}${path.sep}`
    if (resolved !== ASSETS_UPLOADS_DIR && !resolved.startsWith(allowedBase)) {
        throw new Error("Caminho local fora do diretório permitido")
    }
    return resolved
}

async function loadLocalMediaBuffer(filePath, expectedPrefix, fallbackMime) {
    const resolved = resolveLocalAssetPath(filePath)
    if (!fs.existsSync(resolved)) {
        const err = new Error("Arquivo local não encontrado")
        err.silent = true
        throw err
    }
    const buffer = await fs.promises.readFile(resolved)
    const inferredMime = sanitizeMimeType(mime.lookup(resolved)) || fallbackMime
    if (!inferredMime || !inferredMime.startsWith(expectedPrefix)) {
        throw new Error("Arquivo local não corresponde ao tipo esperado")
    }
    return { buffer, mimeType: inferredMime }
}

function isSilentMediaError(error) {
    return Boolean(error && error.silent)
}

async function recordMediaAdminError(remoteJid, actionType, url, error) {
    if (!db) return
    const label = actionType ? actionType.toUpperCase() : "MIDIA"
    const message = `Falha ao enviar ${label}`
    const metadata = {
        severity: "error",
        error: error?.message || "Falha ao enviar mídia",
        media_type: actionType,
        url
    }
    try {
        await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", message, "outbound", JSON.stringify(metadata))
    } catch (err) {
        log("Erro salvando alerta de mídia no admin:", err.message)
    }
}

function parseImageSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^img:/i.test(trimmed)) return null
    const payload = trimmed.slice(4).trim()
    if (!payload) return null
    const [urlPart, ...captionParts] = payload.split("|")
    const url = (urlPart || "").trim()
    if (!url) return null
    if (!isLocalMediaPath(url)) {
        try {
            const parsed = new URL(url)
            if (!["http:", "https:"].includes(parsed.protocol)) {
                return null
            }
        } catch {
            return null
        }
    }
    const caption = captionParts.join("|").trim()
    return {
        url,
        caption: caption || undefined
    }
}

function parseAudioSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^audio:/i.test(trimmed)) return null
    const payload = trimmed.slice(6).trim()
    if (!payload) return null
    const [urlPart, ...captionParts] = payload.split("|")
    const url = (urlPart || "").trim()
    if (!url) return null
    if (!isLocalMediaPath(url)) {
        try {
            const parsed = new URL(url)
            if (!["http:", "https:"].includes(parsed.protocol)) {
                return null
            }
        } catch {
            return null
        }
    }
    const caption = captionParts.join("|").trim()
    return {
        url,
        caption: caption || undefined
    }
}

function parseVideoSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^video:/i.test(trimmed)) return null
    const payload = trimmed.slice(6).trim()
    if (!payload) return null
    const [urlPart, ...captionParts] = payload.split("|")
    const url = (urlPart || "").trim()
    if (!url) return null
    if (!isLocalMediaPath(url)) {
        try {
            const parsed = new URL(url)
            if (!["http:", "https:"].includes(parsed.protocol)) {
                return null
            }
        } catch {
            return null
        }
    }
    const caption = captionParts.join("|").trim()
    return {
        url,
        caption: caption || undefined
    }
}

function parseContactSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^contact:/i.test(trimmed)) return null
    const payload = trimmed.slice(8).trim()
    if (!payload) return null
    const parts = payload.split("|").map(part => part.trim())
    const rawPhone = parts[0] || ""
    const normalizedPhone = normalizeContactPhoneNumber(rawPhone)
    if (!normalizedPhone) return null
    const displayName = parts[1] || undefined
    const note = parts.slice(2).filter(Boolean).join(" | ") || undefined
    return {
        phone: normalizedPhone,
        rawPhone,
        displayName,
        note
    }
}

async function downloadImagePayload(url) {
    if (!url) {
        throw new Error("URL da imagem inválida")
    }
    if (isLocalMediaPath(url)) {
        return loadLocalMediaBuffer(url, "image/", "image/jpeg")
    }
    return fetchMediaWithCache(url, "image/", "image/jpeg", "Imagem")
}

async function downloadAudioPayload(url) {
    if (!url) {
        throw new Error("URL do áudio inválida")
    }
    if (isLocalMediaPath(url)) {
        return loadLocalMediaBuffer(url, "audio/", "audio/mpeg")
    }
    return fetchMediaWithCache(url, "audio/", "audio/mpeg", "Áudio")
}

async function downloadVideoPayload(url) {
    if (!url) {
        throw new Error("URL do vídeo inválida")
    }
    if (isLocalMediaPath(url)) {
        return loadLocalMediaBuffer(url, "video/", "video/mp4")
    }
    return fetchMediaWithCache(url, "video/", "video/mp4", "Vídeo")
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

function detectContactPayload(message) {
    const contact = message?.contactMessage
    if (!contact) return null
    const displayName = normalizeMetaField(contact.displayName || contact.name || contact.notify)
    const note = normalizeMetaField(contact.notify || contact.description)
    const vcard = contact.vcard || ""
    const phones = extractPhonesFromVcard(vcard)
    if (!displayName && !phones.length && !vcard) {
        return null
    }
    return {
        displayName,
        note,
        vcard,
        phones
    }
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

async function downloadMediaNodeToTemp(message, mediaNode, downloadType) {
    if (!message || !mediaNode) {
        throw new Error("Conteúdo multimídia inválido")
    }
    if (!baileysModule || typeof baileysModule.downloadMediaMessage !== "function") {
        throw new Error("Função de download do Baileys indisponível")
    }
    if (!sock) {
        throw new Error("WhatsApp não conectado")
    }

    const reuploadRequest = typeof sock.updateMediaMessage === "function"
        ? sock.updateMediaMessage.bind(sock)
        : undefined

    let buffer
    try {
        buffer = await baileysModule.downloadMediaMessage(message, "buffer", {}, {
            logger: {
                info: (...args) => log("downloadMediaMessage.info", ...args),
                warn: (...args) => log("downloadMediaMessage.warn", ...args),
                error: (...args) => log("downloadMediaMessage.error", ...args)
            },
            reuploadRequest
        })
    } catch (err) {
        throw new Error(`Falha ao baixar o conteúdo multimídia: ${err?.message || "sem detalhe"}`)
    }

    if (!buffer || buffer.length === 0) {
        throw new Error("Arquivo multimídia vazio")
    }

    const baseMime = sanitizeMimeType(mediaNode.mimetype)
    let fallbackExt = "bin"
    if (downloadType === "audio") {
        fallbackExt = "ogg"
    } else if (downloadType === "image") {
        fallbackExt = "jpg"
    } else if (downloadType === "video") {
        fallbackExt = "mp4"
    }

    const extension = mime.extension(baseMime) || fallbackExt
    const tempPath = path.join(UPLOADS_DIR, `wpp-media-${uuidv4()}.${extension}`)
    fs.writeFileSync(tempPath, buffer)
    return tempPath
}

function handleMultiInputQueue(remoteJid, messageBody, delaySeconds) {
    const entry = normalizeMultiInputEntry(messageBody)
    if (!entry || delaySeconds <= 0) {
        return
    }

    const now = Date.now()
    const delayMs = delaySeconds * 1000

    const existing = pendingMultiInputs.get(remoteJid) || {
        messages: [],
        timer: null,
        delaySeconds: 0,
        expiresAt: now,
        lastMessageAt: now
    }
    existing.messages.push(entry)
    existing.delaySeconds = delaySeconds
    existing.lastMessageAt = now
    existing.expiresAt = now + delayMs

    if (existing.timer) {
        clearTimeout(existing.timer)
    }

    existing.timer = setTimeout(() => {
        pendingMultiInputs.delete(remoteJid)
        const entries = existing.messages.filter(Boolean)
        if (!entries.length) {
            return
        }
        processQueuedMultiInput(remoteJid, entries)
            .catch(err => handleAIError(remoteJid, err))
    }, delayMs)

    pendingMultiInputs.set(remoteJid, existing)
    log("flow.queue.add", {
        remoteJid,
        delaySeconds,
        total: existing.messages.length
    })
    log(`[multi-input] aguardando ${delaySeconds}s para ${remoteJid} (${existing.messages.length} mensagem(ns))`)
}

function normalizeMultiInputEntry(entry) {
    if (!entry) return null
    if (typeof entry === "string") {
        const trimmed = entry.trim()
        if (!trimmed) return null
        return { type: "text", text: trimmed }
    }
    if (entry.type === "text") {
        const trimmed = (entry.text || "").trim()
        return trimmed ? { type: "text", text: trimmed } : null
    }
    if (entry.type === "media" && entry.message && entry.mediaPayload) {
        return entry
    }
    return null
}

async function processQueuedMultiInput(remoteJid, entries) {
    const textEntries = entries.filter(entry => entry?.type === "text")
    const mediaEntries = entries.filter(entry => entry?.type === "media")
    log("flow.queue.flush", {
        remoteJid,
        textCount: textEntries.length,
        mediaCount: mediaEntries.length
    })
    if (mediaEntries.length) {
        const aiConfig = await loadAIConfig()
        if (!aiConfig.enabled) {
            return
        }
        const rawProvider = (aiConfig.provider || "").trim()
        const normalizedProvider = rawProvider.toLowerCase()
        const hasGeminiKey = Boolean(aiConfig.gemini_api_key)
        const canUseGemini = normalizedProvider === "gemini" || hasGeminiKey

        if (!canUseGemini) {
            if (sock) {
                await sendWhatsAppMessage(remoteJid, {
                    text: "Multimídia só está disponível quando o Gemini é o provedor ativo."
                })
            }
            return
        }

        const promptText = buildMediaPromptText(mediaEntries, textEntries)
        await handleMultimodalMedia(remoteJid, aiConfig, mediaEntries, promptText)
        return
    }

    const aggregated = textEntries
        .map(entry => entry.text || "")
        .map(text => text.trim())
        .filter(Boolean)
        .join("\n")
    if (!aggregated) {
        return
    }

    await dispatchAIResponse(remoteJid, aggregated)
}

async function handleAIError(remoteJid, error) {
    log("AI processing error:", error?.message || error)
    if (!sock) {
        return
    }
    try {
        const fallbackText = pickRandomErrorResponse()
        await sendWhatsAppMessage(remoteJid, { text: fallbackText })
        if (db) {
            const meta = { debug: true, error: String(error?.message || error || "Erro desconhecido") }
            try {
                await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", fallbackText, "outbound", JSON.stringify(meta))
            } catch (saveError) {
                log("Error saving debug message:", saveError.message)
            }
        }
    } catch (sendError) {
        log("Error sending fallback message:", sendError.message)
    }
}

async function loadAIConfig() {
    if (!db) {
        return {
            enabled: false,
            provider: DEFAULT_PROVIDER,
            model: "gpt-4.1-mini",
            system_prompt: "",
            assistant_prompt: "",
            assistant_id: "",
            history_limit: DEFAULT_HISTORY_LIMIT,
            temperature: DEFAULT_TEMPERATURE,
            max_tokens: DEFAULT_MAX_TOKENS,
            openai_api_key: "",
            openai_mode: "responses",
            gemini_api_key: "",
            gemini_instruction: ""
        }
    }

    const instanceSettings = await db.getSettings(INSTANCE_ID, AI_SETTING_KEYS)
    const globalSettings = await db.getSettings('', AI_SETTING_KEYS)
    const settings = { ...globalSettings, ...instanceSettings }
    const rawStoredDelay = settings.ai_multi_input_delay
    const hasStoredDelay = rawStoredDelay !== undefined && rawStoredDelay !== null && rawStoredDelay !== ""
    const storedDelay = hasStoredDelay ? Math.max(0, toNumber(rawStoredDelay, DEFAULT_MULTI_INPUT_DELAY)) : null
    const multiInputDelay = storedDelay !== null ? storedDelay : getInstanceFallbackMultiInputDelay()

    return {
        enabled: settings.ai_enabled === "true",
        provider: settings.ai_provider || DEFAULT_PROVIDER,
        model: settings.ai_model || "gpt-4.1-mini",
        system_prompt: settings.ai_system_prompt || "",
        assistant_prompt: settings.ai_assistant_prompt || "",
        assistant_id: settings.ai_assistant_id || "",
        history_limit: Math.max(1, toNumber(settings.ai_history_limit, DEFAULT_HISTORY_LIMIT)),
        temperature: toNumber(settings.ai_temperature, DEFAULT_TEMPERATURE),
        max_tokens: Math.max(64, toNumber(settings.ai_max_tokens, DEFAULT_MAX_TOKENS)),
        multi_input_delay: multiInputDelay,
        openai_api_key: settings.openai_api_key || "",
        openai_mode: settings.openai_mode || "responses",
        gemini_api_key: settings.gemini_api_key || "",
        gemini_instruction: settings.gemini_instruction || "",
        auto_pause_enabled: settings.auto_pause_enabled === "true",
        auto_pause_minutes: Math.max(1, toNumber(settings.auto_pause_minutes, 5))
    }
}

async function loadAudioTranscriptionConfig() {
    if (!db) {
        return {
            enabled: false,
            gemini_api_key: "",
            prefix: DEFAULT_AUDIO_TRANSCRIPTION_PREFIX
        }
    }

    const instanceSettings = await db.getSettings(INSTANCE_ID, AUDIO_TRANSCRIPTION_SETTING_KEYS)
    const globalSettings = await db.getSettings('', AUDIO_TRANSCRIPTION_SETTING_KEYS)
    const settings = { ...globalSettings, ...instanceSettings }
    const rawPrefix = (settings.audio_transcription_prefix || "").trim()

    return {
        enabled: settings.audio_transcription_enabled === "true",
        gemini_api_key: settings.audio_transcription_gemini_api_key || "",
        prefix: rawPrefix || DEFAULT_AUDIO_TRANSCRIPTION_PREFIX
    }
}

async function loadSecretaryConfig() {
    if (!db) {
        return {
            enabled: false,
            idle_hours: 0,
            initial_response: "",
            term_1: "",
            response_1: "",
            term_2: "",
            response_2: "",
            quick_replies: []
        }
    }

    const instanceSettings = await db.getSettings(INSTANCE_ID, SECRETARY_SETTING_KEYS)
    const globalSettings = await db.getSettings("", SECRETARY_SETTING_KEYS)
    const settings = { ...globalSettings, ...instanceSettings }
    const idleHours = Math.max(0, toNumber(settings.secretary_idle_hours, 0))
    let quickReplies = []
    if (settings.secretary_quick_replies) {
        try {
            const decoded = JSON.parse(settings.secretary_quick_replies)
            if (Array.isArray(decoded)) {
                quickReplies = decoded
            }
        } catch (err) {
            log("Erro ao parsear respostas rápidas:", err.message)
        }
    }
    if (!quickReplies.length) {
        const fallback = []
        const term1 = (settings.secretary_term_1 || "").trim()
        const response1 = (settings.secretary_response_1 || "").trim()
        if (term1 && response1) {
            fallback.push({ term: term1, response: response1 })
        }
        const term2 = (settings.secretary_term_2 || "").trim()
        const response2 = (settings.secretary_response_2 || "").trim()
        if (term2 && response2) {
            fallback.push({ term: term2, response: response2 })
        }
        quickReplies = fallback
    }

    return {
        enabled: settings.secretary_enabled === "true",
        idle_hours: idleHours,
        initial_response: settings.secretary_initial_response || "",
        term_1: settings.secretary_term_1 || "",
        response_1: settings.secretary_response_1 || "",
        term_2: settings.secretary_term_2 || "",
        response_2: settings.secretary_response_2 || "",
        quick_replies: quickReplies
    }
}

async function persistAIConfig(payload) {
    if (!db) {
        throw new Error("Database not available")
    }

    const {
        enabled,
        provider,
        model,
        system_prompt,
        assistant_prompt,
        assistant_id,
        history_limit,
        temperature,
        max_tokens,
        openai_api_key,
        openai_mode,
        gemini_api_key,
        gemini_instruction,
        multi_input_delay
    } = payload

    const numericHistory = Math.max(1, toNumber(history_limit, DEFAULT_HISTORY_LIMIT))
    const tempo = toNumber(temperature, DEFAULT_TEMPERATURE)
    const maxTokens = Math.max(64, toNumber(max_tokens, DEFAULT_MAX_TOKENS))
    const delaySeconds = Math.max(0, toNumber(multi_input_delay, DEFAULT_MULTI_INPUT_DELAY))

        const entries = [
            ["ai_enabled", enabled ? "true" : "false"],
            ["ai_provider", provider || DEFAULT_PROVIDER],
            ["ai_model", model || "gpt-4.1-mini"],
            ["ai_system_prompt", system_prompt || ""],
            ["ai_assistant_prompt", assistant_prompt || ""],
            ["ai_assistant_id", assistant_id || ""],
            ["ai_history_limit", String(numericHistory)],
            ["ai_temperature", String(tempo)],
            ["ai_max_tokens", String(maxTokens)],
            ["openai_api_key", openai_api_key || ""],
            ["openai_mode", openai_mode || "responses"],
        ["gemini_api_key", gemini_api_key || ""],
        ["gemini_instruction", gemini_instruction || ""],
        ["ai_multi_input_delay", String(delaySeconds)]
    ]

    for (const [key, value] of entries) {
        await db.setSetting(INSTANCE_ID, key, value)
    }
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

async function loadAlarmConfig() {
    if (!db) {
        return buildAlarmConfig({})
    }
    const instanceSettings = await db.getSettings(INSTANCE_ID, ALARM_SETTING_KEYS)
    const globalSettings = await db.getSettings("", ALARM_SETTING_KEYS)
    return buildAlarmConfig({ ...globalSettings, ...instanceSettings })
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

async function persistAlarmLastSent(key) {
    if (!db || !key) return
    try {
        await db.setSetting(INSTANCE_ID, key, new Date().toISOString())
    } catch (err) {
        log("Erro ao registrar alarme:", err.message)
    }
}

async function clearAlarmLastSent(eventKey) {
    if (!db || !eventKey) return
    try {
        const config = await loadAlarmConfig()
        const meta = config[eventKey]
        if (!meta?.lastSentKey) return
        await db.setSetting(INSTANCE_ID, meta.lastSentKey, "")
    } catch (err) {
        log("Erro ao limpar alarme:", eventKey, err.message)
    }
}

const pendingAlarmTimers = new Map()

function clearPendingAlarm(eventKey) {
    const timer = pendingAlarmTimers.get(eventKey)
    if (timer) {
        clearTimeout(timer)
        pendingAlarmTimers.delete(eventKey)
    }
}

function scheduleAlarm(eventKey, subject, body, delayMs) {
    clearPendingAlarm(eventKey)
    const waitMs = Math.max(0, Number(delayMs || 0))
    const timer = setTimeout(async () => {
        pendingAlarmTimers.delete(eventKey)
        if (eventKey === "whatsapp" && (whatsappConnected || connectionStatus === "connected")) {
            log("Alarme whatsapp ignorado: reconectou antes da confirmação.")
            return
        }
        await triggerInstanceAlarm(eventKey, subject, body)
    }, waitMs)
    pendingAlarmTimers.set(eventKey, timer)
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

function buildAlarmEmailHtml({ title, subtitle, info, message, qrLink, tokenExpiresAt }) {
    const safeMessage = escapeHtml(message || "").replace(/\n/g, "<br>")
    const infoRows = [
        ["Instância", info.instanceName],
        ["ID", info.instanceId],
        ["Porta", info.port],
        ["Status", info.connectionStatus],
        ["WhatsApp conectado", info.whatsappConnected],
        ["Último erro", info.lastError],
        ["Detectado em", info.detectedAt]
    ]
    const extraRows = [
        ["Host", info.process],
        ["Node", info.node],
        ["Intervalo", info.intervalLabel],
        ["Último envio", info.lastSent]
    ]
    const qrBlock = qrLink
        ? `
            <tr>
                <td style="padding: 0 0 8px 0; font-size: 14px; color: #123c3b; font-weight: 600;">
                    Link para reconectar
                </td>
            </tr>
            <tr>
                <td style="padding: 0 0 18px 0;">
                    <a href="${escapeHtml(qrLink)}" style="display: inline-block; background: #0f766e; color: #ffffff; text-decoration: none; padding: 12px 18px; border-radius: 10px; font-weight: 600; letter-spacing: 0.2px;">
                        Abrir QR Code
                    </a>
                    <div style="margin-top: 10px; font-size: 12px; color: #45605f;">
                        Token válido até ${escapeHtml(tokenExpiresAt || "24h")}.
                    </div>
                </td>
            </tr>
        `
        : ""

    const renderRows = rows => rows.map(([label, value]) => `
        <tr>
            <td style="padding: 8px 0; font-size: 13px; color: #4b615f; width: 160px;">${escapeHtml(label)}</td>
            <td style="padding: 8px 0; font-size: 14px; color: #0f1f1e; font-weight: 600;">${escapeHtml(value || "-")}</td>
        </tr>
    `).join("")

    return `
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)}</title>
</head>
<body style="margin:0; padding:0; background:#f4f7f7; font-family: 'Segoe UI', 'Inter', Arial, sans-serif;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7f7; padding: 32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="680" cellspacing="0" cellpadding="0" style="background:#ffffff; border-radius: 18px; overflow: hidden; box-shadow: 0 18px 45px rgba(15, 118, 110, 0.12);">
          <tr>
            <td style="background: linear-gradient(120deg, #0f766e, #115e59); padding: 28px 36px; color:#ffffff;">
              <img src="${escapeHtml(MAESTRO_LOGO_URL)}" alt="Maestro" style="height: 36px; display:block; margin-bottom: 14px;">
              <div style="font-size: 20px; font-weight: 700; letter-spacing: 0.3px;">${escapeHtml(title)}</div>
              <div style="margin-top: 6px; font-size: 14px; opacity: 0.9;">${escapeHtml(subtitle || "")}</div>
            </td>
          </tr>
          <tr>
            <td style="padding: 28px 36px;">
              <div style="font-size: 15px; color:#143533; line-height: 1.6; margin-bottom: 18px;">
                ${safeMessage || "Um evento foi detectado na instância."}
              </div>
              ${qrBlock ? `<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 18px;">${qrBlock}</table>` : ""}
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top: 1px solid #e3eceb; padding-top: 12px;">
                ${renderRows(infoRows)}
              </table>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-top: 1px solid #e3eceb; margin-top: 12px; padding-top: 12px;">
                ${renderRows(extraRows)}
              </table>
            </td>
          </tr>
          <tr>
            <td style="background:#f1f7f6; padding: 18px 36px; font-size: 12px; color:#6b7e7c;">
              Este é um aviso automático do Maestro. Se precisar de suporte, responda este e-mail com o log ou o horário do incidente.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
    `.trim()
}

async function triggerInstanceAlarm(eventKey, subject, body) {
    if (!db) return
    try {
        const config = await loadAlarmConfig()
        const meta = config[eventKey]
        if (!shouldSendAlarm(meta)) {
            return
        }
        const to = meta.recipients.join(", ")
        const info = collectAlarmDebugInfo(eventKey, meta)
        const detailedBody = [body?.trim(), buildAlarmDebugContext(eventKey, meta)]
            .filter(Boolean)
            .join("\n\n")
        let qrToken = null
        let qrLink = null
        if (eventKey === "whatsapp") {
            qrToken = generateQrAccessToken(INSTANCE_ID)
            qrLink = `${PUBLIC_BASE_URL}/qr-proxy.php?token=${qrToken.token}`
        }
        const htmlBody = buildAlarmEmailHtml({
            title: subject || "Alerta da instância",
            subtitle: eventKey === "whatsapp"
                ? "A conexão com o WhatsApp caiu e requer reconexão."
                : "Um evento foi detectado na instância.",
            info,
            message: body,
            qrLink,
            tokenExpiresAt: qrToken?.expires_at
        })
        await sendMailCommand(to, subject, htmlBody, undefined, true)
        await persistAlarmLastSent(meta.lastSentKey)
        log("Alarme enviado", eventKey, "para", to)
    } catch (err) {
        log("Erro ao disparar alarme", eventKey, err.message)
    }
}

async function fetchAssistantMessageFromThread(threadsApi, threadId, afterMessageId, historyLimit) {
    const limit = Math.max(1, historyLimit || DEFAULT_HISTORY_LIMIT)
    const params = { limit }
    if (afterMessageId) {
        params.after = afterMessageId
    }

    for (let attempt = 0; attempt < 4; attempt++) {
        const page = await threadsApi.messages.list(threadId, params)
        const messages = Array.isArray(page?.data) ? page.data : []
        const assistantMessages = messages.filter(
            m => m.role === "assistant" && m.status === "completed"
        )

        if (assistantMessages.length) {
            const latest = assistantMessages[assistantMessages.length - 1]
            const text = extractMessageContentText(latest)
            if (text) {
                return {
                    text,
                    messageId: latest.id
                }
            }
        }

        await new Promise(resolve => setTimeout(resolve, 500 * (attempt + 1)))
    }

    return null
}

// ===== INTELLIGENT CHAT PROCESSOR =====
async function processMessageWithAI(msg) {
        if (!msg.key?.fromMe && msg.message) {
            const remoteJid = msg.key.remoteJid
            if (!remoteJid) return
            if (isGroupJid(remoteJid)) {
                await handleGroupMessage(msg)
                return
            }
            const remoteJidLower = remoteJid.toLowerCase()
            const isStatusBroadcast = remoteJidLower.startsWith('status@broadcast')
            if (isStatusBroadcast) {
                log("processMessageWithAI", {
                    remoteJid,
                    status: "status_broadcast_ignored"
                })
                return
            }

        const tempPaths = []
        let contactPayload = null
        try {
            const transcriptionConfig = await loadAudioTranscriptionConfig()
            const aiConfig = await loadAIConfig()
            const secretaryConfig = await loadSecretaryConfig()
            let lastInboundAt = null
            if (db && typeof db.getTimeSinceLastInboundMessage === "function") {
                try {
                    lastInboundAt = await db.getTimeSinceLastInboundMessage(INSTANCE_ID, remoteJid)
                } catch (err) {
                    log("Erro ao obter último inbound:", err.message)
                }
            }
            const shouldSendSecretaryInitial = shouldTriggerSecretaryInitial(lastInboundAt, secretaryConfig)

            const mediaPayload = detectMediaPayload(msg.message)
            if (mediaPayload && mediaPayload.downloadType === "audio" && transcriptionConfig.enabled) {
                const captionText = (mediaPayload.node?.caption || "").trim()
                const inboundText = captionText ? `ÁUDIO RECEBIDO: ${captionText}` : "ÁUDIO RECEBIDO"
                if (db) {
                    try {
                        await db.saveMessage(INSTANCE_ID, remoteJid, "user", inboundText, "inbound")
                    } catch (err) {
                        log("Error saving audio inbound message:", err.message)
                    }
                }

                if (shouldSendSecretaryInitial) {
                    await sendSecretaryReply(remoteJid, secretaryConfig.initial_response, "initial")
                    return
                }

                if (!transcriptionConfig.gemini_api_key) {
                    log("Transcrição de áudio ativada sem chave Gemini")
                    return
                }

                let tempPath
                try {
                    tempPath = await downloadMediaNodeToTemp(msg, mediaPayload.node, mediaPayload.downloadType)
                    tempPaths.push(tempPath)
                } catch (downloadError) {
                    log("Erro ao baixar áudio para transcrição:", downloadError.message)
                    if (sock) {
                        const fallbackText = "Erro ao baixar o áudio para transcrição."
                        try {
                            await sendWhatsAppMessage(remoteJid, { text: fallbackText })
                        } catch (uiError) {
                            log("Erro ao notificar usuário sobre falha no áudio:", uiError.message)
                        }
                        if (db) {
                            try {
                                await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", fallbackText, "outbound")
                            } catch (saveError) {
                                log("Erro salvando mensagem de erro no banco:", saveError.message)
                            }
                        }
                    }
                    return
                }

                const transcriptText = await transcribeAudioWithGemini(transcriptionConfig, tempPath)
                if (!transcriptText) {
                    throw new Error("Transcrição vazia")
                }

                const prefix = (transcriptionConfig.prefix || DEFAULT_AUDIO_TRANSCRIPTION_PREFIX).trim()
                const prefixText = prefix ? `${prefix}: ` : ""
                const outgoingText = `${prefixText}_${transcriptText}_`
                if (!sock) {
                    throw new Error("WhatsApp não conectado")
                }
                await sendWhatsAppMessage(remoteJid, { text: outgoingText })
                if (db) {
                    try {
                        await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", outgoingText, "outbound")
                    } catch (err) {
                        log("Error saving audio transcription message:", err.message)
                    }
                }
                return
            }

            // Check if message is from owner (direct WhatsApp)
            const isFromOwner = remoteJid === `${instanceConfig.phone}@s.whatsapp.net` && !msg.key?.fromMe
            if (isFromOwner && aiConfig.auto_pause_enabled) {
                const pauseMinutes = aiConfig.auto_pause_minutes || 5
                automationPausedUntil = Date.now() + pauseMinutes * 60 * 1000
                log("Auto pause activated by owner message for", pauseMinutes, "minutes")
            }

            const aiEnabled = aiConfig.enabled
            const rawProvider = (aiConfig.provider || "").trim()
            console.log("Provider atual:", rawProvider || "não informado")
            const normalizedProvider = rawProvider.toLowerCase()
            const hasGeminiKey = Boolean(aiConfig.gemini_api_key)
            const canUseGemini = normalizedProvider === "gemini" || hasGeminiKey
            const delaySeconds = Math.max(0, aiConfig.multi_input_delay ?? DEFAULT_MULTI_INPUT_DELAY)
            contactPayload = detectContactPayload(msg.message)
            log("flow.inbound", {
                remoteJid,
                hasMedia: Boolean(mediaPayload),
                hasContact: Boolean(contactPayload),
                aiEnabled,
                delaySeconds
            })
            if (contactPayload) {
                const promptText = buildContactPrompt(contactPayload)
                if (!promptText) {
                    log("processMessageWithAI", {
                        remoteJid,
                        snippet: "Contato sem dados visíveis"
                    })
                    return
                }
                if (db) {
                    try {
                        await db.saveMessage(INSTANCE_ID, remoteJid, "user", promptText, "inbound")
                        log("flow.persist", {
                            remoteJid,
                            role: "user",
                            direction: "inbound",
                            length: promptText.length
                        })
                    } catch (err) {
                        log("Error saving user contact message:", err.message)
                    }
                }
                if (shouldSendSecretaryInitial) {
                    await sendSecretaryReply(remoteJid, secretaryConfig.initial_response, "initial")
                    return
                }
                if (!aiEnabled) {
                    return
                }
                log("processMessageWithAI contact", {
                    remoteJid,
                    snippet: snippet(promptText, 120)
                })
                await dispatchAIResponse(remoteJid, promptText, aiConfig)
                return
            }

            if (mediaPayload && mediaPayload.downloadType === "video") {
                const captionText = (mediaPayload.node?.caption || "").trim()
                const promptText = captionText
                    ? `Recebemos um video|${captionText}`
                    : "Recebemos um video"

                if (db) {
                    try {
                        await db.saveMessage(INSTANCE_ID, remoteJid, "user", promptText, "inbound")
                        log("flow.persist", {
                            remoteJid,
                            role: "user",
                            direction: "inbound",
                            length: promptText.length
                        })
                    } catch (err) {
                        log("Error saving user video message:", err.message)
                    }
                }

                if (shouldSendSecretaryInitial) {
                    await sendSecretaryReply(remoteJid, secretaryConfig.initial_response, "initial")
                    return
                }

                if (!aiEnabled) {
                    return
                }

                if (delaySeconds > 0) {
                    handleMultiInputQueue(remoteJid, { type: "text", text: promptText }, delaySeconds)
                    return
                }

                await dispatchAIResponse(remoteJid, promptText, aiConfig)
                return
            }

            if (mediaPayload) {
                const mediaEntry = { type: "media", message: msg, mediaPayload }
                const captionText = (mediaPayload.node?.caption || "").trim()
                const fallbackDesc = mediaPayload.fallbackDescription || ""
                const description = captionText || fallbackDesc
                const promptText =
                    mediaPayload.type === "imagem" && description
                        ? `IMAGEM RECEBIDA: ${description}`
                        : description

                if (db) {
                    try {
                        await db.saveMessage(INSTANCE_ID, remoteJid, "user", promptText, "inbound")
                        log("flow.persist", {
                            remoteJid,
                            role: "user",
                            direction: "inbound",
                            length: promptText.length
                        })
                    } catch (err) {
                        log("Error saving user message:", err.message)
                    }
                }

                if (shouldSendSecretaryInitial) {
                    await sendSecretaryReply(remoteJid, secretaryConfig.initial_response, "initial")
                    return
                }

                if (!aiEnabled) {
                    return
                }

                if (delaySeconds > 0) {
                    handleMultiInputQueue(remoteJid, mediaEntry, delaySeconds)
                    return
                }

                if (!canUseGemini) {
                    try {
                        await sendWhatsAppMessage(remoteJid, {
                            text: "Multimídia só está disponível quando o Gemini é o provedor ativo."
                        })
                    } catch (err) {
                        log("Error notifying user about Gemini requirement:", err.message)
                    }
                    return
                }

                if (normalizedProvider !== "gemini" && hasGeminiKey) {
                    log("Usando Gemini via chave configurada mesmo com provider padrão:", rawProvider || "não informado")
                }

                log("processMessageWithAI multimodal", {
                    remoteJid,
                    type: mediaPayload.type,
                    snippet: snippet(promptText, 80)
                })

                await handleMultimodalMedia(remoteJid, aiConfig, [mediaEntry], promptText)
                return
            }

            let messageBody = msg.message.conversation || msg.message.extendedTextMessage?.text || ""
            let inboundMetadata
            if (messageBody.trim()) {
                const commandResult = await handleAssistantCommands(remoteJid, messageBody, aiConfig)
                if (commandResult.commands?.length) {
                    inboundMetadata = JSON.stringify({ commands: commandResult.commands })
                }
                if (commandResult.text?.trim()) {
                    messageBody = commandResult.text
                }
            }
            if (!messageBody.trim()) return

            if (db) {
                try {
                    await db.saveMessage(INSTANCE_ID, remoteJid, 'user', messageBody, 'inbound', inboundMetadata)
                    log("flow.persist", {
                        remoteJid,
                        role: "user",
                        direction: "inbound",
                        length: messageBody.length
                    })
                } catch (err) {
                    log("Error saving user message:", err.message)
                }
            }

            if (shouldSendSecretaryInitial) {
                await sendSecretaryReply(remoteJid, secretaryConfig.initial_response, "initial")
                return
            }

            if (secretaryConfig.enabled) {
                const secretaryReply = pickSecretaryTermResponse(messageBody, secretaryConfig)
                if (secretaryReply) {
                    await sendSecretaryReply(remoteJid, secretaryReply, "term")
                    return
                }
            }

            if (!aiEnabled) {
                return
            }

            if (isStatusBroadcast) {
                log("processMessageWithAI", {
                    remoteJid,
                    status: "status_broadcast_skipped",
                    snippet: snippet(messageBody, 120)
                })
                return
            }

            log("processMessageWithAI", {
                remoteJid,
                snippet: snippet(messageBody, 120)
            })

            if (delaySeconds > 0) {
                handleMultiInputQueue(remoteJid, { type: "text", text: messageBody }, delaySeconds)
                return
            }

            try {
                if (aiEnabled) {
                    log("flow.ai.dispatch", { remoteJid, provider: aiConfig.provider || DEFAULT_PROVIDER })
                    await dispatchAIResponse(remoteJid, messageBody, aiConfig)
                }
            } catch (aiError) {
                await handleAIError(remoteJid, aiError)
            }
        } catch (aiError) {
            await handleAIError(remoteJid, aiError)
        } finally {
            for (const filePath of tempPaths) {
                if (filePath && fs.existsSync(filePath)) {
                    try {
                        fs.unlinkSync(filePath)
                    } catch (err) {
                        log("Erro limpando arquivo temporário:", err.message)
                    }
                }
            }
        }
    }
}

// ===== FUNÇÕES WHATSAPP / BAILEYS =====
async function startWhatsApp() {
    log("Iniciando conexão Baileys...")

    try {
        const baileysPackage = await getBaileys()
        baileysModule = baileysPackage
        const {
            default: makeWASocket,
            DisconnectReason,
            useMultiFileAuthState,
            fetchLatestBaileysVersion
        } = baileysPackage

        const authDir = path.join(__dirname, `auth_${INSTANCE_ID}`)
        const { state, saveCreds } = await useMultiFileAuthState(authDir)
        const { version } = await fetchLatestBaileysVersion()

        sock = makeWASocket({
            version,
            auth: state,
            printQRInTerminal: true,
            browser: ["Janeri WPP Panel", "Chrome", "1.0.0"],
            syncFullHistory: false,
            cachedGroupMetadata: groupMetadataCache
        })

        sock.ev.on("creds.update", saveCreds)

        sock.ev.on("connection.update", update => {
            const { connection, lastDisconnect, qr } = update

            if (qr) {
                qrCodeData = qr
                connectionStatus = "qr"
                log("QR code atualizado")
                broadcastToClients("qr", { qr })
                broadcastToClients("status", {
                    instanceId: INSTANCE_ID,
                    connectionStatus,
                    whatsappConnected,
                    hasQR: !!qrCodeData
                })
                persistInstanceStatus("qr", "qr")
            }

            if (connection === "open") {
                whatsappConnected = true
                connectionStatus = "connected"
                qrCodeData = null
                lastConnectionError = null
                clearPendingAlarm("whatsapp")
                log("Conectado ao WhatsApp")
                const connectedPhone = sock?.user?.id || sock?.user?.jid || sock?.user?.name
                if (connectedPhone) {
                    updateInstancePhoneNumber(String(connectedPhone))
                }
                broadcastToClients("status", {
                    instanceId: INSTANCE_ID,
                    connectionStatus,
                    whatsappConnected,
                    hasQR: !!qrCodeData
                })
                persistInstanceStatus("connected", "connected")
                void clearAlarmLastSent("whatsapp")
                void clearAlarmLastSent("error")
            }

            if (connection === "close") {
                whatsappConnected = false
                connectionStatus = "disconnected"

                const reason = lastDisconnect?.error
                lastConnectionError = reason?.message || null

                log("Conexão fechada:", lastConnectionError || "sem detalhe")

                broadcastToClients("status", {
                    instanceId: INSTANCE_ID,
                    connectionStatus,
                    whatsappConnected,
                    hasQR: !!qrCodeData,
                    lastConnectionError
                })
                persistInstanceStatus("disconnected", "disconnected")
                scheduleAlarm(
                    "whatsapp",
                    `WhatsApp desconectado – instância ${INSTANCE_ID}`,
                    `A conexão ao WhatsApp foi encerrada.\n` +
                    `Motivo: ${lastConnectionError || "sem detalhe"}\n` +
                    `Horário: ${new Date().toISOString()}`,
                    WHATSAPP_ALARM_VERIFY_DELAY_MS
                )

                const shouldReconnect =
                    reason?.output?.statusCode !== DisconnectReason.loggedOut

                if (shouldReconnect && !restarting) {
                    log("Tentando reconectar automaticamente em 3s...")
                    setTimeout(() => {
                        startWhatsApp().catch(err =>
                            log("Erro ao reconectar:", err.message)
                        )
                    }, 3000)
                } else {
                    log("Sem reconexão automática (logout ou restart manual).")
                }
            }

            if (connection === "connecting") {
                connectionStatus = "starting"
                broadcastToClients("status", {
                    instanceId: INSTANCE_ID,
                    connectionStatus,
                    whatsappConnected,
                    hasQR: !!qrCodeData
                })
                persistInstanceStatus("starting", "starting")
            }
        })

        sock.ev.on("contacts.upsert", async updates => {
            if (!Array.isArray(updates) || updates.length === 0) return
            await Promise.allSettled(updates.map(contact => handleContactUpsert(contact)))
        })

        sock.ev.on("messages.upsert", async m => {
            try {
                const msgs = m.messages || []
                if (msgs.length) {
                    await Promise.allSettled(msgs.map(msg => handleContactFromMessage(msg)))
                }
                msgs.forEach(msg => {
                    const text = msg.message?.conversation
                        || msg.message?.extendedTextMessage?.text
                        || msg.message?.extendedTextMessage?.contextInfo?.quotedMessage?.conversation
                        || ""
                    log("messages.upsert incoming", {
                        remoteJid: msg.key?.remoteJid,
                        fromMe: msg.key?.fromMe,
                        stub: msg.messageStubType,
                        text: text ? snippet(text, 80) : "[media]"
                    })
                })

                const basic = msgs.map(msg => ({
                    key: msg.key,
                    pushName: msg.pushName,
                    fromMe: msg.key?.fromMe,
                    remoteJid: msg.key?.remoteJid,
                    messageStubType: msg.messageStubType
                }))
                broadcastToClients("messages", { type: m.type, messages: basic })

                // Process incoming messages with intelligent AI logic
                for (const msg of msgs) {
                    if (msg.key?.fromMe) {
                        await processOwnerQuickReply(msg)
                        continue
                    }
                    await processMessageWithAI(msg)

                    // Anti-ban: Mark as read with 5-10 second delay
                    if (msg.key?.fromMe === false) {
                        setTimeout(() => {
                            if (sock && whatsappConnected) {
                                try {
                                    sock.readMessages([msg.key])
                                } catch (err) {
                                    log("Erro ao marcar como lida:", err.message)
                                }
                            }
                        }, Math.random() * 5000 + 5000) // 5-10 seconds
                    }
                }
            } catch (e) {
                log("Error processing messages.upsert:", e.message)
            }
        })

    } catch (err) {
        log("Erro ao iniciar WhatsApp:", err.message)
        lastConnectionError = err.message
        connectionStatus = "error"
        broadcastToClients("status", {
            instanceId: INSTANCE_ID,
            connectionStatus,
            whatsappConnected,
            hasQR: !!qrCodeData,
            lastConnectionError
        })
        persistInstanceStatus("error", "error")
        await triggerInstanceAlarm(
            "error",
            `Erro crítico na instância ${INSTANCE_ID}`,
            `Falha ao iniciar/operar: ${err.message}\n` +
            `Stack: ${err.stack || "sem stack"}\n` +
            `Horário: ${new Date().toISOString()}`
        )
    }
}

async function logoutWhatsApp() {
    if (!sock) return
    try {
        log("Executando logout() no Baileys...")
        await sock.logout()
        whatsappConnected = false
        connectionStatus = "disconnected"
        qrCodeData = null
        broadcastToClients("status", {
            instanceId: INSTANCE_ID,
            connectionStatus,
            whatsappConnected,
            hasQR: !!qrCodeData
        })
        persistInstanceStatus("disconnected", "disconnected")
    } catch (e) {
        log("Erro ao fazer logout:", e.message)
        throw e
    }
}

async function restartWhatsApp() {
    restarting = true
    try {
        if (sock) {
            try {
                await sock.end()
            } catch (e) {
                log("Erro ao encerrar socket anterior (ignorado):", e.message)
            }
        }
        whatsappConnected = false
        qrCodeData = null
        connectionStatus = "starting"
        broadcastToClients("status", {
            instanceId: INSTANCE_ID,
            connectionStatus,
            whatsappConnected,
            hasQR: !!qrCodeData
        })
        persistInstanceStatus("starting", "starting")
        await startWhatsApp()
    } finally {
        restarting = false
    }
}

// ===== ROTAS HTTP =====

// raiz: info básica
app.get("/", (req, res) => {
    res.json({
        instanceId: INSTANCE_ID,
        message: "WhatsApp Instance Server with AI",
        connectionStatus,
        whatsappConnected,
        wsPath: "/ws"
    })
})

// health simples
app.get("/health", (req, res) => {
    res.json({
        ok: true,
        instanceId: INSTANCE_ID,
        status: connectionStatus,
        whatsappConnected
    })
})

// status detalhado
app.get("/status", (req, res) => {
    res.json({
        instanceId: INSTANCE_ID,
        connectionStatus,
        whatsappConnected,
        hasQR: !!qrCodeData,
        lastConnectionError
    })
})

// QR atual (se existir)
app.get("/qr", (req, res) => {
    if (!qrCodeData) {
        return res.status(404).json({ error: "QR não disponível" })
    }
    res.json({ qr: qrCodeData })
})

// ===== INTELLIGENT CHAT API ENDPOINTS =====

// GET /api/chats/:instance_id - List chats with last message
app.get("/api/chats/:instanceId", async (req, res) => {
    try {
        const { instanceId } = req.params
        const search = req.query.search || ''
        const limit = parseInt(req.query.limit) || 50
        const offset = parseInt(req.query.offset) || 0
        
        if (!db) {
            return res.status(503).json({ error: "Database not available" })
        }
        
        const chats = await db.getChats(instanceId, search, limit, offset)
        
        res.json({
            ok: true,
            instanceId,
            chats,
            pagination: {
                limit,
                offset,
                hasMore: chats.length === limit
            }
        })
    } catch (err) {
        log("Error getting chats:", err.message)
        res.status(500).json({ error: "Failed to get chats", detail: err.message })
    }
})

// GET /api/messages/:instance_id/:remote_jid - Get message history
app.get("/api/messages/:instanceId/:remoteJid", async (req, res) => {
    try {
        const { instanceId, remoteJid } = req.params
        const limitParam = parseInt(req.query.limit, 10)
        const offsetParam = parseInt(req.query.offset, 10)
        const limit = Number.isNaN(limitParam) ? 50 : limitParam
        const offset = Number.isNaN(offsetParam) ? 0 : Math.max(0, offsetParam)
        
        if (!db) {
            return res.status(503).json({ error: "Database not available" })
        }
        
        const messages = await db.getMessages(instanceId, remoteJid, limit, offset)
        let contactMeta = null
        if (instanceId === INSTANCE_ID) {
            await ensureContactMetadata(remoteJid)
            if (db?.getContactMetadata) {
                contactMeta = await db.getContactMetadata(instanceId, remoteJid)
            }
        }
        
        res.json({
            ok: true,
            instanceId,
            remoteJid,
            messages,
            contact_meta: contactMeta,
            pagination: {
                limit,
                offset,
                hasMore: limit > 0 && messages.length === limit
            }
        })
    } catch (err) {
        log("Error getting messages:", err.message)
        res.status(500).json({ error: "Failed to get messages", detail: err.message })
    }
})

// GET /api/message-counts/:instance_id/:remote_jid - Get inbound and outbound message counts
app.get("/api/message-counts/:instanceId/:remoteJid", async (req, res) => {
    try {
        const { instanceId, remoteJid } = req.params;
        if (!db) {
            return res.status(503).json({ error: "Database not available" });
        }
        const inboundCount = await db.getInboundMessageCount(instanceId, remoteJid);
        const outboundCount = await db.getOutboundMessageCount(instanceId, remoteJid);
        res.json({
            ok: true,
            instanceId,
            remoteJid,
            inboundCount,
            outboundCount
        });
    } catch (err) {
        log("Error getting message counts:", err.message);
        res.status(500).json({ error: "Failed to get message counts", detail: err.message });
    }
});

// GET /api/scheduled/:instance_id/:remote_jid - Get scheduled messages
app.get("/api/scheduled/:instanceId/:remoteJid", async (req, res) => {
    try {
        const { instanceId, remoteJid } = req.params
        if (!db) {
            return res.status(503).json({ error: "Database not available" })
        }
        const decoded = remoteJid ? decodeURIComponent(remoteJid) : ''
        const schedules = await db.getScheduledMessages(instanceId, decoded)
        res.json({
            ok: true,
            instanceId,
            remoteJid: decoded,
            schedules
        })
    } catch (err) {
        log("Error getting scheduled messages:", err.message)
        res.status(500).json({ error: "Failed to get scheduled messages", detail: err.message })
    }
})

app.post("/api/scheduled/:instanceId", async (req, res) => {
    try {
        const { instanceId } = req.params
        const { remote_jid: remoteJid, message, scheduled_at: scheduledAt, tag, tipo } = req.body || {}
        if (!remoteJid || !message || !scheduledAt) {
            return res.status(400).json({ ok: false, error: "remote_jid, message e scheduled_at são obrigatórios" })
        }
        const date = new Date(scheduledAt)
        if (Number.isNaN(date.getTime())) {
            return res.status(400).json({ ok: false, error: "scheduled_at inválido" })
        }
        const result = await db.enqueueScheduledMessage(instanceId, remoteJid, message, date, tag || "default", tipo || "followup")
        res.json({
            ok: true,
            instanceId,
            remoteJid,
            scheduledId: result.scheduledId,
            scheduledAt: result.scheduledAt
        })
    } catch (err) {
        log("Error creating scheduled message:", err.message)
        res.status(500).json({ ok: false, error: "Failed to create scheduled message", detail: err.message })
    }
})

app.delete("/api/scheduled/:instanceId/:scheduledId", async (req, res) => {
    try {
        const { instanceId, scheduledId } = req.params
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }

        await db.deleteScheduledMessage(Number(scheduledId))
        res.json({ ok: true, instanceId, scheduledId })
    } catch (err) {
        log("Error deleting scheduled message:", err.message)
        res.status(500).json({ ok: false, error: "Failed to delete scheduled message", detail: err.message })
    }
})

// GET /api/groups/:instanceId - list groups + monitored groups
app.get("/api/groups/:instanceId", async (req, res) => {
    try {
        const { instanceId } = req.params
        if (instanceId !== INSTANCE_ID) {
            return res.status(404).json({ ok: false, error: "Instância não encontrada" })
        }
        if (!sock || typeof sock.groupFetchAllParticipating !== "function") {
            return res.status(503).json({ ok: false, error: "WhatsApp não conectado" })
        }
        const groupsMap = await sock.groupFetchAllParticipating()
        const groups = Object.values(groupsMap || {}).map(group => ({
            jid: group.id,
            name: group.subject || group.name || "",
            size: group.size || 0
        }))
        const monitored = db ? await db.getMonitoredGroups(instanceId) : []
        res.json({ ok: true, instanceId, groups, monitored })
    } catch (err) {
        log("Error listing groups:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao listar grupos" })
    }
})

// POST /api/groups/:instanceId/monitor - update monitored groups
app.post("/api/groups/:instanceId/monitor", async (req, res) => {
    try {
        const { instanceId } = req.params
        const groups = req.body?.groups || []
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const result = await db.setMonitoredGroups(instanceId, groups)
        res.json({ ok: true, instanceId, updated: result.updated })
    } catch (err) {
        log("Error updating monitored groups:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao salvar grupos monitorados" })
    }
})

// POST /api/groups/:instanceId/send-bulk - send message to selected groups
app.post("/api/groups/:instanceId/send-bulk", async (req, res) => {
    try {
        const { instanceId } = req.params
        const { groups, message } = req.body || {}
        if (instanceId !== INSTANCE_ID) {
            return res.status(404).json({ ok: false, error: "Instância não encontrada" })
        }
        if (!sock) {
            return res.status(503).json({ ok: false, error: "WhatsApp não conectado" })
        }
        if (!Array.isArray(groups) || groups.length === 0) {
            return res.status(400).json({ ok: false, error: "Lista de grupos é obrigatória" })
        }
        if (!message || typeof message !== "string" || !message.trim()) {
            return res.status(400).json({ ok: false, error: "Mensagem é obrigatória" })
        }

        const trimmed = message.trim()
        const results = []
        const failures = []

        for (const groupJid of groups) {
            if (!groupJid || typeof groupJid !== "string") {
                continue
            }
            try {
                const result = await sendWhatsAppMessage(groupJid, { text: trimmed })
                results.push({ group_jid: groupJid, ok: true })
                if (db) {
                    await db.saveGroupMessage(
                        INSTANCE_ID,
                        groupJid,
                        getSelfJid(),
                        "outbound",
                        trimmed,
                        JSON.stringify({ bulk: true })
                    )
                }
                if (result) {
                    results[results.length - 1].result = result
                }
            } catch (err) {
                failures.push({ group_jid: groupJid, error: err.message || "Falha ao enviar" })
            }
        }

        res.json({
            ok: failures.length === 0,
            instanceId,
            sent: results.length,
            failed: failures
        })
    } catch (err) {
        log("Error sending bulk group message:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao enviar mensagens para grupos" })
    }
})

// POST /api/groups/:instanceId/contacts - fetch group contacts
app.post("/api/groups/:instanceId/contacts", async (req, res) => {
    try {
        const { instanceId } = req.params
        const { groups } = req.body || {}
        if (instanceId !== INSTANCE_ID) {
            return res.status(404).json({ ok: false, error: "Instância não encontrada" })
        }
        if (!sock) {
            return res.status(503).json({ ok: false, error: "WhatsApp não conectado" })
        }
        if (!Array.isArray(groups) || groups.length === 0) {
            return res.status(400).json({ ok: false, error: "Lista de grupos é obrigatória" })
        }

        const contacts = []
        const contactMetaCache = new Map()
        const contactStore = sock?.contacts || null
        const contactsByLid = new Map()
        if (contactStore && typeof contactStore === "object") {
            Object.values(contactStore).forEach(contact => {
                if (contact?.lid && typeof contact.lid === "string") {
                    contactsByLid.set(contact.lid, contact)
                }
            })
        }

        const getContactMetaName = async (jid) => {
            if (!db || !jid) return ""
            if (contactMetaCache.has(jid)) {
                return contactMetaCache.get(jid) || ""
            }
            try {
                const meta = await db.getContactMetadata(instanceId, jid)
                const name = normalizeMetaField(meta?.contact_name) || ""
                contactMetaCache.set(jid, name)
                return name
            } catch (err) {
                contactMetaCache.set(jid, "")
                return ""
            }
        }

        for (const groupJid of groups) {
            if (!groupJid || typeof groupJid !== "string") {
                continue
            }
            const meta = await getGroupMetadata(groupJid)
            const groupName = meta?.subject || meta?.name || groupJid
            const participants = Array.isArray(meta?.participants) ? meta.participants : []
            for (const participant of participants) {
                const candidateJids = [participant?.jid, participant?.id, participant?.participant].filter(Boolean)
                const preferredJid = candidateJids.find(item => item.includes("@s.whatsapp.net")) || candidateJids[0] || ""
                let jid = preferredJid
                let name = normalizeMetaField(participant?.notify || participant?.name || participant?.vname) || ""
                let contactRef = null
                if (contactStore && jid && contactStore[jid]) {
                    contactRef = contactStore[jid]
                } else if (jid && contactsByLid.has(jid)) {
                    contactRef = contactsByLid.get(jid)
                }
                if (contactRef) {
                    const contactJid = contactRef.id || contactRef.jid || ""
                    if (contactJid && contactJid !== jid) {
                        jid = contactJid
                    }
                    name = name || normalizeMetaField(contactRef.notify || contactRef.name || contactRef.vname) || ""
                }
                if (!name && jid) {
                    name = await getContactMetaName(jid)
                }
                const domain = jid.includes("@") ? jid.split("@")[1] : ""
                const phone = domain === "s.whatsapp.net" ? jid.split("@")[0].replace(/\D/g, "") : ""
                contacts.push({
                    group_jid: groupJid,
                    group_name: groupName,
                    jid,
                    name: name || jid,
                    phone
                })
            }
        }

        res.json({ ok: true, instanceId, contacts })
    } catch (err) {
        log("Error fetching group contacts:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao buscar contatos" })
    }
})

// POST /api/groups/:instanceId/leave - leave group and remove monitoring
app.post("/api/groups/:instanceId/leave", async (req, res) => {
    try {
        const { instanceId } = req.params
        const { group_jid: groupJid } = req.body || {}
        if (!groupJid) {
            return res.status(400).json({ ok: false, error: "group_jid é obrigatório" })
        }
        if (instanceId !== INSTANCE_ID) {
            return res.status(404).json({ ok: false, error: "Instância não encontrada" })
        }
        if (!sock || typeof sock.groupLeave !== "function") {
            return res.status(503).json({ ok: false, error: "WhatsApp não conectado" })
        }
        await sock.groupLeave(groupJid)
        if (db && typeof db.deleteMonitoredGroup === "function") {
            await db.deleteMonitoredGroup(instanceId, groupJid)
        }
        res.json({ ok: true, instanceId, group_jid: groupJid })
    } catch (err) {
        log("Error leaving group:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao sair do grupo" })
    }
})

// GET/POST /api/groups/:instanceId/auto-replies
app.get("/api/groups/:instanceId/auto-replies", async (req, res) => {
    try {
        const { instanceId } = req.params
        const groupJid = req.query.group_jid
        if (!groupJid) {
            return res.status(400).json({ ok: false, error: "group_jid é obrigatório" })
        }
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const data = await db.getGroupAutoReplies(instanceId, groupJid)
        res.json({ ok: true, instanceId, group_jid: groupJid, data })
    } catch (err) {
        log("Error fetching group auto replies:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao buscar respostas automáticas" })
    }
})

app.post("/api/groups/:instanceId/auto-replies", async (req, res) => {
    try {
        const { instanceId } = req.params
        const { group_jid: groupJid, replies, enabled } = req.body || {}
        if (!groupJid) {
            return res.status(400).json({ ok: false, error: "group_jid é obrigatório" })
        }
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const result = await db.setGroupAutoReplies(instanceId, groupJid, replies || [], enabled !== false)
        res.json({ ok: true, instanceId, group_jid: groupJid, updated: result.updated })
    } catch (err) {
        log("Error saving group auto replies:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao salvar respostas automáticas" })
    }
})

// POST /api/groups/:instanceId/schedules
app.post("/api/groups/:instanceId/schedules", async (req, res) => {
    try {
        const { instanceId } = req.params
        const { group_jid: groupJid, message, scheduled_at: scheduledAt } = req.body || {}
        if (!groupJid || !message || !scheduledAt) {
            return res.status(400).json({ ok: false, error: "group_jid, message e scheduled_at são obrigatórios" })
        }
        const date = new Date(scheduledAt)
        if (Number.isNaN(date.getTime())) {
            return res.status(400).json({ ok: false, error: "scheduled_at inválido" })
        }
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const result = await db.enqueueGroupScheduledMessage(instanceId, groupJid, message, date)
        res.json({ ok: true, instanceId, group_jid: groupJid, scheduledId: result.scheduledId, scheduledAt: result.scheduledAt })
    } catch (err) {
        log("Error scheduling group message:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao agendar mensagem de grupo" })
    }
})

app.delete("/api/messages/:instanceId/:remoteJid", async (req, res) => {
    if (!db) {
        return res.status(503).json({ ok: false, error: "Database not available" })
    }

    try {
        const { instanceId, remoteJid } = req.params
        await db.clearConversation(instanceId, remoteJid)
        res.json({ ok: true, remoteJid, instanceId })
    } catch (err) {
        log("Error clearing conversation:", err.message)
        res.status(500).json({ ok: false, error: "Failed to delete conversation" })
    }
})

// GET /api/health - Database health and statistics
function createMultiInputSnapshot(remoteJid, entry, now) {
    if (!entry) return null
    const fallbackExpiresAt = (entry.lastMessageAt || 0) + (entry.delaySeconds || 0) * 1000
    const remainingMs = Math.max(0, (entry.expiresAt || fallbackExpiresAt || 0) - now)
    return {
        remote_jid: remoteJid,
        delay_seconds: entry.delaySeconds || 0,
        remaining_seconds: Math.max(0, Math.ceil(remainingMs / 1000)),
        message_count: Array.isArray(entry.messages) ? entry.messages.length : 0
    }
}

app.get("/api/multi-input", (req, res) => {
    try {
        const now = Date.now()
        const remoteFilter = typeof req.query.remote === "string" && req.query.remote.trim()
            ? req.query.remote.trim()
            : null

        const snapshots = []
        for (const [jid, entry] of pendingMultiInputs.entries()) {
            const snapshot = createMultiInputSnapshot(jid, entry, now)
            if (snapshot) {
                snapshots.push(snapshot)
            }
        }

        const targetSnapshot = remoteFilter
            ? snapshots.find(snapshot => snapshot.remote_jid === remoteFilter)
            : null

        res.json({
            ok: true,
            pending: Boolean(targetSnapshot),
            remote_jid: targetSnapshot?.remote_jid || null,
            delay_seconds: targetSnapshot?.delay_seconds ?? 0,
            remaining_seconds: targetSnapshot?.remaining_seconds ?? 0,
            queue: snapshots
        })
    } catch (err) {
        log("Error fetching multi-input status:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao obter status multi-input", detail: err.message })
    }
})

app.get("/api/health", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({
                error: "Database not available",
                status: "disconnected"
            })
        }

        const health = await db.getDatabaseHealth()
        const globalTaxaR = await db.getGlobalTaxaRAverage(INSTANCE_ID)

        res.json({
            ok: true,
            status: "connected",
            database: health,
            globalTaxaR: globalTaxaR || 0,
            timestamp: new Date().toISOString()
        })
    } catch (err) {
        log("Error getting database health:", err.message)
        res.status(500).json({
            error: "Failed to get database health",
            status: "error",
            detail: err.message
        })
    }
})

app.get("/api/auto-pause-status", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }

        const enabled = await db.getSetting(INSTANCE_ID, 'auto_pause_enabled')
        const minutes = await db.getSetting(INSTANCE_ID, 'auto_pause_minutes')
        const pauseUntil = await db.getPersistentVariable(INSTANCE_ID, 'auto_pause_until')

        const isEnabled = enabled === '1' || enabled === 'true'
        const pauseMinutes = parseInt(minutes) || 5
        const pauseUntilMs = pauseUntil ? parseInt(pauseUntil) : null
        const now = Date.now()
        const isPaused = pauseUntilMs && pauseUntilMs > now
        const remainingMs = isPaused ? pauseUntilMs - now : 0
        const remainingSeconds = Math.ceil(remainingMs / 1000)

        res.json({
            ok: true,
            enabled: isEnabled,
            minutes: pauseMinutes,
            paused: isPaused,
            remaining_seconds: remainingSeconds,
            pause_until: pauseUntilMs ? new Date(pauseUntilMs).toISOString() : null
        })
    } catch (err) {
        log("Error getting auto pause status:", err.message)
        res.status(500).json({ ok: false, error: "Failed to get auto pause status", detail: err.message })
    }
})

app.get("/api/instances", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const instances = await db.listInstancesRecords()
        res.json({
            ok: true,
            instances
        })
    } catch (err) {
        log("Error fetching instances:", err.message)
        res.status(500).json({ ok: false, error: "Failed to list instances", detail: err.message })
    }
})

app.get("/api/instances/:instanceId", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const { instanceId } = req.params
        const record = await db.getInstanceRecord(instanceId)
        if (!record) {
            return res.status(404).json({ ok: false, error: "Instance not found" })
        }
        res.json({
            ok: true,
            instance: record
        })
    } catch (err) {
        log("Error fetching instance:", err.message)
        res.status(500).json({ ok: false, error: "Failed to fetch instance", detail: err.message })
    }
})

// ===== GOOGLE CALENDAR API =====

app.get("/api/calendar/auth-url", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        if (!CALENDAR_TOKEN_SECRET) {
            return res.status(400).json({ ok: false, error: "CALENDAR_TOKEN_SECRET não configurada" })
        }
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        cleanupExpiredCalendarStates()
        const state = toBase64Url(nodeCrypto.randomBytes(24))
        calendarOauthStates.set(state, { instanceId, createdAt: Date.now() })
        await persistPendingCalendarAuth(instanceId, state)
        const oauth2Client = buildGoogleOAuthClient()
        const url = oauth2Client.generateAuthUrl({
            access_type: "offline",
            scope: GOOGLE_CALENDAR_SCOPES,
            prompt: "consent",
            state
        })
        res.json({ ok: true, instanceId, url, state })
    } catch (err) {
        log("calendar auth-url error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao gerar URL OAuth", detail: err.message })
    }
})

app.get("/api/calendar/oauth2/callback", async (req, res) => {
    let callbackInstanceId = null
    let callbackState = null
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        if (!CALENDAR_TOKEN_SECRET) {
            return res.status(400).json({ ok: false, error: "CALENDAR_TOKEN_SECRET não configurada" })
        }
        const { code, state, error } = req.query || {}
        callbackState = state ? String(state) : null
        if (error) {
            return res.status(400).json({ ok: false, error: String(error) })
        }
        if (!code || !state) {
            return res.status(400).json({ ok: false, error: "Parâmetros code/state obrigatórios" })
        }
        cleanupExpiredCalendarStates()
        const meta = calendarOauthStates.get(String(state))
        if (!meta) {
            return res.status(400).json({ ok: false, error: "state inválido ou expirado" })
        }
        calendarOauthStates.delete(String(state))
        callbackInstanceId = meta.instanceId || INSTANCE_ID
        const instanceId = meta.instanceId || INSTANCE_ID
        const oauth2Client = buildGoogleOAuthClient()
        const { tokens } = await oauth2Client.getToken(String(code))
        if (!tokens || !tokens.refresh_token) {
            return res.status(400).json({ ok: false, error: "refresh_token ausente. Revogue acesso e reconecte com consentimento." })
        }
        oauth2Client.setCredentials(tokens)
        let calendarEmail = null
        try {
            const oauth2 = googleApis.google.oauth2({ version: "v2", auth: oauth2Client })
            const userInfo = await oauth2.userinfo.get()
            calendarEmail = userInfo?.data?.email || null
        } catch (err) {
            log("calendar userinfo error:", err.message)
        }
        await db.upsertCalendarAccount(instanceId, {
            calendar_email: calendarEmail,
            access_token: encryptCalendarToken(tokens.access_token),
            refresh_token: encryptCalendarToken(tokens.refresh_token),
            token_expiry: tokens.expiry_date || null,
            scope: tokens.scope || null
        })
        const payload = { ok: true, instanceId, calendar_email: calendarEmail }
        if ((req.headers.accept || "").includes("text/html")) {
            res.type("html").send(`
                <!doctype html>
                <html lang="pt-BR">
                <head>
                    <meta charset="utf-8">
                    <title>Google Calendar conectado</title>
                    <style>
                        body { font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:#0f172a; color:#f1f5f9; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
                        .card { text-align:center; background:#111827; padding:2rem; border-radius:1rem; box-shadow:0 20px 45px rgba(15,23,42,.45); max-width:360px; }
                        h1 { margin-bottom:0.5rem; font-size:1.5rem; }
                        p { color:#cbd5f5; margin-bottom:1rem; }
                        button { border:none; padding:0.65rem 1.25rem; border-radius:999px; font-size:0.95rem; font-weight:600; background:#34d399; color:#111827; cursor:pointer; }
                        button:hover { background:#2bb77c; }
                    </style>
                </head>
                <body>
                    <div class="card">
                        <h1>Parabéns!</h1>
                        <p>Seu Google Calendar está conectado com sucesso.</p>
                        <p>Você pode fechar esta janela e continuar o atendimento.</p>
                        <button onclick="window.close();">Fechar</button>
                    </div>
                </body>
                </html>
            `)
            return
        }
        res.json(payload)
    } catch (err) {
        log("calendar oauth2 callback error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao concluir OAuth", detail: err.message })
    } finally {
        if (callbackInstanceId) {
            await clearPendingCalendarAuth(callbackInstanceId, callbackState)
        }
    }
})

app.post("/api/calendar/disconnect", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        await db.clearCalendarAccount(instanceId)
        res.json({ ok: true, instanceId })
    } catch (err) {
        log("calendar disconnect error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao desconectar calendar", detail: err.message })
    }
})

app.post("/api/calendar/force-clear", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        const pending = await loadPendingCalendarAuth(instanceId)
        if (pending?.state) {
            calendarOauthStates.delete(pending.state)
        }
        await clearPendingCalendarAuth(instanceId)
        res.json({ ok: true, instanceId, force_cleared: true })
    } catch (err) {
        log("calendar force-clear error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao liberar bloqueio", detail: err.message })
    }
})

app.get("/api/calendar/config", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        cleanupExpiredCalendarStates()
        const account = await db.getCalendarAccount(instanceId)
        let pendingAuth = await loadPendingCalendarAuth(instanceId)
        if (pendingAuth?.state && !calendarOauthStates.has(pendingAuth.state)) {
            pendingAuth = null
            await clearPendingCalendarAuth(instanceId)
        }
        const calendars = await db.listCalendarConfigs(instanceId)
        const normalized = calendars.map(item => ({
            ...item,
            availability: parseAvailabilityJson(item.availability_json)
        }))
        res.json({
            ok: true,
            instanceId,
            connected: Boolean(account && account.refresh_token),
            account: account ? { calendar_email: account.calendar_email } : null,
            calendars: normalized,
            pending_auth: pendingAuth ? {
                state: pendingAuth.state,
                createdAt: pendingAuth.createdAt
            } : null
        })
    } catch (err) {
        log("calendar config error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao ler configuração", detail: err.message })
    }
})

app.get("/api/calendar/google-calendars", async (req, res) => {
    try {
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        await ensureCalendarConnection(instanceId)
        const { calendar } = await getCalendarService(instanceId)
        const response = await calendar.calendarList.list()
        const items = Array.isArray(response.data.items) ? response.data.items : []
        const calendars = items.map(item => ({
            id: item.id,
            summary: item.summary || "",
            timezone: item.timeZone || null,
            accessRole: item.accessRole || null,
            primary: Boolean(item.primary)
        }))
        res.json({ ok: true, instanceId, calendars })
    } catch (err) {
        log("calendar list error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao listar calendários", detail: err.message })
    }
})

app.post("/api/calendar/calendars", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        const payload = req.body || {}
        const calendarId = (payload.calendar_id || "").trim()
        if (!calendarId) {
            return res.status(400).json({ ok: false, error: "calendar_id é obrigatório" })
        }
        const existing = await db.getCalendarConfig(instanceId, calendarId)
        const availability = payload.availability || payload.availability_json || null
        const availabilityJson = availability ? JSON.stringify(availability) : existing?.availability_json || null
        const isDefault = payload.is_default === undefined ? (existing?.is_default ? 1 : 0) : (payload.is_default ? 1 : 0)
        await db.upsertCalendarConfig(instanceId, calendarId, {
            summary: payload.summary || existing?.summary || null,
            timezone: payload.timezone || existing?.timezone || null,
            availability_json: availabilityJson,
            is_default: isDefault
        })
        if (isDefault) {
            await db.setDefaultCalendarConfig(instanceId, calendarId)
        }
        res.json({ ok: true, instanceId, calendar_id: calendarId })
    } catch (err) {
        log("calendar save error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao salvar calendário", detail: err.message })
    }
})

app.delete("/api/calendar/calendars", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        const calendarId = typeof req.query.calendar_id === "string" ? req.query.calendar_id.trim() : ""
        if (!calendarId) {
            return res.status(400).json({ ok: false, error: "calendar_id é obrigatório" })
        }
        await db.deleteCalendarConfig(instanceId, calendarId)
        res.json({ ok: true, instanceId, calendar_id: calendarId })
    } catch (err) {
        log("calendar delete error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao remover calendário", detail: err.message })
    }
})

app.post("/api/calendar/default", async (req, res) => {
    try {
        if (!db) {
            return res.status(503).json({ ok: false, error: "Database not available" })
        }
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        const calendarId = (req.body?.calendar_id || "").trim()
        if (!calendarId) {
            return res.status(400).json({ ok: false, error: "calendar_id é obrigatório" })
        }
        await db.setDefaultCalendarConfig(instanceId, calendarId)
        res.json({ ok: true, instanceId, calendar_id: calendarId })
    } catch (err) {
        log("calendar default error:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao definir calendário padrão", detail: err.message })
    }
})

// ===== SETTINGS API =====

// Get settings
app.get("/api/settings/:key", async (req, res) => {
    try {
        const { key } = req.params
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID

        if (!db) {
            return res.status(503).json({ error: "Database not available" })
        }
        
        const value = await db.getSetting(instanceId, key)
        
        res.json({
            ok: true,
            key,
            value
        })
    } catch (err) {
        log("Error getting setting:", err.message)
        res.status(500).json({ error: "Failed to get setting", detail: err.message })
    }
})

// Set setting
app.post("/api/settings/:key", async (req, res) => {
    try {
        const { key } = req.params
        const { value } = req.body
        const instanceId = typeof req.query.instance === "string" && req.query.instance.trim()
            ? req.query.instance.trim()
            : INSTANCE_ID
        
        if (!db) {
            return res.status(503).json({ error: "Database not available" })
        }
        
        await db.setSetting(instanceId, key, value)
        
        res.json({
            ok: true,
            key,
            value
        })
    } catch (err) {
        log("Error setting value:", err.message)
        res.status(500).json({ error: "Failed to set setting", detail: err.message })
    }
})

app.get("/api/ai-config", async (req, res) => {
    try {
        const config = await loadAIConfig()
        res.json({
            ok: true,
            config
        })
    } catch (err) {
        log("Error loading AI config:", err.message)
        res.status(500).json({ ok: false, error: "Failed to load AI config" })
    }
})

app.post("/api/ai-config", async (req, res) => {
    if (!db) {
        return res.status(503).json({ ok: false, error: "Database not available" })
    }

    try {
        const payload = req.body || {}
        await persistAIConfig(payload)
        res.json({ ok: true })
    } catch (err) {
        log("Error saving AI config:", err.message)
        res.status(500).json({ ok: false, error: "Failed to save AI config", detail: err.message })
    }
})

app.post("/api/ai-test", async (req, res) => {
    try {
        const { message, remote_jid } = req.body || {}
        if (!message || typeof message !== "string" || !message.trim()) {
            return res.status(400).json({ ok: false, error: "Mensagem é obrigatória" })
        }

        const targetJid = (typeof remote_jid === "string" && remote_jid.trim())
            ? remote_jid.trim()
            : `test-${INSTANCE_ID}`

        const aiResponse = await generateAIResponse(targetJid, message.trim())
        res.json({
            ok: true,
            provider: aiResponse.provider,
            response: aiResponse.text
        })
    } catch (err) {
        log("AI test failed:", err.message)
        res.status(500).json({ ok: false, error: "Falha ao testar IA", detail: err.message })
    }
})

// ===== EXISTING FUNCTIONALITY (PRESERVED) =====

// envio de mensagem
app.post("/send-message", async (req, res) => {
    if (!sock || !whatsappConnected) {
        return res.status(503).json({ error: "WhatsApp não conectado" })
    }

    const { to, message } = req.body

    if (!to || !message) {
        return res.status(400).json({ error: "Parâmetros 'to' e 'message' são obrigatórios" })
    }

    try {
        let jid = to
        if (!jid.includes("@")) {
            const digits = String(jid).replace(/\D/g, "")
            jid = digits + "@s.whatsapp.net"
        }

        const result = await sendWhatsAppMessage(jid, { text: message })
        log("Mensagem enviada para", jid)

        // Save sent message to database
        if (db) {
            try {
                await db.saveMessage(INSTANCE_ID, jid, 'assistant', message, 'outbound')
            } catch (err) {
                log("Error saving sent message:", err.message)
            }
        }

        res.json({
            ok: true,
            instanceId: INSTANCE_ID,
            to: jid,
            result
        })
    } catch (err) {
        const detail = err.message || "Falha ao enviar mensagem"
        const normalized = detail.toLowerCase()
        let status = 500
        let error = "Falha ao enviar mensagem"
        if (normalized.includes("número inválido")) {
            status = 400
            error = "Número inválido"
        } else if (normalized.includes("não existe")) {
            status = 404
            error = "Número não existe no WhatsApp"
        }
        log("Erro ao enviar mensagem:", detail)
        res.status(status).json({ error, detail })
    }
})

// logout (desconectar e invalidar sessão)
app.post("/disconnect", async (req, res) => {
    try {
        await logoutWhatsApp()
        res.json({ ok: true, instanceId: INSTANCE_ID, message: "Logout realizado" })
    } catch (err) {
        res.status(500).json({ error: "Falha ao fazer logout", detail: err.message })
    }
})

// restart (recria conexão com mesma sessão)
app.post("/restart", async (req, res) => {
    try {
        await restartWhatsApp()
        res.json({ ok: true, instanceId: INSTANCE_ID, message: "Restart solicitado" })
    } catch (err) {
        res.status(500).json({ error: "Falha ao reiniciar", detail: err.message })
    }
})

// ===== INÍCIO DO SERVIDOR =====
startScheduleProcessor()
server.listen(PORT, () => {
    log("Servidor HTTP/WS ouvindo na porta", PORT)
    startWhatsApp().catch(err => {
        log("Erro inicial ao conectar WhatsApp:", err.message)
    })
})

// erros globais
process.on("unhandledRejection", err => {
    log("Unhandled Rejection:", err)
})

process.on("uncaughtException", err => {
    log("Uncaught Exception:", err)
})

