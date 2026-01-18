#!/usr/bin/env node

// master-meta.js - Centralized Meta API Server for WhatsApp Integration
// Handles all Meta API instances and provides unified API endpoints

process.env.TZ = "America/Fortaleza"

const express = require("express")
const bodyParser = require("body-parser")
const http = require("http")
const path = require("path")
const fs = require("fs")
const { fetch, Headers, Request, Response } = require("undici")
const mysql = require("mysql2/promise")
const { v4: uuidv4 } = require("uuid")

const app = express()
app.use(bodyParser.json({ limit: "50mb" }))
app.use(bodyParser.urlencoded({ extended: true, limit: "50mb" }))

// CORS configuration
app.use((req, res, next) => {
    res.setHeader("Access-Control-Allow-Origin", "*")
    res.setHeader("Access-Control-Allow-Methods", "GET,POST,OPTIONS,PUT,DELETE")
    res.setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization, X-API-Key")
    if (req.method === "OPTIONS") {
        return res.sendStatus(200)
    }
    next()
})

const PORT = process.env.PORT || 3005
const LOG_FILE = path.join(__dirname, "master-meta.log")

function log(...args) {
    const timestamp = new Date().toISOString()
    const message = `[${timestamp}] ${args.map(arg => 
        typeof arg === "string" ? arg : JSON.stringify(arg)
    ).join(" ")}`
    console.log(message)
    fs.appendFileSync(LOG_FILE, message + "\n", { encoding: "utf8" })
}

function normalizePhoneNumber(phone) {
    if (!phone) return null
    const digits = String(phone).replace(/\D/g, "")
    if (digits.startsWith("55")) {
        return digits
    }
    if (digits.length >= 10 && digits.length <= 11) {
        return `55${digits}`
    }
    return digits
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
            connectionLimit: 5,
            queueLimit: 0
        })
    }
    return customerDbPool
}

class MetaAPI {
    static async sendTemplateMessage(phoneNumberId, accessToken, to, templateName, params = [], language = "pt_BR") {
        const url = `https://graph.facebook.com/v22.0/${phoneNumberId}/messages`
        const payload = {
            messaging_product: "whatsapp",
            to: normalizePhoneNumber(to),
            type: "template",
            template: {
                name: templateName,
                language: { code: language },
                components: []
            }
        }

        if (params.length > 0) {
            payload.template.components.push({
                type: "body",
                parameters: params.map(param => ({
                    type: "text",
                    text: String(param || "")
                }))
            })
        }

        log("Sending template message:", {
            phoneNumberId,
            to: normalizePhoneNumber(to),
            template: templateName,
            paramsCount: params.length
        })

        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Authorization": `Bearer ${accessToken}`,
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        })

        const data = await response.json()

        if (!response.ok) {
            log("Template message failed:", data)
            throw new Error(data?.error?.message || `HTTP ${response.status}`)
        }

        log("Template message sent successfully:", data)
        return data
    }

    static async sendTextMessage(phoneNumberId, accessToken, to, text) {
        const url = `https://graph.facebook.com/v22.0/${phoneNumberId}/messages`
        const payload = {
            messaging_product: "whatsapp",
            to: normalizePhoneNumber(to),
            type: "text",
            text: { body: text }
        }

        log("Sending text message:", {
            phoneNumberId,
            to: normalizePhoneNumber(to),
            textLength: text.length
        })

        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Authorization": `Bearer ${accessToken}`,
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        })

        const data = await response.json()

        if (!response.ok) {
            log("Text message failed:", data)
            throw new Error(data?.error?.message || `HTTP ${response.status}`)
        }

        log("Text message sent successfully:", data)
        return data
    }

    static async sendMediaMessage(phoneNumberId, accessToken, to, mediaType, mediaUrl, caption = "") {
        const url = `https://graph.facebook.com/v22.0/${phoneNumberId}/messages`
        const payload = {
            messaging_product: "whatsapp",
            to: normalizePhoneNumber(to),
            type: mediaType,
            [mediaType]: {
                link: mediaUrl,
                caption: caption
            }
        }

        log("Sending media message:", {
            phoneNumberId,
            to: normalizePhoneNumber(to),
            mediaType,
            mediaUrl,
            captionLength: caption.length
        })

        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Authorization": `Bearer ${accessToken}`,
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        })

        const data = await response.json()

        if (!response.ok) {
            log("Media message failed:", data)
            throw new Error(data?.error?.message || `HTTP ${response.status}`)
        }

        log("Media message sent successfully:", data)
        return data
    }

    static async markMessageAsRead(phoneNumberId, accessToken, messageId) {
        const url = `https://graph.facebook.com/v22.0/${phoneNumberId}/messages`
        const payload = {
            messaging_product: "whatsapp",
            status: "read",
            message_id: messageId
        }

        log("Marking message as read:", {
            phoneNumberId,
            messageId
        })

        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Authorization": `Bearer ${accessToken}`,
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        })

        const data = await response.json()

        if (!response.ok) {
            log("Mark as read failed:", data)
            throw new Error(data?.error?.message || `HTTP ${response.status}`)
        }

        log("Message marked as read successfully:", data)
        return data
    }

    static async getPhoneNumberInfo(phoneNumberId, accessToken) {
        const url = `https://graph.facebook.com/v22.0/${phoneNumberId}`

        log("Getting phone number info:", { phoneNumberId })

        const response = await fetch(url, {
            method: "GET",
            headers: {
                "Authorization": `Bearer ${accessToken}`,
                "Content-Type": "application/json"
            }
        })

        const data = await response.json()

        if (!response.ok) {
            log("Phone number info failed:", data)
            throw new Error(data?.error?.message || `HTTP ${response.status}`)
        }

        log("Phone number info retrieved successfully:", data)
        return data
    }

    static async getBusinessProfile(phoneNumberId, accessToken) {
        const url = `https://graph.facebook.com/v22.0/${phoneNumberId}/whatsapp_business_profile`

        log("Getting business profile:", { phoneNumberId })

        const response = await fetch(url, {
            method: "GET",
            headers: {
                "Authorization": `Bearer ${accessToken}`,
                "Content-Type": "application/json"
            }
        })

        const data = await response.json()

        if (!response.ok) {
            log("Business profile failed:", data)
            throw new Error(data?.error?.message || `HTTP ${response.status}`)
        }

        log("Business profile retrieved successfully:", data)
        return data
    }
}

app.get("/", (req, res) => {
    res.json({
        service: "Meta API Server",
        version: "1.0.0",
        status: "running",
        port: PORT,
        endpoints: {
            sendTemplate: "/api/send-template",
            sendText: "/api/send-text",
            sendMedia: "/api/send-media",
            markRead: "/api/mark-read",
            getProfile: "/api/profile",
            getPhoneInfo: "/api/phone-info",
            webhook: "/webhook"
        }
    })
})

app.get("/health", (req, res) => {
    res.json({
        ok: true,
        service: "Meta API Server",
        status: "healthy",
        timestamp: new Date().toISOString(),
        port: PORT
    })
})

app.post("/api/send-template", async (req, res) => {
    try {
        const { phoneNumberId, accessToken, to, templateName, params, language } = req.body

        if (!phoneNumberId || !accessToken || !to || !templateName) {
            return res.status(400).json({
                ok: false,
                error: "Missing required parameters: phoneNumberId, accessToken, to, templateName"
            })
        }

        const result = await MetaAPI.sendTemplateMessage(
            phoneNumberId,
            accessToken,
            to,
            templateName,
            params || [],
            language || "pt_BR"
        )

        res.json({
            ok: true,
            result
        })
    } catch (error) {
        log("Error sending template message:", error)
        res.status(500).json({
            ok: false,
            error: error.message
        })
    }
})

app.post("/api/send-text", async (req, res) => {
    try {
        const { phoneNumberId, accessToken, to, text } = req.body

        if (!phoneNumberId || !accessToken || !to || !text) {
            return res.status(400).json({
                ok: false,
                error: "Missing required parameters: phoneNumberId, accessToken, to, text"
            })
        }

        const result = await MetaAPI.sendTextMessage(
            phoneNumberId,
            accessToken,
            to,
            text
        )

        res.json({
            ok: true,
            result
        })
    } catch (error) {
        log("Error sending text message:", error)
        res.status(500).json({
            ok: false,
            error: error.message
        })
    }
})

app.post("/api/send-media", async (req, res) => {
    try {
        const { phoneNumberId, accessToken, to, mediaType, mediaUrl, caption } = req.body

        if (!phoneNumberId || !accessToken || !to || !mediaType || !mediaUrl) {
            return res.status(400).json({
                ok: false,
                error: "Missing required parameters: phoneNumberId, accessToken, to, mediaType, mediaUrl"
            })
        }

        const validMediaTypes = ["image", "audio", "video", "document"]
        if (!validMediaTypes.includes(mediaType)) {
            return res.status(400).json({
                ok: false,
                error: `Invalid media type. Valid types: ${validMediaTypes.join(", ")}`
            })
        }

        const result = await MetaAPI.sendMediaMessage(
            phoneNumberId,
            accessToken,
            to,
            mediaType,
            mediaUrl,
            caption || ""
        )

        res.json({
            ok: true,
            result
        })
    } catch (error) {
        log("Error sending media message:", error)
        res.status(500).json({
            ok: false,
            error: error.message
        })
    }
})

app.post("/api/mark-read", async (req, res) => {
    try {
        const { phoneNumberId, accessToken, messageId } = req.body

        if (!phoneNumberId || !accessToken || !messageId) {
            return res.status(400).json({
                ok: false,
                error: "Missing required parameters: phoneNumberId, accessToken, messageId"
            })
        }

        const result = await MetaAPI.markMessageAsRead(
            phoneNumberId,
            accessToken,
            messageId
        )

        res.json({
            ok: true,
            result
        })
    } catch (error) {
        log("Error marking message as read:", error)
        res.status(500).json({
            ok: false,
            error: error.message
        })
    }
})

app.get("/api/phone-info", async (req, res) => {
    try {
        const { phoneNumberId, accessToken } = req.query

        if (!phoneNumberId || !accessToken) {
            return res.status(400).json({
                ok: false,
                error: "Missing required parameters: phoneNumberId, accessToken"
            })
        }

        const result = await MetaAPI.getPhoneNumberInfo(
            phoneNumberId,
            accessToken
        )

        res.json({
            ok: true,
            result
        })
    } catch (error) {
        log("Error getting phone number info:", error)
        res.status(500).json({
            ok: false,
            error: error.message
        })
    }
})

app.get("/api/profile", async (req, res) => {
    try {
        const { phoneNumberId, accessToken } = req.query

        if (!phoneNumberId || !accessToken) {
            return res.status(400).json({
                ok: false,
                error: "Missing required parameters: phoneNumberId, accessToken"
            })
        }

        const result = await MetaAPI.getBusinessProfile(
            phoneNumberId,
            accessToken
        )

        res.json({
            ok: true,
            result
        })
    } catch (error) {
        log("Error getting business profile:", error)
        res.status(500).json({
            ok: false,
            error: error.message
        })
    }
})

app.get("/webhook", (req, res) => {
    const mode = req.query["hub.mode"]
    const token = req.query["hub.verify_token"]
    const challenge = req.query["hub.challenge"]

    const VERIFY_TOKEN = process.env.META_VERIFY_TOKEN || "janeri-whatsapp-2024"

    if (mode && token) {
        if (mode === "subscribe" && token === VERIFY_TOKEN) {
            log("Webhook verification successful")
            res.status(200).send(challenge)
        } else {
            log("Webhook verification failed - invalid token")
            res.sendStatus(403)
        }
    } else {
        log("Webhook verification failed - missing parameters")
        res.sendStatus(400)
    }
})

app.post("/webhook", async (req, res) => {
    try {
        const body = req.body
        log("Webhook received:", body)

        if (body.object === "whatsapp_business_account") {
            for (const entry of body.entry) {
                for (const change of entry.changes) {
                    const value = change.value
                    const metadata = value.metadata

                    log("Webhook event:", {
                        phoneNumberId: metadata.phone_number_id,
                        displayPhoneNumber: metadata.display_phone_number,
                        eventType: change.field
                    })

                    if (change.field === "messages") {
                        const messages = value.messages
                        if (messages && messages.length > 0) {
                            await handleMessageEvents(messages, metadata)
                        }
                    }

                    if (change.field === "message_deliveries") {
                        const deliveries = value.deliveries
                        if (deliveries && deliveries.length > 0) {
                            await handleDeliveryEvents(deliveries, metadata)
                        }
                    }

                    if (change.field === "message_reads") {
                        const reads = value.reads
                        if (reads && reads.length > 0) {
                            await handleReadEvents(reads, metadata)
                        }
                    }

                    if (change.field === "message_reactions") {
                        const reactions = value.reactions
                        if (reactions && reactions.length > 0) {
                            await handleReactionEvents(reactions, metadata)
                        }
                    }
                }
            }
        }

        res.status(200).json({
            ok: true,
            message: "Webhook processed successfully"
        })
    } catch (error) {
        log("Error processing webhook:", error)
        res.status(500).json({
            ok: false,
            error: error.message
        })
    }
})

async function handleMessageEvents(messages, metadata) {
    for (const message of messages) {
        log("Message event:", {
            from: message.from,
            id: message.id,
            type: message.type,
            timestamp: new Date(parseInt(message.timestamp) * 1000).toISOString()
        })

        if (message.type === "text") {
            log("Text message:", {
                body: message.text.body
            })
        }

        if (message.type === "image") {
            log("Image message:", {
                id: message.image.id,
                caption: message.image.caption || "No caption"
            })
        }

        if (message.type === "audio") {
            log("Audio message:", {
                id: message.audio.id
            })
        }

        if (message.type === "video") {
            log("Video message:", {
                id: message.video.id,
                caption: message.video.caption || "No caption"
            })
        }

        if (message.type === "document") {
            log("Document message:", {
                id: message.document.id,
                filename: message.document.filename || "No filename",
                caption: message.document.caption || "No caption"
            })
        }
    }
}

async function handleDeliveryEvents(deliveries, metadata) {
    for (const delivery of deliveries) {
        log("Delivery event:", {
            messageId: delivery.id,
            timestamp: new Date(parseInt(delivery.timestamp) * 1000).toISOString(),
            recipientId: delivery.recipient_id
        })
    }
}

async function handleReadEvents(reads, metadata) {
    for (const read of reads) {
        log("Read event:", {
            messageId: read.id,
            timestamp: new Date(parseInt(read.timestamp) * 1000).toISOString(),
            watermark: new Date(parseInt(read.watermark) * 1000).toISOString()
        })
    }
}

async function handleReactionEvents(reactions, metadata) {
    for (const reaction of reactions) {
        log("Reaction event:", {
            messageId: reaction.message_id,
            emoji: reaction.emoji,
            timestamp: new Date(parseInt(reaction.timestamp) * 1000).toISOString(),
            from: reaction.from
        })
    }
}

const server = http.createServer(app)

server.listen(PORT, () => {
    log(`Meta API Server is running on port ${PORT}`)
    log(`Health check: http://localhost:${PORT}/health`)
    log(`Webhook endpoint: http://localhost:${PORT}/webhook`)
})

process.on("unhandledRejection", err => {
    log("Unhandled Rejection:", err)
})

process.on("uncaughtException", err => {
    log("Uncaught Exception:", err)
    process.exit(1)
})
