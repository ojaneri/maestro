<?php
header("Content-Type: application/json");

if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

require_once __DIR__ . '/instance_data.php';

debug_log('api.php: Request received');

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
