/**
 * @fileoverview Database setting keys mapping for WhatsApp server configuration
 * @module whatsapp-server/config/settings-keys
 * 
 * Code extracted from: whatsapp-server-intelligent.js
 * Maps database setting keys to internal configuration properties
 */

const SETTINGS_KEYS = {
  // AI Configuration Keys
  AI_MODEL: 'ai_model',
  AI_PROVIDER: 'ai_provider',
  AI_API_KEY: 'ai_api_key',
  AI_TEMPERATURE: 'ai_temperature',
  AI_MAX_TOKENS: 'ai_max_tokens',
  
  // Connection Settings
  AUTO_RECONNECT: 'auto_reconnect',
  RECONNECT_DELAY: 'reconnect_delay',
  SESSION_TIMEOUT: 'session_timeout',
  
  // Message Settings
  DEFAULT_MESSAGE_DELAY: 'default_message_delay',
  MAX_MESSAGE_RETRIES: 'max_message_retries',
  ENABLE_TYPING_INDICATOR: 'enable_typing_indicator',
  
  // Calendar Integration
  CALENDAR_ENABLED: 'calendar_enabled',
  CALENDAR_ID: 'calendar_id',
  CALENDAR_OAUTH_TOKEN: 'calendar_oauth_token',
  
  // Scheduler Settings
  SCHEDULER_ENABLED: 'scheduler_enabled',
  SCHEDULER_CHECK_INTERVAL: 'scheduler_check_interval',
  
  // Monitoring Settings
  MONITORING_ENABLED: 'monitoring_enabled',
  ALARM_RECIPIENTS: 'alarm_recipients',
};

// Database table mapping
const SETTINGS_TABLE = 'sistema_configuracoes';
const SETTINGS_COLUMN_INSTANCE = 'instancia_id';
const SETTINGS_COLUMN_KEY = 'chave';
const SETTINGS_COLUMN_VALUE = 'valor';

module.exports = {
  SETTINGS_KEYS,
  SETTINGS_TABLE,
  SETTINGS_COLUMN_INSTANCE,
  SETTINGS_COLUMN_KEY,
  SETTINGS_COLUMN_VALUE,
};
