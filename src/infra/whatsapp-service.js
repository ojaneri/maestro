const fs = require('fs')
const path = require('path')
const { log, INSTANCE_ID, PORT } = require("../config/config")
const { db } = require("./database-service")

// Global state
let whatsappConnected = false
let qrCodeData = null
let connectionStatus = "starting"
let lastConnectionError = null
let sock = null
let restarting = false
let connectionEstablishedAt = null
let lastWhatsAppVersion = null
let lastBaileysVersion = null
let reconnectTimer = null
let reconnectAttempts = 0
let startingWhatsApp = false
const pendingAiInputs = new Map()

// Placeholder for Baileys
let baileysModule = null

function clearReconnectTimer() {
    if (reconnectTimer) {
        clearTimeout(reconnectTimer)
        reconnectTimer = null
    }
}

function cleanupSocket() {
    if (!sock) return

    try {
        if (sock.ev && typeof sock.ev.removeAllListeners === "function") {
            sock.ev.removeAllListeners()
        }
    } catch (err) {
        log("Erro ao remover listeners do socket (ignorado):", err.message)
    }

    try {
        if (sock.ws && typeof sock.ws.close === "function") {
            sock.ws.close()
        }
    } catch (err) {
        log("Erro ao fechar WS do socket (ignorado):", err.message)
    }

    sock = null
}

function scheduleReconnect(reasonCode, reasonMessage) {
    if (restarting) {
        log("Reconexão automática ignorada: reinício manual em andamento.")
        return
    }

    clearReconnectTimer()

    reconnectAttempts += 1
    const delayMs = Math.min(3000 + ((reconnectAttempts - 1) * 2000), 15000)
    log(`Reconectando em ${delayMs}ms (tentativa ${reconnectAttempts}). Motivo: ${reasonCode || "n/a"} ${reasonMessage || ""}`.trim())

    reconnectTimer = setTimeout(() => {
        startWhatsApp().catch(err => {
            log("Erro ao reconectar automaticamente:", err.message)
        })
    }, delayMs)
}

function resolveMultiInputDelayMs(aiConfig = null) {
    const rawDelay = Number(aiConfig?.multi_input_delay || 0)
    if (!Number.isFinite(rawDelay) || rawDelay <= 0) {
        return 0
    }
    // Stored as seconds in settings (ai_multi_input_delay)
    return Math.max(0, Math.floor(rawDelay * 1000))
}

function buildAIDependencies() {
    return {
        sock: sock,
        db: db,
        sendWhatsAppMessage: sendWhatsAppMessage,
        persistSessionMessage: async (data) => {
            if (db && typeof db.saveMessage === "function") {
                await db.saveMessage(
                    INSTANCE_ID,
                    data.sessionContext.remoteJid,
                    data.role,
                    data.content,
                    data.direction,
                    data.metadata || null,
                    { sessionId: data.sessionContext.sessionId || "" }
                )
            }
        }
    }
}

async function dispatchAIForRemote(aiService, remoteJid, messageText, aiConfig = null) {
    if (!aiService || typeof aiService.dispatchAIResponse !== "function") {
        return
    }
    const sessionContext = {
        remoteJid: remoteJid,
        instanceId: INSTANCE_ID,
        // Keep stable empty session to preserve full contact context/history.
        sessionId: ""
    }
    await aiService.dispatchAIResponse(
        sessionContext,
        messageText,
        aiConfig || null,
        {},
        buildAIDependencies()
    )
}

function queueAIDispatch(aiService, remoteJid, messageText, delayMs, aiConfig = null) {
    const existing = pendingAiInputs.get(remoteJid)
    if (existing?.timer) {
        clearTimeout(existing.timer)
    }
    const entry = existing || { messages: [], startedAt: Date.now(), delayMs: delayMs }
    entry.messages.push(messageText)
    entry.timer = setTimeout(async () => {
        pendingAiInputs.delete(remoteJid)
        const aggregatedText = entry.messages
            .map(value => String(value || "").trim())
            .filter(Boolean)
            .join("\n")
        if (!aggregatedText) return
        try {
            await dispatchAIForRemote(aiService, remoteJid, aggregatedText, aiConfig)
            log("[AI] Response dispatched for:", remoteJid)
        } catch (err) {
            log("[AI] Error processing queued message:", err.message)
        }
    }, delayMs)
    pendingAiInputs.set(remoteJid, entry)
}

function getPendingAiInputs(remoteJid = null) {
    if (remoteJid) {
        const entry = pendingAiInputs.get(remoteJid)
        if (!entry) return null
        const elapsed = Date.now() - entry.startedAt
        const remaining = Math.max(0, entry.delayMs - elapsed)
        return {
            remoteJid,
            pending: true,
            remaining_seconds: Math.ceil(remaining / 1000),
            delay_seconds: Math.ceil(entry.delayMs / 1000),
            message_count: entry.messages.length
        }
    }

    const all = []
    pendingAiInputs.forEach((entry, jid) => {
        const elapsed = Date.now() - entry.startedAt
        const remaining = Math.max(0, entry.delayMs - elapsed)
        all.push({
            remoteJid: jid,
            pending: true,
            remaining_seconds: Math.ceil(remaining / 1000),
            delay_seconds: Math.ceil(entry.delayMs / 1000),
            message_count: entry.messages.length
        })
    })
    return all
}

// LID to PN mapping cache (persistent across sessions)
const lidToPNCache = new Map()
const pnToLIDCache = new Map()

// Debug logging for LID resolution
const debugLogs = new Map()
const VERBOSE_LID_DEBUG = process.env.VERBOSE_LID_DEBUG === 'true'

/**
 * Get debug log file path for a specific LID
 * @param {string} lid - The LID
 * @returns {string} - File path
 */
function getDebugLogPath(lid) {
    const lidNum = lid.replace(/@lid$/, '').replace(/^lid:/, '')
    return `debug/lid-${lidNum}.log`
}

/**
 * Log debug information for LID resolution
 * @param {string} lid - The LID
 * @param {string} event - Event name
 * @param {object} data - Data to log
 */
function logLIDDebug(lid, event, data) {
    if (!VERBOSE_LID_DEBUG && !debugLogs.has(lid)) return
    
    const timestamp = new Date().toISOString()
    const logEntry = `[${timestamp}] [${event}] ${JSON.stringify(data, null, 2)}\n`
    
    // Store in memory
    if (!debugLogs.has(lid)) {
        debugLogs.set(lid, [])
    }
    debugLogs.get(lid).push({ timestamp, event, data })
    
    // Log to console if VERBOSE_LID_DEBUG is enabled
    if (VERBOSE_LID_DEBUG) {
        console.log(`[LID-DEBUG] [${lid}] [${event}]`, JSON.stringify(data, null, 2))
    }
}

/**
 * Get debug logs for a specific LID
 * @param {string} lid - The LID
 * @returns {Array} - Debug logs
 */
function getDebugLogs(lid) {
    return debugLogs.get(lid) || []
}

/**
 * Clear debug logs for a specific LID
 * @param {string} lid - The LID
 */
function clearDebugLogs(lid) {
    debugLogs.delete(lid)
}

/**
 * Log debug information for received messages to debug.log
 * @param {object} msg - Message object
 * @param {object} metadata - Additional metadata
 */
function logMessageDebug(msg, metadata = {}) {
    const timestamp = new Date().toISOString().replace('T', ' ').substring(0, 19)
    
    // Extract message content (first 10 chars)
    let messageContent = ''
    const msgObj = msg.message || msg
    if (msgObj?.conversation) {
        messageContent = String(msgObj.conversation).substring(0, 10)
    } else if (msgObj?.extendedTextMessage?.text) {
        messageContent = String(msgObj.extendedTextMessage.text).substring(0, 10)
    } else if (msgObj?.imageMessage?.caption) {
        messageContent = String(msgObj.imageMessage.caption).substring(0, 10)
    } else if (msgObj?.videoMessage?.caption) {
        messageContent = String(msgObj.videoMessage.caption).substring(0, 10)
    } else if (msgObj?.documentMessage?.fileName) {
        messageContent = String(msgObj.documentMessage.fileName).substring(0, 10)
    } else {
        messageContent = Object.keys(msgObj || {}).join(', ').substring(0, 10)
    }
    
    const remoteJid = msg.remoteJid || msg.key?.jid || 'unknown'
    const pushName = msg.pushName || metadata.pushName || 'unknown'
    
    // Build meta string
    let metaParts = []
    if (msg.remoteJidAlt) metaParts.push(`alt: ${msg.remoteJidAlt}`)
    if (msg.participantAlt) metaParts.push(`alt: ${msg.participantAlt}`)
    if (msg.senderPn) metaParts.push(`pn: ${msg.senderPn}`)
    if (msg.participant) metaParts.push(`part: ${msg.participant}`)
    
    const metaStr = metaParts.length > 0 ? ` | ${metaParts.join(' | ')}` : ''
    
    const logLine = `[${timestamp}] LID | ${remoteJid} | ${pushName} | ${messageContent}...${metaStr}\n`
    
    try {
        fs.appendFileSync('debug.log', logLine)
    } catch (err) {
        console.error('Error writing to debug.log:', err.message)
    }
}

// ============================================================================
// LID-based Identity Resolution for Baileys v7
// Resolves LID (Light ID) to Phone Number (PN) and vice versa
// ============================================================================

// ============================================================================
// LID to PN Resolution Functions
// ============================================================================

/**
 * Resolve LID to Phone Number using Baileys v7 signalRepository
 * @param {string} lid - The LID to resolve (e.g., "lid:123456789@lid")
 * @returns {Promise<string|null>} - Phone number or null if not found
 */
async function resolveLIDtoPN(lid) {
    if (!lid) return null
    
    // Check cache first
    if (lidToPNCache.has(lid)) {
        return lidToPNCache.get(lid)
    }
    
    if (!sock?.signalRepository?.lidMapping?.getPNForLID) {
        log("signalRepository.lidMapping.getPNForLID not available")
        return null
    }
    
    try {
        const pn = await sock.signalRepository.lidMapping.getPNForLID(lid)
        if (pn) {
            // Normalize and cache
            const normalizedPN = normalizePN(pn)
            lidToPNCache.set(lid, normalizedPN)
            pnToLIDCache.set(normalizedPN, lid)
            log(`Resolved LID ${lid} to PN ${normalizedPN}`)
            return normalizedPN
        }
    } catch (err) {
        log(`Error resolving LID ${lid}:`, err.message)
    }
    
    return null
}

/**
 * Extract PN from message metadata (PRIORITY 1 - most reliable)
 * @param {object} msg - Message object from Baileys
 * @returns {string|null} - Phone number or null
 */
function extractPNfromMessage(msg) {
    if (!msg) return null
    
    // Try remoteJidAlt (private chats) - this is the most reliable source
    if (msg.remoteJidAlt && isPNFormat(msg.remoteJidAlt)) {
        return normalizePN(msg.remoteJidAlt)
    }
    
    // Try participantAlt (groups)
    if (msg.participantAlt && isPNFormat(msg.participantAlt)) {
        return normalizePN(msg.participantAlt)
    }
    
    // Try senderPn field (groups - Baileys v7)
    if (msg.senderPn && isPNFormat(msg.senderPn)) {
        return normalizePN(msg.senderPn)
    }
    
    // Try key?.jid if it's a PN format
    if (msg.key?.jid && isPNFormat(msg.key.jid) && !msg.key.jid.includes('@lid')) {
        return normalizePN(msg.key.jid)
    }
    
    return null
}

function unwrapMessageNode(message) {
    let current = message
    for (let i = 0; i < 6; i += 1) {
        if (!current || typeof current !== "object") break
        if (current.ephemeralMessage?.message) {
            current = current.ephemeralMessage.message
            continue
        }
        if (current.viewOnceMessage?.message) {
            current = current.viewOnceMessage.message
            continue
        }
        if (current.viewOnceMessageV2?.message) {
            current = current.viewOnceMessageV2.message
            continue
        }
        if (current.viewOnceMessageV2Extension?.message) {
            current = current.viewOnceMessageV2Extension.message
            continue
        }
        if (current.documentWithCaptionMessage?.message) {
            current = current.documentWithCaptionMessage.message
            continue
        }
        if (current.editedMessage?.message) {
            current = current.editedMessage.message
            continue
        }
        break
    }
    return current || message
}

function extractInboundMessageText(message) {
    const node = unwrapMessageNode(message)
    if (!node || typeof node !== "object") return ""

    const text = node.conversation
        || node.extendedTextMessage?.text
        || node.imageMessage?.caption
        || node.videoMessage?.caption
        || node.documentMessage?.caption
        || node.buttonsResponseMessage?.selectedDisplayText
        || node.listResponseMessage?.title
        || node.templateButtonReplyMessage?.selectedDisplayText
        || node.interactiveResponseMessage?.nativeFlowResponseMessage?.paramsJson
        || ""
    if (text) return String(text).trim()

    if (node.audioMessage) return "Áudio recebido sem legenda."
    if (node.imageMessage) return "Imagem recebida sem legenda."
    if (node.videoMessage) return "Vídeo recebido sem legenda."
    if (node.documentMessage) return "Documento recebido sem legenda."
    if (node.stickerMessage) return "Sticker recebido."
    if (node.contactMessage || node.contactsArrayMessage) return "Contato recebido."
    if (node.locationMessage || node.liveLocationMessage) return "Localização recebida."
    return ""
}

async function resolveCanonicalRemoteJid(msg) {
    const keyRemote = msg?.key?.remoteJid || msg?.remoteJid || ""
    if (!keyRemote) return ""
    if (keyRemote.includes("@g.us") || keyRemote.startsWith("status@broadcast")) {
        return keyRemote
    }

    let pn = extractPNfromMessage(msg)
    if (!pn && keyRemote.includes("@lid")) {
        try {
            if (lidToPNCache.has(keyRemote)) {
                pn = lidToPNCache.get(keyRemote)
            } else if (db && typeof db.getPNFromLID === "function") {
                pn = await db.getPNFromLID(INSTANCE_ID, keyRemote)
                if (!pn) {
                    pn = await db.getPNFromLID(keyRemote)
                }
                if (pn) {
                    const normalized = normalizePN(pn)
                    lidToPNCache.set(keyRemote, normalized)
                    pnToLIDCache.set(normalized, keyRemote)
                    pn = normalized
                }
            }
        } catch (err) {
            log("Erro ao resolver PN para LID em contact_metadata:", err.message)
        }
    }

    if (pn && /^\d{10,15}$/.test(String(pn))) {
        return `${pn}@s.whatsapp.net`
    }

    if (keyRemote.includes("@")) {
        return keyRemote
    }

    const digits = String(keyRemote).replace(/\D/g, "")
    return digits ? `${digits}@s.whatsapp.net` : keyRemote
}

/**
 * Extract display name (pushName) from message
 * @param {object} msg - Message object
 * @returns {string|null}
 */
function extractPushName(msg) {
    if (!msg) return null
    
    // Direct pushName field
    if (msg.pushName) {
        return msg.pushName
    }
    
    // From sender user data
    if (msg.senderUser?.notify) {
        return msg.senderUser.notify
    }
    
    // From message context
    if (msg.message?.conversation?.length > 0) {
        // For text messages, check if pushName is embedded
        return null // Would need message context
    }
    
    return null
}

/**
 * Normalize phone number to standard format
 * @param {string} jid - JID or phone number
 * @returns {string} - Normalized phone number
 */
function normalizePN(jid) {
    if (!jid) return null
    
    // Handle LID format: lid:123456789@lid
    if (jid.includes("@lid")) {
        const match = jid.match(/(\d+)/)
        if (match) return match[1]
        return jid
    }
    
    // Handle phone format: 1234567890@s.whatsapp.net
    if (jid.includes("@s.whatsapp.net") || jid.includes("@c.us")) {
        return jid.split("@")[0]
    }
    
    // Handle regular JID
    const match = jid.match(/(\d+)/)
    if (match) return match[1]
    
    return jid
}

/**
 * Check if JID is in phone number format
 * @param {string} jid 
 * @returns {boolean}
 */
function isPNFormat(jid) {
    if (!jid) return false
    // Check if it contains digits and looks like a phone number
    return /^\d{10,15}$/.test(String(jid).replace(/\D/g, ""))
}

// ============================================================================
// Status Fetch Functions
// ============================================================================

/**
 * Get user status (bio) for a given JID
 * @param {string} jid - JID (can be LID or PN format)
 * @returns {Promise<object|null>} - Status object with status property
 */
async function getUserStatus(jid) {
    if (!sock) {
        log("Cannot fetch status: socket not available")
        return null
    }
    
    try {
        const status = await sock.fetchStatus(jid)
        log(`Fetched status for ${jid}: ${status?.status?.substring(0, 50)}...`)
        return status
    } catch (err) {
        log(`Error fetching status for ${jid}:`, err.message)
        return null
    }
}

// ============================================================================
// Contact Sync for LID↔PN Mapping
// ============================================================================

/**
 * Build local LID↔PN mapping from contacts
 * @param {Array} contacts - Array of contact objects
 */
function syncContacts(contacts) {
    if (!Array.isArray(contacts)) return
    
    contacts.forEach(contact => {
        const { id, notify, name } = contact
        
        if (id && id.includes("@lid")) {
            // It's a LID, try to extract PN from notify
            if (notify && isPNFormat(notify)) {
                const pn = normalizePN(notify)
                lidToPNCache.set(id, pn)
                pnToLIDCache.set(pn, id)
                log(`Synced contact: ${id} -> ${pn}`)
            }
        }
    })
}

/**
 * Handle messaging-history.set event for full history sync
 * @param {object} history - History data containing contacts
 */
function syncHistory(history) {
    if (history?.contacts) {
        syncContacts(history.contacts)
    }
}

// ============================================================================
// Enhanced Message Processing
// ============================================================================

/**
 * Process message and extract all identity information
 * @param {object} msg - Message object
 * @returns {Promise<object>} - Enhanced message with identity info
 */
async function processMessageIdentity(msg) {
    if (!msg) return null
    
    const enhancedMsg = {
        ...msg,
        _identity: {}
    }
    
    // Get the message JID
    const messageJID = msg.key?.jid || msg.remoteJid
    
    if (!messageJID) return enhancedMsg
    
    // Check if it's a LID
    if (messageJID.includes('@lid')) {
        // PRIMARY: Try signalRepository.getPNForLID() first (most reliable in Baileys v7)
        const pn = await resolveLIDtoPN(messageJID)
        if (pn) {
            enhancedMsg._identity.pn = pn
            enhancedMsg._identity.lid = messageJID
            enhancedMsg._identity.jid = pn
            enhancedMsg._identity.source = 'signalRepository'
            
            // Cache and save to database
            lidToPNCache.set(messageJID, pn)
            pnToLIDCache.set(pn, messageJID)
            saveLIDPNMapping(messageJID, pn)
            log(`[LID] Resolved ${messageJID} -> ${pn} via signalRepository`)
        } else {
            // Fall back to message metadata if available
            const metadataPN = extractPNfromMessage(msg)
            if (metadataPN) {
                enhancedMsg._identity.pn = metadataPN
                enhancedMsg._identity.lid = messageJID
                enhancedMsg._identity.jid = metadataPN
                enhancedMsg._identity.source = 'message_metadata'
                
                // Cache and save to database
                lidToPNCache.set(messageJID, metadataPN)
                pnToLIDCache.set(metadataPN, messageJID)
                saveLIDPNMapping(messageJID, metadataPN)
                log(`[LID] Resolved ${messageJID} -> ${metadataPN} from message metadata`)
            }
        }
    } else if (isPNFormat(messageJID)) {
        // It's already a PN
        enhancedMsg._identity.pn = normalizePN(messageJID)
        enhancedMsg._identity.jid = enhancedMsg._identity.pn
        enhancedMsg._identity.source = 'direct_jid'
    }
    
    // Extract pushName
    enhancedMsg._identity.pushName = extractPushName(msg) || msg.pushName
    
    // Try to get status if we have a PN
    if (enhancedMsg._identity.pn) {
        try {
            const status = await getUserStatus(enhancedMsg._identity.pn)
            if (status) {
                enhancedMsg._identity.status = status.status
            }
        } catch (e) {
            // Status fetch is optional, don't fail on error
        }
    }
    
    return enhancedMsg
}

// ============================================================================
// Database Integration for Persistent Mapping
// ============================================================================

/**
 * Save LID↔PN mapping to database
 * @param {string} lid 
 * @param {string} pn 
 */
async function saveLIDPNMapping(lid, pn) {
    try {
        if (db && typeof db.saveLIDPNMapping === "function") {
            await db.saveLIDPNMapping(INSTANCE_ID, lid, pn)
            return
        }
        if (db?.db) {
            await db.db.run(
                `INSERT OR REPLACE INTO lid_pn_mappings (lid, pn, updated_at) VALUES (?, ?, datetime('now'))`,
                [lid, pn]
            )
        }
    } catch (err) {
        log("Error saving LID-PN mapping:", err.message)
    }
}

/**
 * Load LID↔PN mappings from database
 */
async function loadLIDPNMappings() {
    try {
        if (db?.db) {
            const rows = await db.db.all(
                "SELECT lid, pn FROM contact_metadata WHERE instance_id = ? AND lid IS NOT NULL AND pn IS NOT NULL",
                [INSTANCE_ID]
            )
            rows.forEach(row => {
                lidToPNCache.set(row.lid, row.pn)
                pnToLIDCache.set(row.pn, row.lid)
            })
            log(`Loaded ${rows.length} LID↔PN mappings from contact_metadata`)
            return
        }
        if (db?.db) {
            const rows = await db.db.all("SELECT lid, pn FROM lid_pn_mappings")
            rows.forEach(row => {
                lidToPNCache.set(row.lid, row.pn)
                pnToLIDCache.set(row.pn, row.lid)
            })
            log(`Loaded ${rows.length} LID↔PN mappings from database`)
        }
    } catch (err) {
        log("Error loading LID-PN mappings:", err.message)
    }
}

// ============================================================================
// Main Baileys Connection Setup
// ============================================================================

async function getBaileys() {
    if (!baileysModule) {
        baileysModule = await import("@whiskeysockets/baileys")
    }
    return baileysModule
}

async function startWhatsApp() {
    if (startingWhatsApp) {
        log("Conexão já em inicialização; ignorando nova tentativa.")
        return
    }

    if (sock && whatsappConnected) {
        log("WhatsApp já conectado; ignorando start duplicado.")
        return
    }

    startingWhatsApp = true
    log("Iniciando conexão Baileys...")

    try {
        clearReconnectTimer()
        cleanupSocket()

        const baileysPackage = await getBaileys()
        baileysModule = baileysPackage
        const {
            default: makeWASocket,
            DisconnectReason,
            useMultiFileAuthState,
            fetchLatestBaileysVersion,
            Browsers
        } = baileysPackage

        const authDir = path.join(__dirname, '..', '..', 'auth_' + INSTANCE_ID)
        const { state, saveCreds } = await useMultiFileAuthState(authDir)
        const { version } = await fetchLatestBaileysVersion()
        lastBaileysVersion = Array.isArray(version) ? version.join(".") : String(version || "")

        const browserTuple = (Browsers && typeof Browsers.macOS === "function")
            ? Browsers.macOS("Desktop")
            : ["Mac OS", "Chrome", "120.0.0"]

        sock = makeWASocket({
            version,
            auth: state,
            printQRInTerminal: true,
            browser: browserTuple,
            syncFullHistory: false
        })

        sock.ev.on("creds.update", saveCreds)

        // Setup event handlers for LID resolution
        setupLIDEventHandlers()

        sock.ev.on("connection.update", update => {
            const { connection, lastDisconnect, qr } = update

            if (qr) {
                qrCodeData = qr
                connectionStatus = "waiting_for_qr"
                log("QR code atualizado")
            }

            if (connection === "open") {
                whatsappConnected = true
                qrCodeData = null
                connectionStatus = "connected"
                lastConnectionError = null
                connectionEstablishedAt = new Date().toISOString()
                reconnectAttempts = 0
                clearReconnectTimer()
                log("Conectado ao WhatsApp")
                
                // Load persisted LID↔PN mappings
                loadLIDPNMappings()
            }

            if (connection === "close") {
                whatsappConnected = false
                connectionStatus = "disconnected"
                const reason = lastDisconnect?.error
                const reasonCode = reason?.output?.statusCode
                lastConnectionError = reason?.message || null
                log("Conexão fechada:", lastConnectionError || "sem detalhe")
                connectionEstablishedAt = null

                const shouldReconnect = reasonCode !== DisconnectReason.loggedOut
                if (shouldReconnect && !restarting) {
                    scheduleReconnect(reasonCode, lastConnectionError)
                } else if (reasonCode === DisconnectReason.loggedOut) {
                    clearReconnectTimer()
                    reconnectAttempts = 0
                    log("Sessão deslogada no WhatsApp. Aguardando novo pareamento por QR.")
                }
            }

            if (connection === "connecting") {
                connectionStatus = "connecting"
                log("Conectando...")
            }
        })

    } catch (err) {
        log("Erro ao iniciar WhatsApp:", err.message)
        lastConnectionError = err.message
        scheduleReconnect("start_error", err.message)
    } finally {
        startingWhatsApp = false
    }
}

/**
 * Setup event handlers for LID-based identity resolution
 */
function setupLIDEventHandlers() {
    if (!sock?.ev) return
    
    // Handle contacts.upsert for contact sync
    sock.ev.on("contacts.upsert", (contacts) => {
        log(`Processing ${contacts.length} contacts`)
        syncContacts(contacts)
    })
    
    // Handle messaging-history.set for full history sync
    sock.ev.on("messaging-history.set", (history) => {
        log("Processing messaging history sync")
        syncHistory(history)
    })
    
    // Handle messages.upsert with identity enrichment
    sock.ev.on("messages.upsert", async (event) => {
        const { messages, type } = event
        log(`Processing ${messages.length} messages (${type})`)
        
        for (const msg of messages) {
            const msgKey = msg.key || {}
            const msgRemoteJidKey = msgKey.remoteJid || msg.remoteJid || ""
            
            // Centralized Inbound Log
            writeDebugLog('INBOUND', `Message received from ${msgRemoteJidKey}`, msg);
            const isGroup = typeof msgRemoteJidKey === "string" && msgRemoteJidKey.includes("@g.us")
            const isStatus = typeof msgRemoteJidKey === "string" && msgRemoteJidKey.startsWith("status@broadcast")
            const isInbound = msgKey.fromMe === false
            if (isInbound && !isGroup && !isStatus && db && typeof db.saveMessage === "function") {
                try {
                    const inboundContent = extractInboundMessageText(msg.message || {}) || "Mensagem recebida sem conteúdo textual."
                    const canonicalRemote = await resolveCanonicalRemoteJid(msg)
                    if (canonicalRemote) {
                        const inboundMetadata = JSON.stringify({
                            source: "baileys_upsert",
                            upsertType: type || null,
                            messageType: Object.keys(unwrapMessageNode(msg.message || {}) || {}).join(","),
                            pushName: msg.pushName || null
                        })
                        await db.saveMessage(
                            INSTANCE_ID,
                            canonicalRemote,
                            "user",
                            inboundContent,
                            "inbound",
                            inboundMetadata,
                            {
                                sessionId: "",
                                waMessageId: msgKey.id || null,
                                remoteJidAlt: msg.remoteJidAlt || null,
                                participantAlt: msg.participantAlt || null,
                                senderPn: msg.senderPn || extractPNfromMessage(msg) || null
                            }
                        )
                    }
                } catch (err) {
                    log("Erro ao persistir inbound:", err.message)
                }
            }

            // Log full message metadata structure
            const msgTimestamp = msg.messageTimestamp
            const msgPushName = msg.pushName
            const msgParticipant = msg.participant
            const msgRemoteJid = msg.remoteJid
            const msgRemoteJidAlt = msg.remoteJidAlt
            const msgParticipantAlt = msg.participantAlt
            const msgSenderPn = msg.senderPn
            const msgSenderUser = msg.senderUser
            
            // Build comprehensive metadata object
            const metadata = {
                key: msgKey,
                messageTimestamp: msgTimestamp,
                pushName: msgPushName,
                participant: msgParticipant,
                remoteJid: msgRemoteJid,
                remoteJidAlt: msgRemoteJidAlt,
                participantAlt: msgParticipantAlt,
                senderPn: msgSenderPn,
                senderUser: msgSenderUser,
                // Full message object for deep inspection
                fullMessage: msg.message,
                // Additional fields that might contain PN
                messageType: Object.keys(msg.message || {}).join(', '),
                // Context info
                isFromMe: msgKey?.fromMe,
                isStatus: msgKey?.remoteJid === 'status@broadcast'
            }
            
            // Log if this message contains LID
            if (msgRemoteJid?.includes('@lid') || msgParticipant?.includes('@lid')) {
                const lid = msgRemoteJid?.includes('@lid') ? msgRemoteJid : msgParticipant
                logLIDDebug(lid, 'MESSAGE_UPSERT', metadata)
                
                // Specifically look for PN in all possible fields
                const pnSources = {
                    remoteJidAlt: msgRemoteJidAlt,
                    participantAlt: msgParticipantAlt,
                    senderPn: msgSenderPn,
                    keyJid: msgKey?.jid,
                    participant: msgParticipant,
                    remoteJid: msgRemoteJid,
                    senderUserNotify: msgSenderUser?.notify,
                    senderUserId: msgSenderUser?.id
                }
                logLIDDebug(lid, 'PN_SOURCES', pnSources)
                
                log(`[LID-DEBUG] LID message received: ${lid}`)
                log(`[LID-DEBUG] PN sources:`, pnSources)
            }
            
            // Log to debug.log for all messages
            logMessageDebug(msg, metadata)
            
            // Process each message for identity resolution
            await processMessageIdentity(msg)
            
            // Process message with AI for inbound non-group messages
            log('[DEBUG AI] Processing message:', {
                fromMe: msgKey.fromMe,
                isGroup: isGroup,
                isStatus: isStatus,
                type: type,
                hasMessageText: !!(extractInboundMessageText(msg.message || {}))
            })
            
            // Extract message text first
            const messageText = extractInboundMessageText(msg.message || {})
            
            // Resolve canonical remote for all message processing
            let canonicalRemote = null
            try {
                canonicalRemote = await resolveCanonicalRemoteJid(msg)
            } catch (err) {
                log('[AI] Could not resolve canonicalRemote:', err.message)
            }
            
            // Check for debug command BEFORE any processing (including fromMe messages)
            if (messageText && messageText.trim().toLowerCase() === '#debug#') {
                log('[DEBUG] Debug command detected from', canonicalRemote)
                const aiService = require('../whatsapp-server/ai')
                if (typeof aiService.handleDebugCommand === 'function') {
                    await aiService.handleDebugCommand(msg, sock)
                } else {
                    // Fallback: send debug info directly
                    await sendDebugInfo(msg, sock)
                }
                log('[DEBUG] Debug info sent to', canonicalRemote)
                continue
            }
            
            if (!msgKey.fromMe && !isGroup && !isStatus) {
                try {
                    const aiService = require('../whatsapp-server/ai')
                    if (aiService && typeof aiService.dispatchAIResponse === 'function') {
                        const messageTextTrim = messageText.trim()
                        if (messageTextTrim) {
                            
                            if (!canonicalRemote) {
                                continue
                            }

                            let aiConfig = null
                            if (typeof aiService.loadAIConfig === "function") {
                                try {
                                    aiConfig = await aiService.loadAIConfig(db, INSTANCE_ID)
                                } catch (configErr) {
                                    log("[AI] Error loading AI config:", configErr.message)
                                }
                            }

                            const delayMs = resolveMultiInputDelayMs(aiConfig)
                            if (delayMs > 0) {
                                queueAIDispatch(aiService, canonicalRemote, messageText.trim(), delayMs, aiConfig)
                            } else {
                                await dispatchAIForRemote(aiService, canonicalRemote, messageText.trim(), aiConfig)
                                log('[AI] Response dispatched for:', canonicalRemote)
                            }
                        }
                    }
                } catch (err) {
                    log('[AI] Error processing message:', err.message)
                }
            }
        }
    })
    
    log("LID event handlers registered")
}

async function logoutWhatsApp() {
    if (!sock) return
    try {
        log("Executando logout() no Baileys...")
        await sock.logout()
        whatsappConnected = false
        qrCodeData = null
    } catch (e) {
        log("Erro ao fazer logout:", e.message)
        throw e
    }
}

async function restartWhatsApp() {
    restarting = true
    try {
        clearReconnectTimer()
        cleanupSocket()
        whatsappConnected = false
        qrCodeData = null
        reconnectAttempts = 0
        await startWhatsApp()
    } finally {
        restarting = false
    }
}

/**
 * Centralized verbose debug logger
 */
function writeDebugLog(category, message, data = null) {
    const debugFilePath = path.join(__dirname, '../../debug.log');
    if (!fs.existsSync(debugFilePath)) return;
    
    const timestamp = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const instanceId = INSTANCE_ID || 'default';
    let logLine = `[${timestamp}] [${instanceId}] [${category.toUpperCase()}] ${message}\n`;
    
    if (data) {
        try {
            const dataStr = typeof data === 'object' ? JSON.stringify(data, null, 2) : String(data);
            logLine += `DATA: ${dataStr}\n`;
        } catch (e) {
            logLine += `DATA: [Error stringifying data]\n`;
        }
    }
    logLine += `--------------------------------------------------\n`;
    
    try {
        fs.appendFileSync(debugFilePath, logLine);
    } catch (err) {
        console.error('Error writing to debug.log:', err.message);
    }
}

async function sendWhatsAppMessage(jid, payload) {
    if (!sock || !whatsappConnected) {
        writeDebugLog('OUTBOUND_ERROR', `Failed to send to ${jid} - Socket not connected`);
        log("Message queued", jid)
        return { id: "queued", status: "queued" }
    }
    
    writeDebugLog('OUTBOUND', `Sending message to ${jid}`, payload);
    const result = await sock.sendMessage(jid, payload)
    log("Mensagem enviada para", jid)
    return result
}

/**
 * Send debug info response to user
 * @param {Object} msg - Message object
 * @param {Object} socket - WhatsApp socket
 */
async function sendDebugInfo(msg, socket) {
    const remoteJid = msg?.key?.remoteJid
    const instanceId = INSTANCE_ID || 'default'
    
    try {
        // Get AI config using correct path
        let aiConfig = null
        try {
            const aiService = require('../whatsapp-server/ai')
            if (typeof aiService.loadAIConfig === 'function') {
                aiConfig = await aiService.loadAIConfig(db, instanceId)
                log('[DEBUG] AI Config loaded successfully for instance:', instanceId)
            }
        } catch (err) {
            log('[DEBUG] Error loading AI config:', err.message)
        }
        
        // Load additional settings for debug info
        let additionalSettings = {}
        try {
            if (db && typeof db.getSettings === 'function') {
                const settingKeys = [
                    // AI / Multi-pause
                    'ai_multi_input_delay', 'auto_pause_enabled', 'auto_pause_minutes',
                    // Secretary
                    'secretary_enabled', 'secretary_idle_hours', 'secretary_initial_response',
                    'secretary_quick_replies', 'secretary_auto_reply', 'secretary_voice_notes',
                    // Audio / Transcription
                    'audio_transcription_enabled', 'audio_voice_reply',
                    // Calendar
                    'calendar_enabled', 'calendar_id'
                ]
                additionalSettings = await db.getSettings(instanceId, settingKeys) || {}
                log('[DEBUG] Additional settings loaded:', Object.keys(additionalSettings).length, 'keys')
            }
        } catch (err) {
            log('[DEBUG] Error loading additional settings:', err.message)
        }
        
        // Get process info
        const uptime = process.uptime()
        const uptimeFormatted = formatUptime(uptime)
        const memoryMB = (process.memoryUsage().heapUsed / 1024 / 1024).toFixed(2)
        const port = PORT || process.env.PORT || 'N/A'
        
        // Get system prompt - use the correct field name from loadAIConfig
        const systemPrompt = aiConfig?.system_prompt || ''
        const promptPreview = systemPrompt.substring(0, 200) + (systemPrompt.length > 200 ? '...' : '')
        const promptStatus = systemPrompt && systemPrompt.trim() ? 'CONFIGURED' : 'NOT CONFIGURED'
        
        // Extract additional settings
        const pauseBetweenMessages = additionalSettings?.ai_multi_input_delay || aiConfig?.multi_input_delay || 0
        const pauseEnabled = additionalSettings?.auto_pause_enabled === 'true' || additionalSettings?.auto_pause_enabled === '1'
        const pauseMinutes = additionalSettings?.auto_pause_minutes || 0
        
        const secretaryEnabled = additionalSettings?.secretary_enabled === 'true' || additionalSettings?.secretary_enabled === '1'
        const secretaryAutoReply = additionalSettings?.secretary_auto_reply === 'true' || additionalSettings?.secretary_auto_reply === '1'
        const secretaryVoiceNotes = additionalSettings?.secretary_voice_notes === 'true' || additionalSettings?.secretary_voice_notes === '1'
        
        const transcriptionEnabled = additionalSettings?.audio_transcription_enabled === 'true' || additionalSettings?.audio_transcription_enabled === '1'
        const audioVoiceReply = additionalSettings?.audio_voice_reply === 'true' || additionalSettings?.audio_voice_reply === '1'
        
        const calendarEnabled = additionalSettings?.calendar_enabled === 'true' || additionalSettings?.calendar_enabled === '1'
        const calendarId = additionalSettings?.calendar_id || 'N/A'
        
        // Build debug message with comprehensive info
        const debugInfo = `🔍 DEBUG INFO - Instance Diagnostics

📋 Instance: ${instanceId}
🌐 Port: ${port}
⚙️ PID: ${process.pid}
⏱️ Uptime: ${uptimeFormatted}
🧠 Memory: ${memoryMB} MB

📝 System Prompt: ${promptStatus}
"${promptPreview}"

🤖 AI Config:
- Provider: ${aiConfig?.provider || 'N/A'}
- Model: ${aiConfig?.model || 'N/A'}
- Temperature: ${aiConfig?.temperature || 'N/A'}
- Max Tokens: ${aiConfig?.max_tokens || 'N/A'}
- History Limit: ${aiConfig?.history_limit || 'N/A'}

⏸️ Multi-Pause Config:
- Enabled: ${pauseEnabled ? 'YES' : 'NO'}
- Delay: ${pauseBetweenMessages}ms
- Auto Pause Minutes: ${pauseMinutes || 'N/A'}

📞 Secretary:
- Enabled: ${secretaryEnabled ? 'YES' : 'NO'}
- Auto Reply: ${secretaryAutoReply ? 'YES' : 'NO'}
- Voice Notes: ${secretaryVoiceNotes ? 'YES' : 'NO'}

🎙️ Transcription:
- Enabled: ${transcriptionEnabled ? 'YES' : 'NO'}
- Audio Voice Reply: ${audioVoiceReply ? 'YES' : 'NO'}

📅 Calendar:
- Enabled: ${calendarEnabled ? 'YES' : 'NO'}
- Calendar ID: ${calendarId}

💻 Environment:
- Node: ${process.version}
- Env: ${process.env.NODE_ENV || 'production'}
- Platform: ${process.platform}`
        
        // Send debug info
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: debugInfo })
            log('[DEBUG] Debug info sent to', remoteJid)
        }
    } catch (error) {
        log('[DEBUG] Error generating debug info:', error)
        // Send error message
        const errorMsg = `❌ Erro ao gerar debug: ${error.message}`
        if (socket && socket.sendMessage) {
            await socket.sendMessage(remoteJid, { text: errorMsg })
        }
    }
}

/**
 * Format uptime in human readable format
 */
function formatUptime(seconds) {
    const days = Math.floor(seconds / 86400)
    const hours = Math.floor((seconds % 86400) / 3600)
    const minutes = Math.floor((seconds % 3600) / 60)
    const secs = Math.floor(seconds % 60)
    
    const parts = []
    if (days > 0) parts.push(`${days}d`)
    if (hours > 0) parts.push(`${hours}h`)
    if (minutes > 0) parts.push(`${minutes}m`)
    if (secs > 0 || parts.length === 0) parts.push(`${secs}s`)
    
    return parts.join(' ')
}

module.exports = {
    startWhatsApp,
    logoutWhatsApp,
    restartWhatsApp,
    sendWhatsAppMessage,
    sendDebugInfo,
    getPendingAiInputs,
    writeDebugLog,
    // LID Resolution Functions
    resolveLIDtoPN,
    extractPNfromMessage,
    extractPushName,
    getUserStatus,
    processMessageIdentity,
    // Contact Sync Functions
    syncContacts,
    syncHistory,
    // Cache Management
    lidToPNCache,
    pnToLIDCache,
    // Debug Functions
    logLIDDebug,
    logMessageDebug,
    getDebugLogs,
    clearDebugLogs,
    getDebugLogPath,
    get whatsappConnected() { return whatsappConnected },
    get qrCodeData() { return qrCodeData },
    get connectionStatus() { return connectionStatus },
    get lastConnectionError() { return lastConnectionError },
    get sock() { return sock }
}
