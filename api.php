<?php

if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

require_once __DIR__ . '/instance_data.php';

function handle_calendar_proxy_request(): void
{
    $path = trim((string)($_REQUEST['path'] ?? ''));
    $state = trim((string)($_REQUEST['state'] ?? ''));
    if ($path === '' && $state !== '') {
        $path = 'api/calendar/oauth2/callback';
    }

    $sanitizedPath = ltrim($path, '/');
    if ($sanitizedPath === '' || stripos($sanitizedPath, 'api/calendar') !== 0) {
        send_calendar_proxy_error('Only api/calendar/* paths are allowed', 400);
    }

    $instanceId = trim((string)($_REQUEST['instance'] ?? ''));
    if ($instanceId === '' && stripos($sanitizedPath, 'api/calendar/oauth2/callback') === 0 && $state !== '') {
        $stateInfo = findInstanceByCalendarState($state);
        if (!empty($stateInfo['instance_id'])) {
            $instanceId = $stateInfo['instance_id'];
        }
    }
    if ($instanceId === '') {
        send_calendar_proxy_error('Instance ID and path are required', 400);
    }

    $instance = loadInstanceRecordFromDatabase($instanceId);
    if (!$instance) {
        send_calendar_proxy_error('Instance not found', 404);
    }

    $port = isset($instance['port']) ? (int)$instance['port'] : 0;
    if ($port <= 0) {
        send_calendar_proxy_error('Instance port is not configured', 500);
    }

    $targetUrl = "http://127.0.0.1:{$port}/{$sanitizedPath}";
    $forwardParams = $_GET;
    unset($forwardParams['calendar_proxy'], $forwardParams['path']);
    if (!empty($forwardParams)) {
        $targetUrl .= '?' . http_build_query($forwardParams);
    }

    debug_log("calendar proxy request for {$instanceId} -> {$targetUrl}");

    $body = file_get_contents('php://input');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
    }

    $forwardHeaders = [];
    foreach (get_proxy_request_headers() as $name => $value) {
        $lowerName = strtolower($name);
        if (in_array($lowerName, ['host', 'content-length'], true)) {
            continue;
        }
        if (is_array($value)) {
            $value = implode(', ', $value);
        }
        $forwardHeaders[] = "{$name}: {$value}";
    }
    $forwardHeaders[] = 'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);

    $responseHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $headerLine) use (&$responseHeaders) {
        $length = strlen($headerLine);
        $trimmed = trim($headerLine);
        if ($trimmed === '' || stripos($trimmed, 'http/') === 0) {
            return $length;
        }
        $responseHeaders[] = $trimmed;
        return $length;
    });

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502;
    curl_close($ch);

    if ($curlError !== '') {
        debug_log("calendar proxy curl error for {$instanceId}: {$curlError}");
        send_calendar_proxy_error('Failed to reach internal calendar service', 502, $curlError);
    }

    header_remove('Content-Type');
    foreach ($responseHeaders as $headerLine) {
        $headerName = explode(':', $headerLine, 2)[0] ?? '';
        $lowerHeader = strtolower($headerName);
        if (in_array($lowerHeader, ['content-length', 'transfer-encoding', 'connection'], true)) {
            continue;
        }
        header($headerLine, false);
    }

    http_response_code($statusCode);
    if ($responseBody !== false) {
        echo $responseBody;
    }
    exit;
}

function send_calendar_proxy_error(string $message, int $code = 500, ?string $detail = null): void
{
    debug_log("calendar proxy error: {$message}" . ($detail ? " ({$detail})" : ''));
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['ok' => false, 'error' => $message];
    if ($detail) {
        $payload['detail'] = $detail;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_proxy_request_headers(): array
{
    if (function_exists('getallheaders')) {
        return getallheaders();
    }
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$headerName] = $value;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
    }
    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
    }
    return $headers;
}

$calendarProxyRequested = array_key_exists('calendar_proxy', $_GET) || array_key_exists('state', $_GET);
if ($calendarProxyRequested) {
    handle_calendar_proxy_request();
    exit;
}

header("Content-Type: application/json");

debug_log('api.php: Request received');

function normalizeAlarmRecipientsForSave(string $raw): string
{
    return implode(',', parseEmailList($raw));
}

function normalizeAlarmIntervalValue($value, $unit = ''): string
{
    $interval = (int)($value ?? 0);
    if ($interval <= 0) {
        return '120';
    }
    $unit = strtolower(trim((string) $unit));
    if ($unit === 'minutes' || $unit === 'min') {
        $interval = max(1, min(1440, $interval));
        return (string) $interval;
    }
    if ($interval === 2 || $interval === 24) {
        return (string) ($interval * 60);
    }
    $interval = max(1, min(1440, $interval));
    return (string) $interval;
}

function postInstanceSetting(string $port, string $instanceId, string $key, string $value): array
{
    $nodeUrl = "http://127.0.0.1:{$port}/api/settings/{$key}";
    $query = http_build_query(['instance' => $instanceId]);
    if ($query) {
        $nodeUrl .= '?' . $query;
    }
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['value' => $value]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'ok' => !$error && $httpCode >= 200 && $httpCode < 300,
        'error' => $error ?: '',
        'code' => $httpCode,
        'response' => $response
    ];
}

// ==================
// Verificar API KEY
// ==================
$apiKey = $_SERVER["HTTP_X_API_KEY"] ?? null;
// debug_log('API Key received: ' . ($apiKey ? substr($apiKey, 0, 8) . '...' : 'none'));

if (!$apiKey) {
    debug_log('API Key missing, returning 401');
    http_response_code(401);
    die(json_encode(["error" => "API KEY required"]));
}

$instance = findInstanceByApiKey($apiKey);
if (!$instance) {
    debug_log('Invalid API Key, returning 403');
    http_response_code(403);
    die(json_encode(["error" => "Invalid API KEY"]));
}

$instanceId = $instance['instance_id'] ?? null;
$port = isset($instance['port']) ? (int)$instance['port'] : null;
if (!$instanceId || !$port) {
    debug_log('Instance record missing port or id for API key');
    http_response_code(500);
    die(json_encode(["error" => "Instance configuration incomplete"]));
}

debug_log('Valid API Key provided.');

// Leitura do payload enviado pelo usuário
$rawPayload = file_get_contents("php://input");
$payload = json_decode($rawPayload, true);
// debug_log('Payload received: ' . json_encode($payload));

function logOutgoingMessage(string $instanceId, string $to, string $message): void {
    $dbPath = __DIR__ . '/chat_data.db';
    if (!file_exists($dbPath)) {
        return;
    }
    $trimmedTo = trim($to);
    $trimmedMessage = trim($message);
    if ($trimmedTo === '' || $trimmedMessage === '') {
        return;
    }

    try {
        $db = new SQLite3($dbPath);
        $stmt = $db->prepare("
            INSERT INTO messages (instance_id, remote_jid, role, content, direction, metadata)
            VALUES (:instance, :remote, 'assistant', :content, 'outbound', :metadata)
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote', $trimmedTo, SQLITE3_TEXT);
        $stmt->bindValue(':content', $trimmedMessage, SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode(['source' => 'api.php']), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
        $db->close();
    } catch (Exception $e) {
        debug_log('logOutgoingMessage error: ' . $e->getMessage());
    }
}

// Check for special actions
if (isset($payload['action'])) {
    if ($payload['action'] === 'save_ai_config') {
        $ai = $payload['ai'] ?? [];
        if (!is_array($ai)) {
            http_response_code(400);
            die(json_encode(["error" => "Invalid AI configuration"]));
        }

        $enabled = (bool)($ai['enabled'] ?? false);
        $provider = in_array($ai['provider'] ?? 'openai', ['openai', 'gemini'], true) ? $ai['provider'] : 'openai';
        $model = trim($ai['model'] ?? 'gpt-4.1-mini');
        $systemPrompt = trim($ai['system_prompt'] ?? '');
        $assistantPrompt = trim($ai['assistant_prompt'] ?? '');
        $assistantId = trim($ai['assistant_id'] ?? '');
        $historyLimit = max(1, (int)($ai['history_limit'] ?? 20));
        $temperature = max(0, floatval($ai['temperature'] ?? 0.3));
        $maxTokens = max(64, (int)($ai['max_tokens'] ?? 600));
        $multiInputDelay = max(0, (int)($ai['multi_input_delay'] ?? 0));
        $openaiMode = in_array($ai['openai_mode'] ?? 'responses', ['responses', 'assistants'], true)
            ? $ai['openai_mode']
            : 'responses';
        $openaiApiKey = trim($ai['openai_api_key'] ?? '');
        $geminiApiKey = trim($ai['gemini_api_key'] ?? '');
        $geminiInstruction = trim($ai['gemini_instruction'] ?? '');

        if ($enabled && $provider === 'openai') {
            if (!$openaiApiKey) {
                http_response_code(400);
                die(json_encode(["error" => "OpenAI API key is required when enabling OpenAI provider"]));
            }
            if (!preg_match('/^sk-[A-Za-z0-9_.-]{48,}$/', $openaiApiKey)) {
                http_response_code(400);
                die(json_encode(["error" => "Invalid OpenAI API key format"]));
            }
            if ($openaiMode === 'assistants' && $assistantId === '') {
                http_response_code(400);
                die(json_encode(["error" => "Assistant ID is required for Assistants API mode"]));
            }
        }

        if ($enabled && $provider === 'gemini' && !$geminiApiKey) {
            http_response_code(400);
            die(json_encode(["error" => "Gemini API key is required when enabling Gemini provider"]));
        }

        $nodePayload = [
            'enabled' => $enabled,
            'provider' => $provider,
            'model' => $model,
            'system_prompt' => $systemPrompt,
            'assistant_prompt' => $assistantPrompt,
            'assistant_id' => $assistantId,
            'history_limit' => $historyLimit,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'multi_input_delay' => $multiInputDelay,
            'openai_api_key' => $openaiApiKey,
            'openai_mode' => $openaiMode,
            'gemini_api_key' => $geminiApiKey,
            'gemini_instruction' => $geminiInstruction,
        ];

        $nodeUrl = "http://127.0.0.1:{$port}/api/ai-config";
        $ch = curl_init($nodeUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($nodePayload));
        $nodeResp = curl_exec($ch);
        $nodeErr = curl_error($ch);
        $nodeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $nodeSyncWarning = null;
        if ($nodeErr) {
            $nodeSyncWarning = "Não foi possível conectar ao serviço Node: {$nodeErr}";
            debug_log("AI config sync error: {$nodeErr}");
        } elseif ($nodeCode >= 400) {
            $decoded = json_decode($nodeResp, true);
            $errorDetail = $decoded['error'] ?? ($decoded['detail'] ?? 'Sem detalhes');
            $nodeSyncWarning = "Node respondeu com erro ({$nodeCode}): {$errorDetail}";
            debug_log("AI config sync failed ({$nodeCode}): " . ($nodeResp ?: 'empty'));
        }

        debug_log('AI settings saved for instance: ' . $instanceId . ' provider=' . $provider);

        $responsePayload = ['success' => true];
        if ($nodeSyncWarning) {
            $responsePayload['warning'] = $nodeSyncWarning;
        }
        die(json_encode($responsePayload));
    }

    if ($payload['action'] === 'save_audio_transcription_config') {
        $audio = $payload['audio'] ?? [];
        if (!is_array($audio)) {
            http_response_code(400);
            die(json_encode(["error" => "Invalid audio transcription configuration"]));
        }

        $enabled = (bool)($audio['enabled'] ?? false);
        $geminiApiKey = trim($audio['gemini_api_key'] ?? '');
        $prefix = trim($audio['prefix'] ?? '');
        if ($prefix === '') {
            $prefix = '🔊';
        }

        if ($enabled && !$geminiApiKey) {
            http_response_code(400);
            die(json_encode(["error" => "Gemini API key is required when enabling audio transcription"]));
        }

        $entries = [
            'audio_transcription_enabled' => $enabled ? 'true' : 'false',
            'audio_transcription_gemini_api_key' => $geminiApiKey,
            'audio_transcription_prefix' => $prefix
        ];

        $warnings = [];
        foreach ($entries as $key => $value) {
            $result = postInstanceSetting((string)$port, (string)$instanceId, $key, (string)$value);
            if (!$result['ok']) {
                $details = $result['error'] ?: ($result['response'] ?: 'Falha desconhecida');
                $warnings[] = "{$key} ({$result['code']}): {$details}";
                debug_log("Audio transcription sync failed ({$key}): {$details}");
            }
        }

        $responsePayload = ['success' => true];
        if (!empty($warnings)) {
            $responsePayload['warning'] = implode(' | ', $warnings);
        }
        die(json_encode($responsePayload));
    }

    if ($payload['action'] === 'save_secretary_config') {
        $secretary = $payload['secretary'] ?? [];
        if (!is_array($secretary)) {
            http_response_code(400);
            die(json_encode(["error" => "Invalid secretary configuration"]));
        }

        $enabled = (bool)($secretary['enabled'] ?? false);
        $idleHours = max(0, (int)($secretary['idle_hours'] ?? 0));
        $initialResponse = trim($secretary['initial_response'] ?? '');
        $term1 = trim($secretary['term_1'] ?? '');
        $response1 = trim($secretary['response_1'] ?? '');
        $term2 = trim($secretary['term_2'] ?? '');
        $response2 = trim($secretary['response_2'] ?? '');
        $quickReplies = $secretary['quick_replies'] ?? [];

        if ($enabled && $idleHours < 1) {
            http_response_code(400);
            die(json_encode(["error" => "Tempo sem contato deve ser pelo menos 1 hora"]));
        }
        if ($enabled && $initialResponse === '') {
            http_response_code(400);
            die(json_encode(["error" => "Resposta inicial é obrigatória"]));
        }
        $normalizedReplies = [];
        if (is_array($quickReplies)) {
            foreach ($quickReplies as $entry) {
                $term = trim($entry['term'] ?? '');
                $response = trim($entry['response'] ?? '');
                if ($term === '' && $response === '') {
                    continue;
                }
                if ($term === '' || $response === '') {
                    http_response_code(400);
                    die(json_encode(["error" => "Cada resposta rápida precisa de termo e resposta"]));
                }
                $normalizedReplies[] = ['term' => $term, 'response' => $response];
            }
        }

        if ($term1 !== '' && $response1 === '') {
            http_response_code(400);
            die(json_encode(["error" => "Resposta do termo 1 é obrigatória"]));
        }
        if ($term2 !== '' && $response2 === '') {
            http_response_code(400);
            die(json_encode(["error" => "Resposta do termo 2 é obrigatória"]));
        }

        $entries = [
            'secretary_enabled' => $enabled ? 'true' : 'false',
            'secretary_idle_hours' => (string)$idleHours,
            'secretary_initial_response' => $initialResponse,
            'secretary_term_1' => $term1,
            'secretary_response_1' => $response1,
            'secretary_term_2' => $term2,
            'secretary_response_2' => $response2,
            'secretary_quick_replies' => json_encode($normalizedReplies, JSON_UNESCAPED_UNICODE)
        ];

        $warnings = [];
        foreach ($entries as $key => $value) {
            $result = postInstanceSetting((string)$port, (string)$instanceId, $key, (string)$value);
            if (!$result['ok']) {
                $details = $result['error'] ?: ($result['response'] ?: 'Falha desconhecida');
                $warnings[] = "{$key} ({$result['code']}): {$details}";
                debug_log("Secretary sync failed ({$key}): {$details}");
            }
        }

        $responsePayload = ['success' => true];
        if (!empty($warnings)) {
            $responsePayload['warning'] = implode(' | ', $warnings);
        }
        die(json_encode($responsePayload));
    }

    if ($payload['action'] === 'save_alarm_config') {
        $events = [
            'whatsapp' => 'WhatsApp desconectado',
            'server' => 'Servidor desconectado',
            'error' => 'Erro reportado'
        ];
        $settings = [];
        foreach ($events as $event => $label) {
            $enabled = isset($payload["alarm_{$event}_enabled"]) && $payload["alarm_{$event}_enabled"] !== '0';
            $rawRecipients = $payload["alarm_{$event}_recipients"] ?? '';
            $recipients = normalizeAlarmRecipientsForSave($rawRecipients);
            $interval = normalizeAlarmIntervalValue(
                $payload["alarm_{$event}_interval"] ?? '',
                $payload["alarm_{$event}_interval_unit"] ?? ''
            );

            if ($enabled && $recipients === '') {
                http_response_code(400);
                die(json_encode(["error" => "Informe pelo menos um e-mail válido para {$label}"]));
            }

            $settings["alarm_{$event}_enabled"] = $enabled ? '1' : '0';
            $settings["alarm_{$event}_recipients"] = $recipients;
            $settings["alarm_{$event}_interval"] = $interval;
            $settings["alarm_{$event}_interval_unit"] = 'minutes';
        }

        $nodeWarnings = [];
        foreach ($settings as $key => $value) {
            $post = postInstanceSetting($port, $instanceId, $key, $value);
            if (!$post['ok']) {
                $nodeWarnings[] = "Não foi possível salvar {$key}: " . ($post['error'] ?: "HTTP {$post['code']}");
                debug_log("Alarm config sync error for {$key}: " . ($post['response'] ?? ''));
            }
        }

        $responsePayload = ['success' => true];
        if (!empty($nodeWarnings)) {
            $responsePayload['warning'] = implode('; ', $nodeWarnings);
        }
        die(json_encode($responsePayload));
    }

    http_response_code(400);
    die(json_encode(["error" => "Unknown action"]));
}

// Normalizar parâmetros conforme documentação
$normalizedPayload = [];

// Se há um campo "to", usá-lo; se há "number", mapear para "to"
if (isset($payload['to'])) {
    $normalizedPayload['to'] = $payload['to'];
} elseif (isset($payload['number'])) {
    $normalizedPayload['to'] = $payload['number'];
}

// Sempre incluir a mensagem
if (isset($payload['message'])) {
    $normalizedPayload['message'] = $payload['message'];
}

debug_log('Normalized payload: ' . json_encode($normalizedPayload));

$ch = curl_init("http://127.0.0.1:$port/send-Message");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($normalizedPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
debug_log('Sending curl request to http://127.0.0.1:' . $port . '/send-Message');

$resp = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    debug_log('Curl error: ' . $err . ', returning 500');
    http_response_code(500);
    die(json_encode(["error" => $err]));
}

debug_log('Curl response: ' . substr($resp, 0, 100) . '...');
if (isset($normalizedPayload['message'])) {
    $recipient = $normalizedPayload['to'] ?? $normalizedPayload['number'] ?? '';
    logOutgoingMessage($instanceId, $recipient, $normalizedPayload['message']);
}
echo $resp;
