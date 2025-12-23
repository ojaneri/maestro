<?php
define('INSTANCE_DB_PATH', __DIR__ . '/chat_data.db');

const INSTANCE_AI_SETTING_KEYS = [
    'ai_enabled',
    'ai_provider',
    'openai_api_key',
    'openai_mode',
    'ai_model',
    'ai_system_prompt',
    'ai_assistant_prompt',
    'ai_assistant_id',
    'ai_history_limit',
    'ai_temperature',
    'ai_max_tokens',
    'gemini_api_key',
    'gemini_instruction',
    'ai_multi_input_delay'
];

const INSTANCE_AUDIO_TRANSCRIPTION_SETTING_KEYS = [
    'audio_transcription_enabled',
    'audio_transcription_gemini_api_key',
    'audio_transcription_prefix'
];

const INSTANCE_SECRETARY_SETTING_KEYS = [
    'secretary_enabled',
    'secretary_idle_hours',
    'secretary_initial_response',
    'secretary_term_1',
    'secretary_response_1',
    'secretary_term_2',
    'secretary_response_2',
    'secretary_quick_replies'
];

const INSTANCE_ALARM_SETTING_KEYS = [
    'alarm_whatsapp_enabled',
    'alarm_whatsapp_recipients',
    'alarm_whatsapp_interval',
    'alarm_whatsapp_interval_unit',
    'alarm_whatsapp_last_sent',
    'alarm_server_enabled',
    'alarm_server_recipients',
    'alarm_server_interval',
    'alarm_server_interval_unit',
    'alarm_server_last_sent',
    'alarm_error_enabled',
    'alarm_error_recipients',
    'alarm_error_interval',
    'alarm_error_interval_unit',
    'alarm_error_last_sent'
];

function openInstanceDatabase(bool $readonly = true): ?SQLite3
{
    if (!file_exists(INSTANCE_DB_PATH)) {
        logDebug("SQLite database missing at " . INSTANCE_DB_PATH);
        return null;
    }

    try {
        $flags = $readonly ? SQLITE3_OPEN_READONLY : (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $db = new SQLite3(INSTANCE_DB_PATH, $flags);
        $db->busyTimeout(5000);
        if (!$readonly) {
            $db->exec('PRAGMA journal_mode = WAL');
        }
        return $db;
    } catch (Exception $e) {
        logDebug("Failed to open SQLite database: " . $e->getMessage());
        return null;
    }
}

function sqliteTableExists(SQLite3 $db, string $tableName): bool
{
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bindValue(':name', $tableName, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) {
        $stmt->close();
        return false;
    }
    $exists = $result->fetchArray(SQLITE3_ASSOC) !== false;
    $result->finalize();
    $stmt->close();
    return $exists;
}

function fetchInstanceSettingsByKeys(SQLite3 $db, string $instanceId, array $keys): array
{
    if (!sqliteTableExists($db, 'settings') || empty($keys)) {
        return [];
    }

    $escapedKeys = array_map(fn($key) => "'" . SQLite3::escapeString($key) . "'", $keys);
    $inClause = implode(',', $escapedKeys);
    $sql = "
        SELECT instance_id, key, value
        FROM settings
        WHERE key IN ($inClause)
          AND (instance_id = '' OR instance_id = :instance)
        ORDER BY CASE WHEN instance_id = '' THEN 0 ELSE 1 END, key ASC
    ";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) {
        $stmt->close();
        return [];
    }

    $global = [];
    $local = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['instance_id'] === '') {
            $global[$row['key']] = $row['value'];
        } elseif ($row['instance_id'] === $instanceId) {
            $local[$row['key']] = $row['value'];
        }
    }
    $result->finalize();
    $stmt->close();

    return array_merge($global, $local);
}

function fetchInstanceAiSettings(SQLite3 $db, string $instanceId): array
{
    return fetchInstanceSettingsByKeys($db, $instanceId, INSTANCE_AI_SETTING_KEYS);
}

function fetchInstanceAudioTranscriptionSettings(SQLite3 $db, string $instanceId): array
{
    return fetchInstanceSettingsByKeys($db, $instanceId, INSTANCE_AUDIO_TRANSCRIPTION_SETTING_KEYS);
}

function fetchInstanceSecretarySettings(SQLite3 $db, string $instanceId): array
{
    return fetchInstanceSettingsByKeys($db, $instanceId, INSTANCE_SECRETARY_SETTING_KEYS);
}

function buildAiMetadata(array $settings): array
{
    $enabled = isset($settings['ai_enabled']) && (strtolower($settings['ai_enabled']) === 'true' || $settings['ai_enabled'] === '1');
    $historyLimit = max(1, (int)($settings['ai_history_limit'] ?? 20));
    $temperature = is_numeric($settings['ai_temperature']) ? (float)$settings['ai_temperature'] : 0.3;
    $maxTokens = max(64, (int)($settings['ai_max_tokens'] ?? 600));
    $delay = max(0, (int)($settings['ai_multi_input_delay'] ?? 0));

    return [
        'enabled' => $enabled,
        'provider' => $settings['ai_provider'] ?? 'openai',
        'model' => $settings['ai_model'] ?? 'gpt-4.1-mini',
        'system_prompt' => $settings['ai_system_prompt'] ?? '',
        'assistant_prompt' => $settings['ai_assistant_prompt'] ?? '',
        'assistant_id' => $settings['ai_assistant_id'] ?? '',
        'history_limit' => $historyLimit,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
        'multi_input_delay' => $delay,
        'openai_api_key' => $settings['openai_api_key'] ?? '',
        'openai_mode' => $settings['openai_mode'] ?? 'responses',
        'gemini_api_key' => $settings['gemini_api_key'] ?? '',
        'gemini_instruction' => $settings['gemini_instruction'] ?? ''
    ];
}

function buildAudioTranscriptionMetadata(array $settings): array
{
    $enabled = isset($settings['audio_transcription_enabled'])
        && (strtolower($settings['audio_transcription_enabled']) === 'true'
            || $settings['audio_transcription_enabled'] === '1');
    $prefix = trim((string)($settings['audio_transcription_prefix'] ?? ''));
    if ($prefix === '') {
        $prefix = '🔊';
    }

    return [
        'enabled' => $enabled,
        'gemini_api_key' => $settings['audio_transcription_gemini_api_key'] ?? '',
        'prefix' => $prefix
    ];
}

function buildSecretaryMetadata(array $settings): array
{
    $enabled = isset($settings['secretary_enabled'])
        && (strtolower($settings['secretary_enabled']) === 'true'
            || $settings['secretary_enabled'] === '1');
    $idleHours = max(0, (int)($settings['secretary_idle_hours'] ?? 0));
    $quickReplies = [];
    if (!empty($settings['secretary_quick_replies'])) {
        $decoded = json_decode($settings['secretary_quick_replies'], true);
        if (is_array($decoded)) {
            $quickReplies = $decoded;
        }
    }

    if (!$quickReplies) {
        $term1 = trim($settings['secretary_term_1'] ?? '');
        $response1 = trim($settings['secretary_response_1'] ?? '');
        if ($term1 !== '' && $response1 !== '') {
            $quickReplies[] = ['term' => $term1, 'response' => $response1];
        }
        $term2 = trim($settings['secretary_term_2'] ?? '');
        $response2 = trim($settings['secretary_response_2'] ?? '');
        if ($term2 !== '' && $response2 !== '') {
            $quickReplies[] = ['term' => $term2, 'response' => $response2];
        }
    }

    return [
        'enabled' => $enabled,
        'idle_hours' => $idleHours,
        'initial_response' => $settings['secretary_initial_response'] ?? '',
        'term_1' => $settings['secretary_term_1'] ?? '',
        'response_1' => $settings['secretary_response_1'] ?? '',
        'term_2' => $settings['secretary_term_2'] ?? '',
        'response_2' => $settings['secretary_response_2'] ?? '',
        'quick_replies' => $quickReplies
    ];
}

function buildOpenAiMetadata(array $aiMetadata): array
{
    return [
        'enabled' => $aiMetadata['enabled'] ?? false,
        'mode' => $aiMetadata['openai_mode'] ?? 'responses',
        'model' => $aiMetadata['model'] ?? 'gpt-4.1-mini',
        'api_key' => $aiMetadata['openai_api_key'] ?? ''
    ];
}

function fetchInstanceAlarmSettings(SQLite3 $db, string $instanceId): array
{
    return fetchInstanceSettingsByKeys($db, $instanceId, INSTANCE_ALARM_SETTING_KEYS);
}

function interpretBooleanSetting($value): bool
{
    if ($value === null) {
        return false;
    }
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function parseEmailList(string $value): array
{
    $parts = preg_split('/[,\s;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
    $clean = [];
    foreach ($parts as $part) {
        $email = filter_var(trim($part), FILTER_VALIDATE_EMAIL);
        if ($email && !in_array($email, $clean, true)) {
            $clean[] = $email;
        }
    }
    return $clean;
}

function normalizeAlarmInterval($value, $unit = ''): int
{
    $interval = (int)$value;
    if ($interval <= 0) {
        return 120;
    }
    $unit = strtolower(trim((string)$unit));
    if ($unit === 'minutes' || $unit === 'min') {
        return max(1, min(1440, $interval));
    }
    if ($interval === 2 || $interval === 24) {
        return $interval * 60;
    }
    return max(1, min(1440, $interval));
}

function buildInstanceAlarmMetadata(array $settings): array
{
    $events = ['whatsapp', 'server', 'error'];
    $metadata = [];

    foreach ($events as $event) {
        $enabled = interpretBooleanSetting($settings["alarm_{$event}_enabled"] ?? null);
        $recipients = trim((string)($settings["alarm_{$event}_recipients"] ?? ''));
        $unit = $settings["alarm_{$event}_interval_unit"] ?? '';
        $interval = normalizeAlarmInterval($settings["alarm_{$event}_interval"] ?? 120, $unit);
        $resolvedUnit = $unit !== '' ? $unit : 'minutes';
        $metadata[$event] = [
            'enabled' => $enabled,
            'recipients' => $recipients,
            'recipients_list' => parseEmailList($recipients),
            'interval' => $interval,
            'interval_unit' => $resolvedUnit,
            'last_sent' => $settings["alarm_{$event}_last_sent"] ?? ''
        ];
    }

    return $metadata;
}

const CALENDAR_PENDING_STATE_TTL_MS = 10 * 60 * 1000;

function findInstanceByCalendarState(string $state): ?array
{
    $trimmed = trim($state);
    if ($trimmed === '') {
        return null;
    }
    $db = openInstanceDatabase(true);
    if (!$db) {
        return null;
    }
    if (!sqliteTableExists($db, 'calendar_pending_states')) {
        $db->close();
        return null;
    }
    $stmt = $db->prepare("SELECT instance_id, created_at FROM calendar_pending_states WHERE state = :state LIMIT 1");
    if (!$stmt) {
        $db->close();
        return null;
    }
    $stmt->bindValue(':state', $trimmed, SQLITE3_TEXT);
    $result = $stmt->execute();
    if (!$result) {
        $stmt->close();
        $db->close();
        return null;
    }
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $result->finalize();
    $stmt->close();
    $db->close();
    if (!$row || empty($row['instance_id'])) {
        return null;
    }
    $createdAt = isset($row['created_at']) ? (int)$row['created_at'] : null;
    if ($createdAt && (microtime(true) * 1000 - $createdAt) > CALENDAR_PENDING_STATE_TTL_MS) {
        return null;
    }
    return [
        'instance_id' => $row['instance_id'],
        'created_at' => $createdAt
    ];
}

function saveInstanceSettings(string $instanceId, array $entries): array
{
    $result = ['ok' => false, 'message' => ''];
    $db = openInstanceDatabase(false);
    if (!$db) {
        $result['message'] = 'Não foi possível abrir chat_data.db';
        return $result;
    }

    if (!sqliteTableExists($db, 'settings')) {
        $result['message'] = 'Tabela settings ausente no SQLite';
        $db->close();
        return $result;
    }

    $sql = <<<SQL
        INSERT INTO settings (instance_id, key, value)
        VALUES (:instance, :key, :value)
        ON CONFLICT(instance_id, key) DO UPDATE SET
            value = excluded.value
    SQL;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        $result['message'] = 'Falha ao preparar instrução SQL';
        $db->close();
        return $result;
    }

    $allOk = true;
    foreach ($entries as $key => $value) {
        $stmt->bindValue(':instance', $instanceId ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value ?? '', SQLITE3_TEXT);
        $exec = $stmt->execute();
        if (!$exec) {
            $allOk = false;
            $result['message'] = 'Erro SQL: ' . $db->lastErrorMsg();
            break;
        }
        $stmt->reset();
    }

    $stmt->close();
    $db->close();

    if ($allOk) {
        $result['ok'] = true;
    }
    return $result;
}

function loadInstancesFromDatabase(): array
{
    $db = openInstanceDatabase(false);
    if (!$db || !sqliteTableExists($db, 'instances')) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT instance_id, name, port, api_key, status, connection_status, base_url, phone, created_at, updated_at
        FROM instances
        ORDER BY created_at ASC
    ");

    if (!$stmt) {
        $db->close();
        return [];
    }

    $result = $stmt->execute();
    $instances = [];
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $instanceId = $row['instance_id'];
            $row['port'] = isset($row['port']) ? (int)$row['port'] : null;
            $aiSettings = fetchInstanceAiSettings($db, $instanceId);
            $aiMetadata = buildAiMetadata($aiSettings);
            $row['ai'] = $aiMetadata;
            $row['openai'] = buildOpenAiMetadata($aiMetadata);
            $audioSettings = fetchInstanceAudioTranscriptionSettings($db, $instanceId);
            $row['audio_transcription'] = buildAudioTranscriptionMetadata($audioSettings);
            $secretarySettings = fetchInstanceSecretarySettings($db, $instanceId);
            $row['secretary'] = buildSecretaryMetadata($secretarySettings);
            $alarmSettings = fetchInstanceAlarmSettings($db, $instanceId);
            $row['alarms'] = buildInstanceAlarmMetadata($alarmSettings);
            ensureInstanceHasApiKey($db, $row);
            $instances[$instanceId] = $row;
        }
        $result->finalize();
    }

    $stmt->close();
    $db->close();

    return $instances;
}

function loadInstanceRecordFromDatabase(string $instanceId): ?array
{
    $db = openInstanceDatabase(false);
    if (!$db || !sqliteTableExists($db, 'instances')) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT instance_id, name, port, api_key, status, connection_status, base_url, phone, created_at, updated_at
        FROM instances
        WHERE instance_id = :id
        LIMIT 1
    ");

    if (!$stmt) {
        $db->close();
        return null;
    }

    $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $record = null;
    if ($result) {
        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            $aiSettings = fetchInstanceAiSettings($db, $instanceId);
            $aiMetadata = buildAiMetadata($aiSettings);
            $row['ai'] = $aiMetadata;
            $row['openai'] = buildOpenAiMetadata($aiMetadata);
            $row['port'] = isset($row['port']) ? (int)$row['port'] : null;
            $audioSettings = fetchInstanceAudioTranscriptionSettings($db, $instanceId);
            $row['audio_transcription'] = buildAudioTranscriptionMetadata($audioSettings);
            $secretarySettings = fetchInstanceSecretarySettings($db, $instanceId);
            $row['secretary'] = buildSecretaryMetadata($secretarySettings);
            $alarmSettings = fetchInstanceAlarmSettings($db, $instanceId);
            $row['alarms'] = buildInstanceAlarmMetadata($alarmSettings);
            ensureInstanceHasApiKey($db, $row);
            $record = $row;
        }
        $result->finalize();
    }

    $stmt->close();
    $db->close();

    return $record;
}

function findInstanceByApiKey(string $apiKey): ?array
{
    $normalizedKey = trim($apiKey);
    if ($normalizedKey === '') {
        return null;
    }

    $db = openInstanceDatabase();
    if (!$db || !sqliteTableExists($db, 'instances')) {
        return null;
    }

    $stmt = $db->prepare("
        SELECT instance_id
        FROM instances
        WHERE api_key = :key
        LIMIT 1
    ");

    if (!$stmt) {
        $db->close();
        return null;
    }

    $stmt->bindValue(':key', $normalizedKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $instanceId = null;
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $instanceId = $row['instance_id'] ?? null;
    }
    $result->finalize();
    $stmt->close();
    $db->close();
    if ($instanceId) {
        return loadInstanceRecordFromDatabase($instanceId);
    }
    return null;
}
function findInstanceRecord(string $instanceId): ?array
{
    return loadInstanceRecordFromDatabase($instanceId);
}

function upsertInstanceRecordToSql(string $instanceId, array $payload): array
{
    $result = ['ok' => false, 'message' => ''];
    $db = openInstanceDatabase(false);
    if (!$db) {
        $result['message'] = 'Não foi possível abrir chat_data.db';
        return $result;
    }

    if (!sqliteTableExists($db, 'instances')) {
        $result['message'] = 'Tabela instances ausente no SQLite';
        $db->close();
        return $result;
    }

    $sql = <<<SQL
        INSERT INTO instances (instance_id, name, port, api_key, status, connection_status, base_url, phone)
        VALUES (:instance_id, :name, :port, :api_key, :status, :connection_status, :base_url, :phone)
        ON CONFLICT(instance_id) DO UPDATE SET
            name = excluded.name,
            port = excluded.port,
            api_key = excluded.api_key,
            status = excluded.status,
            connection_status = excluded.connection_status,
            base_url = excluded.base_url,
            phone = excluded.phone,
            updated_at = CURRENT_TIMESTAMP;
    SQL;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        $result['message'] = 'Falha ao preparar instrução SQL';
        $db->close();
        return $result;
    }

    $baseUrl = trim((string)($payload['base_url'] ?? ''));
    if ($baseUrl === '' && !empty($payload['port'])) {
        $baseUrl = "http://127.0.0.1:{$payload['port']}";
    }

    $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
    $stmt->bindValue(':name', $payload['name'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':port', isset($payload['port']) ? (int)$payload['port'] : null, SQLITE3_INTEGER);
    $stmt->bindValue(':api_key', $payload['api_key'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':status', $payload['status'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':connection_status', $payload['connection_status'] ?? null, SQLITE3_TEXT);
    $stmt->bindValue(':base_url', $baseUrl ?: null, SQLITE3_TEXT);
    $stmt->bindValue(':phone', $payload['phone'] ?? null, SQLITE3_TEXT);

    $exec = $stmt->execute();
    if (!$exec) {
        $result['message'] = 'Erro SQL: ' . $db->lastErrorMsg();
    } else {
        $result['ok'] = true;
    }

    $stmt->close();
    $db->close();
    return $result;
}

function ensureInstanceHasApiKey(SQLite3 $db, array &$record): void
{
    if (!$record || empty(trim((string)($record['instance_id'] ?? '')))) {
        return;
    }

    if (!empty(trim((string)($record['api_key'] ?? '')))) {
        return;
    }

    $instanceId = $record['instance_id'];
    $newKey = bin2hex(random_bytes(16));
    $stmt = $db->prepare("
        UPDATE instances
        SET api_key = :key, updated_at = CURRENT_TIMESTAMP
        WHERE instance_id = :instance
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bindValue(':key', $newKey, SQLITE3_TEXT);
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    $stmt->execute();
    $stmt->close();

    $record['api_key'] = $newKey;
}

function deleteInstanceRecordFromSql(string $instanceId): bool
{
    $db = openInstanceDatabase(false);
    if (!$db) {
        return false;
    }
    if (!sqliteTableExists($db, 'instances')) {
        $db->close();
        return false;
    }
    $stmt = $db->prepare('DELETE FROM instances WHERE instance_id = :id');
    if (!$stmt) {
        $db->close();
        return false;
    }
    $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $stmt->close();
    $db->close();
    return (bool)$result;
}

function logDebug(string $message): void
{
    if (function_exists('debug_log')) {
        debug_log($message);
    }
}
