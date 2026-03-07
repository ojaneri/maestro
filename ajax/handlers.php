<?php
/**
 * AJAX endpoint handlers extracted from index.php
 * Each handler ends with exit; when matched.
 *
 * Expected globals (set by index.php before requiring this file):
 *   $selectedInstance, $selectedInstanceId, $sidebarInstances, $logRange
 */

function handleAjaxRequest(): bool {

    // --- ajax_ai_config (GET) ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_ai_config']) && isset($_GET['instance'])) {
        perf_mark('ajax.ai_config.start');
        $instanceIdForAjax = $_GET['instance'];
        $instanceRecord = loadInstanceRecordFromDatabase($instanceIdForAjax);
        $aiPayload = $instanceRecord['ai'] ?? [];
        header('Content-Type: application/json; charset=utf-8');
        if (!$instanceRecord) {
            echo json_encode([
                'ok' => false,
                'error' => 'Instância não encontrada'
            ]);
            perf_log('ajax.ai_config', ['status' => 'not_found', 'instance' => $instanceIdForAjax]);
            return true;
        }
        echo json_encode([
            'ok' => true,
            'ai' => $aiPayload,
            'instance' => $instanceRecord['instance_id'] ?? $instanceIdForAjax
        ]);
        perf_log('ajax.ai_config', ['status' => 'ok', 'instance' => $instanceIdForAjax]);
        return true;
    }

    // --- ajax_save_ai (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_save_ai'])) {
        perf_mark('ajax.save_ai.start');
        header('Content-Type: application/json; charset=utf-8');
        $targetInstanceId = $_GET['instance'] ?? null;
        if (!$targetInstanceId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Instância não encontrada']);
            perf_log('ajax.save_ai', ['status' => 'not_found']);
            return true;
        }
        $instanceRecord = loadInstanceRecordFromDatabase($targetInstanceId);
        if (!$instanceRecord) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Instância não encontrada']);
            perf_log('ajax.save_ai', ['status' => 'not_found']);
            return true;
        }

        $payload = $_POST;
        $enabled = !empty($payload['ai_enabled']) && $payload['ai_enabled'] !== '0';
        $provider = in_array($payload['ai_provider'] ?? 'openai', ['openai', 'gemini', 'openrouter'], true)
            ? $payload['ai_provider']
            : 'openai';
        $model = trim($payload['ai_model'] ?? 'gpt-4.1-mini');
        $systemPrompt = trim($payload['ai_system_prompt'] ?? '');
        $assistantPrompt = trim($payload['ai_assistant_prompt'] ?? '');
        $assistantId = trim($payload['ai_assistant_id'] ?? '');
        $historyLimit = max(1, (int)($payload['ai_history_limit'] ?? 20));
        $temperature = max(0, floatval($payload['ai_temperature'] ?? 0.3));
        $maxTokens = max(64, (int)($payload['ai_max_tokens'] ?? 600));
        $multiInputDelay = max(0, (int)($payload['ai_multi_input_delay'] ?? 0));
        $openaiMode = in_array($payload['openai_mode'] ?? 'responses', ['responses', 'assistants'], true)
            ? $payload['openai_mode']
            : 'responses';
        $openaiApiKey = trim($payload['openai_api_key'] ?? '');
        $geminiApiKey = trim($payload['gemini_api_key'] ?? '');
        $geminiInstruction = trim($payload['gemini_instruction'] ?? '');
        $modelFallback1 = trim($payload['ai_model_fallback_1'] ?? '');
        $modelFallback2 = trim($payload['ai_model_fallback_2'] ?? '');
        $openrouterApiKey = trim($payload['openrouter_api_key'] ?? '');
        $openrouterBaseUrl = trim($payload['openrouter_base_url'] ?? '');

        if ($enabled && $provider === 'openai') {
            if (!$openaiApiKey) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'OpenAI API key é obrigatória']);
                perf_log('ajax.save_ai', ['status' => 'invalid_openai_key']);
                return true;
            }
            if (!preg_match('/^sk-[A-Za-z0-9_.-]{48,}$/', $openaiApiKey)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Formato da OpenAI API key inválido']);
                perf_log('ajax.save_ai', ['status' => 'invalid_openai_format']);
                return true;
            }
            if ($openaiMode === 'assistants' && $assistantId === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Assistant ID é obrigatório']);
                perf_log('ajax.save_ai', ['status' => 'missing_assistant_id']);
                return true;
            }
        }

        if ($enabled && $provider === 'gemini' && !$geminiApiKey) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Gemini API key é obrigatória']);
            perf_log('ajax.save_ai', ['status' => 'invalid_gemini_key']);
            return true;
        }

        if ($enabled && $provider === 'openrouter' && !$openrouterApiKey) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'OpenRouter API key é obrigatória']);
            perf_log('ajax.save_ai', ['status' => 'invalid_openrouter_key']);
            return true;
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
            'meta_access_token' => trim($payload['meta_access_token'] ?? ''),
            'auto_pause_enabled' => !empty($payload['auto_pause_enabled']) && $payload['auto_pause_enabled'] !== '0',
            'auto_pause_minutes' => max(1, (int)($payload['auto_pause_minutes'] ?? 5))
        ];

        $port = (int)($instanceRecord['port'] ?? 0);
        if (!$port) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Porta da instância inválida']);
            perf_log('ajax.save_ai', ['status' => 'invalid_port']);
            return true;
        }

        $nodeUrl = "http://127.0.0.1:{$port}/api/ai-config";
        $ch = curl_init($nodeUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($nodePayload));
        $nodeResp = curl_exec($ch);
        $nodeErr = curl_error($ch);
        $nodeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($nodeErr) {
            http_response_code(502);
            echo json_encode(['success' => false, 'error' => "Falha ao conectar no Node: {$nodeErr}"]);
            perf_log('ajax.save_ai', ['status' => 'node_error']);
            return true;
        }
        if ($nodeCode >= 400) {
            $decoded = json_decode($nodeResp, true);
            $detail = $decoded['error'] ?? ($decoded['detail'] ?? 'Erro no Node');
            http_response_code($nodeCode);
            echo json_encode(['success' => false, 'error' => $detail]);
            perf_log('ajax.save_ai', ['status' => 'node_fail', 'http' => $nodeCode]);
            return true;
        }

        $dbSettings = [
            'ai_enabled' => $enabled ? '1' : '0',
            'ai_provider' => $provider,
            'ai_model' => $model,
            'ai_system_prompt' => $systemPrompt,
            'ai_assistant_prompt' => $assistantPrompt,
            'ai_assistant_id' => $assistantId,
            'ai_history_limit' => (string)$historyLimit,
            'ai_temperature' => (string)$temperature,
            'ai_max_tokens' => (string)$maxTokens,
            'ai_multi_input_delay' => (string)$multiInputDelay,
            'openai_api_key' => $openaiApiKey,
            'openai_mode' => $openaiMode,
            'gemini_api_key' => $geminiApiKey,
            'gemini_instruction' => $geminiInstruction,
            'ai_model_fallback_1' => $modelFallback1,
            'ai_model_fallback_2' => $modelFallback2,
            'openrouter_api_key' => $openrouterApiKey,
            'openrouter_base_url' => $openrouterBaseUrl,
            'meta_access_token' => trim($payload['meta_access_token'] ?? ''),
            'meta_business_account_id' => trim($payload['meta_business_account_id'] ?? ''),
            'meta_telephone_id' => trim($payload['meta_telephone_id'] ?? ''),
            'auto_pause_enabled' => !empty($payload['auto_pause_enabled']) && $payload['auto_pause_enabled'] !== '0' ? '1' : '0',
            'auto_pause_minutes' => (string)max(1, (int)($payload['auto_pause_minutes'] ?? 5))
        ];

        $saveResult = saveInstanceSettings($targetInstanceId, $dbSettings);
        if (!$saveResult['ok']) {
            http_response_code(500);
            $errorMsg = $saveResult['message'] ?: 'Falha ao salvar configurações no banco de dados';
            error_log('[ajax_save_ai] Database save failed: ' . $errorMsg);
            echo json_encode(['success' => false, 'error' => $errorMsg]);
            perf_log('ajax.save_ai', ['status' => 'db_error', 'message' => $errorMsg]);
            return true;
        }

        error_log('[ajax_save_ai] Successfully saved AI config for instance: ' . $targetInstanceId);
        echo json_encode(['success' => true, 'message' => $saveResult['message'] ?: 'Salvo com sucesso']);
        perf_log('ajax.save_ai', ['status' => 'ok']);
        return true;
    }

    // --- qr_reset (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_reset'])) {
        perf_mark('ajax.qr_reset.start');
        $instanceId = trim((string) $_POST['qr_reset']);
        header('Content-Type: application/json; charset=utf-8');
        if ($instanceId === '' || !preg_match('/^[\w-]{1,64}$/', $instanceId)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Instância inválida.']);
            perf_log('ajax.qr_reset', ['status' => 'invalid']);
            return true;
        }
        $instanceRecord = loadInstanceRecordFromDatabase($instanceId);
        if (!$instanceRecord) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Instância não encontrada.']);
            perf_log('ajax.qr_reset', ['status' => 'not_found', 'instance' => $instanceId]);
            return true;
        }
        $authDir = dirname(__DIR__) . '/auth_' . $instanceId;
        rrmdir($authDir);
        $restartScript = dirname(__DIR__) . '/restart_instance.sh';
        if (is_file($restartScript)) {
            @exec('bash ' . escapeshellarg($restartScript) . ' ' . escapeshellarg($instanceId) . ' >/dev/null 2>&1');
        }
        echo json_encode([
            'ok' => true,
            'message' => 'Sessão reiniciada. Aguarde alguns minutos para o QR ser gerado.'
        ]);
        perf_log('ajax.qr_reset', ['status' => 'ok', 'instance' => $instanceId]);
        return true;
    }

    // --- ajax_send (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_send'])) {
        global $selectedInstance, $selectedInstanceId;
        perf_mark('ajax.send.start');
        header('Content-Type: application/json; charset=utf-8');
        $payloadRaw = file_get_contents('php://input');
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        // Robust instance resolution
        $instanceToUse = $selectedInstance;
        $instanceIdToUse = $selectedInstanceId;
        
        if (!$instanceToUse) {
            $requestedId = $_GET['instance'] ?? $_POST['instance'] ?? $payload['instance'] ?? null;
            if ($requestedId) {
                $instanceToUse = loadInstanceRecordFromDatabase($requestedId);
                $instanceIdToUse = $requestedId;
            }
        }

        if (!$instanceToUse) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Instância não encontrada para envio']);
            perf_log('ajax.send', ['status' => 'not_found']);
            return true;
        }

        $phone = trim($payload['phone'] ?? $payload['to'] ?? '');
        $message = trim($payload['message'] ?? '');
        
        // Extract media fields
        $imageUrl = trim($payload['image_url'] ?? '');
        $imageBase64 = trim($payload['image_base64'] ?? '');
        $videoUrl = trim($payload['video_url'] ?? '');
        $videoBase64 = trim($payload['video_base64'] ?? '');
        $audioUrl = trim($payload['audio_url'] ?? '');
        $caption = trim($payload['caption'] ?? '');
        
        // Determine if this is a media message
        $hasImage = !empty($imageUrl) || !empty($imageBase64);
        $hasVideo = !empty($videoUrl) || !empty($videoBase64);
        $hasAudio = !empty($audioUrl);
        $hasMedia = $hasImage || $hasVideo || $hasAudio;

        // Validate: need either message or media
        if (!$phone || (!$message && !$hasMedia)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Telefone e mensagem ou mídia são obrigatórios']);
            perf_log('ajax.send', ['status' => 'invalid']);
            return true;
        }

        debug_log("AJAX send-card request for {$instanceIdToUse}: phone={$phone}, hasMedia={$hasMedia}");
        $sendUrl = "http://127.0.0.1:{$instanceToUse['port']}/send-message";
        
        // Build request body with all fields
        $bodyData = ['to' => $phone];
        
        // Add text message if present
        if (!empty($message)) {
            $bodyData['message'] = $message;
        }
        
        // Add media fields
        if ($hasImage) {
            if (!empty($imageUrl)) {
                $bodyData['image_url'] = $imageUrl;
            } else {
                $bodyData['image_base64'] = $imageBase64;
            }
        }
        
        if ($hasVideo) {
            if (!empty($videoUrl)) {
                $bodyData['video_url'] = $videoUrl;
            } else {
                $bodyData['video_base64'] = $videoBase64;
            }
        }
        
        if ($hasAudio) {
            $bodyData['audio_url'] = $audioUrl;
        }
        
        if (!empty($caption)) {
            $bodyData['caption'] = $caption;
        }
        
        $body = json_encode($bodyData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'User-Agent: PHP-Curl']);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        debug_log("AJAX send-card response for {$selectedInstanceId}: URL={$sendUrl}, HTTP Code={$httpCode}, Redirect URL={$redirectUrl}, cURL Error='{$curlError}', Response='{$response}'");

        $responsePayload = json_decode($response, true);
        if ($curlError) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => "Falha ao enviar mensagem: {$curlError}"]);
            perf_log('ajax.send', ['status' => 'curl_error']);
            return true;
        }

        if (!$responsePayload || !isset($responsePayload['ok']) || !$responsePayload['ok'] || $httpCode >= 400) {
            $errorMessage = $responsePayload['error'] ?? ($responsePayload['detail'] ?? "Erro HTTP {$httpCode}");
            http_response_code($httpCode >= 400 ? $httpCode : 500);
            echo json_encode(['ok' => false, 'error' => $errorMessage]);
            perf_log('ajax.send', ['status' => 'error', 'http' => $httpCode]);
            return true;
        }

        echo json_encode([
            'ok' => true,
            'message' => 'Mensagem encaminhada com sucesso',
            'remoteJid' => $responsePayload['to'] ?? null,
            'apiResponse' => $responsePayload
        ]);
        perf_log('ajax.send', ['status' => 'ok']);
        return true;
    }

    // --- ajax_history (GET) ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_history'])) {
        global $selectedInstance, $selectedInstanceId;
        perf_mark('ajax.history.start');
        header('Content-Type: application/json; charset=utf-8');
        if (!$selectedInstance) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Instância não encontrada']);
            perf_log('ajax.history', ['status' => 'not_found']);
            return true;
        }

        $nodeEndpoint = "http://127.0.0.1:{$selectedInstance['port']}/api/chats/{$selectedInstanceId}?limit=12";
        $nodeChats = null;
        $nodeRaw = '';
        $nodeHttpCode = 0;
        $nodeError = '';

        try {
            $ch = curl_init($nodeEndpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            $nodeRaw = curl_exec($ch);
            $nodeError = curl_error($ch);
            $nodeHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            debug_log("AJAX history request to {$nodeEndpoint} returned {$nodeHttpCode}");
            if (!$nodeError && $nodeHttpCode < 400) {
                $decoded = json_decode($nodeRaw, true);
                if (is_array($decoded) && !empty($decoded['ok']) && is_array($decoded['chats'])) {
                    $nodeChats = $decoded['chats'];
                    echo json_encode([
                        'ok' => true,
                        'instanceId' => $selectedInstanceId,
                        'source' => 'node',
                        'chats' => $nodeChats
                    ]);
                    perf_log('ajax.history', ['status' => 'ok', 'source' => 'node', 'rows' => count($nodeChats)]);
                    return true;
                }
                debug_log("AJAX history node response not usable: " . ($nodeRaw ?: 'empty'));
            } else {
                debug_log("AJAX history node curl error: {$nodeError}");
            }
        } catch (Exception $err) {
            debug_log("AJAX history node exception: " . $err->getMessage());
        }

        try {
            $chats = fetchChatHistory($selectedInstanceId, 10);
            echo json_encode([
                'ok' => true,
                'instanceId' => $selectedInstanceId,
                'source' => 'sqlite',
                'chats' => $chats
            ]);
            perf_log('ajax.history', ['status' => 'ok', 'source' => 'sqlite', 'rows' => count($chats)]);
        } catch (Exception $err) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Erro ao ler histórico']);
            perf_log('ajax.history', ['status' => 'error']);
        }
        return true;
    }

    // --- ajax_average_taxar (GET) ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_average_taxar'])) {
        global $selectedInstance, $selectedInstanceId;
        perf_mark('ajax.average_taxar.start');
        header('Content-Type: application/json; charset=utf-8');
        if (!$selectedInstance) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Instância não encontrada']);
            perf_log('ajax.average_taxar', ['status' => 'not_found']);
            return true;
        }

        $dbPath = dirname(__DIR__) . '/chat_data.db';
        if (!file_exists($dbPath)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Banco de dados indisponível']);
            perf_log('ajax.average_taxar', ['status' => 'db_not_found']);
            return true;
        }
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);

        $stmt = $db->prepare("SELECT DISTINCT remote_jid FROM messages WHERE instance_id = :instance");
        $stmt->bindValue(':instance', $selectedInstanceId, SQLITE3_TEXT);
        $result = $stmt->execute();

        $remoteJids = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $remoteJids[] = $row['remote_jid'];
        }
        $result->finalize();
        $stmt->close();

        $totalTaxar = 0;
        $validTaxarCount = 0;

        foreach ($remoteJids as $remoteJid) {
            $inboundStmt = $db->prepare("SELECT COUNT(id) as count FROM messages WHERE instance_id = :instance AND remote_jid = :remote AND direction = 'inbound'");
            $inboundStmt->bindValue(':instance', $selectedInstanceId, SQLITE3_TEXT);
            $inboundStmt->bindValue(':remote', $remoteJid, SQLITE3_TEXT);
            $inboundCount = (int)$inboundStmt->execute()->fetchArray(SQLITE3_ASSOC)['count'];
            $inboundStmt->close();

            $outboundStmt = $db->prepare("SELECT COUNT(id) as count FROM messages WHERE instance_id = :instance AND remote_jid = :remote AND direction = 'outbound'");
            $outboundStmt->bindValue(':instance', $selectedInstanceId, SQLITE3_TEXT);
            $outboundStmt->bindValue(':remote', $remoteJid, SQLITE3_TEXT);
            $outboundCount = (int)$outboundStmt->execute()->fetchArray(SQLITE3_ASSOC)['count'];
            $outboundStmt->close();

            if ($outboundCount > 0) {
                $taxar = ($inboundCount / $outboundCount) * 100;
                $totalTaxar += $taxar;
                $validTaxarCount++;
            }
        }
        $db->close();

        $averageTaxar = $validTaxarCount > 0 ? $totalTaxar / $validTaxarCount : 0;

        echo json_encode([
            'ok' => true,
            'instanceId' => $selectedInstanceId,
            'average_taxar' => round($averageTaxar, 2)
        ]);
        perf_log('ajax.average_taxar', ['status' => 'ok', 'average_taxar' => $averageTaxar]);
        return true;
    }

    // --- ajax_ai_test (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_ai_test'])) {
        global $selectedInstance, $selectedInstanceId;
        perf_mark('ajax.ai_test.start');

        $payload = $_POST;
        if (empty($payload)) {
            $dataRaw = file_get_contents('php://input');
            $payload = json_decode($dataRaw, true);
            if (!is_array($payload)) {
                $payload = [];
            }
        }

        $requestedInstanceId = trim((string)($_GET['instance'] ?? $payload['instance'] ?? $payload['instance_id'] ?? $selectedInstanceId ?? ''));
        $targetInstance = $selectedInstance;
        if ($requestedInstanceId !== '') {
            $resolved = loadInstanceRecordFromDatabase($requestedInstanceId);
            if ($resolved) {
                $targetInstance = $resolved;
                $selectedInstanceId = $requestedInstanceId;
            }
        }

        if (!$targetInstance) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Instância não encontrada']);
            perf_log('ajax.ai_test', ['status' => 'not_found', 'instance' => $requestedInstanceId]);
            return true;
        }

        $userMessage = trim($payload['message'] ?? '');
        $remoteJid = trim($payload['remote_jid'] ?? '');
        if (!$userMessage) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Mensagem é obrigatória']);
            perf_log('ajax.ai_test', ['status' => 'invalid']);
            return true;
        }

        $nodeBody = ['message' => $userMessage];
        if ($remoteJid) {
            $nodeBody['remote_jid'] = $remoteJid;
        }

        $nodeUrl = "http://127.0.0.1:{$targetInstance['port']}/api/ai-test";
        $ch = curl_init($nodeUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($nodeBody));
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => "Erro ao testar IA: {$err}"]);
            perf_log('ajax.ai_test', ['status' => 'curl_error']);
            return true;
        }

        $result = json_decode($resp, true);
        $isValidJson = is_array($result);
        if ($httpCode >= 400 || !$isValidJson) {
            http_response_code($httpCode >= 400 ? $httpCode : 500);
            $message = $isValidJson ? ($result['error'] ?? 'Resposta inválida do servidor AI') : 'Resposta inválida do servidor AI';
            $rawPayload = $isValidJson ? $result : trim($resp ?: '');
            echo json_encode(['ok' => false, 'error' => $message, 'raw' => $rawPayload]);
            perf_log('ajax.ai_test', ['status' => 'error', 'http' => $httpCode]);
            return true;
        }

        echo json_encode($result);
        perf_log('ajax.ai_test', ['status' => 'ok']);
        return true;
    }

    return false;
}
