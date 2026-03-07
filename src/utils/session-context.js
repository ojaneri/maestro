const SESSION_KEY_DEFAULT = "default-session"

function normalizeSessionId(value) {
    if (!value && value !== 0) {
        return SESSION_KEY_DEFAULT
    }
    const normalized = typeof value === "string" ? value.trim() : String(value)
    return normalized || SESSION_KEY_DEFAULT
}

function buildCompositeSessionKey(instanceId, remoteJid, sessionId) {
    const components = [
        (instanceId || "").toString().trim() || "global-instance",
        (remoteJid || "").toString().trim() || "global-remote",
        normalizeSessionId(sessionId)
    ]
    return components.join("|")
}

function resolveSessionId(remoteJid, message = null, overrideSessionId = null) {
    if (overrideSessionId) {
        return normalizeSessionId(overrideSessionId)
    }
    if (remoteJid && remoteJid.toLowerCase().includes("@lid")) {
        return remoteJid
    }
    const candidate =
        message?.message?.contextInfo?.stanzaId ||
        message?.key?.id ||
        message?.key?.remoteJid ||
        remoteJid
    return normalizeSessionId(candidate)
}

function createSessionContext(instanceId, remoteJid, message = null, options = {}) {
    const sessionId = resolveSessionId(remoteJid, message, options.sessionId)
    return {
        instanceId: (instanceId || "").toString(),
        remoteJid: remoteJid || "",
        sessionId,
        key: buildCompositeSessionKey(instanceId, remoteJid, sessionId)
    }
}

module.exports = {
    SESSION_KEY_DEFAULT,
    normalizeSessionId,
    buildCompositeSessionKey,
    resolveSessionId,
    createSessionContext
}
