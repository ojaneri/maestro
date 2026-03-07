const os = require("os")
const { INSTANCE_ID, PORT } = require("../config/globals")
const { formatIntervalMinutes } = require("./string-formatters")

/**
 * Collects debug information for an alarm event.
 * Dependent on global state: instanceConfig, connectionStatus, whatsappConnected, lastConnectionError.
 * These are expected to be passed as arguments or accessed via a shared state/config object.
 * @param {string} eventKey
 * @param {object} meta
 * @param {object} globalState - Object containing { instanceConfig, connectionStatus, whatsappConnected, lastConnectionError }
 * @returns {object} Debug information
 */
function collectAlarmDebugInfo(eventKey, meta, globalState) {
    const { instanceConfig, connectionStatus, whatsappConnected, lastConnectionError } = globalState || {}
    const now = new Date().toISOString()
    const instanceName = (instanceConfig?.name && instanceConfig.name.trim()) || INSTANCE_ID
    const port = instanceConfig?.port || PORT
    const host = os.hostname() || "desconhecido"
    const errorDetail = lastConnectionError || "sem erros registrados"
    return {
        instanceName,
        instanceId: INSTANCE_ID,
        eventKey,
        port,
        detectedAt: now,
        connectionStatus,
        whatsappConnected: whatsappConnected ? "sim" : "não",
        lastError: errorDetail,
        process: `pid=${process.pid}, host=${host}`,
        node: `${process.version} (${process.platform})`,
        intervalMinutes: meta?.interval ?? "N/A",
        intervalLabel: formatIntervalMinutes(meta?.interval),
        lastSent: meta?.lastSent || "nunca"
    }
}

function replaceStatusPlaceholder(text, statusName) {
    if (!text) {
        return text
    }
    const replacement = statusName ? statusName.trim() : ""
    return text.replace(/%statusname%/gi, replacement)
}

function splitHashSegments(value) {
    if (!value) {
        return []
    }
    return String(value)
        .split("#")
        .map(part => part.trim())
        .filter(Boolean)
}

module.exports = {
    collectAlarmDebugInfo,
    replaceStatusPlaceholder,
    splitHashSegments
}