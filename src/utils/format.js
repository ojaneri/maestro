function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return "0 Bytes"
    const k = 1024
    const dm = decimals < 0 ? 0 : decimals
    const sizes = ["Bytes", "KB", "MB", "GB", "TB"]
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + " " + sizes[i]
}

function parseBoolean(value) {
    if (typeof value === "string") {
        const normalized = value.trim().toLowerCase()
        return ["1", "true", "yes", "on"].includes(normalized)
    }
    if (typeof value === "number") {
        return value === 1
    }
    return Boolean(value)
}

function truncateText(value, limit = 2000) {
    if (!value) return ""
    const text = String(value)
    return text.length <= limit ? text : `${text.slice(0, limit)}...`
}

function formatIntervalMinutes(value) {
    const minutes = Number(value || 0)
    if (!Number.isFinite(minutes) || minutes <= 0) {
        return "N/A"
    }
    if (minutes < 60) {
        return `${minutes} min`
    }
    const hours = Math.floor(minutes / 60)
    const remainder = minutes % 60
    if (!remainder) {
        return `${hours}h`
    }
    return `${hours}h ${remainder}m`
}

function snippet(value, limit = 120) {
    if (!value) return ""
    const cleaned = String(value).replace(/\s+/g, " ").trim()
    if (!cleaned) return ""
    return cleaned.length <= limit ? cleaned : `${cleaned.slice(0, limit)}...`
}

function toNumber(value, fallback) {
    const num = typeof value === "number" ? value : parseFloat(value)
    return Number.isFinite(num) ? num : fallback
}

function escapeHtml(value) {
    if (value === null || value === undefined) return ""
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;")
}

function normalizeMetaField(value) {
    if (value === undefined || value === null) return null
    const text = typeof value === "string" ? value : String(value)
    const trimmed = text.trim()
    return trimmed === "" ? null : trimmed
}

module.exports = {
    formatBytes,
    parseBoolean,
    truncateText,
    formatIntervalMinutes,
    snippet,
    toNumber,
    escapeHtml,
    normalizeMetaField
}
