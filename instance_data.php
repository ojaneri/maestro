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

function openInstanceDatabase(bool $readonly = true): ?SQLite3
{
    if (!file_exists(INSTANCE_DB_PATH)) {
        logDebug("SQLite database missing at " . INSTANCE_DB_PATH);
        return null;
    }

    try {
        $flags = $readonly ? SQLITE3_OPEN_READONLY : (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        return new SQLite3(INSTANCE_DB_PATH, $flags);
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

function fetchInstanceAiSettings(SQLite3 $db, string $instanceId): array
{
    if (!sqliteTableExists($db, 'settings')) {
        return [];
    }

    $escapedKeys = array_map(fn($key) => "'" . SQLite3::escapeString($key) . "'", INSTANCE_AI_SETTING_KEYS);
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

function buildOpenAiMetadata(array $aiMetadata): array
{
    return [
        'enabled' => $aiMetadata['enabled'] ?? false,
        'mode' => $aiMetadata['openai_mode'] ?? 'responses',
        'model' => $aiMetadata['model'] ?? 'gpt-4.1-mini',
        'api_key' => $aiMetadata['openai_api_key'] ?? ''
    ];
}

function loadInstancesFromDatabase(): array
{
    $db = openInstanceDatabase();
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
    $db = openInstanceDatabase();
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
