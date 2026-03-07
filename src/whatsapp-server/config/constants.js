/**
 * @fileoverview AI settings keys, error responses, and default values configuration
 * @module whatsapp-server/config/constants
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Contains AI model configurations, error messages, and default settings
 */

// AI Model Configuration Keys
const AI_MODEL_CONFIG = {
  DEFAULT_MODEL: 'gpt-4o',
  FALLBACK_MODEL: 'gpt-3.5-turbo',
  MAX_TOKENS: 2000,
  TEMPERATURE: 0.7,
};

// Error Response Messages
const ERROR_RESPONSES = {
  CONNECTION_LOST: 'Conexão perdida. Reconectando...',
  SESSION_EXPIRED: 'Sessão expirada. Por favor, escaneie o QR Code novamente.',
  MESSAGE_FAILED: 'Falha ao enviar mensagem. Tente novamente.',
  UNKNOWN_ERROR: 'Ocorreu um erro desconhecido. Por favor, tente novamente.',
  RATE_LIMIT: 'Muitas solicitações. Por favor, aguarde um momento.',
  AI_PROCESSING_ERROR: 'Erro ao processar com IA. Tente novamente.',
};

// Default Values
const DEFAULTS = {
  MESSAGE_DELAY_MS: 1000,
  MAX_RETRY_ATTEMPTS: 3,
  SESSION_TIMEOUT_MS: 60000,
  HEARTBEAT_INTERVAL_MS: 30000,
};

module.exports = {
  AI_MODEL_CONFIG,
  ERROR_RESPONSES,
  DEFAULTS,
};
