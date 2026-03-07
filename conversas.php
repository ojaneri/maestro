<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// conversas.php - Intelligent Chat Dashboard
// WhatsApp-style interface matching the existing design

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';
require_once __DIR__ . '/external_auth.php';
require_once __DIR__ . '/includes/timezone.php';
ensureExternalUsersSchema();
$clientTimezone = getApplicationTimezone();

if (!function_exists('buildPublicBaseUrl')) {
    function buildPublicBaseUrl(string $basePath): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $normalized = rtrim($basePath, '/');
        return "{$scheme}://{$host}{$normalized}";
    }
}

if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

session_start();
if (!isset($_SESSION['debug_log_event_timestamps'])) {
    $_SESSION['debug_log_event_timestamps'] = [];
}

function should_log_debug_event($key, $windowSeconds = 5) {
    global $_SESSION;
    if (!is_string($key) || $key === '') {
        $key = 'default';
    }
    $now = time();
    $last = $_SESSION['debug_log_event_timestamps'][$key] ?? 0;
    if (($now - $last) >= $windowSeconds) {
        $_SESSION['debug_log_event_timestamps'][$key] = $now;
        return true;
    }
    return false;
}
$externalUser = $_SESSION['external_user'] ?? null;
$isManagerExternal = $externalUser && ($externalUser['role'] ?? '') === 'manager';
if (!$externalUser && !isset($_SESSION['auth'])) {
    header("Location: /api/envio/wpp/");
    exit;
}

$instanceId = $_GET['instance'] ?? null;
if (!$instanceId) {
    header("Location: /api/envio/wpp/");
    exit;
}
if ($externalUser) {
    $allowedIds = array_map(fn($entry) => $entry['instance_id'] ?? '', $externalUser['instances'] ?? []);
    if (!in_array($instanceId, $allowedIds, true)) {
        header("Location: /api/envio/wpp/external_dashboard.php");
        exit;
    }
}

$ajaxDebugKeys = [
    'ajax_chats',
    'ajax_messages',
    'ajax_scheduled',
    'ajax_schedule_delete',
    'ajax_multi_input',
    'ajax_health',
    'ajax_status_notifications',
    'ajax_schedule_create',
    'ajax_message_counts'
];
$isAjaxRequest = false;
foreach ($ajaxDebugKeys as $key) {
    if (isset($_GET[$key])) {
        $isAjaxRequest = true;
        break;
    }
}


// Get instance details
$instance = loadInstanceRecordFromDatabase($instanceId);

if (!$instance) {
    header("Location: /api/envio/wpp/");
    exit;
}

$selfTestMode = !empty($_SESSION['auth']) && isset($_GET['selftest']) && $_GET['selftest'] === '1';
$selfTestPayload = [];
if ($selfTestMode) {
    $tests = [
        ['id' => 'agendar2-ok', 'label' => 'agendar2 válido', 'call' => 'agendar2("+2h","Teste","sdr","followup")'],
        ['id' => 'agendar2-missing-quotes', 'label' => 'agendar2 sem aspas', 'call' => 'agendar2(+2h,Teste,sdr,followup)'],
        ['id' => 'agendar2-newline', 'label' => 'agendar2 com newline', 'call' => "agendar2(\"+2h\",\n\"Teste\",\"sdr\",\"followup\")"],
        ['id' => 'set_variavel-argcount', 'label' => 'set_variavel faltando valor', 'call' => 'set_variavel("nome_cliente")'],
        ['id' => 'agendar2-commands-only', 'label' => 'sem texto visível', 'call' => '&&& agendar2("+2h","x","sdr","followup")']
    ];
    foreach ($tests as $test) {
        $response = callNodeTestFunction($instance, $test['call']);
        $selfTestPayload[] = [
            'id' => $test['id'],
            'label' => $test['label'],
            'call' => $test['call'],
            'response' => $response
        ];
    }
    debug_log('Selftest executado: ' . count($selfTestPayload) . ' casos');
}

$dashboardBaseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($dashboardBaseUrl === '') {
    $dashboardBaseUrl = '/';
}
$dashboardLogoUrl = buildPublicBaseUrl($dashboardBaseUrl . '/assets/maestro-logo.png');

$instancePhoneLabel = formatInstancePhoneLabel($instance['phone'] ?? '');
$isBaileysIntegration = ($instance['integration_type'] ?? 'baileys') === 'baileys';
$lastMessages = getInstanceLastMessages($instanceId);
$lastInbound = $lastMessages['inbound'] ?? null;
$lastOutbound = $lastMessages['outbound'] ?? null;
$lastInboundLabel = htmlspecialchars($lastInbound['remote_jid'] ?? '—', ENT_QUOTES);
$lastInboundTime = formatDisplayTimestamp($lastInbound['timestamp'] ?? null);
$lastInboundContent = htmlspecialchars(abbreviateContent($lastInbound['content'] ?? ''), ENT_QUOTES);
$lastOutboundLabel = htmlspecialchars($lastOutbound['remote_jid'] ?? '—', ENT_QUOTES);
$lastOutboundTime = formatDisplayTimestamp($lastOutbound['timestamp'] ?? null);
$lastOutboundContent = htmlspecialchars(abbreviateContent($lastOutbound['content'] ?? ''), ENT_QUOTES);

// Check if server is running
function isPortOpen($host, $port, $timeout = 1) {
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    } else {
        return false;
    }
}

$isRunning = isPortOpen('localhost', $instance['port']);
$connectionStatus = $isRunning ? 'connected' : 'disconnected';

$nodeBrowserName = null;
$nodeBrowserDetails = null;
$nodeUserAgent = null;

if (!$isAjaxRequest && should_log_debug_event('dashboard_loaded_' . $instanceId, 10)) {
    debug_log('Conversas dashboard loaded for instance: ' . $instanceId);
}

if ($isRunning) {
    // Try to get actual connection status
    $ch = curl_init("http://127.0.0.1:{$instance['port']}/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $resp = curl_exec($ch);
    curl_close($ch);
    
    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && isset($data['whatsappConnected'])) {
            $connectionStatus = $data['whatsappConnected'] ? 'connected' : 'disconnected';
        }
        if ($data) {
            $browserInfo = $data['browser'] ?? null;
            if (is_array($browserInfo) && count($browserInfo)) {
                $nodeBrowserName = $browserInfo[0];
                $nodeBrowserDetails = implode(" / ", $browserInfo);
            } elseif ($browserInfo) {
                $nodeBrowserDetails = (string)$browserInfo;
            }
            if (!empty($data['userAgent'])) {
                $nodeUserAgent = $data['userAgent'];
            }
        }
    }
}

if (!$isAjaxRequest && should_log_debug_event('instance_status_' . $instanceId . '_' . $connectionStatus, 30)) {
    debug_log('Instance status: ' . $connectionStatus);
}

function respondJson($payload, $status = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function buildNodePath(string $base, array $query = []): string {
    if (empty($query)) {
        return $base;
    }
    return $base . '?' . http_build_query($query);
}

function proxyNodeRequest(array $instance, string $path, string $method = 'GET', ?string $body = null, array $extraHeaders = []) {
    $url = "http://127.0.0.1:{$instance['port']}{$path}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $headers = [];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $headers[] = 'Accept: application/json';
    if (!empty($extraHeaders)) {
        $headers = array_merge($headers, $extraHeaders);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        respondJson(['ok' => false, 'error' => 'Erro ao conectar ao serviço interno', 'detail' => $error], 502);
    }

    http_response_code($httpCode ?: 200);
    header('Content-Type: application/json; charset=utf-8');
    echo $response ?: json_encode(['ok' => true]);
    exit;
}

function callNodeTestFunction(array $instance, string $functionCall): array {
    $url = "http://127.0.0.1:{$instance['port']}/api/test-function";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    $payload = json_encode(['function_call' => $functionCall]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return [
            'ok' => false,
            'error' => 'Erro ao conectar ao serviço interno',
            'detail' => $error,
            'http_code' => $httpCode
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $decoded = [
            'ok' => false,
            'error' => 'Resposta inválida',
            'raw' => $response
        ];
    }
    $decoded['http_code'] = $httpCode;
    return $decoded;
}

function formatInstancePhoneLabel($jid) {
    if (!$jid) {
        return '';
    }
}

function openChatDatabase(): ?SQLite3 {
    $path = __DIR__ . '/chat_data.db';
    if (!file_exists($path)) {
        return null;
    }
    try {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(5000);
        return $db;
    } catch (Exception $e) {
        debug_log('Falha ao abrir chat_data.db: ' . $e->getMessage());
        return null;
    }
}

function chatTableExists(SQLite3 $db, string $tableName): bool {
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:table LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bindValue(':table', $tableName, SQLITE3_TEXT);
    $result = $stmt->execute();
    $exists = $result && $result->fetchArray(SQLITE3_ASSOC) !== false;
    if ($result) {
        $result->finalize();
    }
    $stmt->close();
    return $exists;
}

function fetchLastMessageRecord(SQLite3 $db, string $instanceId, string $direction): ?array {
    if (!chatTableExists($db, 'messages')) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT remote_jid, content, timestamp
        FROM messages
        WHERE instance_id = :instance
          AND direction = :direction
          AND content IS NOT NULL
          AND TRIM(content) != ''
        ORDER BY timestamp DESC
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    $stmt->bindValue(':direction', $direction, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if ($result) {
        $result->finalize();
    }
    $stmt->close();
    if (!$row) {
        return null;
    }
    return [
        'remote_jid' => $row['remote_jid'] ?? '',
        'content' => $row['content'] ?? '',
        'timestamp' => $row['timestamp'] ?? ''
    ];
}

function getInstanceLastMessages(string $instanceId): array {
    $db = openChatDatabase();
    if (!$db) {
        return ['inbound' => null, 'outbound' => null];
    }
    $inbound = fetchLastMessageRecord($db, $instanceId, 'inbound');
    $outbound = fetchLastMessageRecord($db, $instanceId, 'outbound');
    $db->close();
    return ['inbound' => $inbound, 'outbound' => $outbound];
}

function formatDisplayTimestamp($value): string {
    if (!$value) {
        return 'sem registro';
    }
    $timestamp = is_numeric($value) ? (int)$value : strtotime((string)$value);
    if ($timestamp === false || $timestamp <= 0) {
        return 'sem registro';
    }
    return date('d/m/Y H:i:s', $timestamp);
}

function abbreviateContent($text, int $limit = 120): string {
    $clean = trim((string)$text);
    if ($clean === '') {
        return 'sem texto';
    }
    if (mb_strlen($clean) <= $limit) {
        return $clean;
    }
    return mb_substr($clean, 0, $limit) . '...';
}

if (isset($_GET['ajax_chats'])) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $path = buildNodePath("/api/chats/{$instanceId}", ['limit' => $limit, 'offset' => $offset]);
    $url = "http://127.0.0.1:{$instance['port']}{$path}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        respondJson(['ok' => false, 'error' => 'Erro ao conectar ao serviço interno', 'detail' => $error], 502);
    }

    $decoded = json_decode((string)$response, true);
    if (!is_array($decoded)) {
        http_response_code($httpCode ?: 200);
        header('Content-Type: application/json; charset=utf-8');
        echo $response ?: json_encode(['ok' => true]);
        exit;
    }

    $sourceChats = null;
    if (isset($decoded['chats']) && is_array($decoded['chats'])) {
        $sourceChats = $decoded['chats'];
    } elseif (isset($decoded['conversations']) && is_array($decoded['conversations'])) {
        $sourceChats = $decoded['conversations'];
    }

    if (is_array($sourceChats)) {
        $visibleChats = array_values(array_filter($sourceChats, static function ($chat) {
            $count = 0;
            if (is_array($chat) && isset($chat['message_count'])) {
                $count = (int)$chat['message_count'];
            } elseif (is_object($chat) && isset($chat->message_count)) {
                $count = (int)$chat->message_count;
            }
            return $count > 0;
        }));
        $decoded['chats'] = $visibleChats;
        $decoded['conversations'] = $visibleChats;
        $decoded['raw_count'] = count($sourceChats);
    }

    respondJson($decoded, $httpCode ?: 200);
}

if (isset($_GET['ajax_messages'])) {
    $remoteJid = $_GET['remote'] ?? '';
    if (!$remoteJid) {
        respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
    }
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $encodedRemote = rawurlencode($remoteJid);
    $path = buildNodePath("/api/messages/{$instanceId}/{$encodedRemote}", ['limit' => $limit, 'offset' => $offset]);

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'DELETE') {
        proxyNodeRequest($instance, $path, 'DELETE');
    }
    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }
        $action = strtolower(trim((string)($payload['action'] ?? '')));
        if ($action === 'delete') {
            proxyNodeRequest($instance, $path, 'DELETE');
        }
    }

    proxyNodeRequest($instance, $path, 'GET');
}

if (isset($_GET['ajax_multi_input'])) {
    $remoteJid = $_GET['remote'] ?? '';
    $query = [];
    if ($remoteJid !== '') {
        $query['remote'] = $remoteJid;
    }
    $path = buildNodePath("/api/multi-input", $query);
    proxyNodeRequest($instance, $path, 'GET');
}

if (isset($_GET['ajax_scheduled'])) {
    $remoteJid = $_GET['remote'] ?? '';
    if (!$remoteJid) {
        respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
    }
    $path = buildNodePath("/api/scheduled/{$instanceId}/" . rawurlencode($remoteJid), []);
    proxyNodeRequest($instance, $path, 'GET');
}

if (isset($_GET['ajax_schedule_delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $scheduledId = $_GET['scheduled_id'] ?? '';
    if (!$scheduledId) {
        respondJson(['ok' => false, 'error' => 'ID do agendamento é obrigatório'], 400);
    }
    $path = "/api/scheduled/{$instanceId}/" . rawurlencode($scheduledId);
    proxyNodeRequest($instance, $path, 'DELETE');
}

if (isset($_GET['ajax_schedule_create']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $path = "/api/scheduled/{$instanceId}";
    $body = file_get_contents('php://input');
    proxyNodeRequest($instance, $path, 'POST', $body);
}

if (isset($_GET['ajax_message_counts'])) {
    $remoteJid = $_GET['remote'] ?? '';
    if (!$remoteJid) {
        respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
    }
    $encodedRemote = rawurlencode($remoteJid);
    $path = buildNodePath("/api/message-counts/{$instanceId}/{$encodedRemote}", []);
    proxyNodeRequest($instance, $path, 'GET');
}

if (isset($_GET['ajax_instance_debug'])) {
    $allowed = [
        'enabled',
        'provider',
        'model',
        'model_fallback_1',
        'model_fallback_2',
        'system_prompt',
        'assistant_prompt',
        'assistant_id',
        'history_limit',
        'temperature',
        'max_tokens',
        'multi_input_delay',
        'openai_mode',
        'gemini_instruction',
        'openrouter_base_url',
        'auto_pause_enabled',
        'auto_pause_minutes'
    ];
    $aiPayload = [];
    foreach ($allowed as $key) {
        if (isset($instance['ai'][$key])) {
            $aiPayload[$key] = $instance['ai'][$key];
        }
    }
    $instanceInfo = [
        'instance_id' => $instance['instance_id'] ?? $instanceId,
        'name' => $instance['name'] ?? '',
        'phone' => $instance['phone'] ?? '',
        'port' => $instance['port'] ?? null,
        'status' => $instance['status'] ?? '',
        'connection_status' => $connectionStatus ?? ''
    ];
    respondJson([
        'ok' => true,
        'ai' => $aiPayload,
        'instance' => $instanceInfo
    ]);
}

if (isset($_GET['ajax_auto_pause_status'])) {
    $path = "/api/auto-pause-status";
    proxyNodeRequest($instance, $path, 'GET');
}

function openChatDbOrFail() {
    $dbPath = __DIR__ . '/chat_data.db';
    if (!file_exists($dbPath)) {
        respondJson(['ok' => false, 'error' => 'Banco de dados indisponível'], 500);
    }
    return new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
}

if (!function_exists('sqliteTableExists')) {
    function sqliteTableExists($db, string $table): bool {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->bindValue(':name', $table, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return !empty($row['name']);
    }
}

if (isset($_GET['ajax_variables'])) {
    $remote = trim((string)($_GET['remote'] ?? ''));
    if ($remote === '') {
        respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
    }

    $db = openChatDbOrFail();
    $persistent = [];
    $context = [];
    $tags = [];

    if (sqliteTableExists($db, 'persistent_variables')) {
        $stmt = $db->prepare("
            SELECT key, value, updated_at
            FROM persistent_variables
            WHERE instance_id = :instance
            ORDER BY key ASC
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $persistent[] = $row;
        }
    }

    if (sqliteTableExists($db, 'contact_context')) {
        $stmt = $db->prepare("
            SELECT key, value, updated_at
            FROM contact_context
            WHERE instance_id = :instance AND remote_jid = :remote
            ORDER BY key ASC
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $context[] = $row;
        }
    }

    if (sqliteTableExists($db, 'scheduled_messages')) {
        $stmt = $db->prepare("
            SELECT tag, COUNT(*) AS total
            FROM scheduled_messages
            WHERE instance_id = :instance AND remote_jid = :remote
            GROUP BY tag
            ORDER BY tag ASC
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tags[] = $row;
        }
    }

    $db->close();
    respondJson([
        'ok' => true,
        'persistent' => $persistent,
        'context' => $context,
        'tags' => $tags
    ]);
}

if (isset($_GET['ajax_variables_delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $scope = strtolower(trim((string)($payload['scope'] ?? '')));
    $key = trim((string)($payload['key'] ?? ''));
    $remote = trim((string)($payload['remote'] ?? ''));

    if ($scope === '' || $key === '') {
        respondJson(['ok' => false, 'error' => 'Escopo e chave são obrigatórios'], 400);
    }

    $db = openChatDbOrFail();
    $changes = 0;

    if ($scope === 'persistent' && sqliteTableExists($db, 'persistent_variables')) {
        $stmt = $db->prepare("
            DELETE FROM persistent_variables
            WHERE instance_id = :instance AND key = :key
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->execute();
        $changes = $db->changes();
    } elseif ($scope === 'context' && sqliteTableExists($db, 'contact_context')) {
        if ($remote === '') {
            $db->close();
            respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
        }
        $stmt = $db->prepare("
            DELETE FROM contact_context
            WHERE instance_id = :instance AND remote_jid = :remote AND key = :key
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->execute();
        $changes = $db->changes();
    } elseif ($scope === 'tag' && sqliteTableExists($db, 'scheduled_messages')) {
        if ($remote === '') {
            $db->close();
            respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
        }
        $stmt = $db->prepare("
            DELETE FROM scheduled_messages
            WHERE instance_id = :instance AND remote_jid = :remote AND tag = :tag
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote', $remote, SQLITE3_TEXT);
        $stmt->bindValue(':tag', $key, SQLITE3_TEXT);
        $stmt->execute();
        $changes = $db->changes();
    }

    $db->close();
    respondJson(['ok' => true, 'deleted' => $changes]);
}

if (isset($_GET['ajax_health'])) {
    proxyNodeRequest($instance, '/health', 'GET');
}

if (isset($_GET['ajax_status_notifications'])) {
    $dbPath = __DIR__ . '/chat_data.db';
    if (file_exists($dbPath)) {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
        $db->exec("DELETE FROM messages WHERE remote_jid = 'status@broadcast'");
        $db->close();
    }
    respondJson(['ok' => true, 'notifications' => []]);
}

if (isset($_GET['ajax_send']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    
    $to = trim((string)($payload['to'] ?? $payload['phone'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));
    
    // Media fields
    $imageUrl = trim((string)($payload['image_url'] ?? ''));
    $imageBase64 = trim((string)($payload['image_base64'] ?? ''));
    $videoUrl = trim((string)($payload['video_url'] ?? ''));
    $videoBase64 = trim((string)($payload['video_base64'] ?? ''));
    $audioUrl = trim((string)($payload['audio_url'] ?? ''));
    $caption = trim((string)($payload['caption'] ?? ''));
    
    $hasMedia = !empty($imageUrl) || !empty($imageBase64) || !empty($videoUrl) || !empty($videoBase64) || !empty($audioUrl);

    if ($to === '' || ($message === '' && !$hasMedia)) {
        respondJson(['ok' => false, 'error' => 'Parâmetros obrigatórios ausentes'], 400);
    }
    
    // Re-encode full payload to pass to Node
    $body = json_encode($payload);
    proxyNodeRequest($instance, '/send-message', 'POST', $body);
}

// Test function execution (without sending WhatsApp messages)
if (isset($_GET['ajax_test_function']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $functionCall = trim((string)($payload['function_call'] ?? ''));
    if ($functionCall === '') {
        respondJson(['ok' => false, 'error' => 'Function call é obrigatório'], 400);
    }
    
    // Execute via internal API endpoint that handles assistant commands
    $path = '/api/test-function';
    $body = json_encode(['function_call' => $functionCall]);
    proxyNodeRequest($instance, $path, 'POST', $body);
}

// AJAX endpoint for contact identity info
if (isset($_GET['ajax_contact_identity'])) {
    $remoteJid = $_GET['remote'] ?? '';
    if (!$remoteJid) {
        respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
    }
    $path = "/{$instanceId}/contact/" . rawurlencode($remoteJid);
    proxyNodeRequest($instance, $path, 'GET');
}

// AJAX endpoint to update contact identity
if (isset($_GET['ajax_update_identity']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $remoteJid = trim((string)($payload['remote'] ?? ''));
    if (!$remoteJid) {
        respondJson(['ok' => false, 'error' => 'Remote JID é obrigatório'], 400);
    }
    $path = "/{$instanceId}/contact/" . rawurlencode($remoteJid);
    $body = json_encode([
        'pushName' => $payload['pushName'] ?? null,
        'formattedPhone' => $payload['formattedPhone'] ?? null,
        'statusBio' => $payload['statusBio'] ?? null
    ]);
    proxyNodeRequest($instance, $path, 'POST', $body);
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conversas IA - <?= htmlspecialchars($instance['name']) ?></title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#2563EB',
            dark: '#1E293B',
            light: '#F1F5F9',
            mid: '#CBD5E1',
            success: '#22C55E',
            alert: '#F59E0B',
            error: '#EF4444'
          }
        }
      }
    }
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    html, body { font-family: Inter, system-ui, sans-serif; }
    
    /* WhatsApp-style chat styles */
    .chat-layout {
      height: calc(100vh - 120px);
    }
    
    .chat-sidebar {
      width: 320px;
      border-right: 1px solid #CBD5E1;
    }
    
    .chat-main {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    
    .message-bubble {
      max-width: 70%;
      word-wrap: break-word;
      position: relative;
    }
    
    .message-debug-icon {
      position: absolute;
      bottom: 4px;
      right: 6px;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: rgba(0, 0, 0, 0.15);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      opacity: 0;
      transition: opacity 0.2s ease;
      font-size: 10px;
      z-index: 10;
    }
    
    .message-outgoing .message-debug-icon {
      background: rgba(255, 255, 255, 0.25);
    }
    
    .message-bubble:hover .message-debug-icon {
      opacity: 0.7;
    }
    
    .message-debug-icon:hover {
      opacity: 1 !important;
    }

    .message-outgoing {
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      color: white;
      margin-left: auto;
      border-radius: 18px 18px 4px 18px;
      box-shadow: 0 8px 20px rgba(37, 99, 235, 0.25);
    }

    .message-incoming {
      background: #F1F5F9;
      color: #1E293B;
      margin-right: auto;
      border-radius: 18px 18px 18px 4px;
    }

    .message-error {
      background: #fee2e2;
      color: #7f1d1d;
      border-radius: 18px;
      border: 1px solid rgba(239, 68, 68, 0.4);
      box-shadow: none;
      max-width: 70%;
    }

    .message-error .text-sm,
    .message-error .text-xs {
      color: #7f1d1d;
    }

    .message-debug {
      border: 1px dashed rgba(185, 28, 28, 0.8);
      background: #fff1f2;
      color: #0f172a;
    }

    .message-function {
      background: linear-gradient(135deg, #fef3c7, #fde68a);
      color: #92400e;
      border: 1px solid #fcd34d;
      border-radius: 18px 18px 18px 4px;
    }

    .message-function .text-sm,
    .message-function .text-xs {
      color: #92400e;
    }

    .function-result {
      margin-top: 0.5rem;
      padding: 0.35rem 0.75rem;
      border-radius: 999px;
      background: #fef3c7;
      color: #92400e;
      border: 1px solid #fde68a;
      font-size: 0.75rem;
      font-weight: 600;
    }
    details.dbg summary {
      list-style: none;
    }
    details.dbg summary::-webkit-details-marker {
      display: none;
    }
    body.sidebar-collapsed aside {
      width: 0;
      min-width: 0;
      opacity: 0;
      pointer-events: none;
      overflow: hidden;
    }
    body.sidebar-collapsed .chat-main {
      width: 100%;
      flex: 1;
    }
    #sidebarExpandBtn {
      display: none;
      position: fixed;
      top: 1rem;
      left: 1rem;
      z-index: 60;
      border: 1px solid rgba(59, 130, 246, 0.4);
      background: white;
      border-radius: 999px;
      padding: 0.35rem 0.75rem;
      font-size: 0.75rem;
      color: #2563eb;
      cursor: pointer;
      box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
    }
    body.sidebar-collapsed #sidebarExpandBtn {
      display: inline-flex;
    }
    .message-debug .text-sm,
    .message-debug .text-xs {
      color: #0f172a;
    }
    
    .contact-item {
      border-bottom: 1px solid #F1F5F9;
      transition: background-color 0.2s;
    }
    
    .contact-item:hover {
      background: #F8FAFC;
    }
    
    .contact-item.active {
      background: #EFF6FF;
      border-right: 3px solid #2563EB;
    }

    .contacts-column {
      flex: 1 1 0;
      min-height: 0;
      display: flex;
      flex-direction: column;
    }

    .contacts-scroll {
      flex: 1 1 0;
      min-height: 0;
      overflow-y: auto;
    }
    
    .typing-indicator {
      animation: pulse 1.5s infinite;
    }
    
    @keyframes pulse {
      0%, 100% { opacity: 0.4; }
      50% { opacity: 1; }
    }
    .instance-sticky-header {
      position: sticky;
      top: 0;
      z-index: 40;
      background: rgba(241, 245, 249, 0.96);
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 10px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
      backdrop-filter: blur(8px);
      margin-bottom: 16px;
    }
  </style>
</head>

<body class="bg-light text-dark overflow-hidden">
<button id="sidebarExpandBtn" type="button" aria-label="Abrir menu" class="flex items-center gap-1 px-3 py-1.5 rounded-full border border-primary text-[11px] font-semibold text-primary bg-white shadow-sm transition">
  <span class="text-sm">&#9776;</span>
  Menu
</button>
<div class="h-screen flex overflow-hidden">

  <!-- SIDEBAR / INSTÂNCIAS (PRESERVED FROM ORIGINAL) -->
  <aside class="w-80 bg-white border-r border-mid hidden lg:flex flex-col h-screen overflow-hidden">
    <div class="p-6 border-b border-mid">
    <a href="<?= htmlspecialchars($dashboardBaseUrl) ?>" class="flex items-center gap-3 inline-flex group">
        <div class="flex items-center justify-center h-12">
          <img src="<?= htmlspecialchars($dashboardLogoUrl) ?>" width="56" style="height:auto;" alt="Logomarca Maestro">
        </div>
        <div>
          <div class="text-lg font-semibold text-dark">Maestro</div>
          <div class="text-xs text-slate-500">WhatsApp Orchestrator</div>
        </div>
      </a>

      <div class="mt-4 w-full px-4 py-2 rounded-xl bg-light border border-mid text-sm text-slate-500 text-center">
        <?= htmlspecialchars($instance['name']) ?>
      </div>

      <a href="<?= htmlspecialchars($dashboardBaseUrl) ?>/?instance=<?= urlencode($instanceId) ?>" class="mt-4 w-full px-4 py-2 rounded-xl bg-mid text-dark font-medium hover:bg-primary hover:text-white transition text-center block">
        ← Voltar ao Painel
      </a>
      <button id="newConversationBtn" class="mt-3 w-full px-4 py-2 rounded-xl border border-primary text-xs text-primary font-medium hover:bg-primary/10">
        + Nova conversa
      </button>
    </div>

    <!-- CHAT CONTACTS SIDEBAR -->
    <div class="flex-1 flex flex-col contacts-column min-h-0">
      <div class="p-3 border-b border-mid">
        <div class="text-xs text-slate-500 px-2 mb-2">CONVERSAS</div>
        <input id="searchInput" class="w-full px-3 py-2 rounded-xl bg-light border border-mid text-sm"
               placeholder="Buscar conversas...">
      </div>
      
      <div class="flex-1 overflow-y-auto contacts-scroll" id="contactsList">
        <div class="p-4 text-center text-slate-500">
          <div class="animate-spin w-6 h-6 border-2 border-primary border-t-transparent rounded-full mx-auto mb-2"></div>
          Carregando conversas...
        </div>
      </div>
    </div>

    <!-- SYSTEM STATUS INDICATOR -->
    <div class="p-4 border-t border-mid">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <div id="systemStatusDot" class="w-2 h-2 rounded-full"></div>
          <span id="systemStatusText" class="text-xs text-slate-500">Verificando...</span>
        </div>
        <button id="refreshBtn" class="p-1 rounded hover:bg-light">
          <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
          </svg>
        </button>
      </div>
      <div id="systemStatusTooltip" class="hidden absolute bottom-16 left-4 bg-dark text-white p-2 rounded text-xs whitespace-nowrap z-50">
        <!-- Tooltip content will be populated by JavaScript -->
      </div>
    </div>
  </aside>

  <!-- CHAT MAIN AREA -->
  <main class="chat-main p-8 flex-1 flex flex-col h-screen overflow-hidden">
    <div class="instance-sticky-header">
      <div class="flex items-center gap-3">
        <button id="sidebarToggleBtn" type="button" class="flex items-center gap-1 px-3 py-1.5 rounded-full border border-mid text-[11px] font-semibold text-slate-600 hover:border-primary hover:text-primary transition">
          <span class="text-sm">&#9776;</span>
          Menu
        </button>
        <div>
          <div class="text-sm text-slate-500">Instância selecionada</div>
          <div class="font-semibold text-dark"><?= htmlspecialchars($instance['name'] ?? 'Nenhuma instância') ?></div>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <?php 
          $isMetaApi = !empty($instance['ai']['meta_access_token']) && !empty($instance['meta']['business_account_id']);
          echo '<div class="text-xs text-slate-500">';
          echo $isMetaApi ? 'Integração: Meta API' : 'Integração: Baileys';
          echo '</div>';
        ?>
      </div>
    </div>
    <div class="flex flex-col flex-1 min-h-0 relative">
    <!-- CHAT HEADER -->
    <div class="bg-white border border-mid rounded-2xl p-4 mb-6 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div id="chatContactAvatar" class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-white font-medium overflow-hidden">
          <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
          </svg>
        </div>
        <div>
        <div class="font-medium" id="chatContactName">Selecione uma conversa</div>
          <div class="text-xs text-slate-500" id="chatStatus">Aguardando seleção...</div>
          <div id="statusBroadcastAlert" class="hidden mt-2 rounded-xl border border-warning/60 bg-warning/10 text-warning text-xs px-3 py-2 flex items-start justify-between gap-2">
            <div id="statusBroadcastText" class="flex-1 leading-tight"></div>
            <button id="statusBroadcastClose" type="button" class="text-warning font-semibold text-[11px] hover:text-warning/80">
              OK
            </button>
          </div>
          <div id="chatQueueStatus" class="text-xs text-orange-600 hidden">Aguardando X segundos...</div>
          <div id="autoPauseStatus" class="text-xs text-orange-600 hidden"></div>
          <?php if ($instancePhoneLabel): ?>
            <div class="text-[11px] text-slate-500 mt-1">WhatsApp local: <?= htmlspecialchars($instancePhoneLabel) ?></div>
          <?php endif; ?>
          <div class="text-[11px] text-slate-500 mt-1">Taxa: <span id="chatTaxar">--</span>%</div>
          <div class="text-[11px] text-slate-500 mt-1">Temperatura: <span id="chatTemperature">--</span></div>
        </div>
      </div>
      
        <div class="flex items-center gap-2">
        <?php $connectionBadgeConnected = ($connectionStatus === 'connected'); ?>
        <span id="instanceConnectionBadge"
              class="px-3 py-1 rounded-full text-sm font-medium <?= $connectionBadgeConnected ? 'bg-success/10 text-success' : 'bg-error/10 text-error' ?>">
          <span id="instanceConnectionText"><?= $connectionBadgeConnected ? 'Conectado' : 'Desconectado' ?></span>
        </span>
            <button id="contactDetailsBtn" class="px-3 py-1 rounded-xl border border-mid text-xs text-slate-600 hover:border-primary hover:text-primary">
              Detalhes
            </button>
            <button id="clearChatBtn" disabled class="px-3 py-1 rounded-xl border border-mid text-xs text-slate-600 hover:border-primary hover:text-primary">
              Limpar conversa
            </button>
            <button id="deleteChatBtn" disabled class="px-3 py-1 rounded-xl border border-error text-xs text-error hover:bg-error/10">
              Apagar conversa
            </button>
            <button id="toggleAgBtn" class="px-3 py-1.5 rounded-lg border border-mid bg-white hover:bg-slate-50 text-sm flex items-center gap-2">
              <span id="toggleAgBtnText">Agendamentos</span>
              <?php if (!empty($instance['interno'])): ?>
                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-success/10 text-success">Interno</span>
              <?php endif; ?>
              <span id="scheduleBadge" class="hidden text-[11px] font-semibold gap-1 flex items-center"></span>
            </button>
            <button id="toggleDebugBtn" class="px-3 py-1.5 rounded-lg border border-mid bg-white hover:bg-slate-50 text-sm flex items-center gap-2">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
              </svg>
              <span id="toggleDebugBtnText">Debug</span>
              <span id="debugBadge" class="hidden text-[11px] font-semibold text-primary"></span>
            </button>
          </div>
    </div>

    <!-- MESSAGES AREA -->
      <div class="bg-white border border-mid rounded-2xl flex-1 flex flex-col min-h-0 overflow-hidden">
        <div class="flex-1 min-h-0 overflow-y-auto p-4 space-y-3" id="messagesArea">
          <div class="text-center text-slate-500 py-8">
            <svg class="w-12 h-12 mx-auto mb-4 text-slate-400" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7zM7 9H5v2h2V9zm8 0h-2v2h2V9zM9 9h2v2H9V9z" clip-rule="evenodd"></path>
            </svg>
            <p>Selecione uma conversa para ver as mensagens</p>
          </div>
        </div>
        <!-- MESSAGE INPUT -->
        <div class="border-t border-mid p-4 space-y-4">
        <form id="messageForm" class="flex gap-3">
          <input type="text" 
                 id="messageInput" 
                 class="px-4 py-3 rounded-xl border border-mid bg-light focus:outline-none focus:border-primary"
                 placeholder="Digite sua mensagem..."
                 disabled>
          <button type="submit" 
                  id="sendBtn"
                  class="px-6 py-3 rounded-xl bg-primary text-white font-medium hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                  disabled>
            Enviar
          </button>
        </form>

      </div>
    </div>
    <div id="agPanel" class="hidden absolute right-4 top-20 w-[420px] max-w-[90vw] bg-white border border-mid rounded-2xl shadow-xl z-50">
      <div class="flex items-center justify-between p-4 border-b border-mid">
        <div class="font-semibold text-sm">Agendamentos</div>
        <button id="closeAgBtn" class="px-2 py-1 rounded-lg border border-mid hover:bg-slate-50 text-sm">Fechar</button>
      </div>
      <div class="max-h-[60vh] overflow-y-auto p-4">
        <div id="scheduledPanel" class="hidden space-y-3">
          <div class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
              <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Agendamentos</p>
                <p id="scheduledSummary" class="text-[11px] text-slate-400">Sem agendamentos para este contato.</p>
              </div>
              <div class="flex items-center gap-2">
                <button id="toggleScheduleForm" type="button"
                        class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                  + Agendar
                </button>
                <button id="refreshScheduleBtn" class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                  Atualizar
                </button>
              </div>
            </div>
            <div id="scheduleFormContainer" class="hidden space-y-3 rounded-2xl border border-dashed border-mid bg-slate-50 p-4 text-sm">
              <form id="manualScheduleForm" class="space-y-3">
                <div>
                  <label class="text-xs text-slate-500">Mensagem</label>
                  <textarea id="manualScheduleMessage" rows="3"
                            class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm"
                            placeholder="Mensagem a ser enviada para o cliente"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="text-xs text-slate-500">Data</label>
                    <input id="manualScheduleDate" type="date"
                           class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
                  </div>
                  <div>
                    <label class="text-xs text-slate-500">Hora</label>
                    <input id="manualScheduleTime" type="time"
                           class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
                  </div>
                </div>
                <div class="flex items-center gap-3">
                  <button type="submit"
                          class="px-4 py-2 rounded-xl bg-primary text-white text-xs font-medium hover:opacity-90">
                    Agendar manualmente
                  </button>
                  <span id="manualScheduleStatus" class="text-[11px] text-slate-500"></span>
                </div>
              </form>
            </div>
          </div>
          <div id="scheduledList" class="space-y-2 text-sm text-slate-500"></div>
        </div>
      </div>
    </div>
    <div id="debugPanel" class="hidden absolute left-[23%] right-0 top-20 bg-white border border-mid rounded-2xl shadow-xl z-[99999]">
      <div class="flex items-center justify-between p-4 border-b border-mid">
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
          <div class="font-semibold text-sm">Debug Console</div>
        </div>
        <div class="flex items-center gap-2">
          <button id="clearDebugBtn" type="button" class="px-2 py-1 rounded-lg border border-mid hover:bg-slate-50 text-xs">Limpar</button>
          <button id="exportDebugBtn" type="button" class="px-2 py-1 rounded-lg border border-primary text-primary hover:bg-primary/5 text-xs">Salvar</button>
          <button id="closeDebugBtn" class="px-2 py-1 rounded-lg border border-mid hover:bg-slate-50 text-sm">Fechar</button>
        </div>
      </div>
      
      <!-- Funções de Teste Section (AT THE TOP) -->
      <div class="border-b border-mid">
        <button id="toggleTestFunctionsBtn" class="w-full px-4 py-3 flex items-center justify-between text-sm font-medium hover:bg-slate-50 transition">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
            </svg>
            <span>Funções de Teste</span>
          </div>
          <svg id="testFunctionsChevron" class="w-4 h-4 text-slate-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
          </svg>
        </button>
        <div id="testFunctionsPanel" class="hidden p-4 bg-slate-50 border-t border-mid space-y-3">
          <div class="text-xs text-slate-500 mb-2">Execute funções do sistema sem enviar mensagens. Clique em 💡 para ver funções disponíveis.</div>
          <div class="flex gap-2">
            <input id="testFunctionInput" type="text" 
                   class="flex-1 px-3 py-2 rounded-xl border border-mid bg-white text-sm font-mono" 
                   placeholder="dados('email')">
            <button id="showFunctionsBtn" type="button" 
                    class="px-3 py-2 rounded-xl border border-amber-300 bg-amber-50 text-amber-600 text-sm font-medium hover:bg-amber-100 transition" 
                    title="Ver funções disponíveis">
              💡 Funções
            </button>
            <button id="runTestFunctionBtn" type="button" 
                    class="px-4 py-2 rounded-xl bg-amber-500 text-white text-sm font-medium hover:bg-amber-600 transition">
              Executar
            </button>
          </div>
          <div id="testFunctionResult" class="hidden p-3 rounded-xl bg-white border border-amber-200 text-sm text-amber-800 font-mono whitespace-pre-wrap"></div>
        </div>
      </div>
      
      <!-- Available Functions Dropdown (Hidden by default) -->
      <div id="functionsDropdown" class="hidden p-4 bg-amber-50 border-b border-amber-200">
        <div class="flex items-center justify-between mb-2">
          <div class="font-medium text-sm text-amber-800">💡 Funções Disponíveis</div>
          <button id="closeFunctionsDropdown" class="text-amber-600 hover:text-amber-800 text-lg leading-none">&times;</button>
        </div>
        <div class="text-xs text-amber-700 mb-3">Clique em uma função para inseri-la no campo de teste:</div>
        <div class="grid grid-cols-1 gap-2 max-h-64 overflow-y-auto">
          <!-- Core Functions -->
          <button type="button" data-function="whatsapp('numero', 'mensagem')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">whatsapp('numero', 'mensagem')</span>
            <span class="block text-xs text-slate-500">Envia mensagem WhatsApp</span>
          </button>
          <button type="button" data-function="mail('email@exemplo.com', 'Assunto', 'Corpo do email')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">mail('email', 'assunto', 'corpo')</span>
            <span class="block text-xs text-slate-500">Envia email</span>
          </button>
          <button type="button" data-function="get_web('https://url.com')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">get_web('url')</span>
            <span class="block text-xs text-slate-500">Busca conteúdo de URL</span>
          </button>
          <button type="button" data-function="dados('email@exemplo.com')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">dados('email')</span>
            <span class="block text-xs text-slate-500">Retorna dados do cliente</span>
          </button>
          
          <!-- Scheduling Functions -->
          <button type="button" data-function="agendar('DD/MM/AAAA', 'HH:MM', 'mensagem')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">agendar('data', 'hora', 'mensagem')</span>
            <span class="block text-xs text-slate-500">Agenda mensagem para data/hora</span>
          </button>
          <button type="button" data-function="agendar2('+30m', 'mensagem')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">agendar2('+30m', 'mensagem')</span>
            <span class="block text-xs text-slate-500">Agenda com tempo relativo</span>
          </button>
          <button type="button" data-function="agendar3('AAAA-MM-DD HH:mm:ss', 'mensagem')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">agendar3('YYYY-MM-DD HH:mm:ss', 'mensagem')</span>
            <span class="block text-xs text-slate-500">Agenda com timestamp exato</span>
          </button>
          <button type="button" data-function="listar_agendamentos()" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">listar_agendamentos()</span>
            <span class="block text-xs text-slate-500">Lista agendamentos do contato</span>
          </button>
          <button type="button" data-function="apagar_agenda(123)" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">apagar_agenda(id)</span>
            <span class="block text-xs text-slate-500">Remove agendamento específico</span>
          </button>
          <button type="button" data-function="apagar_agendas_por_tag('followup')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">apagar_agendas_por_tag('tag')</span>
            <span class="block text-xs text-slate-500">Remove agendamentos por tag</span>
          </button>
          <button type="button" data-function="apagar_agendas_por_tipo('followup')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">apagar_agendas_por_tipo('tipo')</span>
            <span class="block text-xs text-slate-500">Remove agendamentos por tipo</span>
          </button>
          <button type="button" data-function="cancelar_e_agendar2('+1h', 'nova mensagem')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">cancelar_e_agendar2('tempo', 'mensagem')</span>
            <span class="block text-xs text-slate-500">Cancela e agenda novo</span>
          </button>
          <button type="button" data-function="cancelar_e_agendar3('AAAA-MM-DD HH:mm', 'nova mensagem')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">cancelar_e_agendar3('data', 'mensagem')</span>
            <span class="block text-xs text-slate-500">Cancela e agenda com data</span>
          </button>
          
          <!-- Calendar Functions -->
          <button type="button" data-function="verificar_disponibilidade('DD/MM/AAAA HH:mm', 60)" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">verificar_disponibilidade('inicio', duracao)</span>
            <span class="block text-xs text-slate-500">Verifica disponibilidade no calendário</span>
          </button>
          <button type="button" data-function="sugerir_horarios('DD/MM/AAAA', '09:00-12:00', 30, 3)" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">sugerir_horarios('data', 'janela', duracao, limite)</span>
            <span class="block text-xs text-slate-500">Sugere horários disponíveis</span>
          </button>
          <button type="button" data-function="marcar_evento('Título', 'DD/MM/AAAA HH:mm', 'DD/MM/AAAA HH:MM', 'email1@exemplo.com,email2@exemplo.com', 'Descrição do evento', '2', 'America/Sao_Paulo')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">marcar_evento('titulo', 'inicio', 'fim', 'emails', 'descricao', 'calendario', 'timezone')</span>
            <span class="block text-xs text-slate-500">Cria evento no calendário</span>
          </button>
          <button type="button" data-function="remarcar_evento('event_id', 'nova data', 'nova hora')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">remarcar_evento('id', 'inicio', 'fim')</span>
            <span class="block text-xs text-slate-500">Atualiza evento no calendário</span>
          </button>
          <button type="button" data-function="desmarcar_evento('event_id')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">desmarcar_evento('id')</span>
            <span class="block text-xs text-slate-500">Cancela evento no calendário</span>
          </button>
          <button type="button" data-function="listar_eventos('YYYY-MM-DD', 'YYYY-MM-DD')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">listar_eventos('inicio', 'fim')</span>
            <span class="block text-xs text-slate-500">Lista eventos do calendário</span>
          </button>
          
          <!-- State & Context Functions -->
          <button type="button" data-function="set_estado('novo_lead')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">set_estado('estado')</span>
            <span class="block text-xs text-slate-500">Define estado do funil</span>
          </button>
          <button type="button" data-function="get_estado()" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">get_estado()</span>
            <span class="block text-xs text-slate-500">Retorna estado atual</span>
          </button>
          <button type="button" data-function="set_contexto('chave', 'valor')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">set_contexto('chave', 'valor')</span>
            <span class="block text-xs text-slate-500">Define contexto do contato</span>
          </button>
          <button type="button" data-function="get_contexto('chave')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">get_contexto('chave')</span>
            <span class="block text-xs text-slate-500">Retorna contexto específico</span>
          </button>
          <button type="button" data-function="limpar_contexto()" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">limpar_contexto()</span>
            <span class="block text-xs text-slate-500">Limpa contexto do contato</span>
          </button>
          <button type="button" data-function="set_variavel('nome', 'valor')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">set_variavel('nome', 'valor')</span>
            <span class="block text-xs text-slate-500">Salva variável persistente</span>
          </button>
          <button type="button" data-function="get_variavel('nome')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">get_variavel('nome')</span>
            <span class="block text-xs text-slate-500">Retorna variável persistente</span>
          </button>
          
          <!-- Utility Functions -->
          <button type="button" data-function="boomerang()" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">boomerang()</span>
            <span class="block text-xs text-slate-500">Reprocessa mensagem pela IA</span>
          </button>
          <button type="button" data-function="optout()" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">optout()</span>
            <span class="block text-xs text-slate-500">Desativa follow-up do contato</span>
          </button>
          <button type="button" data-function="status_followup()" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">status_followup()</span>
            <span class="block text-xs text-slate-500">Retorna status de follow-up</span>
          </button>
          <button type="button" data-function="log_evento('categoria', 'descricao', 'metadata')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">log_evento('categoria', 'descricao')</span>
            <span class="block text-xs text-slate-500">Registra evento no log</span>
          </button>
          <button type="button" data-function="tempo_sem_interacao()" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">tempo_sem_interacao()</span>
            <span class="block text-xs text-slate-500">Tempo desde última mensagem</span>
          </button>
          <button type="button" data-function="template('nome_do_template', 'param1', 'param2')" class="text-left px-3 py-2 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm transition">
            <span class="font-mono text-amber-700">template('nome', 'param1', 'param2')</span>
            <span class="block text-xs text-slate-500">Envia template Meta (24h)</span>
          </button>
        </div>
      </div>
      
      <!-- Search Section -->
      <div id="debugSearchContainer" class="p-3 border-b border-mid">
        <input id="debugSearchInput" type="text" class="w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm" placeholder="Filtrar logs...">
      </div>
      
      <!-- Footer Section -->
      <div class="p-3 border-t border-b border-mid bg-slate-50">
        <div class="flex items-center justify-between text-xs text-slate-500">
          <div class="flex items-center gap-3">
            <span><span id="debugEntryCount">0</span> entradas</span>
            <span class="text-slate-300">|</span>
            <span>Última atualização: <span id="debugLastUpdate">—</span></span>
          </div>
          <div class="flex items-center gap-2">
            <label class="flex items-center gap-1 cursor-pointer">
              <input type="checkbox" id="debugAutoScroll" checked class="rounded border-mid">
              <span>Auto-scroll</span>
            </label>
            <label class="flex items-center gap-1 cursor-pointer">
              <input type="checkbox" id="debugWrapLines" class="rounded border-mid">
              <span>Wrap</span>
            </label>
          </div>
        </div>
      </div>
      
      <!-- Log Section (AT THE END/BOTTOM) -->
      <div class="max-h-[40vh] overflow-y-auto p-3 bg-slate-900" id="debugLogContainer">
        <div id="debugLogContent" class="font-mono text-xs space-y-1">
          <div class="text-slate-500 text-center py-4">Nenhum log de debug disponível</div>
        </div>
      </div>
    </div>
    <div id="chatDebugFooter" class="mt-3 text-[11px] text-slate-400 flex flex-wrap gap-3">
      <span>Conversa: <span id="debugConversationId">—</span></span>
      <span id="debugScheduledHint" class="text-[10px] text-slate-400"></span>
    </div>
    <div class="debug-info" style="background: #f0f0f0; padding: 10px; margin: 10px 0; font-size: 11px;">
      <strong>Debug Info:</strong><br>
      Instance ID: <?= htmlspecialchars($instanceId) ?><br>
      Instance Name: <?= htmlspecialchars($instance['name'] ?? 'N/A') ?><br>
      Remote JID: <span id="debugInfoRemoteJid">—</span><br>
      Timestamp: <?= date('Y-m-d H:i:s') ?>
      <span style="margin-left: 10px;">|</span>
      <a href="#" id="saveLogBtn" style="margin-left: 10px; color: #666; text-decoration: underline; cursor: pointer;" title="Exportar log de debug completo">💾 Save log</a>
    </div>
    </div>
  </main>
</div>

<!-- Contact details modal -->
<div id="contactDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 px-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6 relative border border-mid">
    <button id="contactDetailsClose" class="absolute top-3 right-3 text-slate-500 hover:text-dark">&times;</button>
    <h3 class="text-lg font-semibold mb-3">Detalhes da conversa</h3>
    <div id="contactDetailsBody" class="space-y-2 text-sm text-slate-600">
      <p>Selecione uma conversa para ver os detalhes.</p>
    </div>
    <div class="mt-4 border-t border-mid pt-3 flex items-center justify-between">
      <div>
        <div class="text-sm font-semibold text-slate-700">Variaveis</div>
        <div class="text-[11px] text-slate-500">Persistentes da instância + contexto do contato e tags.</div>
      </div>
      <button id="manageVariablesBtn" type="button"
              class="px-3 py-1 rounded-xl border border-mid text-xs text-slate-600 hover:border-primary hover:text-primary">
        Ver
      </button>
    </div>
  </div>
</div>

<!-- New conversation modal -->
<div id="newConversationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 px-4">
  <div class="bg-white rounded-2xl w-full max-w-md p-6 relative border border-mid">
    <button id="newConversationClose" class="absolute top-3 right-3 text-slate-500 hover:text-dark">&times;</button>
    <h3 class="text-lg font-semibold mb-3">Nova conversa</h3>
    <form id="newConversationForm" class="space-y-4">
      <div>
        <label class="text-xs text-slate-500">Número destino</label>
        <input id="newConversationPhone" type="text" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Ex: 5585999999999" required>
      </div>
      <div>
        <label class="text-xs text-slate-500">Mensagem inicial</label>
        <textarea id="newConversationMessage" rows="4" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Sua mensagem..." required></textarea>
      </div>
      <div class="flex items-center justify-between">
        <button type="button" id="newConversationCancel" class="px-4 py-2 rounded-xl border border-mid text-xs text-slate-500 hover:bg-light">Cancelar</button>
        <button type="submit" id="newConversationSubmit" class="px-4 py-2 rounded-xl bg-primary text-white text-xs font-medium">Enviar e abrir</button>
      </div>
      <p id="newConversationStatus" class="text-xs text-slate-500"></p>
    </form>
  </div>
</div>

<div id="variablesModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 px-4">
  <div class="bg-white rounded-2xl w-full max-w-2xl p-6 relative border border-mid">
    <button id="variablesClose" class="absolute top-3 right-3 text-slate-500 hover:text-dark">&times;</button>
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-lg font-semibold">Variaveis, Contexto e Tags</h3>
      <button id="variablesRefresh" type="button"
              class="px-3 py-1 rounded-xl border border-mid text-xs text-slate-600 hover:border-primary hover:text-primary">
        Atualizar
      </button>
    </div>
    <div id="variablesStatus" class="text-[11px] text-slate-500 mb-3"></div>
    <div id="variablesBody" class="space-y-4 text-sm text-slate-600">
      <p>Selecione uma conversa para ver os dados.</p>
    </div>
  </div>
</div>

<!-- Message Debug Modal -->
<div id="messageDebugModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 px-4">
  <div class="bg-white rounded-2xl w-full max-w-lg max-h-[80vh] overflow-hidden flex flex-col">
    <div class="flex items-center justify-between p-4 border-b border-mid">
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        <div class="font-semibold text-sm">Debug da Mensagem</div>
      </div>
      <button id="messageDebugClose" class="px-2 py-1 rounded-lg border border-mid hover:bg-slate-50 text-sm">Fechar</button>
    </div>
    <div id="messageDebugContent" class="flex-1 overflow-y-auto p-4 space-y-3 text-xs">
      <!-- Debug content will be populated here -->
    </div>
  </div>
</div>

<script>
window.__selfTestMode = <?= $selfTestMode ? 'true' : 'false' ?>;
window.__selfTestResults = <?= json_encode($selfTestPayload ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>

<script>
const INSTANCE_ID = '<?= $instanceId ?>';
const API_BASE_URL = `${window.location.origin}${window.location.pathname}`;
const CLIENT_TIMEZONE = <?= json_encode($clientTimezone) ?>;
const SELF_TEST_MODE = Boolean(window.__selfTestMode);
const SELF_TEST_RESULTS = Array.isArray(window.__selfTestResults) ? window.__selfTestResults : [];

// State management
let selectedContact = null;
let selectedContactData = null;
let contacts = [];
let messages = {};
let isLoading = false;
const timeOptions = { hour: '2-digit', minute: '2-digit', timeZone: CLIENT_TIMEZONE };
const dateTimeOptions = { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: CLIENT_TIMEZONE };
function normalizeTimestamp(value) {
    if (!value) return value;
    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(trimmed)) {
            return `${trimmed.replace(' ', 'T')}Z`;
        }
    }
    return value;
}

function toDate(value) {
    const normalized = normalizeTimestamp(value);
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? null : date;
}
const encodeAttrValue = value => encodeURIComponent(value || '');
const urlParams = new URLSearchParams(window.location.search);
let pendingInitialContact = urlParams.get('contact') || null;
const logPrefix = `[conversas ${INSTANCE_ID}]`;
const CHAT_SCROLL_THRESHOLD = 48;
const CONTACTS_PAGE_SIZE = 50;
let contactsOffset = 0;
let contactsHasMore = true;
let contactsLoading = false;
let contactsLoadingRow = null;
const scheduleTimeOptions = {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: CLIENT_TIMEZONE
};
let shouldAutoScrollMessages = true;
let multiInputPollTimer = null;
let lastStatusBroadcastId = 0;
const isStatusBroadcastJid = (remoteJid) => {
    return typeof remoteJid === 'string' && remoteJid.toLowerCase().startsWith('status@broadcast');
};

const buildAjaxUrl = (params = {}) => {
    const url = new URL(API_BASE_URL);
    url.searchParams.set('instance', INSTANCE_ID);
    url.searchParams.delete('contact');
    Object.entries(params).forEach(([key, value]) => {
        if (value === null || value === undefined) {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    });
    return url.toString();
};
const AUTO_REFRESH_INTERVAL = 15000;
const STATUS_BROADCAST_POLL_INTERVAL = 15000;
const fetchWithCreds = (url, options = {}) => fetch(url, { credentials: 'include', ...options });
const toggleAgBtnText = document.getElementById('toggleAgBtnText');
const scheduleBadge = document.getElementById('scheduleBadge');

// UI Elements
const contactsList = document.getElementById('contactsList');
const messagesArea = document.getElementById('messagesArea');
const chatContactAvatar = document.getElementById('chatContactAvatar');
const searchInput = document.getElementById('searchInput');
const messageInput = document.getElementById('messageInput');
const messageForm = document.getElementById('messageForm');
const sendBtn = document.getElementById('sendBtn');
const chatContactName = document.getElementById('chatContactName');
const chatStatus = document.getElementById('chatStatus');
const chatQueueStatus = document.getElementById('chatQueueStatus');
const refreshBtn = document.getElementById('refreshBtn');
const systemStatusDot = document.getElementById('systemStatusDot');
const systemStatusText = document.getElementById('systemStatusText');
const systemStatusTooltip = document.getElementById('systemStatusTooltip');
const contactDetailsModal = document.getElementById('contactDetailsModal');
const contactDetailsClose = document.getElementById('contactDetailsClose');
const contactDetailsBody = document.getElementById('contactDetailsBody');
const contactDetailsBtn = document.getElementById('contactDetailsBtn');
const clearChatBtn = document.getElementById('clearChatBtn');
const deleteChatBtn = document.getElementById('deleteChatBtn');
const newConversationBtn = document.getElementById('newConversationBtn');
const newConversationModal = document.getElementById('newConversationModal');
const variablesModal = document.getElementById('variablesModal');
const variablesBody = document.getElementById('variablesBody');
const variablesStatus = document.getElementById('variablesStatus');
const variablesClose = document.getElementById('variablesClose');
const variablesRefresh = document.getElementById('variablesRefresh');
const manageVariablesBtn = document.getElementById('manageVariablesBtn');
const newConversationClose = document.getElementById('newConversationClose');
const newConversationCancel = document.getElementById('newConversationCancel');
const newConversationForm = document.getElementById('newConversationForm');
const newConversationPhone = document.getElementById('newConversationPhone');
const newConversationMessage = document.getElementById('newConversationMessage');
const newConversationStatus = document.getElementById('newConversationStatus');
const scheduledPanel = document.getElementById('scheduledPanel');
const scheduledList = document.getElementById('scheduledList');
const scheduledSummary = document.getElementById('scheduledSummary');
const toggleScheduleFormBtn = document.getElementById('toggleScheduleForm');
const scheduleFormContainer = document.getElementById('scheduleFormContainer');
const manualScheduleForm = document.getElementById('manualScheduleForm');
const manualScheduleMessage = document.getElementById('manualScheduleMessage');
const manualScheduleDate = document.getElementById('manualScheduleDate');
const manualScheduleTime = document.getElementById('manualScheduleTime');
const manualScheduleStatus = document.getElementById('manualScheduleStatus');
const debugConversationId = document.getElementById('debugConversationId');
const debugScheduledHint = document.getElementById('debugScheduledHint');
const refreshScheduleBtn = document.getElementById('refreshScheduleBtn');
const statusBroadcastAlert = document.getElementById('statusBroadcastAlert');
const statusBroadcastText = document.getElementById('statusBroadcastText');
const statusBroadcastClose = document.getElementById('statusBroadcastClose');

// Debug panel elements
const toggleDebugBtn = document.getElementById('toggleDebugBtn');
const debugPanel = document.getElementById('debugPanel');
const closeDebugBtn = document.getElementById('closeDebugBtn');
const clearDebugBtn = document.getElementById('clearDebugBtn');
const exportDebugBtn = document.getElementById('exportDebugBtn');
const debugSearchInput = document.getElementById('debugSearchInput');
const debugLogContent = document.getElementById('debugLogContent');
const debugEntryCount = document.getElementById('debugEntryCount');
const debugLastUpdate = document.getElementById('debugLastUpdate');
const debugAutoScroll = document.getElementById('debugAutoScroll');
const debugWrapLines = document.getElementById('debugWrapLines');
const debugBadge = document.getElementById('debugBadge');
const debugLogContainer = document.getElementById('debugLogContainer');

const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
const sidebarExpandBtn = document.getElementById('sidebarExpandBtn');

// Test functions panel elements (moved from line 1362-1370)
// Note: toggleTestFunctionsBtn is already declared at line 1362
// Remove duplicate declarations below if they exist

// Debug log storage
let debugLogs = [];
let debugFilter = '';
const MAX_DEBUG_ENTRIES = 500;

let exportEnabled = false;
let lastScheduledRows = [];
let lastVariablesPayload = null;
let instanceDebugPayload = null;
let instanceDebugPromise = null;

const chatTaxar = document.getElementById('chatTaxar');
const chatTemperature = document.getElementById('chatTemperature');
const autoPauseStatus = document.getElementById('autoPauseStatus');

// ===== DEBUG PANEL FUNCTIONS =====

function addDebugLog(level, category, message, data = null) {
  const entry = {
    timestamp: new Date().toISOString(),
    level: level || 'info',
    category: category || 'general',
    message: message || '',
    data: data !== undefined ? data : null
  };
  
  debugLogs.unshift(entry);
  
  // Limit entries
  if (debugLogs.length > MAX_DEBUG_ENTRIES) {
    debugLogs = debugLogs.slice(0, MAX_DEBUG_ENTRIES);
  }
  
  renderDebugLogs();
  updateDebugBadge();
  
  return entry;
}

function updateDebugBadge() {
  if (!debugBadge) return;
  const count = debugLogs.length;
  if (count > 0) {
    debugBadge.textContent = count > 99 ? '99+' : count;
    debugBadge.classList.remove('hidden');
  } else {
    debugBadge.classList.add('hidden');
  }
}

function getDebugLevelColor(level) {
  const colors = {
    error: 'text-red-400',
    warn: 'text-yellow-400',
    info: 'text-blue-400',
    debug: 'text-slate-400',
    success: 'text-green-400'
  };
  return colors[level] || 'text-slate-400';
}

function getDebugLevelIcon(level) {
  const icons = {
    error: '✖',
    warn: '⚠',
    info: 'ℹ',
    debug: '◆',
    success: '✓'
  };
  return icons[level] || '◆';
}

function renderDebugLogs() {
  if (!debugLogContent) return;
  
  const filtered = debugFilter 
    ? debugLogs.filter(entry => {
        const searchStr = debugFilter.toLowerCase();
        return (
          entry.message.toLowerCase().includes(searchStr) ||
          entry.category.toLowerCase().includes(searchStr) ||
          (entry.data && JSON.stringify(entry.data).toLowerCase().includes(searchStr))
        );
      })
    : debugLogs;
  
  if (filtered.length === 0) {
    debugLogContent.innerHTML = '<div class="text-slate-500 text-center py-4">Nenhum log correspondente</div>';
    if (debugEntryCount) debugEntryCount.textContent = '0';
    return;
  }
  
  const html = filtered.map(entry => {
    const time = new Date(entry.timestamp).toLocaleTimeString('pt-BR', {
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      timeZone: CLIENT_TIMEZONE
    });
    
    const levelColor = getDebugLevelColor(entry.level);
    const levelIcon = getDebugLevelIcon(entry.level);
    
    let dataHtml = '';
    if (entry.data !== null && entry.data !== undefined) {
      try {
        const dataStr = typeof entry.data === 'object' 
          ? JSON.stringify(entry.data, null, 2) 
          : String(entry.data);
        dataHtml = `<div class="text-slate-500 mt-1 whitespace-pre-wrap">${escapeHtml(dataStr)}</div>`;
      } catch {
        dataHtml = `<div class="text-slate-500 mt-1">${escapeHtml(String(entry.data))}</div>`;
      }
    }
    
    return `
      <div class="flex gap-2 py-1 hover:bg-slate-800 rounded px-2 -mx-2">
        <span class="text-slate-500 text-[10px] whitespace-nowrap">${time}</span>
        <span class="${levelColor} text-xs">${levelIcon}</span>
        <span class="text-purple-400 text-xs uppercase">[${escapeHtml(entry.category)}]</span>
        <div class="flex-1 min-w-0">
          <span class="text-slate-300">${escapeHtml(entry.message)}</span>
          ${dataHtml}
        </div>
      </div>
    `;
  }).join('');
  
  debugLogContent.innerHTML = html;
  
  if (debugEntryCount) {
    debugEntryCount.textContent = filtered.length;
  }
  
  if (debugLastUpdate) {
    debugLastUpdate.textContent = new Date().toLocaleTimeString('pt-BR', {
      hour: '2-digit',
      minute: '2-digit',
      timeZone: CLIENT_TIMEZONE
    });
  }
  
  // Auto-scroll if enabled
  if (debugAutoScroll?.checked && debugLogContainer) {
    debugLogContainer.scrollTop = 0;
  }
}

function clearDebugLogs() {
  debugLogs = [];
  renderDebugLogs();
  updateDebugBadge();
}

function parseExecutionTimestamp(value) {
  if (value === undefined || value === null || value === '') {
    return Date.now();
  }
  const numeric = Number(value);
  if (Number.isFinite(numeric)) {
    return numeric < 1e12 ? numeric * 1000 : numeric;
  }
  const parsed = Date.parse(value);
  return Number.isFinite(parsed) ? parsed : Date.now();
}

function formatExecutionTimestampForLog(value) {
  const timestampMs = parseExecutionTimestamp(value);
  return new Date(timestampMs).toLocaleString('pt-BR', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZone: CLIENT_TIMEZONE
  });
}

function stringifyForLog(value) {
  if (value === undefined || value === null) {
    return '—';
  }
  if (typeof value === 'object') {
    try {
      return JSON.stringify(value, null, 2);
    } catch {
      return String(value);
    }
  }
  return String(value);
}

function getFunctionExecutionTimelineForExport() {
  const timeline = [];
  if (selectedContact) {
    timeline.push(...collectCommandExecutions(selectedContact));
  }
  timeline.push(...collectSelfTestExecutions());
  return timeline.sort((a, b) => parseExecutionTimestamp(a.timestamp) - parseExecutionTimestamp(b.timestamp));
}

function exportDebugLogs() {
  if (debugLogs.length === 0) {
    return;
  }
  
  const filtered = debugFilter
    ? debugLogs.filter(entry => {
        const searchStr = debugFilter.toLowerCase();
        return (
          entry.message.toLowerCase().includes(searchStr) ||
          entry.category.toLowerCase().includes(searchStr)
        );
      })
    : debugLogs;
  
  const lines = [
    `Debug Log Export - ${new Date().toLocaleString('pt-BR')}`,
    `Instância: ${INSTANCE_ID}`,
    `Contato: ${selectedContact || 'Nenhum'}`,
    `Total entradas: ${filtered.length}`,
    '---',
    ''
  ];
  
  filtered.forEach(entry => {
    const time = new Date(entry.timestamp).toLocaleString('pt-BR');
    lines.push(`[${time}] [${entry.level.toUpperCase()}] [${entry.category}] ${entry.message}`);
    if (entry.data !== null && entry.data !== undefined) {
      lines.push(`  Data: ${typeof entry.data === 'object' ? JSON.stringify(entry.data, null, 2) : entry.data}`);
    }
    lines.push('');
  });

  const functionExecutions = getFunctionExecutionTimelineForExport();
  if (functionExecutions.length) {
    lines.push('---');
    lines.push('Execuções de funções (linha do tempo)');
    lines.push('');
    functionExecutions.forEach(exec => {
      const debug = exec.debug || {};
      const icon = exec.status === 'success' ? '✅' : '❌';
      const timestampLabel = formatExecutionTimestampForLog(exec.timestamp);
      lines.push(`[${timestampLabel}] ${icon} ${exec.type} • ${exec.code || '—'} • ${exec.message || 'sem mensagem'}`);
      lines.push(`  call_raw: ${stringifyForLog(debug.call_raw)}`);
      lines.push(`  call_sanitized: ${stringifyForLog(debug.call_sanitized)}`);
      const argsArray = Array.isArray(debug.parsed_args) ? debug.parsed_args : [];
      const argsText = argsArray.length
        ? argsArray.map(arg => stringifyForLog(arg)).join(', ')
        : 'sem argumentos';
      lines.push(`  parsed_args: ${argsText}`);
      lines.push(`  arg_count: ${String(debug.arg_count ?? 0)}`);
      lines.push(`  validation: ${stringifyForLog(debug.validation)}`);
      lines.push(`  context: ${stringifyForLog(debug.context)}`);
      lines.push(`  executor_raw: ${stringifyForLog(debug.executor_raw)}`);
      lines.push('');
    });
  }
  
  const content = lines.join('\n');
  const filename = `debug-log-${sanitizeForFilename(selectedContact || 'general')}-${Date.now()}.txt`;
  
  const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  setTimeout(() => {
    URL.revokeObjectURL(link.href);
    document.body.removeChild(link);
  }, 200);
  
  addDebugLog('info', 'export', `Logs exportados: ${filtered.length} entradas`);
}

function openDebugPanel() {
  if (!debugPanel) return;
  debugPanel.classList.remove('hidden');
  if (toggleDebugBtnText) {
    toggleDebugBtnText.textContent = 'Ocultar Debug';
  }
  addDebugLog('info', 'ui', 'Painel de debug aberto');
}

function closeDebugPanel() {
  if (!debugPanel) return;
  debugPanel.classList.add('hidden');
  if (toggleDebugBtnText) {
    toggleDebugBtnText.textContent = 'Debug';
  }
}

function toggleDebugPanel() {
  if (!debugPanel || !toggleDebugBtnText) return;
  if (debugPanel.classList.contains('hidden')) {
    openDebugPanel();
  } else {
    closeDebugPanel();
  }
}

// Initialize debug panel event listeners
if (toggleDebugBtn) {
  toggleDebugBtn.addEventListener('click', toggleDebugPanel);
}

if (closeDebugBtn) {
  closeDebugBtn.addEventListener('click', closeDebugPanel);
}

if (clearDebugBtn) {
  clearDebugBtn.addEventListener('click', () => {
    clearDebugLogs();
    addDebugLog('info', 'ui', 'Logs de debug limpos');
  });
}

if (exportDebugBtn) {
  exportDebugBtn.addEventListener('click', exportDebugLogs);
}

if (debugSearchInput) {
  debugSearchInput.addEventListener('input', (e) => {
    debugFilter = e.target.value.trim();
    renderDebugLogs();
  });
}

if (debugWrapLines) {
  debugWrapLines.addEventListener('change', (e) => {
    if (debugLogContent) {
      debugLogContent.classList.toggle('whitespace-pre-wrap', e.target.checked);
    }
  });
}

if (sidebarToggleBtn) {
  sidebarToggleBtn.addEventListener('click', () => {
    document.body.classList.toggle('sidebar-collapsed');
  });
}

if (sidebarExpandBtn) {
  sidebarExpandBtn.addEventListener('click', () => {
    document.body.classList.remove('sidebar-collapsed');
  });
}

// ===== FUNÇÕES DE TESTE PANEL =====
// Note: All test function panel elements are already declared above (lines 1361-1370)

function toggleTestFunctionsPanel() {
  if (!testFunctionsPanel || !testFunctionsChevron) return;
  const isHidden = testFunctionsPanel.classList.contains('hidden');
  if (isHidden) {
    testFunctionsPanel.classList.remove('hidden');
    testFunctionsChevron.classList.remove('rotate-180');
    // Hide dropdown when expanding panel
    if (functionsDropdown) functionsDropdown.classList.add('hidden');
    addDebugLog('info', 'test_functions', 'Painel de funções de teste aberto');
  } else {
    testFunctionsPanel.classList.add('hidden');
    testFunctionsChevron.classList.add('rotate-180');
    addDebugLog('info', 'test_functions', 'Painel de funções de teste fechado');
  }
}

if (toggleTestFunctionsBtn) {
  toggleTestFunctionsBtn.addEventListener('click', toggleTestFunctionsPanel);
}

// Functions dropdown handling
if (showFunctionsBtn && functionsDropdown) {
  showFunctionsBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    functionsDropdown.classList.toggle('hidden');
    // Expand panel if collapsed
    if (testFunctionsPanel && testFunctionsPanel.classList.contains('hidden')) {
      testFunctionsPanel.classList.remove('hidden');
      testFunctionsChevron.classList.remove('rotate-180');
    }
  });
}

if (closeFunctionsDropdown && functionsDropdown) {
  closeFunctionsDropdown.addEventListener('click', () => {
    functionsDropdown.classList.add('hidden');
  });
}

// Handle function selection from dropdown
if (functionsDropdown) {
  functionsDropdown.addEventListener('click', (e) => {
    const button = e.target.closest('[data-function]');
    if (button) {
      const funcCall = button.getAttribute('data-function');
      if (testFunctionInput) {
        testFunctionInput.value = funcCall;
        testFunctionInput.focus();
      }
      functionsDropdown.classList.add('hidden');
      addDebugLog('info', 'test_functions', `Função selecionada: ${funcCall}`);
    }
  });
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
  if (functionsDropdown && !functionsDropdown.classList.contains('hidden')) {
    if (!functionsDropdown.contains(e.target) && !e.target.closest('#showFunctionsBtn')) {
      functionsDropdown.classList.add('hidden');
    }
  }
});

async function runTestFunction() {
  if (!testFunctionInput || !testFunctionResult) return;
  
  const functionCall = testFunctionInput.value.trim();
  if (!functionCall) {
    testFunctionResult.textContent = 'Digite uma função para executar. Ex: dados("email")';
    testFunctionResult.classList.remove('hidden');
    return;
  }
  
  testFunctionResult.textContent = 'Executando...';
  testFunctionResult.classList.remove('hidden');
  
  addDebugLog('info', 'test_functions', `Executando: ${functionCall}`);
  
  try {
    const response = await fetchWithCreds(buildAjaxUrl({ ajax_test_function: '1' }), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ function_call: functionCall })
    });
    
    const data = await response.json().catch(() => null);
    
    if (response.ok && data?.ok) {
      const result = data.result || data;
      const resultText = typeof result === 'object' ? JSON.stringify(result, null, 2) : String(result);
      testFunctionResult.textContent = resultText;
      addDebugLog('success', 'test_functions', `Resultado: ${functionCall}`, result);
    } else {
      const errorMsg = data?.error || 'Erro ao executar função';
      testFunctionResult.textContent = `Erro: ${errorMsg}`;
      addDebugLog('error', 'test_functions', `Falha: ${functionCall}`, { error: errorMsg });
    }
  } catch (error) {
    testFunctionResult.textContent = `Erro: ${error.message}`;
    addDebugLog('error', 'test_functions', `Exceção: ${functionCall}`, { error: error.message });
  }
}

if (runTestFunctionBtn) {
  runTestFunctionBtn.addEventListener('click', runTestFunction);
}

if (testFunctionInput) {
  testFunctionInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
      runTestFunction();
    }
  });
}

// Log initialization
addDebugLog('info', 'system', 'Debug console inicializado', { instance: INSTANCE_ID });

function updateScheduleBadge(pendingCount = 0, sentCount = 0) {
    if (!scheduleBadge) {
        return;
    }
    const fragments = [];
    if (pendingCount > 0) {
        fragments.push(`<span class="text-success">(${pendingCount})</span>`);
    }
    if (sentCount > 0) {
        fragments.push(`<span class="text-orange-600">(${sentCount})</span>`);
    }
    if (fragments.length === 0) {
        scheduleBadge.classList.add('hidden');
        scheduleBadge.innerHTML = '';
        return;
    }
    scheduleBadge.classList.remove('hidden');
    scheduleBadge.innerHTML = fragments.join(' ');
}

function setToggleAgText(text) {
    if (toggleAgBtnText) {
        toggleAgBtnText.textContent = text;
    }
}

updateChatActions(false);
getInstanceDebugMetadata();

const scrollMessagesToBottom = () => {
    messagesArea.scrollTop = messagesArea.scrollHeight;
};

messagesArea.addEventListener('scroll', () => {
    const distanceToBottom = messagesArea.scrollHeight - messagesArea.scrollTop - messagesArea.clientHeight;
    shouldAutoScrollMessages = distanceToBottom <= CHAT_SCROLL_THRESHOLD;
});

function updateQueueIndicator(payload) {
    if (!chatQueueStatus) return;
    if (payload?.pending) {
        const seconds = Math.max(0, payload.remaining_seconds ?? 0);
        const count = payload.message_count || 1;
        const msgText = count > 1 ? `${count} mensagens recebidas. ` : '';
        chatQueueStatus.textContent = `${msgText}Aguardando ${seconds}s para responder...`;
        chatQueueStatus.classList.remove('hidden', 'text-slate-500');
        chatQueueStatus.classList.add('text-orange-600', 'font-medium', 'animate-pulse');
    } else {
        chatQueueStatus.textContent = '';
        chatQueueStatus.classList.add('hidden');
        chatQueueStatus.classList.remove('text-orange-600', 'font-medium', 'animate-pulse');
    }
}

function hideStatusBroadcastAlert() {
    statusBroadcastAlert?.classList.add('hidden');
    statusBroadcastText && (statusBroadcastText.textContent = '');
}

function renderStatusBroadcastAlert(notification) {
    if (!statusBroadcastAlert || !statusBroadcastText || !notification) {
        hideStatusBroadcastAlert();
        return;
    }
    const snippet = (notification.content || '').trim();
    const displaySnippet = snippet.length > 120 ? `${snippet.slice(0, 117)}...` : snippet || 'Nova atualização de status';
    const notificationDate = toDate(notification.timestamp);
    const timestamp = notificationDate ? notificationDate.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: CLIENT_TIMEZONE }) : '';
    statusBroadcastText.textContent = `Mensagem ${timestamp ? `(${timestamp}) ` : ''}do status ignorada: ${displaySnippet}`;
    statusBroadcastAlert.classList.remove('hidden');
}

async function pollStatusBroadcasts() {
    if (!statusBroadcastAlert) {
        return;
    }
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_status_notifications: '1' }));
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Falha ao buscar notificações do status');
        }
        const notifications = Array.isArray(data.notifications) ? data.notifications : [];
        const unread = notifications.filter(item => (item.id || 0) > lastStatusBroadcastId);
        if (unread.length) {
            const latest = unread.sort((a, b) => (a.id || 0) - (b.id || 0)).pop();
            lastStatusBroadcastId = latest.id || lastStatusBroadcastId;
            renderStatusBroadcastAlert(latest);
        }
    } catch (error) {
        console.error(logPrefix, 'pollStatusBroadcasts error', error);
    }
}

async function refreshMultiInputStatus(remoteJid) {
    if (!remoteJid) {
        updateQueueIndicator(null);
        return null;
    }

    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_multi_input: '1', remote: remoteJid }));
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Não foi possível verificar o status');
        }
        updateQueueIndicator(data);
        return data;
    } catch (error) {
        console.error(logPrefix, 'refreshMultiInputStatus error', error);
        updateQueueIndicator(null);
        return null;
    }
}

async function refreshAutoPauseStatus() {
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_auto_pause_status: '1' }));
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.error || 'Não foi possível verificar o status do auto pause');
        }
        updateAutoPauseIndicator(data);
        return data;
    } catch (error) {
        console.error(logPrefix, 'refreshAutoPauseStatus error', error);
        updateAutoPauseIndicator(null);
        return null;
    }
}

function updateAutoPauseIndicator(data) {
    if (!autoPauseStatus) return;
    if (!data || !data.enabled) {
        autoPauseStatus.classList.add('hidden');
        autoPauseStatus.textContent = '';
        return;
    }
    if (data.paused && data.remaining_seconds > 0) {
        const minutes = Math.floor(data.remaining_seconds / 60);
        const seconds = data.remaining_seconds % 60;
        const timeStr = minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
        autoPauseStatus.textContent = `Auto Pause ativo - ${timeStr} restantes`;
        autoPauseStatus.classList.remove('hidden');
    } else {
        autoPauseStatus.classList.add('hidden');
        autoPauseStatus.textContent = '';
    }
}

function stopMultiInputPolling() {
    if (multiInputPollTimer) {
        clearInterval(multiInputPollTimer);
        multiInputPollTimer = null;
    }
}

function startMultiInputPolling() {
    stopMultiInputPolling();
    if (!selectedContact) {
        updateQueueIndicator(null);
        return;
    }
    refreshMultiInputStatus(selectedContact);
    multiInputPollTimer = setInterval(() => {
        refreshMultiInputStatus(selectedContact);
    }, 1500);
}

// Utility helpers
const SPECIAL_REMOTE_DOMAINS = new Set(['g.us', 'broadcast', 'status@broadcast', 'lid']);

function ensureBrazilCountryCodeForDisplay(value) {
  if (!value) return '';
  const digits = String(value || '').replace(/\D/g, '');
  if (!digits) return '';
  if (digits.startsWith('55')) {
    return digits;
  }
  if (digits.length >= 10 && digits.length <= 11) {
    return `55${digits}`;
  }
  return digits;
}

function normalizeRemoteJidForDisplay(remoteJid) {
  const normalized = String(remoteJid || '').trim();
  if (!normalized) return '';
  const [localPart = '', domainPart = ''] = normalized.split('@');
  const lowerDomain = domainPart.toLowerCase();
  if (lowerDomain && SPECIAL_REMOTE_DOMAINS.has(lowerDomain)) {
    return `${localPart}@${lowerDomain}`;
  }
  const digits = (localPart || '').replace(/\D/g, '');
  if (!digits) {
    return remoteJid;
  }
  const normalizedDigits = ensureBrazilCountryCodeForDisplay(digits);
  return `${normalizedDigits}@s.whatsapp.net`;
}

function resolveKnownRemoteJid(remoteJid) {
  const normalized = String(remoteJid || '').trim();
  if (!normalized) return '';
  // First try direct match with remote_jid
  const direct = contacts.find(c => c.remote_jid === normalized);
  if (direct) return direct.remote_jid;
  const [localPart = ''] = normalized.split('@');
  const digits = localPart.replace(/\D/g, '');
  if (!digits) return normalized;
  const candidates = [
    `${digits}@lid`,
    `${digits}@s.whatsapp.net`
  ];
  for (const candidate of candidates) {
    const match = contacts.find(c => c.remote_jid === candidate);
    if (match) return match.remote_jid; // Return actual remote_jid from contacts
  }
  return normalized;
}

function formatRemoteJid(remoteJid) {
  if (!remoteJid) return 'Não definido';
  
  // LID is an internal/ephemeral identifier. Never infer phone from raw digits.
  if (remoteJid.includes('@lid')) {
    const contact = contacts.find(c => c.remote_jid === remoteJid);
    if (contact?.formatted_phone) {
      return contact.formatted_phone;
    }
    if (contact?.pn) {
      const pnDigits = ensureBrazilCountryCodeForDisplay(String(contact.pn || '').replace(/\D/g, ''));
      if (pnDigits) {
        return formatPhoneNumber(pnDigits) || contact.pn;
      }
    }
    return 'Contato sem telefone resolvido';
  }
  
  const digits = (remoteJid.split('@')[0] || '').replace(/\D/g, '');
  const normalized = ensureBrazilCountryCodeForDisplay(digits);
  if (!normalized) return remoteJid;
  
  // Use Brazilian phone formatter
  const formatted = formatBrazilianPhone(normalized);
  return formatted || remoteJid;
}

/**
 * Format Brazilian phone number to standard format
 * @param {string} phone - Phone number (with or without country code)
 * @returns {string} Formatted phone number
 */
function formatBrazilianPhone(phone) {
  if (!phone) return '';
  
  const digits = String(phone).replace(/\D/g, '');
  if (!digits || digits.length < 10) return phone;
  
  // Brazilian format: +55 XX XXXXX-XXXX or XX XXXXX-XXXX
  if (digits.startsWith('55') && digits.length >= 12) {
    const country = '55';
    const area = digits.slice(2, 4);
    const prefix = digits.slice(4, -4);
    const suffix = digits.slice(-4);
    return `${country} ${area} ${prefix}-${suffix}`;
  }
  
  // Generic format for other numbers
  if (digits.length >= 10) {
    const area = digits.slice(0, 2);
    const prefix = digits.slice(2, -4);
    const suffix = digits.slice(-4);
    return `${area} ${prefix}-${suffix}`;
  }
  
  return phone;
}

function getContactStatusLabel(contact) {
  if (!contact) return 'Não definido';
  const status = (contact.status_name || '').trim();
  if (status) return status;
  return formatRemoteJid(contact.display_remote_jid || contact.remote_jid || '');
}

function formatScheduledTime(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleString('pt-BR', scheduleTimeOptions);
}

async function fetchScheduledRows(remoteJid) {
  if (!remoteJid) {
    return [];
  }
  const response = await fetchWithCreds(buildAjaxUrl({ ajax_scheduled: '1', remote: remoteJid }));
  const data = await response.json();
  if (!response.ok || !data?.ok) {
    throw new Error(data?.error || 'Falha ao buscar agendamentos');
  }
  const rows = Array.isArray(data.schedules) ? data.schedules : [];
  lastScheduledRows = rows;
  return rows;
}

async function loadScheduledList(remoteJid) {
  if (!remoteJid || !scheduledPanel) {
    scheduledPanel?.classList.add('hidden');
    updateScheduleBadge(0, 0);
    showScheduleForm(false);
    return;
  }
  scheduledPanel.classList.remove('hidden');
  scheduledList.innerHTML = '<div class="text-xs text-slate-400">Carregando agendamentos...</div>';

  try {
    const rows = await fetchScheduledRows(remoteJid);
    renderScheduledList(rows);
  } catch (error) {
    console.error(logPrefix, 'loadScheduledList error', error);
    scheduledList.innerHTML = `<div class="text-xs text-error">Erro ao carregar agendamentos</div>`;
    scheduledSummary?.classList?.add('text-error');
    scheduledSummary.textContent = 'Erro ao listar agendamentos.';
    updateScheduleBadge(0, 0);
  }
}

function setScheduleFormDefaults() {
  if (!manualScheduleDate || !manualScheduleTime) return;
  const now = new Date();
  const future = new Date(now.getTime() + 5 * 60 * 1000);
  const pad = (value) => String(value).padStart(2, '0');
  manualScheduleDate.value = `${future.getFullYear()}-${pad(future.getMonth() + 1)}-${pad(future.getDate())}`;
  manualScheduleTime.value = `${pad(future.getHours())}:${pad(future.getMinutes())}`;
  if (manualScheduleStatus) {
    manualScheduleStatus.textContent = '';
  }
}

function showScheduleForm(show) {
  if (!scheduleFormContainer || !toggleScheduleFormBtn) return;
  const shouldShow = Boolean(show);
  scheduleFormContainer.classList.toggle('hidden', !shouldShow);
  toggleScheduleFormBtn.textContent = shouldShow ? 'Fechar' : '+ Agendar';
  if (shouldShow) {
    setScheduleFormDefaults();
  } else if (manualScheduleStatus) {
    manualScheduleStatus.textContent = '';
  }
}

async function handleManualScheduleSubmit(event) {
  event.preventDefault();
  if (!selectedContact) {
    if (manualScheduleStatus) {
      manualScheduleStatus.textContent = 'Selecione uma conversa primeiro.';
    }
    return;
  }
  if (!manualScheduleMessage || !manualScheduleDate || !manualScheduleTime) return;
  const message = manualScheduleMessage.value.trim();
  const date = manualScheduleDate.value;
  const time = manualScheduleTime.value;
  if (!message || !date || !time) {
    if (manualScheduleStatus) {
      manualScheduleStatus.textContent = 'Informe mensagem, data e hora.';
    }
    return;
  }
  const scheduledAt = new Date(`${date}T${time}:00`);
  if (Number.isNaN(scheduledAt.getTime())) {
    if (manualScheduleStatus) {
      manualScheduleStatus.textContent = 'Data/hora inválidas.';
    }
    return;
  }
  if (manualScheduleStatus) {
    manualScheduleStatus.textContent = 'Agendando...';
  }
  try {
    const payload = {
      remote_jid: selectedContact,
      message,
      scheduled_at: scheduledAt.toISOString()
    };
    const response = await fetchWithCreds(buildAjaxUrl({ ajax_schedule_create: '1' }), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await response.json();
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Falha ao criar agendamento');
    }
    if (manualScheduleStatus) {
      manualScheduleStatus.textContent = 'Agendamento criado!';
    }
    manualScheduleMessage.value = '';
    showScheduleForm(false);
    loadScheduledList(selectedContact);
  } catch (error) {
    console.error(logPrefix, 'manual schedule error', error);
    if (manualScheduleStatus) {
      manualScheduleStatus.textContent = `Erro: ${error.message}`;
    }
  }
}

function renderScheduledList(rows) {
  if (!rows || !rows.length) {
    scheduledList.innerHTML = '<div class="text-xs text-slate-400">Nenhum agendamento encontrado.</div>';
    scheduledSummary.textContent = 'Sem agendamentos para este contato.';
    renderDebugScheduledHint('Sem agendamentos para este contato.');
    updateScheduleBadge(0, 0);
    return;
  }
  const pendingCount = rows.filter(row => row.status === 'pending').length;
  const sentCount = rows.filter(row => row.status === 'sent').length;
  updateScheduleBadge(pendingCount, sentCount);
  scheduledSummary.textContent = `${rows.length} agendamento${rows.length > 1 ? 's' : ''} pendente${rows.length > 1 ? 's' : ''}.`;
  renderDebugScheduledHint(`${rows.length} agendamento${rows.length > 1 ? 's' : ''} pendente${rows.length > 1 ? 's' : ''}.`);
  scheduledList.innerHTML = rows.map(row => {
    const statusBadge = row.status === 'sent' ? 'text-success' : row.status === 'failed' ? 'text-error' : 'text-slate-500';
    const scheduledAt = formatScheduledTime(row.scheduled_at);
    return `
      <div class="flex items-start justify-between gap-3 border border-mid/60 rounded-2xl p-3 bg-slate-50">
        <div class="flex-1 min-w-0 space-y-1">
          <div class="text-[13px] font-semibold text-slate-700">${escapeHtml(row.message)}</div>
          <div class="text-[11px] text-slate-500 flex items-center gap-2">
            <span>${scheduledAt || 'Horário indefinido'}</span>
            <span class="${statusBadge}">${row.status || 'pendente'}</span>
          </div>
          <div class="text-[10px] text-slate-400 break-words">ID: ${escapeHtml(row.id ?? '—')}</div>
        </div>
        <button data-id="${row.id}" class="text-[11px] text-error font-semibold hover:underline delete-schedule-btn">Cancelar</button>
      </div>
    `;
  }).join('');
}

function renderDebugScheduledHint(message) {
    if (!debugScheduledHint) return;
    const trimmed = (message || '').trim();
    if (!trimmed) {
        debugScheduledHint.innerHTML = '';
        return;
    }
    const safeText = escapeHtml(trimmed);
    debugScheduledHint.innerHTML = safeText;
}

async function deleteSchedule(id) {
  if (!id) return;
  try {
    const response = await fetchWithCreds(buildAjaxUrl({ ajax_schedule_delete: '1', scheduled_id: id }), {
      method: 'POST'
    });
    const data = await response.json();
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Falha ao cancelar');
    }
    if (selectedContact) {
      loadScheduledList(selectedContact);
    }
  } catch (error) {
    console.error(logPrefix, 'deleteSchedule error', error);
    scheduledSummary.textContent = `Erro ao cancelar: ${error.message}`;
    scheduledSummary.classList.add('text-error');
  }
}

// ===== CONTACT IDENTITY FUNCTIONS =====

/**
 * Fetch contact identity info (pushName, formattedPhone, statusBio)
 * @param {string} remoteJid - The contact's remote JID
 * @returns {Promise<object|null>}
 */
async function fetchContactIdentity(remoteJid) {
    if (!remoteJid) return null;
    
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_contact_identity: '1', remote: remoteJid }));
        const data = await response.json().catch(() => null);
        
        if (response.ok && data?.ok) {
            return data;
        }
        return null;
    } catch (error) {
        console.error(logPrefix, 'fetchContactIdentity error', error);
        return null;
    }
}

/**
 * Update contact identity info in the database
 * @param {string} remoteJid - The contact's remote JID
 * @param {object} identityData - Object with pushName, formattedPhone, statusBio
 * @returns {Promise<boolean>}
 */
async function updateContactIdentity(remoteJid, identityData) {
    if (!remoteJid) return false;
    
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_update_identity: '1' }), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                remote: remoteJid,
                ...identityData
            })
        });
        
        const data = await response.json().catch(() => null);
        return response.ok && data?.ok;
    } catch (error) {
        console.error(logPrefix, 'updateContactIdentity error', error);
        return false;
    }
}

/**
 * Refresh and update contact identity from messages
 * @param {string} remoteJid - The contact's remote JID
 * @param {object} messageData - Message data that may contain identity info
 */
async function refreshContactIdentityFromMessage(remoteJid, messageData) {
    if (!remoteJid) return;
    
    // Extract identity info from message if available
    const identityData = {};
    
    // Try to get pushName from message
    if (messageData?.pushName) {
        identityData.pushName = messageData.pushName;
    }
    
    // Try to get status/bio from message
    if (messageData?.status) {
        identityData.statusBio = messageData.status;
    }
    
    // Only update if we have new data
    if (Object.keys(identityData).length > 0) {
        await updateContactIdentity(remoteJid, identityData);
    }
}

/**
 * Load and display contact identity in the header
 * @param {string} remoteJid - The contact's remote JID
 */
async function loadContactIdentityInHeader(remoteJid) {
    if (!remoteJid) return;
    
    const identityData = await fetchContactIdentity(remoteJid);
    
    if (identityData) {
        // Update the contacts list with identity info
        updateContactInList(remoteJid, identityData);
        
        // Update the chat header with identity info
        renderChatHeaderIdentity(identityData);
    }
}

/**
 * Update contact in the contacts list with identity data
 * @param {string} remoteJid - The contact's remote JID
 * @param {object} identityData - Identity data from API
 */
function updateContactInList(remoteJid, identityData) {
    const contactIndex = contacts.findIndex(c => c.remote_jid === remoteJid);
    if (contactIndex >= 0) {
      contacts[contactIndex] = {
        ...contacts[contactIndex],
        push_name: identityData.pushName || contacts[contactIndex].push_name,
        formatted_phone: identityData.formattedPhone || contacts[contactIndex].formatted_phone,
        status_bio: identityData.statusBio || contacts[contactIndex].status_bio,
        contact_name: identityData.contactName || contacts[contactIndex].contact_name
      };
      renderContacts();
    }
}

/**
 * Render contact identity info in the chat header
 * @param {object} identityData - Object with pushName, formattedPhone, statusBio
 */
function renderChatHeaderIdentity(identityData) {
    if (!identityData) return;
    
    const { pushName, formattedPhone, statusBio, contactName } = identityData;
    
    // Update chat contact name with pushName or contactName
    if (chatContactName && (pushName || contactName)) {
      chatContactName.textContent = pushName || contactName;
    }
    
    // Update chat status with identity info
    let statusText = [];
    
    if (formattedPhone) {
        statusText.push(formattedPhone);
    }
    
    if (statusBio) {
        statusText.push(`"${statusBio.substring(0, 50)}${statusBio.length > 50 ? '...' : ''}"`);
    }
    
    if (chatStatus && statusText.length > 0) {
        chatStatus.textContent = statusText.join(' • ');
    } else if (chatStatus && !pushName && !contactName) {
        // Fallback to formatted phone from remoteJid
        const contact = contacts.find(c => c.remote_jid === selectedContact);
        if (contact?.formatted_phone) {
          chatStatus.textContent = contact.formatted_phone;
        }
    }
}

// ===== API FUNCTIONS =====

// Load contacts from API
function mergeContacts(existing, incoming) {
    const known = new Set(existing.map(contact => contact.remote_jid));
    const merged = [...existing];
    incoming.forEach(contact => {
        if (!known.has(contact.remote_jid)) {
            merged.push(contact);
            known.add(contact.remote_jid);
        }
    });
    return merged;
}

function setContactsLoadingIndicator(visible) {
    if (!contactsList) return;
    if (!contactsLoadingRow) {
        contactsLoadingRow = document.createElement('div');
        contactsLoadingRow.id = 'contactsLoadingRow';
        contactsLoadingRow.className = 'p-3 text-center text-xs text-slate-400';
        contactsLoadingRow.textContent = 'Carregando mais conversas...';
    }
    if (visible) {
        if (!contactsList.contains(contactsLoadingRow)) {
            contactsList.appendChild(contactsLoadingRow);
        }
    } else if (contactsLoadingRow.parentNode) {
        contactsLoadingRow.parentNode.removeChild(contactsLoadingRow);
    }
}

async function loadContacts(options = {}) {
    const { reset = false } = options;
    if (contactsLoading) return;
    if (!contactsHasMore && !reset) return;
    console.log(logPrefix, 'loadContacts', { reset, offset: contactsOffset });
    contactsLoading = true;
    setContactsLoadingIndicator(true);
    try {
        if (reset) {
            contactsOffset = 0;
            contactsHasMore = true;
            contacts = [];
        }
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_chats: '1', limit: String(CONTACTS_PAGE_SIZE), offset: String(contactsOffset) }));
        const data = await response.json();
        console.log(logPrefix, 'loadContacts response', { ok: data.ok, hasChats: Array.isArray(data?.chats), hasConversations: Array.isArray(data?.conversations), chatsCount: data?.chats?.length, conversationsCount: data?.conversations?.length });
        
        if (data.ok) {
            // Support both 'chats' and 'conversations' keys
            const chatData = data.chats || data.conversations || [];
            if (!Array.isArray(chatData)) {
                console.error(logPrefix, 'loadContacts error: chats is not an array', { chatsType: typeof data.chats, conversationsType: typeof data.conversations });
                throw new Error('Invalid chats data format');
            }
            const visibleChatData = chatData.filter(contact => Number(contact?.message_count || 0) > 0);
            const batch = visibleChatData.map(contact => {
                const normalizedRemote = normalizeRemoteJidForDisplay(contact.remote_jid || '');
                return {
                    ...contact,
                    display_remote_jid: normalizedRemote || (contact.remote_jid || '')
                };
            });
            contacts = reset ? batch : mergeContacts(contacts, batch);
            const rawBatchCount = Number.isFinite(Number(data?.raw_count)) ? Number(data.raw_count) : chatData.length;
            contactsOffset += rawBatchCount;
            contactsHasMore = rawBatchCount === CONTACTS_PAGE_SIZE;
            console.log(logPrefix, 'loadContacts success', { total: contacts.length, batch: batch.length, rawBatchCount, hasMore: contactsHasMore });
            renderContacts();
            setContactsLoadingIndicator(contactsHasMore);
            if (reset && pendingInitialContact) {
                selectContactByRemote(pendingInitialContact);
                pendingInitialContact = null;
            }
        } else {
            throw new Error(data.error || 'Failed to load contacts');
        }
    } catch (error) {
        console.error(logPrefix, 'Error loading contacts:', error);
        contactsList.innerHTML = '<div class="p-4 text-center text-error">Erro ao carregar conversas</div>';
    } finally {
        contactsLoading = false;
        if (!contactsHasMore) {
            setContactsLoadingIndicator(false);
        }
    }
}

// Load messages for specific contact
async function loadMessages(remoteJid) {
    console.log(logPrefix, 'loadMessages', { remote: remoteJid });
    try {
        isLoading = true;
        chatStatus.textContent = 'Carregando mensagens...';
        
        const messagesResponse = await fetchWithCreds(buildAjaxUrl({ ajax_messages: '1', remote: remoteJid, limit: '0' }));
        const messagesData = await messagesResponse.json();

        const messageCountsResponse = await fetchWithCreds(buildAjaxUrl({ ajax_message_counts: '1', remote: remoteJid }));
        const messageCountsData = await messageCountsResponse.json();
        
        if (messagesData.ok && messageCountsData.ok) {
            const loadedMessages = Array.isArray(messagesData.messages) ? messagesData.messages : [];
            messages[remoteJid] = loadedMessages;
            let taxar = 0;
            if (messageCountsData.outboundCount > 0) {
                taxar = (messageCountsData.inboundCount / messageCountsData.outboundCount) * 100;
            }

            if (messagesData.contact_meta) {
                const mergedMeta = {
                    ...(selectedContactData || {}),
                    ...messagesData.contact_meta,
                    remote_jid: remoteJid,
                    taxar: taxar,
                    temperature: messagesData.contact_meta.temperature || 'warm' // Ensure temperature is set
                };
                selectedContactData = mergedMeta;
                const contactIndex = contacts.findIndex(c => c.remote_jid === remoteJid);
                if (contactIndex >= 0) {
                    contacts[contactIndex] = { ...contacts[contactIndex], ...messagesData.contact_meta, taxar: taxar, temperature: messagesData.contact_meta.temperature || 'warm' };
                    renderContacts();
                }
                renderChatHeaderAvatar(mergedMeta);
                renderChatHeaderDetails(); // Call the new rendering function
            }
            const selectedContactMeta = contacts.find(c => c.remote_jid === remoteJid) || null;
            const hasPersistedHistory = loadedMessages.length > 0;
            const hasFallbackPreview = selectedContactMeta && typeof selectedContactMeta.last_message === 'string' && selectedContactMeta.last_message.trim() !== '' && selectedContactMeta.last_message !== 'Nenhuma mensagem';
            console.log(logPrefix, 'loadMessages success', { remote: remoteJid, count: loadedMessages.length, taxar: taxar, temperature: selectedContactData.temperature });
            renderMessages(remoteJid);
            if (!hasPersistedHistory && hasFallbackPreview) {
                chatStatus.textContent = 'Histórico não persistido nesta instância';
            } else {
                chatStatus.textContent = `${loadedMessages.length} mensagens`;
            }
            
            // Load and display contact identity info
            loadContactIdentityInHeader(remoteJid);
        } else {
            throw new Error(messagesData.error || messageCountsData.error || 'Failed to load messages or message counts');
        }
    } catch (error) {
        console.error(logPrefix, 'Error loading messages:', error);
        chatStatus.textContent = 'Erro ao carregar mensagens';
    } finally {
        isLoading = false;
    }
}

// Check system health
async function checkSystemHealth() {
    console.log(logPrefix, 'checkSystemHealth');
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_health: '1' }));
        const data = await response.json();
        
        if (data.ok && data.status === 'connected') {
            updateSystemStatus('online', data.database);
            console.log(logPrefix, 'checkSystemHealth connected', data.database);
        } else {
            updateSystemStatus('error', null);
            console.log(logPrefix, 'checkSystemHealth unexpected status', data);
        }
    } catch (error) {
        console.error(logPrefix, 'checkSystemHealth error', error);
        updateSystemStatus('offline', null);
    }
}

// ===== UI RENDERING FUNCTIONS =====

// Render contacts list
function renderContacts() {
    console.log(logPrefix, 'renderContacts', { total: contacts.length, search: searchInput.value });
    const searchTerm = searchInput.value.toLowerCase();
    const filteredContacts = contacts.filter(contact => {
        if (!contact || !contact.remote_jid) {
            return false;
        }
        if (Number(contact.message_count || 0) <= 0) {
            return false;
        }
        if (isStatusBroadcastJid(contact.remote_jid)) {
            return false;
        }
        const target = (contact.remote_jid || '').toLowerCase();
        const statusLabel = (getContactStatusLabel(contact) || '').toLowerCase();
        const contactName = (contact.contact_name || '').toLowerCase();
        const statusName = (contact.status_name || '').toLowerCase();
        return (
            target.includes(searchTerm) ||
            statusLabel.includes(searchTerm) ||
            contactName.includes(searchTerm) ||
            statusName.includes(searchTerm)
        );
    });
    
    // Sort by timestamp (newest first), handle invalid dates
    filteredContacts.sort((a, b) => {
        const ta = toDate(a.last_timestamp)?.getTime();
        const tb = toDate(b.last_timestamp)?.getTime();
        
        // Handle invalid dates (NaN)
        const timeA = Number.isFinite(ta) ? ta : 0;
        const timeB = Number.isFinite(tb) ? tb : 0;
        
        return timeB - timeA;
    });
    
    if (filteredContacts.length === 0) {
        console.log(logPrefix, 'renderContacts none found', { searchTerm, totalContacts: contacts.length });
        contactsList.innerHTML = '<div class="p-4 text-center text-slate-500">Nenhuma conversa encontrada</div>';
        return;
    }
    
    contactsList.innerHTML = '';
    filteredContacts.forEach(contact => {
        const lastMessage = contact.last_message || 'Nenhuma mensagem';
        const rolePrefixMap = {
            user: 'Você: ',
            assistant: 'IA: ',
            system: ''
        };
        const lastRole = rolePrefixMap[contact.last_role] || 'IA: ';
        const contactDate = toDate(contact.last_timestamp);
        const time = contactDate ? contactDate.toLocaleTimeString('pt-BR', timeOptions) : '--:--';
        const statusLabel = getContactStatusLabel(contact);
        const statusNote = (contact.status_name || '').trim();
        const attrRemote = encodeAttrValue(contact.remote_jid);
        const attrLabel = encodeAttrValue(statusLabel);
        const contactName = (contact.contact_name || '').trim();
        const previewBase = lastMessage.substring(0, 40);
        const previewSuffix = lastMessage.length > 40 ? '...' : '';
        const previewText = `${lastRole}${previewBase}${previewSuffix}`;
        const subtitleText = contactName ? `${contactName} • ${previewText}` : previewText;

        const item = document.createElement('button');
        item.type = 'button';
        item.className = `contact-item w-full text-left p-4 cursor-pointer ${selectedContact === contact.remote_jid ? 'active' : ''}`;
        item.dataset.remote = attrRemote;
        item.dataset.label = attrLabel;
        if (statusNote) {
            item.title = `Recado: ${statusNote}`;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-center gap-3';

        const avatar = document.createElement('div');
        avatar.className = 'w-10 h-10 rounded-full bg-primary flex items-center justify-center text-white font-medium overflow-hidden';
        if (statusNote) {
            avatar.title = `Recado: ${statusNote}`;
        }
        if (contact.profile_picture) {
            const img = document.createElement('img');
            img.src = contact.profile_picture;
            img.alt = statusLabel;
            img.loading = 'lazy';
            img.className = 'w-full h-full object-cover';
            avatar.appendChild(img);
        } else {
            avatar.textContent = statusLabel.charAt(0).toUpperCase() || '';
        }

        const body = document.createElement('div');
        body.className = 'flex-1 min-w-0';

        const title = document.createElement('div');
        title.className = 'font-medium text-dark truncate';
        title.textContent = statusLabel;

        const subtitle = document.createElement('div');
        subtitle.className = 'text-sm text-slate-500 truncate';
        subtitle.textContent = subtitleText;

        body.appendChild(title);
        body.appendChild(subtitle);

        const timeEl = document.createElement('div');
        timeEl.className = 'text-xs text-slate-400';
        timeEl.textContent = time;

        wrapper.appendChild(avatar);
        wrapper.appendChild(body);
        wrapper.appendChild(timeEl);

        item.appendChild(wrapper);

        item.addEventListener('click', () => {
            selectContact(item.dataset.remote, item.dataset.label, item);
        });

        contactsList.appendChild(item);
    });
}

function selectContactByRemote(remoteJid) {
    if (!remoteJid) return;
    if (isStatusBroadcastJid(remoteJid)) {
        console.log(logPrefix, 'selectContactByRemote skipped status broadcast', remoteJid);
        return;
    }
    const resolved = resolveKnownRemoteJid(remoteJid);
    const contact = contacts.find(c => c.remote_jid === resolved);
    if (!contact) return;
    const label = getContactStatusLabel(contact);
    console.log(logPrefix, 'selectContactByRemote', { remote: resolved, label });
    setTimeout(() => {
        const element = contactsList.querySelector(`[data-remote="${encodeAttrValue(resolved)}"]`);
        selectContact(encodeAttrValue(resolved), encodeAttrValue(label), element);
    }, 0);
}

function renderChatHeaderAvatar(meta) {
    if (!chatContactAvatar) return;
    const statusLabel = getContactStatusLabel(meta || {});
    const statusNote = (meta?.status_name || '').trim();
    if (statusNote) {
        chatContactAvatar.title = `Recado: ${statusNote}`;
    } else {
        chatContactAvatar.removeAttribute('title');
    }
    if (meta?.profile_picture) {
        chatContactAvatar.innerHTML = `<img src="${escapeHtml(meta.profile_picture)}" alt="${escapeHtml(statusLabel)}" class="w-full h-full object-cover">`;
        return;
    }
    const initial = statusLabel.charAt(0).toUpperCase() || '';
    chatContactAvatar.textContent = initial;
}

function renderChatHeaderDetails() {
    if (!selectedContactData) {
        if (chatTaxar) chatTaxar.textContent = '--';
        if (chatTemperature) chatTemperature.textContent = '--';
        return;
    }

    const taxar = selectedContactData.taxar !== undefined ? selectedContactData.taxar.toFixed(2) : '--';
    const temperature = selectedContactData.temperature || '--';

    if (chatTaxar) chatTaxar.textContent = taxar;
    if (chatTemperature) chatTemperature.textContent = temperature;
}

function parseJsonRecursively(value, maxDepth = 3) {
    let current = value;
    for (let depth = 0; depth < maxDepth; depth += 1) {
        if (current === null || current === undefined) return null;
        if (typeof current === 'object') return current;
        if (typeof current !== 'string') return null;
        const trimmed = current.trim();
        if (!trimmed) return null;
        try {
            current = JSON.parse(trimmed);
        } catch {
            return null;
        }
    }
    return (current && typeof current === 'object') ? current : null;
}

function parseInlineCommandCalls(text) {
    if (!text || typeof text !== 'string') return [];
    const matches = [];
    const commandRegex = /\[\[([a-zA-Z_][a-zA-Z0-9_]*)\(([^)]*)\)\]\]/g;
    let match;
    while ((match = commandRegex.exec(text)) !== null) {
        const type = (match[1] || '').trim();
        const rawArgs = (match[2] || '').trim();
        if (!type) continue;
        const args = rawArgs ? [rawArgs] : [];
        matches.push({ type, args, result: null });
    }
    return matches;
}

function toNormalizedCommand(cmd) {
    if (!cmd || typeof cmd !== 'object') return null;
    const type = cmd.type || cmd.name || cmd.fn || cmd.command || cmd?.function?.name || 'função';
    let args = cmd.args ?? cmd.arguments ?? cmd.params ?? cmd.parsed_args ?? cmd.rawArgs ?? cmd?.function?.arguments ?? [];
    if (typeof args === 'string') {
        const parsedArgs = parseJsonRecursively(args, 2);
        args = parsedArgs ?? args;
    }
    const result = cmd.result
        ?? cmd.output
        ?? cmd.response
        ?? cmd.return
        ?? cmd.value
        ?? (cmd.success === false ? { ok: false, error: cmd.error || cmd.message || 'erro' } : null);
    return { ...cmd, type, args, result };
}

function extractCommandLines(metadata, messageContent = '') {
    const normalizedMeta = parseJsonRecursively(metadata, 4) || (typeof metadata === 'object' ? metadata : null);
    const buckets = [];
    if (normalizedMeta && typeof normalizedMeta === 'object') {
        buckets.push(normalizedMeta.commands);
        buckets.push(normalizedMeta.executedCommands);
        buckets.push(normalizedMeta.commandResults);
        buckets.push(normalizedMeta.results);
        buckets.push(normalizedMeta.result?.commands);
        buckets.push(normalizedMeta.data?.commands);
        buckets.push(normalizedMeta.payload?.commands);
        buckets.push(normalizedMeta.execution?.commands);
        buckets.push(normalizedMeta.debug?.commands);
        if (normalizedMeta.function_call || normalizedMeta.tool_call) {
            buckets.push([normalizedMeta.function_call || normalizedMeta.tool_call]);
        }
        if (Array.isArray(normalizedMeta.tool_calls)) {
            buckets.push(normalizedMeta.tool_calls);
        }
    }

    for (const bucket of buckets) {
        if (!Array.isArray(bucket) || bucket.length === 0) continue;
        const commands = bucket.map(toNormalizedCommand).filter(Boolean);
        if (commands.length) return commands;
    }

    return parseInlineCommandCalls(messageContent);
}

// Render messages for selected contact
function renderMessages(remoteJid) {
    const currentMessages = messages[remoteJid] || [];
    console.log(logPrefix, 'renderMessages', { remote: remoteJid, count: currentMessages.length });
    const contactMessages = [...currentMessages].sort((a, b) => {
        const ta = toDate(a.timestamp)?.getTime() ?? 0;
        const tb = toDate(b.timestamp)?.getTime() ?? 0;
        return ta - tb;
    });
    
    const previousScrollTop = messagesArea.scrollTop;
    const previousScrollHeight = messagesArea.scrollHeight;

    if (contactMessages.length === 0) {
        const contactMeta = contacts.find(contact => contact.remote_jid === remoteJid) || {};
        const fallbackLastMessage = (contactMeta.last_message || '').trim();
        const fallbackTimeDate = toDate(contactMeta.last_timestamp || null);
        const fallbackTime = fallbackTimeDate ? fallbackTimeDate.toLocaleString('pt-BR', dateTimeOptions) : '';
        const hasFallback = fallbackLastMessage !== '' && fallbackLastMessage !== 'Nenhuma mensagem';
        const fallbackInfo = hasFallback
            ? `<div class="mt-3 text-xs text-slate-500">Última atividade detectada: ${escapeHtml(fallbackLastMessage)}${fallbackTime ? ` (${escapeHtml(fallbackTime)})` : ''}</div>`
            : '';
        messagesArea.innerHTML = `
            <div class="text-center text-slate-500 py-8">
                <p>Nenhuma mensagem persistida para este contato</p>
                <p class="text-sm mt-2">Mensagens antigas podem não ter sido gravadas no histórico local.</p>
                ${fallbackInfo}
            </div>
        `;
        if (shouldAutoScrollMessages) {
            scrollMessagesToBottom();
        } else {
            messagesArea.scrollTop = Math.min(previousScrollTop, Math.max(0, messagesArea.scrollHeight - messagesArea.clientHeight));
        }
        return;
    }
    
    // Split messages by # or &&& to create multiple bubbles
    const flattenedMessages = [];
    contactMessages.forEach(msg => {
        const content = msg.content || '';
        const hasAmpersand = content.includes('&&&');
        const hasHash = content.includes('#');
        
        // Priority: Split by &&& first (for AI function commands), then by #
        if (hasAmpersand) {
            const parts = content.split('&&&').map(part => part.trim()).filter(part => part !== '');
            parts.forEach((part, index) => {
                flattenedMessages.push({
                    ...msg,
                    content: part,
                    isSplit: true,
                    splitIndex: index,
                    splitTotal: parts.length,
                    isFunctionCall: index > 0 // First part is visible message, rest are function calls
                });
            });
        } else if (hasHash) {
            const parts = content.split('#').map(part => part.trim()).filter(part => part !== '');
            parts.forEach((part, index) => {
                flattenedMessages.push({
                    ...msg,
                    content: part,
                    isSplit: true,
                    splitIndex: index,
                    splitTotal: parts.length
                });
            });
        } else {
            flattenedMessages.push(msg);
        }
    });
    
    messagesArea.innerHTML = flattenedMessages.map((msg, msgIndex) => {
        // DEBUG: Log AI message content for debugging &&& separator
        const rawContent = msg.content || '';
        const hasAmpersandSeparator = rawContent.includes('&&&');
        const msgMetadata = msg.metadata;
        
        if (msg.role === 'assistant' || msg.direction === 'outbound') {
            console.log('[AI DEBUG] Raw message content:', rawContent);
            console.log('[AI DEBUG] Contains &&& separator:', hasAmpersandSeparator);
            console.log('[AI DEBUG] Message metadata:', msgMetadata);
        }
        
        const direction = msg.direction || (msg.role === 'assistant' ? 'outbound' : 'inbound');
        const isOutgoing = direction === 'outbound';
        const metadata = parseMetadata(msg.metadata);
        const isDebug = metadata?.debug === true;
        const isErrorMessage = metadata?.severity === 'error';
        const errorText = metadata?.error;
        const msgDate = toDate(msg.timestamp);
        const time = msgDate ? msgDate.toLocaleTimeString('pt-BR', { 
            hour: '2-digit', 
            minute: '2-digit',
            timeZone: CLIENT_TIMEZONE
        }) : '--:--';
        const errorDetails = errorText ? `<div class="text-[10px] ${isErrorMessage ? 'text-error' : 'text-slate-500'} mt-1">Erro: ${escapeHtml(errorText)}</div>` : '';
        
        const commandLines = extractCommandLines(metadata, msg.content || '');
        const commandSection = commandLines.length ? `
            <div class="message-bubble message-function p-3 mt-1 ${isOutgoing ? 'ml-auto' : 'mr-auto'}">
                <div class="text-xs font-semibold uppercase tracking-wide opacity-75">Funções executadas</div>
                <div class="mt-2 space-y-2">
                ${commandLines.map(cmd => {
                    const rawArgs = cmd?.args;
                    const argsList = Array.isArray(rawArgs)
                        ? rawArgs
                        : (rawArgs && typeof rawArgs === 'object')
                            ? Object.entries(rawArgs).map(([key, value]) => `${key}=${value === undefined || value === null ? '' : value}`)
                            : (typeof rawArgs === 'string' && rawArgs.trim() ? [rawArgs.trim()] : []);
                    const argsText = argsList.map(arg => escapeHtml(String(arg || ''))).filter(Boolean).join(', ');
                    const displayArgs = argsText || 'sem argumentos';
                    const resultText = formatCommandResultRaw(cmd);
                    const resultLine = resultText ? `<div class="text-[11px] text-orange-800/80 mt-1">Retorno: ${escapeHtml(resultText)}</div>` : '';
                    return `
                    <div class="px-3 py-2 rounded-2xl border border-orange-200 bg-orange-50 text-[12px] text-orange-900">
                        Função <span class="font-semibold text-orange-800">${escapeHtml(cmd.type || 'função')}()</span>
                        <div class="text-[11px] text-orange-800/80 mt-1">Parâmetros: ${displayArgs}</div>
                        ${resultLine}
                    </div>
                    `;
                }).join('')}
                </div>
            </div>
        ` : '';

        // Check if this is a function call split by &&&
        const isFunctionCallPart = msg.isFunctionCall === true;
        
        const bubbleClasses = [
            isOutgoing ? 'message-outgoing' : 'message-incoming',
            isDebug ? 'message-debug' : '',
            isErrorMessage ? 'message-error' : '',
            isFunctionCallPart ? 'message-function' : ''  // Different color for function calls
        ].filter(Boolean).join(' ');

        // Meta API metadata
        const metaStatus = metadata?.meta_status;
        let statusIndicator = '';
        if (metaStatus) {
            const statusConfig = {
                'sent': { text: 'Enviada', color: 'text-blue-600' },
                'delivered': { text: 'Entregue', color: 'text-green-600' },
                'read': { text: 'Lida', color: 'text-purple-600' },
                'failed': { text: 'Falha', color: 'text-red-600' }
            };
            const config = statusConfig[metaStatus] || { text: metaStatus, color: 'text-slate-500' };
            statusIndicator = `<div class="text-[10px] ${config.color} mt-1">${config.text}</div>`;
        }

        // Function call indicator
        const functionCallIndicator = isFunctionCallPart ? 
            `<div class="text-[10px] text-amber-600 mt-1 font-semibold">⚡ Função</div>` : '';

        // Get function result from metadata if this is a function call
        let functionResultHtml = '';
        if (isFunctionCallPart && metadata?.commands?.length > 0) {
            const cmd = metadata.commands[0];
            if (cmd?.result) {
                let resultText = '';
                if (typeof cmd.result === 'object') {
                    if (cmd.result.ok === false) {
                        resultText = cmd.result.error || JSON.stringify(cmd.result).substring(0, 150);
                    } else {
                        resultText = JSON.stringify(cmd.result).substring(0, 150);
                    }
                } else {
                    resultText = String(cmd.result).substring(0, 150);
                }
                const isError = cmd.result && typeof cmd.result === 'object' && cmd.result.ok === false;
                const resultClass = isError ? 'text-red-700' : 'text-green-700';
                const resultIcon = isError ? '⚠️' : '✓';
                functionResultHtml = `<div class="text-[11px] ${resultClass} mt-2 pt-2 border-t border-amber-200/50">${resultIcon} Retorno: ${escapeHtml(resultText)}</div>`;
            }
        }

        return `
            <div class="flex ${isOutgoing ? 'justify-end' : 'justify-start'} flex-col">
                <div class="message-bubble ${bubbleClasses} p-3">
                    <div class="text-sm">${escapeHtml(msg.content)}</div>
                    <div class="text-xs mt-1 opacity-70">${time}</div>
                    ${errorDetails}
                    ${statusIndicator}
                    ${functionCallIndicator}
                    ${functionResultHtml}
                    <div class="message-debug-icon" data-msg-index="${msgIndex}" title="Debug">🔍</div>
                </div>
                ${commandSection}
            </div>
        `;
    }).join('');
    
    // Scroll to bottom so newest messages show at end
    const maxScrollTop = Math.max(0, messagesArea.scrollHeight - messagesArea.clientHeight);
    if (shouldAutoScrollMessages) {
        scrollMessagesToBottom();
    } else {
        messagesArea.scrollTop = Math.min(previousScrollTop, maxScrollTop);
    }
    updateChatActions(Boolean(selectedContact));
}

function prettyJson(value) {
    if (value === undefined || value === null) {
        return '—';
    }
    try {
        if (typeof value === 'string') {
            return escapeHtml(value);
        }
        return escapeHtml(JSON.stringify(value, null, 2));
    } catch {
        return escapeHtml(String(value));
    }
}

function collectCommandExecutions(remoteJid) {
    if (!remoteJid) {
        return [];
    }
    const contactMessages = Array.isArray(messages[remoteJid]) ? messages[remoteJid] : [];
    const executions = [];
    contactMessages.forEach(msg => {
        const metadata = parseMetadata(msg.metadata);
        const commandList = extractCommandLines(metadata, msg.content || '');
        commandList.forEach(cmd => {
            const result = cmd?.result;
            if (!result) return;
            const data = result.data && typeof result.data === 'object' ? result.data : {};
            const debug = {
                call_raw: data.call_raw || cmd.call_raw || `${cmd.type || 'função'}()`,
                call_sanitized: data.call_sanitized || '',
                parsed_args: Array.isArray(data.parsed_args) ? data.parsed_args : (Array.isArray(cmd.args) ? cmd.args : (cmd.args ? [cmd.args] : [])),
                arg_count: data.arg_count ?? (Array.isArray(cmd.args) ? cmd.args.length : (cmd.args ? 1 : 0)),
                validation: data.validation || {},
                context: data.context || {},
                executor_raw: data.executor_raw || null
            };
            executions.push({
                type: cmd.type || 'função',
                status: result.ok ? 'success' : 'error',
                code: result.code || '',
                message: result.message || '',
                debug,
                timestamp: msg.timestamp || ''
            });
        });
    });
    return executions;
}

function collectSelfTestExecutions() {
    if (!SELF_TEST_MODE || !SELF_TEST_RESULTS.length) {
        return [];
    }
    const executions = [];
    SELF_TEST_RESULTS.forEach(test => {
        const response = test.response || {};
        const commands = response && response.result && Array.isArray(response.result.commands) ? response.result.commands : [];
        if (commands.length) {
            commands.forEach(cmd => {
                const result = cmd.result || {};
                const data = result.data && typeof result.data === 'object' ? result.data : {};
                const debug = {
                    call_raw: data.call_raw || cmd.call_raw || test.call || `${cmd.type || 'função'}()`,
                    call_sanitized: data.call_sanitized || test.call || '',
                    parsed_args: Array.isArray(data.parsed_args) ? data.parsed_args : (Array.isArray(cmd.args) ? cmd.args : []),
                    arg_count: data.arg_count ?? (Array.isArray(cmd.args) ? cmd.args.length : 0),
                    validation: data.validation || {},
                    context: data.context || {},
                    executor_raw: data.executor_raw || response
                };
                executions.push({
                    type: cmd.type || test.label || 'função',
                    status: result.ok ? 'success' : 'error',
                    code: result.code || response.error || '',
                    message: result.message || response.error || '',
                    debug,
                    timestamp: response.timestamp || '',
                    testLabel: test.label
                });
            });
        } else {
            executions.push({
                type: test.label || 'Auto-teste',
                status: response.ok ? 'success' : 'error',
                code: response.error || 'ERR_TEST',
                message: response.error || 'Resultado indefinido',
                debug: {
                    call_raw: test.call,
                    call_sanitized: test.call,
                    parsed_args: [],
                    arg_count: 0,
                    validation: {},
                    context: {},
                    executor_raw: response
                },
                timestamp: response.timestamp || '',
                testLabel: test.label
            });
        }
    });
    return executions;
}

function updateChatActions(active) {
    [contactDetailsBtn, clearChatBtn, deleteChatBtn].forEach(btn => {
        if (btn) btn.disabled = !active;
    });
    const hasMessages = active && selectedContact && Array.isArray(messages[selectedContact]) && messages[selectedContact].length > 0;
    setExportControls(hasMessages);
}

function setExportControls(enabled) {
    exportEnabled = Boolean(enabled);
}

function getInstanceDebugMetadata() {
    if (!instanceDebugPromise) {
        instanceDebugPromise = (async () => {
            try {
                const response = await fetchWithCreds(buildAjaxUrl({ ajax_instance_debug: '1' }));
                const payload = await response.json().catch(() => null);
                if (response.ok && payload?.ok) {
                    instanceDebugPayload = payload;
                } else {
                    instanceDebugPayload = { error: payload?.error || 'Falha ao carregar metadados da instância' };
                }
            } catch (error) {
                instanceDebugPayload = { error: error.message };
            }
            return instanceDebugPayload;
        })();
    }
    return instanceDebugPromise;
}

function formatTimestamp(value) {
    if (!value) return '';
    const date = toDate(value);
    if (!date) return '';
    return date.toLocaleTimeString('pt-BR', timeOptions);
}

function formatDateTime(value) {
    if (!value) return '';
    const date = toDate(value);
    if (!date) return '';
    return date.toLocaleString('pt-BR', dateTimeOptions);
}

function formatCommandResult(cmd) {
    if (!cmd || !cmd.result) return null;
    const type = (cmd.type || 'função').toLowerCase();
    if (type === 'dados') {
        const name = cmd.result.nome || cmd.result.email || 'Cliente';
        const status = cmd.result.status || 'sem status';
        const info = cmd.result.assinatura_info ? ` • ${cmd.result.assinatura_info}` : '';
        return `${type} → ${name} está ${status}${info}`;
    }
    if (type === 'mail') {
        return formatCommandResultRaw(cmd) || `${type} → email processado`;
    }
    if (type === 'agendar') {
        const when = formatDateTime(cmd.result.scheduledAt || cmd.result.scheduled_at || cmd.result.scheduledAt || cmd.result.scheduled_at);
        return `${type} → mensagem programada para ${when || 'horário indefinido'}`;
    }
    if (typeof cmd.result === 'string') {
        return `${type} → ${cmd.result}`;
    }
    try {
        return `${type} → ${JSON.stringify(cmd.result)}`;
    } catch {
        return `${type} → resultado indisponível`;
    }
}

function formatCommandResultRaw(cmd) {
    if (!cmd || cmd.result === undefined || cmd.result === null) return null;
    if (typeof cmd.result === 'string') {
        return cmd.result;
    }
    try {
        return JSON.stringify(cmd.result);
    } catch {
        return String(cmd.result);
    }
}

function buildConversationDump(remoteJid, options = {}) {
    if (!remoteJid) return null;
    const storedMessages = Array.isArray(messages[remoteJid]) ? [...messages[remoteJid]] : [];
    if (!storedMessages.length) {
        return null;
    }
    const { aiConfig = null, instanceInfo = null, scheduledRows = [], variablesPayload = null } = options;
    const safeScheduledRows = Array.isArray(scheduledRows) ? scheduledRows : [];
    const sortedMessages = storedMessages.sort((a, b) => {
        const ta = toDate(a.timestamp)?.getTime() ?? 0;
        const tb = toDate(b.timestamp)?.getTime() ?? 0;
        return ta - tb;
    });
    const contactLabel = (selectedContactData?.status_name || selectedContactData?.contact_name || formatRemoteJid(remoteJid) || remoteJid).trim();
    const contactMeta = selectedContactData || contacts.find(c => c.remote_jid === remoteJid) || {};
    const contactMetaLines = [
        ['Nome do contato', contactMeta.contact_name],
        ['Status name', contactMeta.status_name],
        ['Última mensagem', contactMeta.last_message],
        ['Mensagem count', contactMeta.message_count],
        ['Foto de perfil', contactMeta.profile_picture],
        ['Data da última mensagem', formatDateTime(contactMeta.last_timestamp)]
    ];
    const lines = [
        `Conversa exportada: ${new Date().toLocaleString('pt-BR', dateTimeOptions)}`,
        `Instância: ${INSTANCE_ID}`,
        `Contato: ${contactLabel}`,
        `Remote JID: ${remoteJid}`
    ];
    contactMetaLines.forEach(([label, value]) => {
        if (value !== undefined && value !== null && String(value).trim() !== '') {
            lines.push(`${label}: ${String(value)}`);
        }
    });
    lines.push(`Mensagens registradas: ${sortedMessages.length}`);

    if (instanceInfo && Object.keys(instanceInfo).length) {
        lines.push('');
        lines.push('=== Informações da instância ===');
        if (instanceInfo.name) {
            lines.push(`Nome: ${instanceInfo.name}`);
        }
        if (instanceInfo.phone) {
            lines.push(`Telefone: ${instanceInfo.phone}`);
        }
        if (instanceInfo.port !== undefined && instanceInfo.port !== null) {
            lines.push(`Porta: ${instanceInfo.port}`);
        }
        if (instanceInfo.status) {
            lines.push(`Status: ${instanceInfo.status}`);
        }
        if (instanceInfo.connection_status) {
            lines.push(`Status de conexão: ${instanceInfo.connection_status}`);
        }
    }

    lines.push('');
    lines.push('=== Configurações da IA ===');
    if (aiConfig && Object.keys(aiConfig).length) {
        lines.push(`IA ativada: ${aiConfig.enabled ? 'sim' : 'não'}`);
        if (aiConfig.provider) {
            lines.push(`Provedor: ${aiConfig.provider}`);
        }
        lines.push(`Modelo principal: ${aiConfig.model || 'não definido'}`);
        if (aiConfig.model_fallback_1) {
            lines.push(`Modelo fallback 1: ${aiConfig.model_fallback_1}`);
        }
        if (aiConfig.model_fallback_2) {
            lines.push(`Modelo fallback 2: ${aiConfig.model_fallback_2}`);
        }
        if (aiConfig.openai_mode) {
            lines.push(`Modo OpenAI: ${aiConfig.openai_mode}`);
        }
        if (aiConfig.history_limit !== undefined && aiConfig.history_limit !== null) {
            lines.push(`Limite de histórico: ${aiConfig.history_limit}`);
        }
        if (aiConfig.temperature !== undefined && aiConfig.temperature !== null) {
            lines.push(`Temperatura: ${aiConfig.temperature}`);
        }
        if (aiConfig.max_tokens !== undefined && aiConfig.max_tokens !== null) {
            lines.push(`Tokens máximos: ${aiConfig.max_tokens}`);
        }
        if (aiConfig.multi_input_delay !== undefined && aiConfig.multi_input_delay !== null) {
            lines.push(`Delay multi-input: ${aiConfig.multi_input_delay}s`);
        }
        if (aiConfig.auto_pause_enabled !== undefined && aiConfig.auto_pause_minutes !== undefined) {
            lines.push(`Auto pause: ${aiConfig.auto_pause_enabled ? 'ativado' : 'desativado'} (${aiConfig.auto_pause_minutes} min)`);
        }
        if (aiConfig.gemini_instruction) {
            lines.push(`Gemini instruction: ${aiConfig.gemini_instruction}`);
        }
        if (aiConfig.openrouter_base_url) {
            lines.push(`OpenRouter URL: ${aiConfig.openrouter_base_url}`);
        }
        if (aiConfig.assistant_id) {
            lines.push(`Assistant ID: ${aiConfig.assistant_id}`);
        }
        if (aiConfig.system_prompt) {
            lines.push('');
            lines.push('Prompt do sistema:');
            lines.push(aiConfig.system_prompt);
        }
        if (aiConfig.assistant_prompt) {
            lines.push('');
            lines.push('Prompt do assistente:');
            lines.push(aiConfig.assistant_prompt);
        }
    } else {
        lines.push('Dados de IA indisponíveis para esta instância.');
    }

    const persistentVars = Array.isArray(variablesPayload?.persistent) ? variablesPayload.persistent : [];
    const contextVars = Array.isArray(variablesPayload?.context) ? variablesPayload.context : [];
    const tagVars = Array.isArray(variablesPayload?.tags) ? variablesPayload.tags : [];
    lines.push('');
    lines.push('=== Variáveis e contexto ===');
    if (persistentVars.length) {
        lines.push('Variáveis persistentes:');
        persistentVars.forEach(item => {
            const updatedAt = item.updated_at ? ` (atualizado: ${item.updated_at})` : '';
            lines.push(`- ${item.key}: ${item.value}${updatedAt}`);
        });
    } else {
        lines.push('- Nenhuma variável persistente registrada.');
    }
    if (contextVars.length) {
        lines.push('Contexto do contato:');
        contextVars.forEach(item => {
            const updatedAt = item.updated_at ? ` (atualizado: ${item.updated_at})` : '';
            lines.push(`- ${item.key}: ${item.value}${updatedAt}`);
        });
    } else {
        lines.push('- Nenhum contexto registrado.');
    }
    if (tagVars.length) {
        lines.push('Tags de agendamento:');
        tagVars.forEach(item => {
            lines.push(`- ${item.tag}: ${item.total || 0}`);
        });
    } else {
        lines.push('- Nenhuma tag de agendamento registrada.');
    }

    lines.push('');
    lines.push('=== Agendamentos associados ===');
    if (safeScheduledRows.length) {
        safeScheduledRows.forEach(row => {
            const when = formatScheduledTime(row.scheduled_at) || (row.scheduled_at || 'Horário indefinido');
            const statusLabel = row.status || 'pendente';
            lines.push(`- [${statusLabel.toUpperCase()}] ID: ${row.id || 'sem ID'} • ${when}`);
            if (row.tag) {
                lines.push(`  Tag: ${row.tag}`);
            }
            if (row.tipo) {
                lines.push(`  Tipo: ${row.tipo}`);
            }
            if (row.message) {
                lines.push(`  Mensagem: ${row.message}`);
            }
        });
    } else {
        lines.push('Nenhum agendamento encontrado para este contato.');
    }

    lines.push('---');

    sortedMessages.forEach(msg => {
        const timestamp = formatDateTime(msg.timestamp) || 'sem horário';
        const direction = (msg.direction || (msg.role === 'assistant' ? 'outbound' : 'inbound'));
        const directionLabel = direction === 'outbound' ? 'SAÍDA' : 'ENTRADA';
        lines.push(`[${timestamp}] [${directionLabel}] ${msg.role || 'desconhecido'}`);
        lines.push(msg.content || '');

        const metadata = parseMetadata(msg.metadata);
        const commandList = extractCommandLines(metadata, msg.content || '');
        if (commandList.length) {
            lines.push('  Funções executadas:');
            commandList.forEach(cmd => {
                const argsText = (Array.isArray(cmd.args) ? cmd.args : [])
                    .map(arg => (arg === undefined || arg === null) ? '' : String(arg))
                    .map(arg => arg.trim())
                    .filter(Boolean)
                    .join(', ');
                const argsLabel = argsText || 'sem argumentos';
                const commandName = (cmd.type || 'função').trim();
                lines.push(`    - ${commandName}(${argsLabel})`);
                const resultText = formatCommandResult(cmd);
                if (resultText) {
                    lines.push(`      Retorno: ${resultText}`);
                }
            });
        }

        const metaNotes = [];
        if (metadata) {
            if (metadata.severity) metaNotes.push(`severity=${metadata.severity}`);
            if (metadata.error) metaNotes.push(`error=${metadata.error}`);
            if (metadata.debug) metaNotes.push(`debug=true`);
        }
        if (metaNotes.length) {
            lines.push(`  Metadata: ${metaNotes.join(' | ')}`);
        }

        lines.push('');
    });

    // Add debug logs section
    if (debugLogs && debugLogs.length > 0) {
        lines.push('');
        lines.push('=== Debug Logs ===');
        lines.push(`Total de entradas: ${debugLogs.length}`);
        lines.push('---');
        
        // Sort debug logs by timestamp (newest first for display)
        const sortedDebugLogs = [...debugLogs].sort((a, b) => {
            const ta = new Date(a.timestamp).getTime() || 0;
            const tb = new Date(b.timestamp).getTime() || 0;
            return tb - ta;
        });
        
        sortedDebugLogs.forEach(entry => {
            const time = entry.timestamp ? new Date(entry.timestamp).toLocaleString('pt-BR', dateTimeOptions) : 'sem horário';
            const level = (entry.level || 'info').toUpperCase();
            const category = entry.category || 'general';
            const message = entry.message || '';
            
            lines.push(`[${time}] [${level}] [${category}] ${message}`);
            
            if (entry.data !== null && entry.data !== undefined) {
                try {
                    const dataStr = typeof entry.data === 'object' 
                        ? JSON.stringify(entry.data, null, 2) 
                        : String(entry.data);
                    lines.push(`  Dados: ${dataStr}`);
                } catch (e) {
                    lines.push(`  Dados: ${String(entry.data)}`);
                }
            }
            lines.push('');
        });
    }

    // Add diagnostic information section
    lines.push('');
    lines.push('=== Informações de Diagnóstico ===');
    lines.push(`Gerado em: ${new Date().toLocaleString('pt-BR', dateTimeOptions)}`);
    lines.push(`Instância: ${INSTANCE_ID}`);
    lines.push(`Contato: ${contactLabel}`);
    lines.push(`Remote JID: ${remoteJid}`);
    
    // Add instance connection status
    if (instanceInfo && instanceInfo.connection_status) {
        lines.push(`Status de conexão: ${instanceInfo.connection_status}`);
    }
    
    // Add browser/node info if available
    if (window.__nodeBrowserInfo) {
        lines.push('');
        lines.push('--- Informações do Node/Browser ---');
        if (window.__nodeBrowserInfo.browserName) {
            lines.push(`Browser: ${window.__nodeBrowserInfo.browserName}`);
        }
        if (window.__nodeBrowserInfo.userAgent) {
            lines.push(`User Agent: ${window.__nodeBrowserInfo.userAgent}`);
        }
    }
    
    // Add system health info if available
    if (window.__systemHealthData) {
        lines.push('');
        lines.push('--- Saúde do Sistema ---');
        lines.push(`Status: ${window.__systemHealthData.status || 'desconhecido'}`);
        if (window.__systemHealthData.database) {
            lines.push(`Banco de dados: ${window.__systemHealthData.database.fileSizeMB || 'N/A'}MB`);
            lines.push(`Total de mensagens: ${window.__systemHealthData.database.totalMessages || 'N/A'}`);
        }
    }
    
    // Add message statistics
    const inboundCount = sortedMessages.filter(m => m.direction === 'inbound' || m.role === 'user').length;
    const outboundCount = sortedMessages.filter(m => m.direction === 'outbound' || m.role === 'assistant').length;
    lines.push('');
    lines.push('--- Estatísticas da Conversa ---');
    lines.push(`Total de mensagens: ${sortedMessages.length}`);
    lines.push(`Mensagens de entrada (usuário): ${inboundCount}`);
    lines.push(`Mensagens de saída (bot): ${outboundCount}`);
    
    // Add function execution summary
    let totalFunctions = 0;
    sortedMessages.forEach(msg => {
        const metadata = parseMetadata(msg.metadata);
        totalFunctions += extractCommandLines(metadata, msg.content || '').length;
    });
    lines.push(`Total de funções executadas: ${totalFunctions}`);
    
    return lines.join('\n');
}

function sanitizeForFilename(value) {
    if (!value) return 'conversa';
    return value.replace(/[^a-z0-9-_]/gi, '_').slice(0, 64);
}

function downloadConversationDump(content, remoteJid) {
    if (!content) return;
    const headerName = selectedContactData?.status_name || selectedContactData?.contact_name || remoteJid || 'conversa';
    const filename = `conversa-${sanitizeForFilename(headerName)}-${Date.now()}.txt`;
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    setTimeout(() => {
        URL.revokeObjectURL(link.href);
        document.body.removeChild(link);
    }, 200);
}

async function handleExportConversation() {
    if (!exportEnabled || !selectedContact) {
        return false;
    }
    let scheduledRows = lastScheduledRows || [];
    let variablesPayload = lastVariablesPayload;
    try {
        scheduledRows = await fetchScheduledRows(selectedContact);
    } catch (error) {
        console.error(logPrefix, 'export scheduled fetch failed', error);
    }
    try {
        variablesPayload = await fetchVariablesForContact(selectedContact);
    } catch (error) {
        console.error(logPrefix, 'export variables fetch failed', error);
    }
    try {
        await getInstanceDebugMetadata();
    } catch (error) {
        console.error(logPrefix, 'instance debug metadata error', error);
    }
    
    // Collect diagnostic data for export
    await collectDiagnosticData();
    
    const dump = buildConversationDump(selectedContact, {
        aiConfig: instanceDebugPayload?.ai ?? null,
        instanceInfo: instanceDebugPayload?.instance ?? null,
        scheduledRows,
        variablesPayload
    });
    if (!dump) {
        chatStatus.textContent = 'Não há mensagens para exportar.';
        return false;
    }
    downloadConversationDump(dump, selectedContact);
    chatStatus.textContent = 'Exportação iniciada.';
    return true;
}

// Diagnostic data collection functions
let nodeBrowserInfo = null;
let systemHealthData = null;

function collectNodeBrowserInfo() {
    if (nodeBrowserInfo) return nodeBrowserInfo;
    
    nodeBrowserInfo = {
        browserName: getBrowserName(),
        userAgent: navigator.userAgent,
        platform: navigator.platform,
        language: navigator.language,
        screenResolution: `${window.screen.width}x${window.screen.height}`,
        timestamp: new Date().toISOString()
    };
    
    return nodeBrowserInfo;
}

function getBrowserName() {
    const ua = navigator.userAgent;
    let browser = 'Unknown';
    
    if (ua.indexOf('Firefox') > -1) {
        browser = 'Firefox';
    } else if (ua.indexOf('Chrome') > -1) {
        browser = 'Chrome';
    } else if (ua.indexOf('Safari') > -1) {
        browser = 'Safari';
    } else if (ua.indexOf('Edge') > -1 || ua.indexOf('Edg') > -1) {
        browser = 'Edge';
    } else if (ua.indexOf('MSIE') > -1 || ua.indexOf('Trident') > -1) {
        browser = 'Internet Explorer';
    }
    
    return browser;
}

async function collectSystemHealthData() {
    if (systemHealthData) return systemHealthData;
    
    systemHealthData = {
        status: 'checking',
        timestamp: new Date().toISOString(),
        database: null,
        memory: null
    };
    
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_health: '1' }));
        const data = await response.json().catch(() => null);
        
        if (data && data.ok) {
            systemHealthData.status = data.status || 'connected';
            systemHealthData.database = {
                fileSizeMB: data.database?.fileSizeMB || 0,
                totalMessages: data.database?.totalMessages || 0
            };
            systemHealthData.memory = {
                uptime: data.uptime || 0,
                whatsappConnected: data.whatsappConnected || false
            };
        } else {
            systemHealthData.status = 'error';
        }
    } catch (error) {
        console.error(logPrefix, 'collectSystemHealthData error', error);
        systemHealthData.status = 'offline';
    }
    
    return systemHealthData;
}

async function collectDiagnosticData() {
    // Collect browser/node info
    nodeBrowserInfo = collectNodeBrowserInfo();
    
    // Collect system health data
    systemHealthData = await collectSystemHealthData();
    
    // Expose to window for use in buildConversationDump
    window.__nodeBrowserInfo = nodeBrowserInfo;
    window.__systemHealthData = systemHealthData;
    
    return { nodeBrowserInfo, systemHealthData };
}

async function openContactDetails() {
    if (!selectedContact) return;
    const meta = selectedContactData || contacts.find(c => c.remote_jid === selectedContact) || {};
    const normalizedRemote = meta.display_remote_jid || normalizeRemoteJidForDisplay(meta.remote_jid);
    const formattedPhone = normalizedRemote ? formatRemoteJid(normalizedRemote) : '-';
    const rows = [
        ['Remote JID', normalizedRemote || '-'],
        ['Telefone formatado', formattedPhone || '-'],
        ['Nome do contato', meta.contact_name || meta.status_name || '-'],
        ['Status name', meta.status_name || '-'],
        ['Mensagens', meta.message_count ?? 0],
        ['Última mensagem', formatDateTime(meta.last_timestamp)]
    ];
    const statusLabel = getContactStatusLabel(meta);
    const contactSubtitle = (meta.contact_name || meta.status_name || formatRemoteJid(normalizedRemote) || '').trim() || 'Não definido';
    const avatarInner = meta.profile_picture
        ? `<img src="${escapeHtml(meta.profile_picture)}" alt="${escapeHtml(statusLabel)}" class="w-full h-full object-cover">`
        : `<span>${escapeHtml(statusLabel.charAt(0).toUpperCase() || '')}</span>`;
    const rowsMarkup = rows.map(([label, value]) => {
        const displayValue = (value === null || value === undefined || value === '') ? '-' : value;
        return `
        <div class="flex justify-between">
            <span class="font-medium text-slate-500">${label}</span>
            <span class="text-slate-700 text-right">${escapeHtml(String(displayValue))}</span>
        </div>
    `;
    }).join('');
    contactDetailsBody.innerHTML = `
        <div class="flex items-center gap-3 mb-4">
            <div class="w-14 h-14 rounded-full bg-mid/10 flex items-center justify-center text-2xl font-semibold text-primary overflow-hidden">
                ${avatarInner}
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-base font-semibold text-dark truncate">${escapeHtml(statusLabel)}</div>
                <div class="text-xs text-slate-500 truncate">${escapeHtml(contactSubtitle)}</div>
            </div>
        </div>
        <div class="space-y-2">
            ${rowsMarkup}
        </div>
    `;
    contactDetailsModal.classList.remove('hidden');
}

function renderVariablesSection(title, itemsMarkup) {
    return `
        <div class="rounded-2xl border border-mid bg-slate-50 p-3 space-y-2">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">${title}</div>
            ${itemsMarkup}
        </div>
    `;
}

function renderVariablesBody(payload) {
    lastVariablesPayload = payload;
    const persistent = Array.isArray(payload?.persistent) ? payload.persistent : [];
    const context = Array.isArray(payload?.context) ? payload.context : [];
    const tags = Array.isArray(payload?.tags) ? payload.tags : [];

    const renderList = (items, scope, valueKey = 'value') => {
        if (!items.length) {
            return '<div class="text-[11px] text-slate-400">Nenhum item encontrado.</div>';
        }
        return items.map(item => {
            const key = escapeHtml(String(item.key || item.tag || ''));
            const value = escapeHtml(String(item[valueKey] ?? item.total ?? ''));
            const display = value !== '' ? `${key}: ${value}` : key;
            const actionKey = escapeHtml(String(item.key || item.tag || ''));
            return `
                <div class="flex items-center justify-between gap-3 bg-white border border-mid rounded-xl px-3 py-2">
                    <div class="text-xs text-slate-700 truncate">${display}</div>
                    <button type="button"
                            class="text-[11px] text-error border border-error/60 rounded-full px-2 py-0.5 hover:bg-error/10"
                            data-action="delete-variable" data-scope="${scope}" data-key="${actionKey}">
                        Excluir
                    </button>
                </div>
            `;
        }).join('');
    };

    const persistentMarkup = renderVariablesSection('Variaveis da instância', renderList(persistent, 'persistent'));
    const contextMarkup = renderVariablesSection('Contexto do contato', renderList(context, 'context'));
    const tagsMarkup = renderVariablesSection('Tags de agendamentos', renderList(tags.map(item => ({
        key: item.tag || '',
        total: item.total || 0
    })), 'tag', 'total'));

    variablesBody.innerHTML = `
        <div class="space-y-4">
            ${persistentMarkup}
            ${contextMarkup}
            ${tagsMarkup}
        </div>
    `;
}

async function fetchVariablesForContact(remoteJid) {
    if (!remoteJid) {
        throw new Error('Remote JID é obrigatório');
    }
    const response = await fetchWithCreds(buildAjaxUrl({ ajax_variables: '1', remote: remoteJid }));
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload?.ok) {
        throw new Error(payload?.error || 'Erro ao carregar dados');
    }
    lastVariablesPayload = payload;
    return payload;
}

async function loadVariablesPanel() {
    if (!selectedContact) {
        variablesBody.innerHTML = '<p>Selecione uma conversa para ver os dados.</p>';
        lastVariablesPayload = null;
        return;
    }
    variablesStatus.textContent = 'Carregando...';
    try {
        const payload = await fetchVariablesForContact(selectedContact);
        renderVariablesBody(payload);
        variablesStatus.textContent = '';
    } catch (error) {
        variablesStatus.textContent = `Erro: ${error.message}`;
        variablesBody.innerHTML = '<p>Não foi possível carregar os dados.</p>';
    }
}

function openVariablesModal() {
    if (!variablesModal) return;
    variablesModal.classList.remove('hidden');
    loadVariablesPanel();
}

function closeVariablesModal() {
    variablesModal?.classList.add('hidden');
}

async function deleteVariable(scope, key) {
    if (!scope || !key || !selectedContact) {
        return;
    }
    const confirmText = scope === 'tag'
        ? `Remover todos os agendamentos com a tag "${key}"?`
        : `Excluir "${key}"?`;
    if (!confirm(confirmText)) {
        return;
    }
    variablesStatus.textContent = 'Excluindo...';
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_variables_delete: '1' }), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope, key, remote: selectedContact })
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) {
            throw new Error(payload?.error || 'Erro ao excluir');
        }
        variablesStatus.textContent = `Excluído (${payload.deleted || 0}).`;
        await loadVariablesPanel();
    } catch (error) {
        variablesStatus.textContent = `Erro: ${error.message}`;
    }
}

function closeContactDetails() {
    contactDetailsModal.classList.add('hidden');
}

function clearConversationUI() {
    if (!selectedContact) return;
    console.log(logPrefix, 'clearConversationUI', { remote: selectedContact });
    messages[selectedContact] = [];
    renderMessages(selectedContact);
    chatStatus.textContent = 'Conversa limpa';
    renderDebugScheduledHint('');
}

async function deleteConversation() {
    if (!selectedContact) return;
    if (!confirm('Tem certeza que deseja apagar todo o histórico desta conversa?')) return;
    try {
        console.log(logPrefix, 'deleteConversation start', { remote: selectedContact });
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_messages: '1', remote: selectedContact }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ action: 'delete' })
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload?.ok) {
            throw new Error(payload?.error || 'Erro ao apagar conversa');
        }
        console.log(logPrefix, 'deleteConversation success', payload);
        chatStatus.textContent = 'Histórico apagado';
        messages[selectedContact] = [];
        renderMessages(selectedContact);
        selectedContact = null;
        selectedContactData = null;
        updateChatActions(false);
        loadContacts({ reset: true });
        stopMultiInputPolling();
        if (debugConversationId) {
            debugConversationId.textContent = '—';
        }
        renderDebugScheduledHint('');
        loadVariablesPanel();
    } catch (error) {
        console.error('Error deleting conversation:', error);
        chatStatus.textContent = `Erro ao apagar conversa: ${error.message}`;
    }
}

// Update system status indicator
function updateSystemStatus(status, data) {
    const statusConfig = {
        online: { color: 'bg-success', text: 'Online', tooltip: data ? `SQLite Conectado | ${data.fileSizeMB}MB | ${data.totalMessages} mensagens` : 'Sistema operacional' },
        offline: { color: 'bg-error', text: 'Offline', tooltip: 'Sistema desconectado' },
        error: { color: 'bg-alert', text: 'Erro', tooltip: 'Erro no sistema' }
    };
    
    const config = statusConfig[status];
    systemStatusDot.className = `w-2 h-2 rounded-full ${config.color}`;
    systemStatusText.textContent = config.text;
    systemStatusTooltip.textContent = config.tooltip;
}

// ===== EVENT HANDLERS =====

// Select contact and load messages
function selectContact(remoteJid, contactName, element) {
    const decodedRemote = decodeURIComponent(remoteJid || '');
    const resolvedRemote = resolveKnownRemoteJid(decodedRemote);
    const decodedName = decodeURIComponent(contactName || '');
    if (isStatusBroadcastJid(resolvedRemote)) {
        chatStatus.textContent = 'Conversa do status não pode ser aberta.';
        updateChatActions(false);
        messageInput.disabled = true;
        sendBtn.disabled = true;
        if (element) {
            element.classList.remove('active');
        }
        return;
    }
    selectedContact = resolvedRemote;
    if (debugConversationId) {
        debugConversationId.textContent = resolvedRemote || '—';
    }
    // Update debug info remote JID
    const debugInfoRemoteJid = document.getElementById('debugInfoRemoteJid');
    if (debugInfoRemoteJid) {
        debugInfoRemoteJid.textContent = resolvedRemote || '—';
    }
        renderDebugScheduledHint('');
    selectedContactData = contacts.find(c => c.remote_jid === resolvedRemote) || null;
    // Clear previous variables to prevent data leak between contacts
    lastVariablesPayload = null;
    renderChatHeaderAvatar(selectedContactData);
    renderChatHeaderDetails(); // Call here to update details immediately
    updateChatActions(true);
    console.log(logPrefix, 'selectContact', { remote: resolvedRemote, name: decodedName || formatRemoteJid(resolvedRemote) });
    chatContactName.textContent = decodedName || formatRemoteJid(resolvedRemote);
    chatStatus.textContent = 'Carregando mensagens...';
    
    // Update UI
    document.querySelectorAll('.contact-item').forEach(item => {
        item.classList.remove('active');
    });
    if (element) {
        element.classList.add('active');
    }

    // Enable message input
    messageInput.disabled = false;
    sendBtn.disabled = false;

    // Load messages
    loadMessages(resolvedRemote).finally(() => loadScheduledList(resolvedRemote));
    startMultiInputPolling();
    loadVariablesPanel();
}

// Send message
async function sendMessage(e) {
    e.preventDefault();
    
    const message = messageInput.value.trim();
    if (!message || !selectedContact) return;
    console.log(logPrefix, 'sendMessage start', { to: selectedContact, message });
    
    // Add to UI immediately
    const tempMessage = {
        role: 'user',
        content: message,
        timestamp: new Date().toISOString()
    };
    
    if (!messages[selectedContact]) {
        messages[selectedContact] = [];
    }
    messages[selectedContact].push(tempMessage);
    renderMessages(selectedContact);
    
    // Clear input
    messageInput.value = '';
    chatStatus.textContent = 'Enviando mensagem...';
    messageInput.disabled = true;
    sendBtn.disabled = true;
    
    try {
        console.log(logPrefix, 'sendMessage fetch', buildAjaxUrl({ ajax_send: '1' }));
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_send: '1' }), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to: selectedContact,
                message: message
            })
        });
        
        const payload = await response.json().catch(() => null);
        
        if (!response.ok) {
            const errMsg = payload?.detail || payload?.error || response.statusText || 'Falha ao enviar mensagem';
            throw new Error(errMsg);
        }
        
        const successTarget = payload?.to || selectedContact;
        console.log(logPrefix, 'sendMessage success', payload);
        chatStatus.textContent = `Mensagem enviada para ${formatRemoteJid(successTarget)}`;
        
        // Reload messages to get the latest state
        setTimeout(() => loadMessages(selectedContact), 1000);
        
    } catch (error) {
        console.error(logPrefix, 'Error sending message:', error);
        chatStatus.textContent = `Erro ao enviar mensagem: ${error.message}`;
    } finally {
        messageInput.disabled = false;
        sendBtn.disabled = false;
    }
}

// Send audio message
async function sendAudioMessage(audioFile) {
    if (!audioFile || !selectedContact) return;
    
    chatStatus.textContent = 'Enviando áudio...';
    console.log(logPrefix, 'sendAudioMessage start', { to: selectedContact, file: audioFile.name });
    
    try {
        // First upload the audio file
        const formData = new FormData();
        formData.append('asset_file', audioFile);
        formData.append('csrf_token', window.CSRF_TOKEN || '');
        
        const uploadResponse = await fetchWithCreds('assets/upload_asset.php?instance=' + encodeURIComponent(currentInstanceId), {
            method: 'POST',
            body: formData
        });
        
        const uploadResult = await uploadResponse.json().catch(() => null);
        
        if (!uploadResponse.ok) {
            throw new Error(uploadResult?.error || 'Falha no upload do áudio');
        }
        
        const audioUrl = uploadResult.url;
        console.log(logPrefix, 'audio uploaded', audioUrl);
        
        // Then send the audio via the send-message endpoint
        const sendResponse = await fetchWithCreds(buildAjaxUrl({ ajax_send: '1' }), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                to: selectedContact,
                audio_url: audioUrl
            })
        });
        
        const payload = await sendResponse.json().catch(() => null);
        
        if (!sendResponse.ok) {
            const errMsg = payload?.detail || payload?.error || sendResponse.statusText || 'Falha ao enviar áudio';
            throw new Error(errMsg);
        }
        
        console.log(logPrefix, 'sendAudioMessage success', payload);
        chatStatus.textContent = `Áudio enviado para ${formatRemoteJid(selectedContact)}`;
        
        // Reload messages to get the latest state
        setTimeout(() => loadMessages(selectedContact), 1000);
        
    } catch (error) {
        console.error(logPrefix, 'Error sending audio:', error);
        chatStatus.textContent = `Erro ao enviar áudio: ${error.message}`;
    }
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function normalizeRemoteJid(value) {
    if (!value) return '';
    const trimmed = value.trim();
    if (trimmed.includes('@')) {
        return trimmed;
    }
    const digits = trimmed.replace(/\D/g, '');
    return digits ? `${digits}@s.whatsapp.net` : trimmed;
}

function openNewConversationModal() {
    if (newConversationModal) {
        newConversationStatus.textContent = '';
        newConversationForm?.reset();
        newConversationModal.classList.remove('hidden');
        newConversationPhone?.focus();
    }
}

function closeNewConversationModal() {
    if (newConversationModal) {
        newConversationModal.classList.add('hidden');
    }
}

async function handleNewConversationSubmit(event) {
    event.preventDefault();
    const rawPhone = newConversationPhone?.value.trim();
    const message = newConversationMessage?.value.trim();
    if (!rawPhone || !message) {
        newConversationStatus.textContent = 'Telefone e mensagem são obrigatórios';
        return;
    }
    const remoteJid = normalizeRemoteJid(rawPhone);
    if (!remoteJid) {
        newConversationStatus.textContent = 'Telefone inválido';
        return;
    }
    const targetLabel = formatRemoteJid(remoteJid);
    newConversationStatus.textContent = 'Enviando...';
    newConversationStatus.className = 'text-xs text-slate-500';

    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_send: '1' }), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ to: remoteJid, message })
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            throw new Error(payload?.detail || payload?.error || 'Erro ao enviar');
        }

        newConversationStatus.textContent = `Mensagem enviada para ${targetLabel}`;
        newConversationStatus.className = 'text-xs text-success';

        messages[remoteJid] = messages[remoteJid] || [];
        messages[remoteJid].push({
            role: 'user',
            direction: 'outbound',
            content: message,
            timestamp: new Date().toISOString()
        });

        selectedContact = remoteJid;
        selectedContactData = {
            remote_jid: remoteJid,
            status_name: targetLabel,
            contact_name: targetLabel,
            profile_picture: null
        };
        // Clear previous contact variables to prevent data leak
        lastVariablesPayload = null;
        chatContactName.textContent = targetLabel;
        chatStatus.textContent = 'Mensagem enviada';
        updateChatActions(true);
        renderMessages(remoteJid);
        loadContacts({ reset: true });
        closeNewConversationModal();
        startMultiInputPolling();
        loadVariablesPanel();
    } catch (error) {
        console.error(logPrefix, 'New conversation error', error);
        newConversationStatus.textContent = `Erro: ${error.message}`;
        newConversationStatus.className = 'text-xs text-error';
    }
}

function parseMetadata(raw) {
    if (!raw) return null;
    if (typeof raw === 'object') return raw;
    return parseJsonRecursively(raw, 4);
}

// ===== INITIALIZATION =====

// Event listeners
searchInput.addEventListener('input', renderContacts);
if (contactsList) {
    contactsList.addEventListener('scroll', () => {
        const threshold = 120;
        const distanceToBottom = contactsList.scrollHeight - contactsList.scrollTop - contactsList.clientHeight;
        if (distanceToBottom <= threshold) {
            loadContacts();
        }
    });
}
messageForm.addEventListener('submit', sendMessage);

// Audio button handler
const audioBtn = document.getElementById('audioBtn');
const audioInput = document.getElementById('audioInput');
if (audioBtn && audioInput) {
    audioBtn.addEventListener('click', () => {
        audioInput.click();
    });
    audioInput.addEventListener('change', async (e) => {
        const file = e.target.files?.[0];
        if (file) {
            // Check if it's an audio file
            if (!file.type.startsWith('audio/')) {
                chatStatus.textContent = 'Por favor, selecione um arquivo de áudio';
                return;
            }
            await sendAudioMessage(file);
            audioInput.value = ''; // Reset input
        }
    });
}

refreshBtn.addEventListener('click', () => {
    loadContacts({ reset: true });
    if (selectedContact) {
        loadMessages(selectedContact);
    }
});
if (refreshScheduleBtn) {
    refreshScheduleBtn.addEventListener('click', () => {
        if (selectedContact) {
            loadScheduledList(selectedContact);
        }
    });
}
if (statusBroadcastClose) {
    statusBroadcastClose.addEventListener('click', () => {
        hideStatusBroadcastAlert();
    });
}
document.addEventListener('click', (event) => {
    const action = event.target.closest('[data-id].delete-schedule-btn');
    if (action) {
        const id = action.getAttribute('data-id');
        deleteSchedule(id);
    }
});
if (contactDetailsBtn) {
    contactDetailsBtn.addEventListener('click', openContactDetails);
}
if (contactDetailsClose) {
    contactDetailsClose.addEventListener('click', closeContactDetails);
}
if (manageVariablesBtn) {
    manageVariablesBtn.addEventListener('click', openVariablesModal);
}
if (variablesClose) {
    variablesClose.addEventListener('click', closeVariablesModal);
}
if (variablesRefresh) {
    variablesRefresh.addEventListener('click', loadVariablesPanel);
}
if (variablesBody) {
    variablesBody.addEventListener('click', (event) => {
        const target = event.target.closest('[data-action="delete-variable"]');
        if (!target) return;
        const scope = target.getAttribute('data-scope');
        const key = target.getAttribute('data-key');
        deleteVariable(scope, key);
    });
}
if (clearChatBtn) {
    clearChatBtn.addEventListener('click', () => {
        clearConversationUI();
    });
}
if (deleteChatBtn) {
    deleteChatBtn.addEventListener('click', deleteConversation);
}
if (debugScheduledHint) {
    debugScheduledHint.addEventListener('click', (event) => {
        const target = event.target;
        if (target && target.matches('[data-export-log]')) {
            event.preventDefault();
            handleExportConversation();
        }
    });
}
const saveLogBtn = document.getElementById('saveLogBtn');
if (saveLogBtn) {
    saveLogBtn.addEventListener('click', async (event) => {
        event.preventDefault();
        saveLogBtn.style.opacity = '0.5';
        saveLogBtn.textContent = '⏳ Gerando...';
        try {
            await handleExportConversation();
        } catch (error) {
            console.error('[SaveLog]', error);
            alert('Erro ao gerar log: ' + error.message);
        } finally {
            saveLogBtn.textContent = '💾 Save log';
            saveLogBtn.style.opacity = '1';
        }
    });
}
if (newConversationBtn) {
    newConversationBtn.addEventListener('click', openNewConversationModal);
}
if (newConversationClose) {
    newConversationClose.addEventListener('click', () => {
        closeNewConversationModal();
    });
}
if (newConversationCancel) {
    newConversationCancel.addEventListener('click', () => {
        closeNewConversationModal();
    });
}
if (newConversationForm) {
    newConversationForm.addEventListener('submit', handleNewConversationSubmit);
}
if (toggleScheduleFormBtn) {
    toggleScheduleFormBtn.addEventListener('click', () => {
        const isOpen = scheduleFormContainer && !scheduleFormContainer.classList.contains('hidden');
        showScheduleForm(!isOpen);
    });
}
if (manualScheduleForm) {
    manualScheduleForm.addEventListener('submit', handleManualScheduleSubmit);
}

// ===== MESSAGE DEBUG MODAL =====
const messageDebugModal = document.getElementById('messageDebugModal');
const messageDebugClose = document.getElementById('messageDebugClose');
const messageDebugContent = document.getElementById('messageDebugContent');
let currentMessagesForDebug = [];

function openMessageDebugModal(msgIndex) {
    if (!messageDebugModal || !messageDebugContent || !selectedContact) return;
    
    const contactMessages = messages[selectedContact] || [];
    if (msgIndex < 0 || msgIndex >= contactMessages.length) return;
    
    const msg = contactMessages[msgIndex];
    if (!msg) return;
    
    const direction = msg.direction || (msg.role === 'assistant' ? 'outbound' : 'inbound');
    const isOutgoing = direction === 'outbound';
    
    // Parse timestamp
    const msgDate = toDate(msg.timestamp);
    const timestampOriginal = msg.timestamp || '—';
    const timestampFormatted = msgDate ? msgDate.toLocaleString('pt-BR', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        timeZone: CLIENT_TIMEZONE
    }) : '—';
    
    // Get message type
    const msgType = msg.type || msg.messageType || 'text';
    
    // Get metadata
    const metadata = parseMetadata(msg.metadata);
    
    // Get delivery status
    const metaStatus = metadata?.meta_status || '—';
    
    // Get AI info if available
    const aiProvider = metadata?.provider || '—';
    const aiTokens = metadata?.tokens || '—';
    const aiResponseTime = metadata?.responseTime || metadata?.response_time || '—';
    
    // Get function calls info from metadata
    const commandList = extractCommandLines(metadata, msg.content || '');
    let functionCallsContent = '';
    if (commandList && commandList.length > 0) {
        functionCallsContent = `
            <div class="bg-blue-50 rounded-xl p-3 space-y-2 mt-3">
                <div class="font-semibold text-blue-800 border-b border-blue-200 pb-2 mb-2">Funções Executadas</div>
                <div class="space-y-2">
                    ${commandList.map(cmd => {
                        const rawArgs = cmd?.args;
                        const argsList = Array.isArray(rawArgs)
                            ? rawArgs
                            : (rawArgs && typeof rawArgs === 'object')
                                ? Object.entries(rawArgs).map(([key, value]) => `${key}=${value === undefined || value === null ? '' : value}`)
                                : (typeof rawArgs === 'string' && rawArgs.trim() ? [rawArgs.trim()] : []);
                        const argsText = argsList.map(arg => escapeHtml(String(arg || ''))).filter(Boolean).join(', ');
                        const displayArgs = argsText || 'sem argumentos';
                        const resultText = formatCommandResult(cmd);
                        const resultLine = resultText ? `<div class="text-[11px] text-blue-700 mt-1">Retorno: ${escapeHtml(resultText)}</div>` : '';
                        return `
                            <div class="border border-blue-200 rounded-lg p-2 bg-white">
                                <div class="font-semibold text-blue-800">${escapeHtml(cmd.type || 'função')}()</div>
                                <div class="text-[11px] text-blue-700 mt-1">Parâmetros: ${displayArgs}</div>
                                ${resultLine}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    }
    
    // Build raw data section
    let rawDataStr = '—';
    try {
        rawDataStr = JSON.stringify(msg, null, 2);
    } catch {
        rawDataStr = String(msg);
    }
    
    // Build content
    const content = `
        <div class="bg-slate-50 rounded-xl p-3 space-y-2">
            <div class="font-semibold text-slate-700 border-b border-slate-200 pb-2 mb-2">Informações da Mensagem</div>
            <div class="grid grid-cols-2 gap-2">
                <div><span class="text-slate-500">Message ID:</span></div>
                <div class="text-slate-700 font-mono text-[10px] break-all">${msg.id || msg.key?.id || '—'}</div>
                
                <div><span class="text-slate-500">Timestamp (Original):</span></div>
                <div class="text-slate-700 font-mono text-[10px]">${timestampOriginal}</div>
                
                <div><span class="text-slate-500">Timestamp (Formatado):</span></div>
                <div class="text-slate-700 font-mono text-[10px]">${timestampFormatted}</div>
                
                <div><span class="text-slate-500">Tipo:</span></div>
                <div class="text-slate-700">${msgType}</div>
                
                <div><span class="text-slate-500">Direction:</span></div>
                <div class="text-slate-700">${isOutgoing ? 'Saída (outbound)' : 'Entrada (inbound)'}</div>
                
                <div><span class="text-slate-500">fromMe:</span></div>
                <div class="text-slate-700">${isOutgoing ? 'true' : 'false'}</div>
                
                <div><span class="text-slate-500">Status entrega:</span></div>
                <div class="text-slate-700">${metaStatus}</div>
                
                <div><span class="text-slate-500">Role:</span></div>
                <div class="text-slate-700">${msg.role || '—'}</div>
            </div>
        </div>
    `;
    
    const aiContent = aiProvider !== '—' ? `
        <div class="bg-amber-50 rounded-xl p-3 space-y-2 mt-3">
            <div class="font-semibold text-amber-800 border-b border-amber-200 pb-2 mb-2">Informações de IA</div>
            <div class="grid grid-cols-2 gap-2">
                <div><span class="text-amber-700">Provider:</span></div>
                <div class="text-amber-900">${aiProvider}</div>
                
                <div><span class="text-amber-700">Tokens:</span></div>
                <div class="text-amber-900">${typeof aiTokens === 'object' ? JSON.stringify(aiTokens) : aiTokens}</div>
                
                <div><span class="text-amber-700">Tempo de resposta:</span></div>
                <div class="text-amber-900">${aiResponseTime}ms</div>
            </div>
        </div>
    ` : '';
    
    const rawContent = `
        <div class="bg-slate-900 rounded-xl p-3 mt-3">
            <div class="font-semibold text-slate-300 border-b border-slate-700 pb-2 mb-2">Raw Data (JSON)</div>
            <pre class="text-[10px] text-green-400 overflow-x-auto max-h-64">${escapeHtml(rawDataStr)}</pre>
        </div>
    `;
    
    messageDebugContent.innerHTML = content + aiContent + functionCallsContent + rawContent;
    messageDebugModal.classList.remove('hidden');
}

function closeMessageDebugModal() {
    if (messageDebugModal) {
        messageDebugModal.classList.add('hidden');
    }
}

if (messageDebugClose) {
    messageDebugClose.addEventListener('click', closeMessageDebugModal);
}

if (messageDebugModal) {
    messageDebugModal.addEventListener('click', (e) => {
        if (e.target === messageDebugModal) {
            closeMessageDebugModal();
        }
    });
}

// Handle debug icon clicks using event delegation
document.addEventListener('click', (e) => {
    const debugIcon = e.target.closest('.message-debug-icon');
    if (debugIcon) {
        const msgIndex = parseInt(debugIcon.getAttribute('data-msg-index'), 10);
        if (!isNaN(msgIndex)) {
            openMessageDebugModal(msgIndex);
        }
    }
});

// Close modal on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && messageDebugModal && !messageDebugModal.classList.contains('hidden')) {
        closeMessageDebugModal();
    }
});

// System status tooltip
systemStatusDot.addEventListener('mouseenter', () => {
    systemStatusTooltip.classList.remove('hidden');
});

systemStatusDot.addEventListener('mouseleave', () => {
    systemStatusTooltip.classList.add('hidden');
});

// Auto-refresh functionality
setInterval(() => {
    checkSystemHealth();
    refreshAutoPauseStatus();
    if (selectedContact && !isLoading) {
        loadMessages(selectedContact);
    }
    loadContacts({ reset: true });
}, AUTO_REFRESH_INTERVAL);

function initializeConversationView() {
    loadContacts({ reset: true });
    checkSystemHealth();
    refreshAutoPauseStatus();

    if ('<?= $connectionStatus ?>' !== 'connected') {
        chatStatus.textContent = 'Instância desconectada';
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeConversationView);
} else {
    initializeConversationView();
}

if (statusBroadcastAlert) {
    pollStatusBroadcasts();
    setInterval(pollStatusBroadcasts, STATUS_BROADCAST_POLL_INTERVAL);
}
</script>
<script>
  (function() {
    const btn = document.getElementById('toggleAgBtn');
    const panel = document.getElementById('agPanel');
    const closeBtn = document.getElementById('closeAgBtn');

    function openPanel() {
      panel?.classList.remove('hidden');
      setToggleAgText('Ocultar agendamentos');
    }
    function closePanel() {
      panel?.classList.add('hidden');
      showScheduleForm(false);
      setToggleAgText('Agendamentos');
    }

    btn?.addEventListener('click', () => {
      if (!panel) return;
      if (panel.classList.contains('hidden')) {
        openPanel();
      } else {
        closePanel();
      }
    });

    closeBtn?.addEventListener('click', closePanel);

    document.addEventListener('click', (e) => {
      if (!panel || panel.classList.contains('hidden')) return;
      const target = e.target;
      if (panel.contains(target) || btn?.contains(target)) return;
      closePanel();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        closePanel();
      }
    });
  })();
</script>
<script>
  (function () {
    const basePath = window.location.pathname.replace(/[^/]+$/, '/');
    const statusEndpoint = `${window.location.origin}${basePath}status?instance=${INSTANCE_ID}`;
    const statusConnectionStatusEl = document.getElementById('statusTabConnectionStatus');
    const statusConnectionSinceEl = document.getElementById('statusTabConnectionSince');
    const statusTabHasQR = document.getElementById('statusTabHasQR');
    const statusWhatsAppVersionEl = document.getElementById('statusTabWhatsAppVersion');
    const statusBaileysVersionEl = document.getElementById('statusTabBaileysVersion');
    const statusLastErrorEl = document.getElementById('statusTabLastError');
    const statusBrowserNameEl = document.getElementById('statusTabBrowserName');
    const statusBrowserDetailsEl = document.getElementById('statusTabBrowserDetails');
    const statusUserAgentEl = document.getElementById('statusTabUserAgent');
    const statusBaileysNameEl = document.getElementById('statusBaileysName');
    const statusBaileysUAEl = document.getElementById('statusBaileysUserAgent');
    const statusBaileysClientVersionEl = document.getElementById('statusBaileysClientVersion');
    const refreshButton = document.getElementById('refreshStatusButton');
    const instanceConnectionBadge = document.getElementById('instanceConnectionBadge');
    const instanceConnectionText = document.getElementById('instanceConnectionText');

    const formatStatusDate = value => {
      const date = toDate(value);
      if (!date) {
        return '—';
      }
      return date.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    };

    function updateInstanceConnectionBadge(connected) {
      if (!instanceConnectionBadge || !instanceConnectionText) {
        return;
      }
      const isConnected = !!connected;
      instanceConnectionText.textContent = isConnected ? 'Conectado' : 'Desconectado';
      instanceConnectionBadge.classList.toggle('bg-success/10', isConnected);
      instanceConnectionBadge.classList.toggle('text-success', isConnected);
      instanceConnectionBadge.classList.toggle('bg-error/10', !isConnected);
      instanceConnectionBadge.classList.toggle('text-error', !isConnected);
      instanceConnectionBadge.setAttribute('data-state', isConnected ? 'connected' : 'disconnected');
    }

    async function refreshInstanceStatusData() {
      if (!statusConnectionStatusEl) {
        return;
      }
      try {
        const response = await fetchWithCreds(statusEndpoint);
        if (!response.ok) {
          throw new Error('Falha ao carregar o status');
        }
        const data = await response.json();
        const connected = !!data.whatsappConnected;
        statusConnectionStatusEl.textContent = connected ? 'Conectado' : 'Desconectado';
        statusConnectionStatusEl.classList.toggle('text-success', connected);
        statusConnectionSinceEl && (statusConnectionSinceEl.textContent = formatStatusDate(data.connectionSince));
        statusWhatsAppVersionEl && (statusWhatsAppVersionEl.textContent = data.whatsappVersion || '—');
        statusBaileysVersionEl && (statusBaileysVersionEl.textContent = data.baileysVersion || '—');
        statusTabHasQR && (statusTabHasQR.textContent = `QR disponível: ${data.hasQR ? 'Sim' : 'Não'}`);
        statusLastErrorEl && (statusLastErrorEl.textContent = data.lastConnectionError ? `Ult. erro: ${data.lastConnectionError}` : 'Último erro: sem registros');
        const browserName = Array.isArray(data.browser) ? data.browser[0] : (data.browser || '—');
        statusBrowserNameEl && (statusBrowserNameEl.textContent = browserName || '—');
        statusBrowserDetailsEl && (statusBrowserDetailsEl.textContent = Array.isArray(data.browser) ? data.browser.join(' / ') : (data.browser || '—'));
        statusUserAgentEl && (statusUserAgentEl.textContent = `User-Agent: ${data.userAgent || '—'}`);
        if (statusBaileysNameEl) {
          statusBaileysNameEl.textContent = browserName || '—';
        }
        if (statusBaileysUAEl) {
          statusBaileysUAEl.textContent = data.userAgent || '—';
        }
        if (statusBaileysClientVersionEl) {
          statusBaileysClientVersionEl.textContent = data.baileysVersion || '—';
        }
        updateInstanceConnectionBadge(connected);
      } catch (error) {
        console.error(logPrefix, 'refreshInstanceStatusData error', error);
        statusConnectionStatusEl.textContent = 'Erro';
        statusConnectionStatusEl.classList.remove('text-success');
        if (statusLastErrorEl) {
          statusLastErrorEl.textContent = `Erro ao atualizar status: ${error.message || 'não foi possível carregar'}`;
        }
        updateInstanceConnectionBadge(false);
      }
    }

    refreshButton?.addEventListener('click', event => {
      event.preventDefault();
      refreshInstanceStatusData();
    });

    refreshInstanceStatusData();
    setInterval(refreshInstanceStatusData, AUTO_REFRESH_INTERVAL);
  })();
</script>

<section class="mt-6 bg-white border border-mid rounded-3xl p-6 space-y-4">
  <div class="flex flex-wrap items-center justify-between gap-4">
    <div>
      <h3 class="text-lg font-semibold text-dark">Status da instância</h3>
      <p class="text-xs text-slate-500">Informações capturadas diretamente do backend (Baileys/Meta).</p>
    </div>
    <button id="refreshStatusButton"
            class="px-4 py-2 rounded-xl border border-primary text-primary text-xs font-semibold hover:bg-primary/5">
      Atualizar
    </button>
  </div>
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
    <div class="rounded-2xl border border-mid bg-slate-50 p-3 space-y-1">
      <div class="text-[11px] text-slate-500 uppercase tracking-widest">Conexão</div>
      <div id="statusTabConnectionStatus" class="text-xl font-semibold text-dark">Carregando...</div>
      <div id="statusTabConnectionSince" class="text-[11px] text-slate-500">—</div>
    </div>
    <div class="rounded-2xl border border-mid bg-slate-50 p-3 space-y-1">
      <div class="text-[11px] text-slate-500 uppercase tracking-widest">WhatsApp</div>
      <div id="statusTabWhatsAppVersion" class="text-base font-semibold text-dark">—</div>
      <div id="statusTabHasQR" class="text-[11px] text-slate-500">QR disponível: —</div>
    </div>
    <div class="rounded-2xl border border-mid bg-slate-50 p-3 space-y-1">
      <div class="text-[11px] text-slate-500 uppercase tracking-widest">Baileys</div>
      <div id="statusTabBaileysVersion" class="text-base font-semibold text-dark">—</div>
      <div id="statusTabLastError" class="text-[11px] text-slate-500">Último erro: sem registros</div>
    </div>
    <div class="rounded-2xl border border-mid bg-slate-50 p-3 space-y-1">
      <div class="text-[11px] text-slate-500 uppercase tracking-widest">Browser</div>
      <div id="statusTabBrowserName" class="text-base font-semibold text-dark">—</div>
      <div id="statusTabBrowserDetails" class="text-[11px] text-slate-500">—</div>
      <div id="statusTabUserAgent" class="text-[11px] text-slate-500">User-Agent: —</div>
    </div>
  </div>
  <?php if ($isBaileysIntegration): ?>
    <div class="rounded-2xl border border-mid bg-slate-50 p-4 space-y-2">
      <div class="text-xs text-slate-500 uppercase tracking-widest">Dados do Baileys</div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <p class="text-[11px] text-slate-500">Nome registrado</p>
          <p id="statusBaileysName" class="text-slate-900 font-semibold">Carregando...</p>
        </div>
        <div>
          <p class="text-[11px] text-slate-500">User Agent atual</p>
          <p id="statusBaileysUserAgent" class="text-slate-900 font-semibold text-[12px]">—</p>
        </div>
        <div>
          <p class="text-[11px] text-slate-500">Versão Baileys</p>
          <p id="statusBaileysClientVersion" class="text-slate-900 font-semibold">—</p>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="rounded-2xl border border-mid bg-slate-50 p-4 space-y-3">
      <div class="text-xs text-slate-500 uppercase tracking-widest">Meta API</div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-3 space-y-1">
          <div class="text-[11px] text-slate-500">Última mensagem recebida</div>
          <div class="text-sm text-slate-900 font-semibold"><?= $lastInboundTime ?></div>
          <div class="text-[11px] text-slate-500">Remoto</div>
          <div class="text-sm text-slate-900"><?= $lastInboundLabel ?></div>
          <div class="text-[11px] text-slate-500 mt-1">Conteúdo</div>
          <p class="text-[11px] text-slate-500"><?= $lastInboundContent ?></p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-3 space-y-1">
          <div class="text-[11px] text-slate-500">Última mensagem enviada</div>
          <div class="text-sm text-slate-900 font-semibold"><?= $lastOutboundTime ?></div>
          <div class="text-[11px] text-slate-500">Remoto</div>
          <div class="text-sm text-slate-900"><?= $lastOutboundLabel ?></div>
          <div class="text-[11px] text-slate-500 mt-1">Conteúdo</div>
          <p class="text-[11px] text-slate-500"><?= $lastOutboundContent ?></p>
        </div>
      </div>
    </div>
  <?php endif; ?>
</section>
<footer class="w-full bg-slate-900 text-slate-200 text-xs text-center py-3 mt-6">
  Por <strong>Osvaldo J. Filho</strong> |
  <a href="https://linkedin.com/in/ojaneri" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">LinkedIn</a> |
  <a href="https://github.com/ojaneri/maestro" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">GitHub</a>
  <?php if ($isBaileysIntegration): ?>
    <div class="text-[11px] text-slate-300 mt-2">
      Baileys: <?= htmlspecialchars($nodeBrowserName ?: '—', ENT_QUOTES) ?> • User Agent: <?= htmlspecialchars($nodeUserAgent ?: '—', ENT_QUOTES) ?>
    </div>
  <?php endif; ?>
</footer>
</body>
</html>
