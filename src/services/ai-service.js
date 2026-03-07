const { DEFAULT_HISTORY_LIMIT, DEFAULT_TEMPERATURE, DEFAULT_MAX_TOKENS } = require("../config/constants");

const DEFAULT_MODEL_BY_PROVIDER = {
    openai: "gpt-4o-mini",
    gemini: "gemini-1.5-flash",
    openrouter: "meta-llama/llama-3.1-8b-instruct"
};

function buildModelCandidates(aiConfig) {
    const provider = (aiConfig.provider || "openai").toLowerCase();
    const model = aiConfig.model || DEFAULT_MODEL_BY_PROVIDER[provider] || DEFAULT_MODEL_BY_PROVIDER.openai;
    return [{ provider, model }];
}

function extractAssistantCommands(text) {
    if (!text) return [];
    // Simplificado para o exemplo, manteria a lógica regex original do arquivo
    const commands = [];
    const pattern = /([a-zA-Z0-9_]+)\s*\(([^)]*)\)/g;
    let match;
    while ((match = pattern.exec(text)) !== null) {
        commands.push({
            fn: match[1],
            args: match[2]
        });
    }
    return commands;
}

function stripAssistantCalls(text) {
    if (!text) return "";
    return text.split("&&&")[0].trim();
}

async function generateAIResponse(remoteJid, messageBody, config) {
    // Lógica principal de despacho de IA
    // Aqui seria implementada a integração com as APIs
    return "Resposta simulada da IA";
}

module.exports = {
    buildModelCandidates,
    extractAssistantCommands,
    stripAssistantCalls,
    generateAIResponse,
    DEFAULT_MODEL_BY_PROVIDER
};
