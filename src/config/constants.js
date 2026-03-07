const path = require("path");
const nodeCrypto = require("crypto");

const APP_TIMEZONE = process.env.APP_TIMEZONE || "America/Sao_Paulo";
const UPLOADS_DIR = path.join(__dirname, "../../uploads");
const ASSETS_UPLOADS_DIR = path.resolve(__dirname, "../../assets", "uploads");
const REMOTE_CACHE_DIR = path.join(ASSETS_UPLOADS_DIR, "remote-cache");
const QR_TOKEN_DIR = path.join(__dirname, "../../storage", "qr_tokens");
const PUBLIC_BASE_URL = (process.env.PUBLIC_BASE_URL || "https://janeri.com.br/api/envio/wpp").replace(/\/+$/, "");
const MAESTRO_LOGO_URL = `${PUBLIC_BASE_URL}/assets/maestro-logo.png`;

const GOOGLE_OAUTH_CLIENT_ID = process.env.GOOGLE_OAUTH_CLIENT_ID || "";
const GOOGLE_OAUTH_CLIENT_SECRET = process.env.GOOGLE_OAUTH_CLIENT_SECRET || "";
const GOOGLE_OAUTH_REDIRECT_URL = (process.env.GOOGLE_OAUTH_REDIRECT_URL || `${PUBLIC_BASE_URL}/api/calendar/oauth2/callback`).trim();
const CALENDAR_TOKEN_SECRET = process.env.CALENDAR_TOKEN_SECRET || "";
const CALENDAR_STATE_TTL_MS = 10 * 60 * 1000;
const GOOGLE_CALENDAR_SCOPES = [
    "https://www.googleapis.com/auth/calendar",
    "https://www.googleapis.com/auth/userinfo.email"
];
const CALENDAR_PENDING_VARIABLE_KEY = "calendar_pending_auth";

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
];

const BAILEYS_PANEL_PREFIXES = ["Painel", "Central", "Núcleo", "Porto", "Base", "Mesa", "Observatório", "Estação", "Vértice", "Hub"];
const BAILEYS_PANEL_CONTEXTS = ["Atendimento", "Operações", "Conexões", "Clientes", "Vendas", "Suporte", "Integração", "Fluxo", "Contatos", "Experiência"];
const BAILEYS_PANEL_SUFFIXES = ["Digital", "Ágil", "Inteligente", "Norte", "Sul", "Leste", "Oeste", "Premium", "Rápido", "Pulse"];

const SCHEDULE_TIMEZONE_OFFSET_HOURS = -3;
const SCHEDULE_TIMEZONE_LABEL = APP_TIMEZONE;
const SCHEDULE_CHECK_INTERVAL_MS = 30 * 1000;
const SCHEDULE_FETCH_LIMIT = 10;
const WHATSAPP_ALARM_VERIFY_DELAY_MS = Number(process.env.WHATSAPP_ALARM_VERIFY_DELAY_MS || 15000);

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
    BAILEYS_PANEL_PREFIXES,
    BAILEYS_PANEL_CONTEXTS,
    BAILEYS_PANEL_SUFFIXES,
    SCHEDULE_TIMEZONE_OFFSET_HOURS,
    SCHEDULE_TIMEZONE_LABEL,
    SCHEDULE_CHECK_INTERVAL_MS,
    SCHEDULE_FETCH_LIMIT,
    WHATSAPP_ALARM_VERIFY_DELAY_MS
};
