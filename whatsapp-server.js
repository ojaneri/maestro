// whatsapp-server.js
// Servidor por instância (Baileys + Express + WebSocket)

const express = require("express")
const bodyParser = require("body-parser")
const http = require("http")
const WebSocket = require("ws")
const { v4: uuidv4 } = require("uuid")
const argv = require("minimist")(process.argv.slice(2))
const path = require("path")
const nodeCrypto = require("crypto")

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

log("Iniciando instância:", INSTANCE_ID, "Porta:", PORT)

// ===== CARREGAR CONFIGURAÇÕES DA INSTÂNCIA =====
let instanceConfig = null
try {
    const instances = JSON.parse(require("fs").readFileSync("instances.json", "utf8"))
    instanceConfig = instances[INSTANCE_ID] || {}
    log("Configurações carregadas para instância:", INSTANCE_ID)
} catch (err) {
    log("Erro ao carregar instances.json:", err.message)
    instanceConfig = {}
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

// ===== FUNÇÕES WHATSAPP / BAILEYS =====
async function startWhatsApp() {
    log("Iniciando conexão Baileys...")

    try {
        const {
            default: makeWASocket,
            DisconnectReason,
            useMultiFileAuthState,
            fetchLatestBaileysVersion
        } = await getBaileys()

        const authDir = path.join(__dirname, `auth_${INSTANCE_ID}`)
        const { state, saveCreds } = await useMultiFileAuthState(authDir)
        const { version } = await fetchLatestBaileysVersion()

        sock = makeWASocket({
            version,
            auth: state,
            printQRInTerminal: true, // bom pra debug
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
            }

            if (connection === "open") {
                whatsappConnected = true
                connectionStatus = "connected"
                qrCodeData = null
                lastConnectionError = null
                log("Conectado ao WhatsApp")
                broadcastToClients("status", {
                    instanceId: INSTANCE_ID,
                    connectionStatus,
                    whatsappConnected,
                    hasQR: !!qrCodeData
                })
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
            }
        })

        sock.ev.on("messages.upsert", async m => {
            try {
                const msgs = m.messages || []
                const basic = msgs.map(msg => ({
                    key: msg.key,
                    pushName: msg.pushName,
                    fromMe: msg.key?.fromMe,
                    remoteJid: msg.key?.remoteJid,
                    messageStubType: msg.messageStubType
                }))
                broadcastToClients("messages", { type: m.type, messages: basic })

                // Process incoming messages for OpenAI responses
                for (const msg of msgs) {
                    if (msg.key?.fromMe || !msg.message) continue // Skip own messages or empty

                    const remoteJid = msg.key.remoteJid
                    if (!remoteJid || remoteJid.includes('@g.us')) continue // Skip groups for now

                    const openaiConfig = instanceConfig.openai || {}
                    if (!openaiConfig.enabled || !openaiConfig.api_key || !OpenAI) continue

                    try {
                        const messageBody = msg.message.conversation || msg.message.extendedTextMessage?.text || ""
                        if (!messageBody.trim()) continue

                        log("Processando mensagem com OpenAI:", messageBody.substring(0, 50) + "...")

                        const openai = new OpenAI({ apiKey: openaiConfig.api_key })
                        const messages = []
                        if (openaiConfig.system_prompt) {
                            messages.push({ role: 'system', content: openaiConfig.system_prompt })
                        }
                        if (openaiConfig.assistant_prompt) {
                            messages.push({ role: 'assistant', content: openaiConfig.assistant_prompt })
                        }
                        messages.push({ role: 'user', content: messageBody })

                        const response = await openai.chat.completions.create({
                            model: 'gpt-3.5-turbo',
                            messages: messages,
                            max_tokens: 500
                        })

                        const aiResponse = response.choices[0]?.message?.content?.trim()
                        if (aiResponse) {
                            await sock.sendMessage(remoteJid, { text: aiResponse })
                            log("Resposta OpenAI enviada para", remoteJid)
                        }
                    } catch (aiError) {
                        log("Erro no processamento OpenAI:", aiError.message)
                        // Send fallback message
                        try {
                            await sock.sendMessage(remoteJid, { text: "Desculpe, estou com problemas para responder no momento." })
                        } catch (sendError) {
                            log("Erro ao enviar mensagem de fallback:", sendError.message)
                        }
                    }
                }
            } catch (e) {
                log("Erro processando messages.upsert:", e.message)
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
        message: "WhatsApp Instance Server",
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
