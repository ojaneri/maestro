const path = require("path")
const fs = require("fs")
const argv = require("minimist")(process.argv.slice(2), {
    string: ['id', 'port'],
    alias: { id: ['instanceId', 'instance_id', 'i'] },
    stopEarly: false
})

const UPLOADS_DIR = path.join(__dirname, "..", "..", "uploads")
const ASSETS_UPLOADS_DIR = path.resolve(__dirname, "..", "..", "assets", "uploads")
const REMOTE_CACHE_DIR = path.join(ASSETS_UPLOADS_DIR, "remote-cache")
const QR_TOKEN_DIR = path.join(__dirname, "..", "..", "storage", "qr_tokens")

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

const INSTANCE_ID = argv.id || argv.instanceId || argv.instance_id || process.env.INSTANCE_ID || 'default'
const PORT = Number(argv.port || process.env.PORT || 3000)

// Ensure directories exist (side effect, but tightly coupled with constants)
if (!fs.existsSync(UPLOADS_DIR)) {
    fs.mkdirSync(UPLOADS_DIR, { recursive: true })
}
if (!fs.existsSync(REMOTE_CACHE_DIR)) {
    fs.mkdirSync(REMOTE_CACHE_DIR, { recursive: true })
}
if (!fs.existsSync(QR_TOKEN_DIR)) {
    fs.mkdirSync(QR_TOKEN_DIR, { recursive: true })
}

// Export all constants
module.exports = {
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
    INSTANCE_ID,
    PORT,
}