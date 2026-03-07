function escapeHtml(value) {
    if (value === null || value === undefined) return ""
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;")
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

module.exports = {
    escapeHtml,
    formatIntervalMinutes,
    snippet
}