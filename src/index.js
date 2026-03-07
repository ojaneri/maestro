// whatsapp-server-intelligent.js refactored into modules

// Global setup
const { APP_TIMEZONE } = require("./config/config")
process.env.TZ = APP_TIMEZONE

if (typeof navigator === "undefined") {
    global.navigator = {}
}

const express = require("express")
const bodyParser = require("body-parser")
const http = require("http")
const WebSocket = require("ws")
const { v4: uuidv4 } = require("uuid")

const { PORT, INSTANCE_ID, log } = require("./config/config")
global.INSTANCE_ID = INSTANCE_ID;

const { db, dbReadyPromise } = require("./infra/database-service")
global.db = db;
const createCoreRoutes = require("./routes")
const createLegacyCompatRoutes = require("./routes/legacy-compat.routes")
const whatsappService = require("./infra/whatsapp-service")

// Import services
const { startScheduleProcessor } = require("./services/scheduler")
const { startWhatsApp } = require("./infra/whatsapp-service")

const app = express()
app.use(bodyParser.json())

// CORS - use ALLOWED_ORIGINS env var (comma-separated list)
const ALLOWED_ORIGINS = process.env.ALLOWED_ORIGINS?.split(',').map(o => o.trim()).filter(o => o) || [];
app.use((req, res, next) => {
    const origin = req.headers.origin;
    // Check if origin is in allowed list (or if ALLOWED_ORIGINS is empty, deny all)
    if (ALLOWED_ORIGINS.length > 0 && origin && ALLOWED_ORIGINS.includes(origin)) {
        res.setHeader('Access-Control-Allow-Origin', origin);
    } else if (ALLOWED_ORIGINS.length === 0) {
        // If no ALLOWED_ORIGINS set, deny all cross-origin requests
        res.setHeader('Access-Control-Allow-Origin', 'null');
    } else {
        // Origin not allowed
        return res.status(403).json({ error: 'Origin not allowed' });
    }
    res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
    if (req.method === 'OPTIONS') {
        return res.sendStatus(200);
    }
    next();
})

const server = http.createServer(app)
const wss = new WebSocket.Server({ server, path: "/ws" })

// WebSocket management
let clientConnections = []

wss.on("connection", ws => {
    const clientId = uuidv4()
    log("Novo cliente WebSocket conectado:", clientId)

    clientConnections.push({ id: clientId, ws })

    // Send initial status
    try {
        ws.send(JSON.stringify({
            type: "status",
            data: {
                connectionStatus: "starting",
                whatsappConnected: false,
                hasQR: false
            }
        }))
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

// Routes
const messageQueue = []
const pendingMultiInputs = new Map()
const getRouteSnapshot = () => ({
    instanceId: INSTANCE_ID,
    connectionStatus: whatsappService.connectionStatus || "starting",
    whatsappConnected: Boolean(whatsappService.whatsappConnected),
    hasQR: Boolean(whatsappService.qrCodeData),
    qrCodeData: whatsappService.qrCodeData || null,
    lastConnectionError: whatsappService.lastConnectionError || null,
    connectionSince: null,
    whatsappVersion: null,
    baileysVersion: null,
    browser: null,
    userAgent: null,
    wsPath: "/ws"
})

app.use(createCoreRoutes({
    getRouteSnapshot,
    messageQueue,
    pendingMultiInputs,
    db,
    INSTANCE_ID,
    sock: whatsappService.sock
}))
app.use(createLegacyCompatRoutes())

// Start
startScheduleProcessor()
server.listen({ host: "0.0.0.0", port: PORT }, () => {
    log("Servidor HTTP/WS ouvindo em 0.0.0.0:" + PORT)
    startWhatsApp().catch(err => {
        log("Erro inicial ao conectar WhatsApp:", err.message)
    })
})

// Global error handling
process.on("unhandledRejection", err => {
    log("Unhandled Rejection:", err)
})

process.on("uncaughtException", err => {
    log("Uncaught Exception:", err)
})
