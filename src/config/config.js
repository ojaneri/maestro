const path = require("path")
const fs = require("fs")

// Timezone setup
const APP_TIMEZONE = process.env.APP_TIMEZONE || "America/Sao_Paulo"
process.env.TZ = APP_TIMEZONE

// Global polyfills
if (typeof navigator === "undefined") {
    global.navigator = {}
}

try {
    const dotenv = require("dotenv")
    dotenv.config({ path: path.join(__dirname, "../../.env") })
} catch (err) {
    console.warn("[GLOBAL] dotenv não disponível:", err.message)
}

// Directory constants
const UPLOADS_DIR = path.join(__dirname, "../../uploads")
const ASSETS_UPLOADS_DIR = path.resolve(__dirname, "../../assets", "uploads")
const REMOTE_CACHE_DIR = path.join(ASSETS_UPLOADS_DIR, "remote-cache")

if (!fs.existsSync(UPLOADS_DIR)) {
    fs.mkdirSync(UPLOADS_DIR, { recursive: true })
}
if (!fs.existsSync(REMOTE_CACHE_DIR)) {
    fs.mkdirSync(REMOTE_CACHE_DIR, { recursive: true })
}

// QR token management
const QR_TOKEN_DIR = path.join(__dirname, "../../storage", "qr_tokens")
const PUBLIC_BASE_URL = (process.env.PUBLIC_BASE_URL || "https://janeri.com.br/api/envio/wpp").replace(/\/+$/, "")
const MAESTRO_LOGO_URL = `${PUBLIC_BASE_URL}/assets/maestro-logo.png`

// Google OAuth
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

// Baileys constants
const BAILEYS_USER_AGENTS = [
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 18_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.3 Mobile/15E148 Safari/604.1",
    "Mozilla/5.0 (Linux; Android 15; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Mobile Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36 Edg/144.0.3719.82",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36",
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.3 Safari/605.1.15",
    "Mozilla/5.0 (Linux; Android 15; SM-S931U) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/27.0 Chrome/132.0.0.0 Mobile Safari/537.36",
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0",
    "Mozilla/5.0 (iPhone; CPU iPhone OS 18_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/144.0.7559.85 Mobile/15E148 Safari/604.1"
]
const BAILEYS_USER_AGENT_DEFAULT = BAILEYS_USER_AGENTS[0]
const BAILEYS_USER_AGENT_OVERRIDE = (process.env.BAILEYS_USER_AGENT || "").trim()
const BAILEYS_USER_AGENT = BAILEYS_USER_AGENT_OVERRIDE || BAILEYS_USER_AGENT_DEFAULT

const BAILEYS_PANEL_PREFIXES = [
    "Painel",
    "Central",
    "Núcleo",
    "Porto",
    "Base",
    "Mesa",
    "Observatório",
    "Estação",
    "Vértice",
    "Hub"
]
const BAILEYS_PANEL_CONTEXTS = [
    "Atendimento",
    "Operações",
    "Conexões",
    "Clientes",
    "Vendas",
    "Suporte",
    "Integração",
    "Fluxo",
    "Contatos",
    "Experiência"
]
const BAILEYS_PANEL_SUFFIXES = [
    "Digital",
    "Ágil",
    "Inteligente",
    "Norte",
    "Sul",
    "Leste",
    "Oeste",
    "Premium",
    "Rápido",
    "Pulse"
]

// Customer DB config - Default to empty values if not set (used for optional MySQL features)
const CUSTOMER_DB_CONFIG = {
    host: process.env.CUSTOMER_DB_HOST || 'localhost',
    port: Number(process.env.CUSTOMER_DB_PORT) || 3306,
    user: process.env.CUSTOMER_DB_USER || 'root',
    password: process.env.CUSTOMER_DB_PASSWORD || '',
    database: process.env.CUSTOMER_DB_NAME || 'chat_data',
    charset: "utf8mb4"
}

// Instance parameters
const INSTANCE_ID = process.argv.find(arg => arg.startsWith('--id='))?.split('=')[1] || process.env.INSTANCE_ID
const PORT = Number(process.argv.find(arg => arg.startsWith('--port='))?.split('=')[1] || process.env.PORT || 3000)

if (!INSTANCE_ID) {
    console.error("Faltou parâmetro --id=INSTANCE_ID ou variável INSTANCE_ID")
    process.exit(1)
}

// Global exposure
global.INSTANCE_ID = INSTANCE_ID;

// Logging function
function log(...args) {
    console.log(`[${INSTANCE_ID}]`, ...args)
}

// Other constants
const SCHEDULE_TIMEZONE_OFFSET_HOURS = -3 // UTC-3 fixed
const SCHEDULE_TIMEZONE_LABEL = APP_TIMEZONE
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

const DEFAULT_HISTORY_LIMIT = 15
const DEFAULT_TEMPERATURE = 0.3
const DEFAULT_MAX_TOKENS = 600
const DEFAULT_PROVIDER = "openai"
const DEFAULT_GEMINI_INSTRUCTION = "Você é um assistente atencioso e prestativo. Mantenha o tom profissional e informal. Sempre separe claramente o texto visível ao usuário do bloco de instruções/funções usando o marcador lógico &&& antes de iniciar os comandos."
const DEFAULT_MULTI_INPUT_DELAY = 0
const DEFAULT_AUDIO_TRANSCRIPTION_PREFIX = "🔊"
const DEFAULT_OPENROUTER_BASE_URL = "https://openrouter.ai"
const DEFAULT_SCHEDULE_TAG = "default"
const DEFAULT_SCHEDULE_TIPO = "followup"

const WHATSAPP_CACHE_TTL_DAYS = 90

module.exports = {
    APP_TIMEZONE,
    UPLOADS_DIR,
    ASSETS_UPLOADS_DIR,
    REMOTE_CACHE_DIR,
    QR_TOKEN_DIR,
    PUBLIC_BASE_URL,
    MAESTRO_LOGO_URL,
    GOOGLE_OAUTH_CLIENT_ID,
    GOOGLE_OAUTH_CLIENT_SECRET,
    GOOGLE_OAUTH_REDIRECT_URL,
    CALENDAR_TOKEN_SECRET,
    CALENDAR_STATE_TTL_MS,
    GOOGLE_CALENDAR_SCOPES,
    CALENDAR_PENDING_VARIABLE_KEY,
    BAILEYS_USER_AGENTS,
    BAILEYS_USER_AGENT_DEFAULT,
    BAILEYS_USER_AGENT,
    BAILEYS_PANEL_PREFIXES,
    BAILEYS_PANEL_CONTEXTS,
    BAILEYS_PANEL_SUFFIXES,
    CUSTOMER_DB_CONFIG,
    INSTANCE_ID,
    PORT,
    log,
    SCHEDULE_TIMEZONE_OFFSET_HOURS,
    SCHEDULE_TIMEZONE_LABEL,
    SCHEDULE_CHECK_INTERVAL_MS,
    SCHEDULE_FETCH_LIMIT,
    WHATSAPP_ALARM_VERIFY_DELAY_MS,
    GEMINI_ALLOWED_MEDIA_MIME_TYPES,
    DEFAULT_HISTORY_LIMIT,
    DEFAULT_TEMPERATURE,
    DEFAULT_MAX_TOKENS,
    DEFAULT_PROVIDER,
    DEFAULT_GEMINI_INSTRUCTION,
    DEFAULT_MULTI_INPUT_DELAY,
    DEFAULT_AUDIO_TRANSCRIPTION_PREFIX,
    DEFAULT_OPENROUTER_BASE_URL,
    DEFAULT_SCHEDULE_TAG,
    DEFAULT_SCHEDULE_TIPO,
    WHATSAPP_CACHE_TTL_DAYS
}