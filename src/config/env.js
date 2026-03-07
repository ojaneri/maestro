/**
 * Environment Configuration
 * Extracted from whatsapp-server-intelligent.js
 * Contains all require() statements, process.env configurations, and global constants
 */

// ============================================
// Core Module Imports (excluding express/http/server initialization)
// ============================================

const calendarResolver = require("../utils/calendarResolver");

// Express and HTTP (not initializing app/server)
const express = require("express");
const bodyParser = require("body-parser");
const http = require("http");

// WebSocket (not initializing wss)
const WebSocket = require("ws");

// Utility libraries
const { v4: uuidv4 } = require("uuid");
const argv = require("minimist")(process.argv.slice(2));
const path = require("path");
const fs = require("fs");
const os = require("os");
const mime = require("mime-types");
const nodeCrypto = require("crypto");
const { exec } = require("child_process");
const { fetch, Headers, Request, Response } = require("undici");
const mysql = require("mysql2/promise");
const { Readable } = require("stream");
const { monitorEventLoopDelay } = require("perf_hooks");

// Environment configuration
try {
    const dotenv = require("dotenv");
    dotenv.config({ path: path.join(__dirname, "..", "..", ".env") });
} catch (err) {
    console.warn("[GLOBAL] dotenv não disponível:", err.message);
}

// Google APIs (optional)
let googleApis = null;
try {
    googleApis = require("googleapis");
} catch (err) {
    console.warn("[GLOBAL] Google APIs SDK não disponível:", err.message);
}

// ============================================
// Environment Variable Configurations
// ============================================

// Set timezone from APP_TIMEZONE
const APP_TIMEZONE = process.env.APP_TIMEZONE || "America/Sao_Paulo";
process.env.TZ = APP_TIMEZONE;

// Customer Database Configuration
const CUSTOMER_DB_CONFIG = {
    host: process.env.CUSTOMER_DB_HOST || "localhost",
    port: Number(process.env.CUSTOMER_DB_PORT || 3306),
    user: process.env.CUSTOMER_DB_USER,  // REQUIRED - no default
    password: process.env.CUSTOMER_DB_PASSWORD,  // REQUIRED - no default
    database: process.env.CUSTOMER_DB_NAME,  // REQUIRED - no default
    charset: "utf8mb4"
};

// ============================================
// Schedule & Timezone Constants
// ============================================

const SCHEDULE_TIMEZONE_OFFSET_HOURS = -3; // UTC-3 fixed
const SCHEDULE_TIMEZONE_LABEL = APP_TIMEZONE;
const SCHEDULE_CHECK_INTERVAL_MS = 30 * 1000;
const SCHEDULE_FETCH_LIMIT = 10;
const WHATSAPP_ALARM_VERIFY_DELAY_MS = Number(process.env.WHATSAPP_ALARM_VERIFY_DELAY_MS || 15000);

// ============================================
// AI & Gemini Configuration
// ============================================

const GEMINI_ALLOWED_MEDIA_MIME_TYPES = new Set([
    "image/jpeg",
    "image/png",
    "image/gif",
    "image/webp",
    "image/bmp",
    "audio/mpeg",
    "audio/mp3",
    "audio/ogg",
    "audio/wav",
    "audio/webm",
    "audio/x-wav",
    "audio/x-m4a",
    "application/pdf"
]);

// Default AI Settings
const DEFAULT_HISTORY_LIMIT = 15;
const DEFAULT_TEMPERATURE = 0.3;
const DEFAULT_MAX_TOKENS = 600;
const DEFAULT_PROVIDER = "openai";
const DEFAULT_GEMINI_INSTRUCTION = "Você é um assistente atencioso e prestativo. Mantenha o tom profissional e informal. Sempre separe claramente o texto visível ao usuário do bloco de instruções/funções usando o marcador lógico &&& antes de iniciar os comandos.";
const DEFAULT_MULTI_INPUT_DELAY = 0;
const DEFAULT_AUDIO_TRANSCRIPTION_PREFIX = "🔊";
const DEFAULT_OPENROUTER_BASE_URL = "https://openrouter.ai";
const DEFAULT_SCHEDULE_TAG = "default";
const DEFAULT_SCHEDULE_TIPO = "followup";

// Setting Keys Arrays
const AI_SETTING_KEYS = [
    "ai_enabled",
    "ai_provider",
    "openai_api_key",
    "openai_mode",
    "ai_model",
    "ai_model_fallback_1",
    "ai_model_fallback_2",
    "ai_system_prompt",
    "ai_assistant_prompt",
    "ai_assistant_id",
    "ai_history_limit",
    "ai_temperature",
    "ai_max_tokens",
    "gemini_api_key",
    "gemini_instruction",
    "openrouter_api_key",
    "openrouter_base_url",
    "ai_multi_input_delay",
    "auto_pause_enabled",
    "auto_pause_minutes",
    "meta_access_token",
    "meta_phone_number_id"
];

const AUDIO_TRANSCRIPTION_SETTING_KEYS = [
    "audio_transcription_enabled",
    "audio_transcription_gemini_api_key",
    "audio_transcription_prefix"
];

const SECRETARY_SETTING_KEYS = [
    "secretary_enabled",
    "secretary_idle_hours",
    "secretary_initial_response",
    "secretary_term_1",
    "secretary_response_1",
    "secretary_term_2",
    "secretary_response_2",
    "secretary_quick_replies"
];

const ALARM_SETTING_KEYS = [
    "alarm_whatsapp_enabled",
    "alarm_whatsapp_recipients",
    "alarm_whatsapp_interval",
    "alarm_whatsapp_interval_unit",
    "alarm_whatsapp_last_sent",
    "alarm_server_enabled",
    "alarm_server_recipients",
    "alarm_server_interval",
    "alarm_server_interval_unit",
    "alarm_server_last_sent",
    "alarm_error_enabled",
    "alarm_error_recipients",
    "alarm_error_interval",
    "alarm_error_interval_unit",
    "alarm_error_last_sent"
];

// ============================================
// Error Response Messages
// ============================================

const ERROR_RESPONSE_OPTIONS = [
    "Ops, achei que encontrei um erro aqui...me dá uns minutinhos",
    "Me chama em alguns minutos, me apareceu um problema aqui na minha programação ...",
    "Me chamaram aqui, parece que vai ter manutenção no sistema. Me chama daqui um pedacinho...",
    "Hmmm, deu problema aqui. Mas não se preocupe, me chama daqui a pouco que estarei bem",
    "Vou pedir uma pausa porque me deu um erro aqui no sistema, mas já já você pode me chamar, eu ficarei bem!"
];

// ============================================
// WhatsApp & Cache Configuration
// ============================================

const WHATSAPP_CACHE_TTL_DAYS = 90;

// ============================================
// Model Configuration
// ============================================

const DEFAULT_MODEL_BY_PROVIDER = {
    openai: "gpt-4.1-mini",
    gemini: "gemini-2.5-flash",
    openrouter: "gpt-4o-mini"
};

// ============================================
// Exports
// ============================================

module.exports = {
    // Imports
    calendarResolver,
    express,
    bodyParser,
    http,
    WebSocket,
    uuidv4,
    argv,
    path,
    fs,
    os,
    mime,
    nodeCrypto,
    exec,
    fetch,
    Headers,
    Request,
    Response,
    mysql,
    Readable,
    monitorEventLoopDelay,
    googleApis,

    // Environment
    APP_TIMEZONE,
    CUSTOMER_DB_CONFIG,

    // Schedule
    SCHEDULE_TIMEZONE_OFFSET_HOURS,
    SCHEDULE_TIMEZONE_LABEL,
    SCHEDULE_CHECK_INTERVAL_MS,
    SCHEDULE_FETCH_LIMIT,
    WHATSAPP_ALARM_VERIFY_DELAY_MS,

    // AI & Gemini
    GEMINI_ALLOWED_MEDIA_MIME_TYPES,
    DEFAULT_HISTORY_LIMIT,
    DEFAULT_TEMPERATURE,
    DEFAULT_MAX_TOKENS,
    DEFAULT_PROVIDER,
    DEFAULT_GEMINI_INSTRUCTION,
    DEFAULT_MULTI_INPUT_DELAY,
    DEFAULT_AUDIO_TRANSCRIPTION_PREFIX,
    DEFAULT_OPENROUTER_BASE_URL,
    DEFAULT_SCHEDULE_TAG,
    DEFAULT_SCHEDULE_TIPO,
    AI_SETTING_KEYS,
    AUDIO_TRANSCRIPTION_SETTING_KEYS,
    SECRETARY_SETTING_KEYS,
    ALARM_SETTING_KEYS,

    // Error Responses
    ERROR_RESPONSE_OPTIONS,

    // WhatsApp & Cache
    WHATSAPP_CACHE_TTL_DAYS,

    // Models
    DEFAULT_MODEL_BY_PROVIDER
};
