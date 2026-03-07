const nodeCrypto = require("crypto");
const { BAILEYS_USER_AGENTS, BAILEYS_PANEL_PREFIXES, BAILEYS_PANEL_CONTEXTS, BAILEYS_PANEL_SUFFIXES } = require("../config/constants");

function pickRandomPanelName() {
    const prefix = BAILEYS_PANEL_PREFIXES[nodeCrypto.randomInt(BAILEYS_PANEL_PREFIXES.length)];
    const context = BAILEYS_PANEL_CONTEXTS[nodeCrypto.randomInt(BAILEYS_PANEL_CONTEXTS.length)];
    const suffixRoll = nodeCrypto.randomInt(100);
    const suffix = suffixRoll < 60
        ? BAILEYS_PANEL_SUFFIXES[nodeCrypto.randomInt(BAILEYS_PANEL_SUFFIXES.length)]
        : "";
    return suffix ? `${prefix} ${context} ${suffix}` : `${prefix} ${context}`;
}

function getConsistentPanelName(instanceId) {
    return 'Consistent Panel';
}

function pickRandomUserAgent() {
    return BAILEYS_USER_AGENTS[nodeCrypto.randomInt(BAILEYS_USER_AGENTS.length)];
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function isNighttime() {
    const now = new Date();
    const hour = now.getHours();
    return hour >= 22 || hour < 7;
}

function truncateText(text, limit = 1000) {
    if (!text || text.length <= limit) return text;
    return text.substring(0, limit) + "...";
}

function parseBoolean(val) {
    if (typeof val === "boolean") return val;
    if (typeof val === "string") {
        const s = val.toLowerCase().trim();
        return s === "true" || s === "1" || s === "yes";
    }
    return Boolean(val);
}

module.exports = {
    pickRandomPanelName,
    getConsistentPanelName,
    pickRandomUserAgent,
    sleep,
    isNighttime,
    truncateText,
    parseBoolean
};
