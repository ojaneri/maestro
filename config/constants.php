<?php

define('DEFAULT_GEMINI_INSTRUCTION', 'Você é um assistente atencioso e prestativo. Mantenha o tom profissional e informal. Sempre separe claramente o texto visível ao usuário do bloco de instruções/funções usando o marcador lógico &&& antes de iniciar os comandos.');
define('DEFAULT_MULTI_INPUT_DELAY', 0);
define('DEFAULT_OPENROUTER_BASE_URL', 'https://openrouter.ai');
const BAILEYS_LOG_TAIL_BYTES = 128 * 1024;
const BAILEYS_LOG_LINE_LIMIT = 200;

// Database configuration
define('DB_PATH', __DIR__ . '/../chat_data.db');

// Session configuration
define('SESSION_LIFETIME', 7200); // 2 hours

// File upload configuration
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('UPLOAD_PATH', __DIR__ . '/../assets/uploads');

// API configuration
define('API_TIMEOUT', 30);
define('MAX_API_RETRIES', 3);

// Logging configuration
define('DEBUG_LOG_ENABLED', file_exists('debug'));

// Performance monitoring
define('PERF_LOG_ENABLED', getenv('PERF_LOG') === '1');

// WhatsApp integration
define('DEFAULT_WHATSAPP_PORT', 3010);
define('MAX_INSTANCES', 100);

// AI configuration
define('DEFAULT_AI_HISTORY_LIMIT', 20);
define('DEFAULT_AI_TEMPERATURE', 0.3);
define('DEFAULT_AI_MAX_TOKENS', 600);

// Security
define('CSRF_TOKEN_LENGTH', 32);
define('PASSWORD_MIN_LENGTH', 8);

// Timezone
define('DEFAULT_TIMEZONE', 'America/Fortaleza');

// Email configuration
define('DEFAULT_FROM_EMAIL', 'noreply@janeri.com.br');

// Cache configuration
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour