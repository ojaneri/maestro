<?php
header("Content-Type: application/json");

if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

debug_log('api.php: Request received');
$instances = json_decode(file_get_contents("instances.json"), true);
debug_log('Loaded instances: ' . count($instances));

// ==================
// Verificar API KEY
// ==================
$apiKey = $_SERVER["HTTP_X_API_KEY"] ?? null;
debug_log('API Key received: ' . ($apiKey ? substr($apiKey, 0, 8) . '...' : 'none'));

if (!$apiKey) {
    debug_log('API Key missing, returning 401');
    http_response_code(401);
    die(json_encode(["error" => "API KEY required"]));
}

$instanceId = null;
foreach ($instances as $id => $inst) {
    if ($inst["api_key"] === $apiKey) {
        $instanceId = $id;
        break;
    }
}

if (!$instanceId) {
    debug_log('Invalid API Key, returning 403');
    http_response_code(403);
    die(json_encode(["error" => "Invalid API KEY"]));
}
debug_log('Valid API Key, instance ID: ' . $instanceId);

// ==================
// Roteia para a porta da instância
// ==================
$port = $instances[$instanceId]["port"];
debug_log('Routing to instance port: ' . $port);

// Leitura do payload enviado pelo usuário
$rawPayload = file_get_contents("php://input");
$payload = json_decode($rawPayload, true);
debug_log('Payload received: ' . json_encode($payload));

// Check for special actions
if (isset($payload['action'])) {
    if ($payload['action'] === 'save_openai') {
        $openai = $payload['openai'] ?? [];
        // Validate
        if (!is_array($openai)) {
            http_response_code(400);
            die(json_encode(["error" => "Invalid openai data"]));
        }
        // Load instances
        $instances = json_decode(file_get_contents("instances.json"), true);
        if (!isset($instances[$instanceId])) {
            http_response_code(404);
            die(json_encode(["error" => "Instance not found"]));
        }
        // Validate API key format if provided
        $apiKey = trim($openai['api_key'] ?? '');
        if ($apiKey && !preg_match('/^sk-[a-zA-Z0-9]{48,}$/', $apiKey)) {
            http_response_code(400);
            die(json_encode(["error" => "Invalid OpenAI API key format"]));
        }

        // Update openai settings
        $instances[$instanceId]['openai'] = [
            'enabled' => (bool)($openai['enabled'] ?? false),
            'api_key' => $apiKey,
            'system_prompt' => trim($openai['system_prompt'] ?? ''),
            'assistant_prompt' => trim($openai['assistant_prompt'] ?? '')
        ];
        // Save
        file_put_contents("instances.json", json_encode($instances, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        debug_log('OpenAI settings saved for instance: ' . $instanceId);
        die(json_encode(["success" => true]));
    } else {
        http_response_code(400);
        die(json_encode(["error" => "Unknown action"]));
    }
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
echo $resp;

