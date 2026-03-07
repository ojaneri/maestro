const express = require("express")
const db = require("../../db-updated")
const { INSTANCE_ID } = require("../config/config")
const whatsappService = require("../infra/whatsapp-service")
const { sendWhatsAppMessage, sendWhatsAppMedia } = require("../whatsapp-server/whatsapp/send-message")
const calendar = require("../whatsapp-server/calendar")

let calendarInitialized = false

function ensureCalendarInitialized() {
    if (calendarInitialized) return
    let googleApis = null
    try {
        googleApis = require("googleapis")
    } catch (err) {
        googleApis = null
    }
    calendar.initialize({ db, googleApis })
    calendarInitialized = true
}

function resolveInstanceId(req) {
    return (
        req.params.instanceId ||
        req.params.instance_id ||
        req.query.instanceId ||
        req.query.instance_id ||
        req.query.instance ||
        req.body?.instanceId ||
        req.body?.instance_id ||
        INSTANCE_ID
    )
}

function normalizeJid(value) {
    if (!value) return ""
    if (String(value).includes("@")) return String(value)
    const digits = String(value).replace(/\D/g, "")
    if (!digits) return ""
    return `${digits}@s.whatsapp.net`
}

function getConnectionSnapshot() {
    return {
        instanceId: INSTANCE_ID,
        connectionStatus: whatsappService.connectionStatus || "starting",
        whatsappConnected: Boolean(whatsappService.whatsappConnected),
        hasQR: Boolean(whatsappService.qrCodeData),
        qrCodeData: whatsappService.qrCodeData || null,
        lastConnectionError: whatsappService.lastConnectionError || null
    }
}

function requireConnectedSocket(res) {
    const sock = whatsappService.sock
    if (!sock || !whatsappService.whatsappConnected) {
        res.status(503).json({ ok: false, error: "WhatsApp não conectado" })
        return null
    }
    return sock
}

module.exports = () => {
    const router = express.Router()

    router.get("/api/chats/:instanceId", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const search = String(req.query.search || "")
            const limit = Number(req.query.limit || 50)
            const offset = Number(req.query.offset || 0)
            const chats = await db.getChats(instanceId, search, limit, offset)
            const chatsWithMessages = (Array.isArray(chats) ? chats : [])
                .filter(chat => Number(chat?.message_count || 0) > 0)
            res.json({ ok: true, instanceId, chats: chatsWithMessages, filter_applied: true })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/messages/:instanceId/:remoteJid", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const remoteJid = decodeURIComponent(req.params.remoteJid)
            const limit = Number(req.query.limit || 50)
            const offset = Number(req.query.offset || 0)
            const sessionId = String(req.query.sessionId || req.query.session_id || "")
            const messages = await db.getMessages(instanceId, remoteJid, limit, offset, sessionId)
            res.json({ ok: true, instanceId, remoteJid, messages })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.delete("/api/messages/:instanceId/:remoteJid", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const remoteJid = decodeURIComponent(req.params.remoteJid)
            const result = await db.clearConversation(instanceId, remoteJid)
            res.json({ ok: true, instanceId, remoteJid, result })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/message-counts/:instanceId/:remoteJid", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const remoteJid = decodeURIComponent(req.params.remoteJid)
            const inbound = await db.getInboundMessageCount(instanceId, remoteJid)
            const outbound = await db.getOutboundMessageCount(instanceId, remoteJid)
            const lastInboundAt = await db.getTimeSinceLastInboundMessage(instanceId, remoteJid)
            res.json({
                ok: true,
                instanceId,
                remoteJid,
                inbound,
                outbound,
                lastInboundAt: lastInboundAt ? new Date(lastInboundAt).toISOString() : null
            })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/scheduled/:instanceId/:remoteJid", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const remoteJid = decodeURIComponent(req.params.remoteJid)
            const scheduled = await db.getScheduledMessages(instanceId, remoteJid)
            res.json({ ok: true, instanceId, remoteJid, scheduled })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/scheduled/:instanceId", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const remoteJid = normalizeJid(req.body?.remoteJid || req.body?.to)
            const message = String(req.body?.message || "")
            const scheduledFor = req.body?.scheduledFor || req.body?.scheduled_at
            const tag = req.body?.tag || "default"
            const tipo = req.body?.tipo || "followup"

            if (!remoteJid || !message || !scheduledFor) {
                return res.status(400).json({ ok: false, error: "remoteJid, message e scheduledFor são obrigatórios" })
            }

            const result = await db.enqueueScheduledMessage(instanceId, remoteJid, message, new Date(scheduledFor), tag, tipo)
            res.json({ ok: true, instanceId, remoteJid, ...result })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.delete("/api/scheduled/:instanceId/:scheduledId", async (req, res) => {
        try {
            const result = await db.deleteScheduledMessage(Number(req.params.scheduledId))
            res.json({ ok: true, result })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/groups/:instanceId", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const monitored = await db.getMonitoredGroups(instanceId)
            const sock = whatsappService.sock
            let liveGroups = []

            if (sock && whatsappService.whatsappConnected && typeof sock.groupFetchAllParticipating === "function") {
                const participating = await sock.groupFetchAllParticipating()
                liveGroups = Object.values(participating || {}).map(group => ({
                    jid: group.id,
                    name: group.subject || group.name || group.id
                }))
            }

            res.json({ ok: true, instanceId, groups: liveGroups, monitored })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/groups/:instanceId/monitor", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const groups = Array.isArray(req.body?.groups) ? req.body.groups : []
            const result = await db.setMonitoredGroups(instanceId, groups)
            res.json({ ok: true, instanceId, ...result })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/groups/:instanceId/send-bulk", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const sock = requireConnectedSocket(res)
            if (!sock) return

            const message = String(req.body?.message || "")
            const targets = Array.isArray(req.body?.targets) ? req.body.targets : []
            if (!message || !targets.length) {
                return res.status(400).json({ ok: false, error: "message e targets[] são obrigatórios" })
            }

            const sent = []
            const failed = []

            for (const rawTarget of targets) {
                const jid = normalizeJid(rawTarget)
                try {
                    await sendWhatsAppMessage(sock, jid, { text: message })
                    sent.push(jid)
                } catch (err) {
                    failed.push({ jid, error: err.message })
                }
            }

            res.json({ ok: true, instanceId, sent, failed })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/groups/:instanceId/contacts", async (req, res) => {
        try {
            const sock = requireConnectedSocket(res)
            if (!sock) return

            const groupJid = String(req.body?.groupJid || "")
            if (!groupJid) {
                return res.status(400).json({ ok: false, error: "groupJid é obrigatório" })
            }

            const metadata = await sock.groupMetadata(groupJid)
            const contacts = (metadata?.participants || []).map(participant => ({
                id: participant.id,
                admin: participant.admin || null
            }))

            res.json({ ok: true, groupJid, contacts, count: contacts.length })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/groups/:instanceId/leave", async (req, res) => {
        try {
            const sock = requireConnectedSocket(res)
            if (!sock) return

            const groupJid = String(req.body?.groupJid || "")
            if (!groupJid) {
                return res.status(400).json({ ok: false, error: "groupJid é obrigatório" })
            }

            const result = await sock.groupLeave(groupJid)
            res.json({ ok: true, groupJid, result })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/groups/:instanceId/auto-replies", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const groupJid = String(req.query.groupJid || "")
            if (!groupJid) {
                return res.status(400).json({ ok: false, error: "groupJid é obrigatório" })
            }

            const row = await db.getGroupAutoReplies(instanceId, groupJid)
            const replies = row?.replies_json ? JSON.parse(row.replies_json) : []
            const enabled = Boolean(row?.enabled)
            res.json({ ok: true, instanceId, groupJid, replies, enabled })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/groups/:instanceId/auto-replies", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const groupJid = String(req.body?.groupJid || "")
            const replies = Array.isArray(req.body?.replies) ? req.body.replies : []
            const enabled = req.body?.enabled !== false
            if (!groupJid) {
                return res.status(400).json({ ok: false, error: "groupJid é obrigatório" })
            }

            const result = await db.setGroupAutoReplies(instanceId, groupJid, replies, enabled)
            res.json({ ok: true, instanceId, groupJid, ...result })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/groups/:instanceId/schedules", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const groupJid = String(req.body?.groupJid || "")
            const message = String(req.body?.message || "")
            const scheduledFor = req.body?.scheduledFor || req.body?.scheduled_at
            if (!groupJid || !message || !scheduledFor) {
                return res.status(400).json({ ok: false, error: "groupJid, message e scheduledFor são obrigatórios" })
            }

            const result = await db.enqueueGroupScheduledMessage(instanceId, groupJid, message, new Date(scheduledFor))
            res.json({ ok: true, instanceId, groupJid, ...result })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/multi-input", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const remoteJid = req.query.remote || null
            
            if (remoteJid) {
                const pending = whatsappService.getPendingAiInputs(remoteJid)
                if (pending) {
                    return res.json({
                        ok: true,
                        ...pending
                    })
                }
            }
            
            const value = await db.getSetting(instanceId, "ai_multi_input_delay")
            res.json({
                ok: true,
                pending: false,
                remote_jid: remoteJid,
                delay_seconds: Number(value || 0),
                remaining_seconds: 0,
                queue: []
            })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/health", async (req, res) => {
        try {
            const snapshot = getConnectionSnapshot()
            const database = await db.getDatabaseHealth()
            res.json({
                ok: true,
                instanceId: INSTANCE_ID,
                connection: snapshot,
                database
            })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/auto-pause-status", async (req, res) => {
        try {
            const instanceId = resolveInstanceId(req)
            const enabled = await db.getSetting(instanceId, "auto_pause_enabled")
            const minutes = await db.getSetting(instanceId, "auto_pause_minutes")
            const pauseUntil = await db.getPersistentVariable(instanceId, "auto_pause_until")
            const isEnabled = enabled === "1" || enabled === "true"
            const pauseMinutes = parseInt(minutes, 10) || 5
            const pauseUntilMs = pauseUntil ? parseInt(pauseUntil, 10) : null
            const now = Date.now()
            const isPaused = Boolean(pauseUntilMs && pauseUntilMs > now)
            const remainingSeconds = isPaused ? Math.ceil((pauseUntilMs - now) / 1000) : 0

            res.json({
                ok: true,
                enabled: isEnabled,
                minutes: pauseMinutes,
                paused: isPaused,
                remaining_seconds: remainingSeconds,
                pause_until: pauseUntilMs ? new Date(pauseUntilMs).toISOString() : null
            })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/instances", async (req, res) => {
        try {
            const instances = await db.listInstancesRecords()
            res.json({ ok: true, instances })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/instances/:instanceId", async (req, res) => {
        try {
            const instance = await db.getInstanceRecord(req.params.instanceId)
            if (!instance) {
                return res.status(404).json({ ok: false, error: "Instância não encontrada" })
            }
            res.json({ ok: true, instance })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/calendar/auth-url", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const result = await calendar.getAuthUrl(String(req.query.instance || INSTANCE_ID))
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao gerar URL OAuth", detail: err.message })
        }
    })

    router.get("/api/calendar/oauth2/callback", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const instanceId = String(req.query.instance || INSTANCE_ID)
            const { code, state } = req.query
            if (!code || !state) {
                return res.status(400).json({ ok: false, error: "Parâmetros code/state obrigatórios" })
            }
            const result = await calendar.handleOAuthCallback(instanceId, code, state)
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao concluir OAuth", detail: err.message })
        }
    })

    router.post("/api/calendar/disconnect", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const instanceId = String(req.query.instance || INSTANCE_ID)
            const result = await calendar.disconnect(instanceId)
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao desconectar calendar", detail: err.message })
        }
    })

    router.post("/api/calendar/force-clear", async (req, res) => {
        try {
            const instanceId = String(req.query.instance || INSTANCE_ID)
            await db.clearCalendarAccount(instanceId)
            res.json({ ok: true, instanceId, message: "Calendar account removida" })
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao limpar calendar", detail: err.message })
        }
    })

    router.get("/api/calendar/config", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const result = await calendar.getConfig(String(req.query.instance || INSTANCE_ID))
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao ler configuração", detail: err.message })
        }
    })

    router.get("/api/calendar/google-calendars", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const result = await calendar.listGoogleCalendars(String(req.query.instance || INSTANCE_ID))
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao listar calendários", detail: err.message })
        }
    })

    router.post("/api/calendar/calendars", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const result = await calendar.saveCalendarConfig(String(req.query.instance || INSTANCE_ID), req.body || {})
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao salvar calendário", detail: err.message })
        }
    })

    router.delete("/api/calendar/calendars", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const calendarId = String(req.query.calendar_id || "")
            if (!calendarId) {
                return res.status(400).json({ ok: false, error: "calendar_id é obrigatório" })
            }
            const result = await calendar.deleteCalendarConfig(String(req.query.instance || INSTANCE_ID), calendarId)
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao remover calendário", detail: err.message })
        }
    })

    router.post("/api/calendar/default", async (req, res) => {
        try {
            ensureCalendarInitialized()
            const calendarId = String(req.body?.calendar_id || "")
            if (!calendarId) {
                return res.status(400).json({ ok: false, error: "calendar_id é obrigatório" })
            }
            const result = await calendar.setDefaultCalendar(String(req.query.instance || INSTANCE_ID), calendarId)
            res.json(result)
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao definir calendário padrão", detail: err.message })
        }
    })

    router.get("/api/settings/:key", async (req, res) => {
        try {
            const value = await db.getSetting(INSTANCE_ID, req.params.key)
            res.json({ ok: true, key: req.params.key, value })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.post("/api/settings/:key", async (req, res) => {
        try {
            await db.setSetting(INSTANCE_ID, req.params.key, req.body?.value)
            res.json({ ok: true, key: req.params.key, value: req.body?.value })
        } catch (err) {
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    router.get("/api/ai-config", async (req, res) => {
        try {
            const ai = require("../whatsapp-server/ai")
            const instanceId = resolveInstanceId(req)
            const config = await ai.loadAIConfig(db, instanceId)
            res.json({ ok: true, config })
        } catch (err) {
            res.status(500).json({ ok: false, error: "Failed to load AI config", detail: err.message })
        }
    })

    router.post("/api/ai-config", async (req, res) => {
        try {
            const ai = require("../whatsapp-server/ai")
            const instanceId = resolveInstanceId(req)
            await ai.persistAIConfig(db, instanceId, req.body || {})
            res.json({ ok: true })
        } catch (err) {
            res.status(500).json({ ok: false, error: "Failed to save AI config", detail: err.message })
        }
    })

    router.post("/api/instance", async (req, res) => {
        try {
            const instanceId = String(req.query.instance || INSTANCE_ID)
            const result = await db.saveInstanceRecord(instanceId, req.body || {})
            res.json({ ok: true, instanceId, result })
        } catch (err) {
            res.status(500).json({ ok: false, error: "Não foi possível atualizar a instância", detail: err.message })
        }
    })

    router.post("/api/ai-test", async (req, res) => {
        try {
            const message = String(req.body?.message || "").trim()
            const instanceId = resolveInstanceId(req)
            const remoteJid = normalizeJid(req.body?.remote_jid || req.body?.to || `test-${instanceId}`)
            if (!message) {
                return res.status(400).json({ ok: false, error: "Mensagem é obrigatória" })
            }
            const ai = require("../whatsapp-server/ai")
            const sessionContext = {
                remoteJid,
                instanceId,
                sessionId: `test-${Date.now()}`
            }
            const aiConfig = await ai.loadAIConfig(db, instanceId)
            const testConfig = { ...aiConfig, enabled: true }
            const response = await ai.generateAIResponse(sessionContext, message, testConfig, { db })

            res.json({
                ok: true,
                provider: response.provider,
                response: response.text
            })
        } catch (err) {
            res.status(500).json({ ok: false, error: "Falha ao testar IA", detail: err.message })
        }
    })

    async function handleSendMessage(req, res) {
        try {
            const sock = requireConnectedSocket(res)
            if (!sock) return
            const to = normalizeJid(req.body?.to)
            const message = String(req.body?.message || "")
            
            // Extract media fields
            const image_url = req.body?.image_url
            const image_base64 = req.body?.image_base64
            const video_url = req.body?.video_url
            const video_base64 = req.body?.video_base64
            const audio_url = req.body?.audio_url
            const caption = req.body?.caption || message
            
            // Check if this is a media message
            const hasImage = !!(image_url || image_base64)
            const hasVideo = !!(video_url || video_base64)
            const hasAudio = !!audio_url
            const hasMedia = hasImage || hasVideo || hasAudio
            
            if (!to) {
                return res.status(400).json({ error: "Parâmetro 'to' é obrigatório" })
            }
            
            if (!message && !hasMedia) {
                return res.status(400).json({ error: "Parâmetro 'message' ou dados de mídia são obrigatórios" })
            }
            
            // Handle media message
            if (hasMedia) {
                let mediaType, mediaContent
                
                if (hasImage) {
                    mediaType = 'image'
                    mediaContent = image_url || image_base64
                } else if (hasVideo) {
                    mediaType = 'video'
                    mediaContent = video_url || video_base64
                } else if (hasAudio) {
                    mediaType = 'audio'
                    mediaContent = audio_url
                }
                
                const mediaPayload = {
                    type: mediaType,
                    content: mediaContent,
                    caption: caption || ''
                }
                
                console.log("[Legacy Compat] Sending media:", mediaType, "to:", to)
                const result = await sendWhatsAppMedia(sock, to, mediaPayload)
                const mediaDesc = `${mediaType}: ${caption || ''}`
                await db.saveMessage(INSTANCE_ID, to, "assistant", mediaDesc, "outbound")
                
                return res.json({ ok: true, instanceId: INSTANCE_ID, to, type: mediaType, result })
            }
            
            // Handle text message (legacy)
            const result = await sendWhatsAppMessage(sock, to, { text: message })
            await db.saveMessage(INSTANCE_ID, to, "assistant", message, "outbound")
            res.json({ ok: true, instanceId: INSTANCE_ID, to, result })
        } catch (err) {
            res.status(500).json({ error: "Falha ao enviar mensagem", detail: err.message })
        }
    }

    router.post("/api/send-message", handleSendMessage)
    router.post("/send-message", handleSendMessage)

    async function handleDisconnect(req, res) {
        try {
            await whatsappService.logoutWhatsApp()
            res.json({ ok: true, message: "Logout realizado" })
        } catch (err) {
            res.status(500).json({ error: "Falha ao fazer logout", detail: err.message })
        }
    }

    async function handleRestart(req, res) {
        try {
            await whatsappService.restartWhatsApp()
            res.json({ ok: true, message: "Restart solicitado" })
        } catch (err) {
            res.status(500).json({ error: "Falha ao reiniciar", detail: err.message })
        }
    }

    router.post("/api/disconnect", handleDisconnect)
    router.post("/disconnect", handleDisconnect)
    router.post("/api/restart", handleRestart)
    router.post("/restart", handleRestart)

    return router
}
