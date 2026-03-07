const fs = require("fs")
const path = require("path")
const nodeCrypto = require("crypto")
const { log } = require("./logger")
const { QR_TOKEN_DIR, INSTANCE_ID } = require("../config/globals") // INSTANCE_ID is needed for log

function ensureQrTokenDir() {
    if (!fs.existsSync(QR_TOKEN_DIR)) {
        fs.mkdirSync(QR_TOKEN_DIR, { recursive: true })
    }
}

function toBase64Url(buffer) {
    return buffer
        .toString("base64")
        .replace(/\+/g, "-")
        .replace(/\//g, "_")
        .replace(/=+$/, "")
}

function cleanupExpiredQrTokens(now = Date.now()) {
    try {
        ensureQrTokenDir()
        const entries = fs.readdirSync(QR_TOKEN_DIR)
        entries.forEach(fileName => {
            if (!fileName.endsWith(".json")) return
            const fullPath = path.join(QR_TOKEN_DIR, fileName)
            try {
                const raw = fs.readFileSync(fullPath, "utf8")
                const data = JSON.parse(raw)
                const expiresAt = Number(data?.expires_at_ms || 0)
                if (expiresAt && expiresAt <= now) {
                    fs.unlinkSync(fullPath)
                }
            } catch (err) {
                fs.unlinkSync(fullPath)
            }
        })
    } catch (err) {
        log("Erro ao limpar tokens QR:", err.message)
    }
}

function generateQrAccessToken(instanceId) {
    ensureQrTokenDir()
    cleanupExpiredQrTokens()
    const token = toBase64Url(nodeCrypto.randomBytes(32))
    const createdAt = new Date()
    const expiresAt = new Date(Date.now() + 24 * 60 * 60 * 1000)
    const payload = {
        token,
        instance_id: instanceId,
        created_at: createdAt.toISOString(),
        expires_at: expiresAt.toISOString(),
        expires_at_ms: expiresAt.getTime()
    }
    const filePath = path.join(QR_TOKEN_DIR, `${token}.json`)
    fs.writeFileSync(filePath, JSON.stringify(payload, null, 2), { mode: 0o600 })
    return payload
}

module.exports = {
    ensureQrTokenDir,
    toBase64Url,
    cleanupExpiredQrTokens,
    generateQrAccessToken
}