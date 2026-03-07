const fs = require("fs")
const path = require("path")
const mime = require("mime-types")
const { fetch } = require("undici")
const { UPLOADS_DIR, REMOTE_CACHE_DIR, ASSETS_UPLOADS_DIR, WHATSAPP_CACHE_TTL_DAYS } = require("../config/config")
const { log } = require("../config/config")

function sanitizeMimeType(value) {
    if (!value) return null
    return value.split(";")[0].trim().toLowerCase() || null
}

function getRemoteCacheKey(url) {
    const encoded = Buffer.from(url, "utf8").toString("base64")
    return encoded.replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "")
}

function getRemoteCachePaths(url) {
    const key = getRemoteCacheKey(url)
    return {
        filePath: path.join(REMOTE_CACHE_DIR, `${key}.bin`),
        metaPath: path.join(REMOTE_CACHE_DIR, `${key}.json`)
    }
}

async function loadRemoteCache(url, expectedPrefix, fallbackMime) {
    const { filePath, metaPath } = getRemoteCachePaths(url)
    if (!fs.existsSync(filePath) || !fs.existsSync(metaPath)) {
        return null
    }
    const rawMeta = await fs.promises.readFile(metaPath, "utf8").catch(() => "")
    if (!rawMeta) return null
    let meta
    try {
        meta = JSON.parse(rawMeta)
    } catch {
        return null
    }
    const mimeType = sanitizeMimeType(meta?.mimeType) || fallbackMime
    if (!mimeType || !mimeType.startsWith(expectedPrefix)) {
        return null
    }
    const buffer = await fs.promises.readFile(filePath)
    return { buffer, mimeType }
}

async function saveRemoteCache(url, buffer, mimeType) {
    const { filePath, metaPath } = getRemoteCachePaths(url)
    await fs.promises.writeFile(filePath, buffer)
    const payload = {
        url,
        mimeType,
        savedAt: new Date().toISOString()
    }
    await fs.promises.writeFile(metaPath, JSON.stringify(payload))
}

async function fetchMediaWithCache(url, expectedPrefix, fallbackMime, fetchLabel) {
    const cached = await loadRemoteCache(url, expectedPrefix, fallbackMime)
    if (cached) {
        return cached
    }
    const response = await fetch(url, {
        method: "GET",
        headers: { "User-Agent": "Janeri Bot/1.0" }
    })
    if (!response.ok) {
        throw new Error(`${fetchLabel} não pôde ser baixado (HTTP ${response.status})`)
    }
    const arrayBuffer = await response.arrayBuffer()
    const buffer = Buffer.from(arrayBuffer)
    const inferredMime = sanitizeMimeType(response.headers.get("content-type")) || sanitizeMimeType(mime.lookup(url))
    const mimeType = inferredMime || fallbackMime
    if (!mimeType || !mimeType.startsWith(expectedPrefix)) {
        throw new Error(`Conteúdo baixado não parece ser ${fetchLabel.toLowerCase()}`)
    }
    await saveRemoteCache(url, buffer, mimeType).catch(err => {
        log("Cache de mídia falhou:", { url, error: err.message })
    })
    return { buffer, mimeType }
}

function isLocalMediaPath(value) {
    if (typeof value !== "string") return false
    const trimmed = value.trim()
    return trimmed.startsWith("/") || trimmed.startsWith("uploads/")
}

function resolveLocalAssetPath(inputPath) {
    if (!inputPath || typeof inputPath !== "string") {
        throw new Error("Caminho local inválido")
    }
    const trimmed = inputPath.trim()
    const resolved = trimmed.startsWith("/")
        ? path.resolve(trimmed)
        : path.resolve(ASSETS_UPLOADS_DIR, trimmed.replace(/^uploads[\\/]+/i, ""))
    const allowedBase = ASSETS_UPLOADS_DIR.endsWith(path.sep)
        ? ASSETS_UPLOADS_DIR
        : `${ASSETS_UPLOADS_DIR}${path.sep}`
    if (resolved !== ASSETS_UPLOADS_DIR && !resolved.startsWith(allowedBase)) {
        throw new Error("Caminho local fora do diretório permitido")
    }
    return resolved
}

async function loadLocalMediaBuffer(filePath, expectedPrefix, fallbackMime) {
    const resolved = resolveLocalAssetPath(filePath)
    if (!fs.existsSync(resolved)) {
        const err = new Error("Arquivo local não encontrado")
        err.silent = true
        throw err
    }
    const buffer = await fs.promises.readFile(resolved)
    const inferredMime = sanitizeMimeType(mime.lookup(resolved)) || fallbackMime
    if (!inferredMime || !inferredMime.startsWith(expectedPrefix)) {
        throw new Error("Arquivo local não corresponde ao tipo esperado")
    }
    return { buffer, mimeType: inferredMime }
}

function isSilentMediaError(error) {
    return Boolean(error && error.silent)
}

async function downloadImagePayload(url) {
    if (!url) {
        throw new Error("URL da imagem inválida")
    }
    if (isLocalMediaPath(url)) {
        return loadLocalMediaBuffer(url, "image/", "image/jpeg")
    }
    return fetchMediaWithCache(url, "image/", "image/jpeg", "Imagem")
}

async function downloadAudioPayload(url) {
    if (!url) {
        throw new Error("URL do áudio inválida")
    }
    if (isLocalMediaPath(url)) {
        return loadLocalMediaBuffer(url, "audio/", "audio/mpeg")
    }
    return fetchMediaWithCache(url, "audio/", "audio/mpeg", "Áudio")
}

async function downloadVideoPayload(url) {
    if (!url) {
        throw new Error("URL do vídeo inválida")
    }
    if (isLocalMediaPath(url)) {
        return loadLocalMediaBuffer(url, "video/", "video/mp4")
    }
    return fetchMediaWithCache(url, "video/", "video/mp4", "Vídeo")
}

function parseImageSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^img:/i.test(trimmed)) return null
    const payload = trimmed.slice(4).trim()
    if (!payload) return null
    const [urlPart, ...captionParts] = payload.split("|")
    const url = (urlPart || "").trim()
    if (!url) return null
    if (!isLocalMediaPath(url)) {
        try {
            const parsed = new URL(url)
            if (!["http:", "https:"].includes(parsed.protocol)) {
                return null
            }
        } catch {
            return null
        }
    }
    const caption = captionParts.join("|").trim()
    return {
        url,
        caption: caption || undefined
    }
}

function parseAudioSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^audio:/i.test(trimmed)) return null
    const payload = trimmed.slice(6).trim()
    if (!payload) return null
    const [urlPart, ...captionParts] = payload.split("|")
    const url = (urlPart || "").trim()
    if (!url) return null
    if (!isLocalMediaPath(url)) {
        try {
            const parsed = new URL(url)
            if (!["http:", "https:"].includes(parsed.protocol)) {
                return null
            }
        } catch {
            return null
        }
    }
    const caption = captionParts.join("|").trim()
    return {
        url,
        caption: caption || undefined
    }
}

function parseVideoSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^video:/i.test(trimmed)) return null
    const payload = trimmed.slice(6).trim()
    if (!payload) return null
    const [urlPart, ...captionParts] = payload.split("|")
    const url = (urlPart || "").trim()
    if (!url) return null
    if (!isLocalMediaPath(url)) {
        try {
            const parsed = new URL(url)
            if (!["http:", "https:"].includes(parsed.protocol)) {
                return null
            }
        } catch {
            return null
        }
    }
    const caption = captionParts.join("|").trim()
    return {
        url,
        caption: caption || undefined
    }
}

function parseContactSegment(segment) {
    const trimmed = (segment || "").trim()
    if (!trimmed) return null
    if (!/^contact:/i.test(trimmed)) return null
    const payload = trimmed.slice(8).trim()
    if (!payload) return null
    const parts = payload.split("|").map(part => part.trim())
    const rawPhone = parts[0] || ""
    const normalizedPhone = normalizeContactPhoneNumber(rawPhone)
    if (!normalizedPhone) return null
    const displayName = parts[1] || undefined
    const note = parts.slice(2).filter(Boolean).join(" | ") || undefined
    return {
        phone: normalizedPhone,
        rawPhone,
        displayName,
        note
    }
}

function normalizeContactPhoneNumber(value) {
    if (!value) return null
    const digits = String(value).replace(/\D/g, "")
    if (!digits) return null
    const normalized = ensureBrazilCountryCode(digits)
    return normalized || null
}

function ensureBrazilCountryCode(digits) {
    if (!digits) return ''
    if (digits.startsWith('55')) {
        return digits
    }
    if (digits.length >= 10 && digits.length <= 11) {
        return `55${digits}`
    }
    return digits
}

function formatContactPhoneLabel(value) {
    if (!value) return null
    const trimmed = String(value).trim()
    if (trimmed === "") return null
    if (trimmed.startsWith("+")) return trimmed
    return `+${trimmed}`
}

function extractPhonesFromVcard(vcard) {
    if (!vcard) return []
    const lines = vcard.split(/\r?\n/)
    const phones = []
    for (const line of lines) {
        const trimmed = line.trim()
        if (!trimmed.toUpperCase().startsWith("TEL")) continue
        const parts = trimmed.split(":")
        if (parts.length < 2) continue
        const rawValue = parts.slice(1).join(":").trim()
        const normalized = normalizeContactPhoneNumber(rawValue)
        if (!normalized) continue
        phones.push({
            raw: rawValue,
            normalized
        })
    }
    return phones
}

function detectContactPayload(message) {
    const contact = message?.contactMessage
    if (!contact) return null
    const displayName = normalizeMetaField(contact.displayName || contact.name || contact.notify)
    const note = normalizeMetaField(contact.notify || contact.description)
    const vcard = contact.vcard || ""
    const phones = extractPhonesFromVcard(vcard)
    if (!displayName && !phones.length && !vcard) {
        return null
    }
    return {
        displayName,
        note,
        vcard,
        phones
    }
}

function normalizeMetaField(value) {
    if (value === undefined || value === null) return null
    const text = typeof value === "string" ? value : String(value)
    const trimmed = text.trim()
    return trimmed === "" ? null : trimmed
}

function buildContactPrompt(payload) {
    if (!payload) return ""
    const lines = ["CONTATO RECEBIDO:"]
    if (payload.displayName) {
        lines.push(`Nome: ${payload.displayName}`)
    }
    if (payload.note) {
        lines.push(`Descrição: ${payload.note}`)
    }
    if (payload.phones?.length) {
        const formatted = payload.phones
            .map(entry => formatContactPhoneLabel(entry.normalized) || entry.raw)
            .filter(Boolean)
        if (formatted.length) {
            lines.push(`Telefone(s): ${formatted.join(", ")}`)
        }
    }
    const summaryLines = (payload.vcard || "")
        .split(/\r?\n/)
        .map(line => line.trim())
        .filter(Boolean)
        .slice(0, 4)
    if (summaryLines.length) {
        lines.push(`vCard: ${summaryLines.join(" | ")}`)
    }
    return lines.join("\n")
}

function detectMediaPayload(message) {
    if (message.imageMessage) {
        return {
            key: "imageMessage",
            node: message.imageMessage,
            type: "imagem",
            downloadType: "image",
            fallbackDescription: "Imagem recebida sem legenda."
        }
    }
    if (message.videoMessage) {
        return {
            key: "videoMessage",
            node: message.videoMessage,
            type: "vídeo",
            downloadType: "video",
            fallbackDescription: "Vídeo recebido sem legenda."
        }
    }
    if (message.audioMessage) {
        return {
            key: "audioMessage",
            node: message.audioMessage,
            type: "áudio",
            downloadType: "audio",
            fallbackDescription: "Áudio recebido sem legenda."
        }
    }
    return null
}

async function downloadMediaNodeToTemp(message, mediaNode, downloadType) {
    if (!message || !mediaNode) {
        throw new Error("Conteúdo multimídia inválido")
    }
    // Note: baileysModule is not available here, will be passed or imported
    // For now, placeholder
    throw new Error("downloadMediaNodeToTemp needs Baileys integration")
}

module.exports = {
    sanitizeMimeType,
    getRemoteCacheKey,
    getRemoteCachePaths,
    loadRemoteCache,
    saveRemoteCache,
    fetchMediaWithCache,
    isLocalMediaPath,
    resolveLocalAssetPath,
    loadLocalMediaBuffer,
    isSilentMediaError,
    downloadImagePayload,
    downloadAudioPayload,
    downloadVideoPayload,
    parseImageSegment,
    parseAudioSegment,
    parseVideoSegment,
    parseContactSegment,
    normalizeContactPhoneNumber,
    ensureBrazilCountryCode,
    formatContactPhoneLabel,
    extractPhonesFromVcard,
    detectContactPayload,
    normalizeMetaField,
    buildContactPrompt,
    detectMediaPayload,
    downloadMediaNodeToTemp
}