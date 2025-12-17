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
if (!fs.existsSync(UPLOADS_DIR)) {
    fs.mkdirSync(UPLOADS_DIR, { recursive: true })
}

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

const DEFAULT_HISTORY_LIMIT = 15
const DEFAULT_TEMPERATURE = 0.3
const DEFAULT_MAX_TOKENS = 600
const DEFAULT_PROVIDER = "openai"
const DEFAULT_GEMINI_INSTRUCTION = "Você é um assistente atencioso e prestativo. Mantenha o tom profissional e informal. Sempre separe claramente o texto visível ao usuário do bloco de instruções/funções usando o marcador lógico &&& antes de iniciar os comandos."
const DEFAULT_MULTI_INPUT_DELAY = 0
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
    "ai_multi_input_delay"
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

function unescapeCommandString(value) {
    return value.replace(/\\(.)/g, "$1")
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
    "set_estado",
    "get_estado",
    "set_contexto",
    "get_contexto",
    "limpar_contexto",
    "optout",
    "status_followup",
    "log_evento",
    "tempo_sem_interacao"
]

const assistantCommandPattern = `\\b(${assistantFunctionNames.join("|")})\\s*\\(([^)]*)\\)`

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
        const [, type, rawArgs] = match
        commands.push({
            type: type.toLowerCase(),
            args: parseFunctionArgs(rawArgs),
            position: {
                start: match.index,
                end: match.index + match[0].length
            }
        })
        ranges.push({ start: match.index, end: match.index + match[0].length })
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
    if (!text) return text;
    return text.replace(createAssistantCommandRegex(), '').trim();
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

async function sendMailCommand(to, subject, body) {
    if (!to) {
        throw new Error("mail(): endereço de destino ausente")
    }

    const mailData = `To: ${to}\nSubject: ${subject || "Sem assunto"}\n\n${body || ""}\n`
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

    await sock.sendMessage(jid, { text: message })
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
                    await sendMailCommand(command.args[0], command.args[1], command.args[2])
                    log("Executor mail() acionado para", command.args[0])
                    result = buildFunctionResult(true, "OK", "email enviado", {
                        to: command.args[0] || ""
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
        if (!dueMessages.length) {
            return
        }

        for (const job of dueMessages) {
            try {
                await sock.sendMessage(job.remote_jid, { text: job.message })
                await db.saveMessage(INSTANCE_ID, job.remote_jid, "assistant", job.message, "outbound")
                await db.updateScheduledMessageStatus(job.id, "sent")
                log("Mensagem agendada enviada para", job.remote_jid, job.scheduled_at)
            } catch (err) {
                await db.updateScheduledMessageStatus(job.id, "failed", err.message)
                log("Erro ao enviar mensagem agendada", job.id, err.message)
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

function buildResponsesPayload(historyMessages, messageBody, config) {
    const conversation = []
    if (config.system_prompt) {
        conversation.push({ role: "system", content: config.system_prompt })
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

async function generateOpenAIResponse(aiConfig, remoteJid, messageBody) {
    if (!aiConfig.openai_api_key) {
        throw new Error("Chave OpenAI não configurada")
    }
    const openai = new OpenAI({ apiKey: aiConfig.openai_api_key })

    if (aiConfig.openai_mode === "assistants") {
        if (!aiConfig.assistant_id) {
            throw new Error("Assistant ID necessário para o modo Assistants")
        }

        const threadMeta = db ? await db.getThreadMetadata(INSTANCE_ID, remoteJid) : null
        let threadId = threadMeta?.threadId || null

        if (!threadId) {
            const run = await openai.beta.threads.createAndRun({
                assistant_id: aiConfig.assistant_id,
                model: aiConfig.model,
                temperature: aiConfig.temperature,
                max_completion_tokens: aiConfig.max_tokens,
                instructions: aiConfig.system_prompt || undefined,
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
                instructions: aiConfig.system_prompt || undefined,
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
    const instructionText = (aiConfig.gemini_instruction || DEFAULT_GEMINI_INSTRUCTION).trim()
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

async function generateAIResponse(remoteJid, messageBody, providedConfig = null) {
    const aiConfig = providedConfig || await loadAIConfig()
    if (!aiConfig.enabled) {
        throw new Error("Respostas automáticas estão desabilitadas")
    }

    log("generateAIResponse", {
        remoteJid,
        provider: aiConfig.provider,
        model: aiConfig.model,
        historyLimit: aiConfig.history_limit,
        snippet: snippet(messageBody, 120)
    })

    if (aiConfig.provider === "gemini") {
        const response = await generateGeminiResponse(aiConfig, remoteJid, messageBody)
        return { ...response, provider: "gemini" }
    }

    const response = await generateOpenAIResponse(aiConfig, remoteJid, messageBody)
    return { ...response, provider: "openai" }
}

async function dispatchAIResponse(remoteJid, messageBody, providedConfig = null, options = { allowBoomerang: true }) {
    if (!messageBody || !messageBody.trim()) {
        throw new Error("Mensagem vazia para IA")
    }

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

    const sanitizedOutputText = stripBeforeSeparator(outputText || finalBaseText)
    const segments = sanitizedOutputText
        .split("#")
        .map(part => part.trim())
        .filter(Boolean)

    const statusNameForContact = await resolveContactStatusName(remoteJid)
    const preparedSegments = segments.map(segment => replaceStatusPlaceholder(segment, statusNameForContact))

    if (!segments.length) {
        throw new Error("IA retornou apenas separadores de mensagem")
    }

    if (!sock || !whatsappConnected) {
        throw new Error("WhatsApp não conectado")
    }

    const metadata = commands?.length ? JSON.stringify({ commands }) : undefined

    for (const [index, segment] of preparedSegments.entries()) {
        await sock.sendMessage(remoteJid, { text: segment })
        if (db) {
            try {
                const segmentMetadata = index === 0 ? metadata : undefined
                await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", segment, "outbound", segmentMetadata)
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
    }

    const extension = mime.extension(baseMime) || fallbackExt
    const tempPath = path.join(UPLOADS_DIR, `wpp-media-${uuidv4()}.${extension}`)
    fs.writeFileSync(tempPath, buffer)
    return tempPath
}

function handleMultiInputQueue(remoteJid, messageBody, delaySeconds) {
    const trimmed = messageBody.trim()
    if (!trimmed || delaySeconds <= 0) {
        return
    }

    const now = Date.now()
    const delayMs = delaySeconds * 1000
    const expiresAt = now + delayMs

    const existing = pendingMultiInputs.get(remoteJid) || { messages: [], timer: null, delaySeconds: 0, expiresAt: now }
    existing.messages.push(trimmed)
    existing.delaySeconds = delaySeconds
    existing.expiresAt = expiresAt

    if (existing.timer) {
        clearTimeout(existing.timer)
    }

    existing.timer = setTimeout(() => {
        pendingMultiInputs.delete(remoteJid)
        const aggregated = existing.messages.filter(Boolean).join("\n")
        if (!aggregated) {
            return
        }

        dispatchAIResponse(remoteJid, aggregated)
            .catch(err => handleAIError(remoteJid, err))
    }, delayMs)

    pendingMultiInputs.set(remoteJid, existing)
    log(`[multi-input] aguardando ${delaySeconds}s para ${remoteJid} (${existing.messages.length} mensagem(ns))`)
}

async function handleAIError(remoteJid, error) {
    log("AI processing error:", error?.message || error)
    if (!sock) {
        return
    }
    try {
        const fallbackText = pickRandomErrorResponse()
        await sock.sendMessage(remoteJid, { text: fallbackText })
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
        gemini_instruction: settings.gemini_instruction || ""
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
            if (!remoteJid || remoteJid.includes('@g.us')) return // Skip groups
            const isStatusBroadcast = remoteJid === 'status@broadcast'
            if (isStatusBroadcast) {
                log("processMessageWithAI", {
                    remoteJid,
                    status: "status_broadcast_ignored"
                })
                return
            }

        const tempPaths = []
        try {
            const aiConfig = await loadAIConfig()
            if (!aiConfig.enabled) {
                return
            }

            const rawProvider = (aiConfig.provider || "").trim()
            console.log("Provider atual:", rawProvider || "não informado")
            const normalizedProvider = rawProvider.toLowerCase()
            const hasGeminiKey = Boolean(aiConfig.gemini_api_key)
            const canUseGemini = normalizedProvider === "gemini" || hasGeminiKey

            const mediaPayload = detectMediaPayload(msg.message)
            if (mediaPayload) {
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
                    } catch (err) {
                        log("Error saving user message:", err.message)
                    }
                }

                if (!canUseGemini) {
                    try {
                        await sock.sendMessage(remoteJid, {
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

                let tempPath
                try {
                    tempPath = await downloadMediaNodeToTemp(msg, mediaPayload.node, mediaPayload.downloadType)
                    tempPaths.push(tempPath)
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
                            await sock.sendMessage(remoteJid, {
                                text: fallbackText
                            })
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

                log("processMessageWithAI multimodal", {
                    remoteJid,
                    type: mediaPayload.type,
                    snippet: snippet(promptText, 80)
                })

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
                    await sock.sendMessage(remoteJid, { text: segment })
                }
                if (db) {
                    try {
                        await db.saveMessage(INSTANCE_ID, remoteJid, "assistant", sanitizedMultimodalText, "outbound")
                    } catch (err) {
                        log("Error saving assistant message:", err.message)
                    }
                }
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
                } catch (err) {
                    log("Error saving user message:", err.message)
                }
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

            const delaySeconds = Math.max(0, aiConfig.multi_input_delay ?? DEFAULT_MULTI_INPUT_DELAY)
            if (delaySeconds > 0) {
                handleMultiInputQueue(remoteJid, messageBody, delaySeconds)
                return
            }

            try {
                await dispatchAIResponse(remoteJid, messageBody, aiConfig)
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
            syncFullHistory: false
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
                    await processMessageWithAI(msg)
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
        const limit = parseInt(req.query.limit) || 50
        const offset = parseInt(req.query.offset) || 0
        
        if (!db) {
            return res.status(503).json({ error: "Database not available" })
        }
        
        const messages = await db.getMessages(instanceId, remoteJid, limit, offset)
        
        res.json({
            ok: true,
            instanceId,
            remoteJid,
            messages,
            pagination: {
                limit,
                offset,
                hasMore: messages.length === limit
            }
        })
    } catch (err) {
        log("Error getting messages:", err.message)
        res.status(500).json({ error: "Failed to get messages", detail: err.message })
    }
})

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
    const remainingMs = Math.max(0, (entry.expiresAt || 0) - now)
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
        
        res.json({
            ok: true,
            status: "connected",
            database: health,
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

        const result = await sock.sendMessage(jid, { text: message })
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
        log("Erro ao enviar mensagem:", err.message)
        res.status(500).json({ error: "Falha ao enviar mensagem", detail: err.message })
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
