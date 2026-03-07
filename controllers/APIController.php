<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

class APIController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = new Auth();
    }

    public function handleRequest(): void {
        header("Content-Type: application/json");
        
        debug_log('API request received');

        // Check for API key
        $apiKey = $_SERVER["HTTP_X_API_KEY"] ?? null;
        if (!$apiKey) {
            http_response_code(401);
            die(json_encode(["error" => "API KEY required"]));
        }

        $instance = $this->findInstanceByApiKey($apiKey);
        if (!$instance) {
            http_response_code(403);
            die(json_encode(["error" => "Invalid API KEY"]));
        }

        $instanceId = $instance['instance_id'] ?? null;
        $port = isset($instance['port']) ? (int)$instance['port'] : null;
        if (!$instanceId || !$port) {
            http_response_code(500);
            die(json_encode(["error" => "Instance configuration incomplete"]));
        }

        debug_log('Valid API Key provided.');

        // Read payload
        $rawPayload = file_get_contents("php://input");
        $payload = json_decode($rawPayload, true);

        // Handle special actions
        if (isset($payload['action'])) {
            $this->handleAction($payload, $instanceId, $port);
        }

        // Default response
        http_response_code(200);
        die(json_encode(["success" => true]));
    }

    private function handleAction(array $payload, string $instanceId, int $port): void {
        switch ($payload['action']) {
            case 'save_ai_config':
                $this->saveAIConfig($payload, $instanceId, $port);
                break;
            case 'save_audio_transcription_config':
                $this->saveAudioTranscriptionConfig($payload, $instanceId, $port);
                break;
            case 'save_secretary_config':
                $this->saveSecretaryConfig($payload, $instanceId, $port);
                break;
            default:
                http_response_code(400);
                die(json_encode(["error" => "Unknown action: " . $payload['action']]));
        }
    }

    private function saveAIConfig(array $payload, string $instanceId, int $port): void {
        $ai = $payload['ai'] ?? [];
        if (!is_array($ai)) {
            http_response_code(400);
            die(json_encode(["error" => "Invalid AI configuration"]));
        }

        $enabled = (bool)($ai['enabled'] ?? false);
        $provider = in_array($ai['provider'] ?? 'openai', ['openai', 'gemini', 'openrouter'], true) 
            ? $ai['provider'] 
            : 'openai';
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
        $modelFallback1 = trim($ai['model_fallback_1'] ?? '');
        $modelFallback2 = trim($ai['model_fallback_2'] ?? '');
        $openrouterApiKey = trim($ai['openrouter_api_key'] ?? '');
        $openrouterBaseUrl = trim($ai['openrouter_base_url'] ?? '');

        // Validation
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

        if ($enabled && $provider === 'openrouter' && !$openrouterApiKey) {
            http_response_code(400);
            die(json_encode(["error" => "OpenRouter API key is required when enabling OpenRouter provider"]));
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
            'ai_model_fallback_1' => $modelFallback1,
            'ai_model_fallback_2' => $modelFallback2,
            'openrouter_api_key' => $openrouterApiKey,
            'openrouter_base_url' => $openrouterBaseUrl,
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
            $nodeSyncWarning = "Failed to connect to Node service: {$nodeErr}";
            debug_log("AI config sync error: {$nodeErr}");
        } elseif ($nodeCode >= 400) {
            $decoded = json_decode($nodeResp, true);
            $errorDetail = $decoded['error'] ?? ($decoded['detail'] ?? 'No details');
            $nodeSyncWarning = "Node responded with error ({$nodeCode}): {$errorDetail}";
            debug_log("AI config sync failed ({$nodeCode}): " . ($nodeResp ?: 'empty'));
        }

        debug_log('AI settings saved for instance: ' . $instanceId . ' provider=' . $provider);

        $responsePayload = ['success' => true];
        if ($nodeSyncWarning) {
            $responsePayload['warning'] = $nodeSyncWarning;
        }
        die(json_encode($responsePayload));
    }

    private function saveAudioTranscriptionConfig(array $payload, string $instanceId, int $port): void {
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
            $result = $this->postInstanceSetting((string)$port, (string)$instanceId, $key, (string)$value);
            if (!$result['ok']) {
                $details = $result['error'] ?: ($result['response'] ?: 'Unknown failure');
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

    private function saveSecretaryConfig(array $payload, string $instanceId, int $port): void {
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
            die(json_encode(["error" => "Idle time must be at least 1 hour"]));
        }
        if ($enabled && $initialResponse === '') {
            http_response_code(400);
            die(json_encode(["error" => "Initial response is required"]));
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
                    die(json_encode(["error" => "Each quick reply needs term and response"]));
                }
                $normalizedReplies[] = ['term' => $term, 'response' => $response];
            }
        }

        if ($term1 !== '' && $response1 === '') {
            http_response_code(400);
            die(json_encode(["error" => "Response for term 1 is required"]));
        }
        if ($term2 !== '' && $response2 === '') {
            http_response_code(400);
            die(json_encode(["error" => "Response for term 2 is required"]));
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
            $result = $this->postInstanceSetting((string)$port, (string)$instanceId, $key, (string)$value);
            if (!$result['ok']) {
                $details = $result['error'] ?: ($result['response'] ?: 'Unknown failure');
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

    private function postInstanceSetting(string $port, string $instanceId, string $key, string $value): array {
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

    private function findInstanceByApiKey(string $apiKey): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instances WHERE api_key = :api_key");
        $stmt->bindValue(':api_key', $apiKey, SQLITE3_TEXT);
        $result = $stmt->execute();
        $instance = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $instance ?: null;
    }
}