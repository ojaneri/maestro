const nodeCrypto = require("crypto")
const { log } = require("./logger")
const { CALENDAR_TOKEN_SECRET, CALENDAR_STATE_TTL_MS, CALENDAR_PENDING_VARIABLE_KEY } = require("../config/globals")

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

async function persistPendingCalendarAuth(instanceId, state, db) { // db passed as parameter
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

async function clearPendingCalendarAuth(instanceId, db, state = null) { // db passed as parameter
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

async function loadPendingCalendarAuth(instanceId, db) { // db passed as parameter
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
                await clearPendingCalendarAuth(instanceId, db, parsed.state)
                return null
            }
            return {
                state: String(parsed.state),
                createdAt
            }
        }
        await clearPendingCalendarAuth(instanceId, db)
        return null
    } catch (err) {
        log("calendar pending load error:", err.message)
        await clearPendingCalendarAuth(instanceId, db)
        return null
    }
}

module.exports = {
    calendarOauthStates,
    getCalendarEncryptionKey,
    encryptCalendarToken,
    decryptCalendarToken,
    cleanupExpiredCalendarStates,
    persistPendingCalendarAuth,
    clearPendingCalendarAuth,
    loadPendingCalendarAuth
}