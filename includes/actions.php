<?php
/**
 * Action handlers (POST/GET form actions) extracted from index.php
 *
 * Expected globals (set by index.php before requiring this file):
 *   $instances, $sidebarInstances, $selectedInstance, $selectedInstanceId,
 *   $baseRedirectUrl, $logRange, $statuses, $connectionStatuses
 *
 * Returns: bool — true if an action was handled (caller should exit)
 */

function fetchInstanceQrImageUrl(string $instanceId): array
{
    $instance = loadInstanceRecordFromDatabase($instanceId);
    if (!$instance) {
        return ['ok' => false, 'status' => 404, 'error' => 'Instância não encontrada'];
    }

    $port = $instance['port'] ?? null;
    if (!$port) {
        return ['ok' => false, 'status' => 400, 'error' => 'Porta da instância não configurada'];
    }

    $url = "http://127.0.0.1:{$port}/qr";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['ok' => false, 'status' => 503, 'error' => "Erro de rede: {$error}"];
    }

    if ($httpCode !== 200 || !$response) {
        $statusCode = $httpCode ?: 502;
        $errorMessage = "QR request retornou código HTTP {$httpCode}";
        if ($httpCode === 404) {
            $errorMessage = "QR ainda não disponível";
        }
        return [
            'ok' => false,
            'status' => $statusCode,
            'error' => $errorMessage
        ];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['qr'])) {
        return ['ok' => false, 'status' => 502, 'error' => 'Resposta QR inválida'];
    }

    $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($data['qr']);
    return [
        'ok' => true,
        'status' => 200,
        'qr_url' => $qrImageUrl,
        'qr_data' => $data['qr']
    ];
}

function handleActions(): bool {
    global $instances, $sidebarInstances, $selectedInstance, $selectedInstanceId,
           $baseRedirectUrl, $logRange, $statuses, $connectionStatuses;

    // --- export_conversations (GET) ---
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export_conversations']) && isset($_GET['instance'])) {
        $exportInstanceId = trim((string)$_GET['instance']);
        if ($exportInstanceId === '' || !isset($sidebarInstances[$exportInstanceId])) {
            http_response_code(404);
            echo "Instância não encontrada para exportação.";
            return true;
        }
        $rangeTag = 'hoje';
        if ($logRange['preset'] === 'all') {
            $rangeTag = 'total';
        } elseif ($logRange['preset'] === 'yesterday') {
            $rangeTag = 'ontem';
        } elseif ($logRange['preset'] === 'custom') {
            $startLabel = formatLogDateForFilename($logRange['start']) ?: 'inicio';
            $endLabel = formatLogDateForFilename($logRange['end']) ?: 'fim';
            $rangeTag = "{$startLabel}_{$endLabel}";
        }
        $filename = "conversas-{$exportInstanceId}-{$rangeTag}-" . date('Ymd-His') . ".txt";
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo buildAllConversationsLog(
            $exportInstanceId,
            $sidebarInstances[$exportInstanceId] ?? [],
            $logRange['start'],
            $logRange['end']
        );
        return true;
    }

    // --- create (POST) ---
    if (isset($_POST['create'])) {
        debug_log('Creating new instance: name=' . $_POST['name']);
        $nextPort = 3010 + count($instances) + 1;

        $id = uniqid("inst_");
        $apiKey = bin2hex(random_bytes(16));

        $newEntry = [
            "name" => $_POST["name"],
            "port" => $nextPort,
            "api_key" => $apiKey,
            "status" => "stopped",
            "connection_status" => "disconnected",
            "base_url" => "http://127.0.0.1:{$nextPort}",
            "phone" => null
        ];

        $sqlResult = upsertInstanceRecordToSql($id, $newEntry);
        if (!$sqlResult['ok']) {
            debug_log('Falha ao gravar instância no SQLite: ' . $sqlResult['message']);
        } else {
            debug_log('Instância persistida no SQLite: ' . $id);
        }
        exec("bash create_instance.sh {$id} {$nextPort} >/dev/null 2>&1 &");
        debug_log('Executed create_instance.sh for ' . $id . ' on port ' . $nextPort);

        debug_log('Redirecting to /api/envio/wpp/ after create');
        header("Location: " . $baseRedirectUrl);
        return true;
    }

    // --- delete (GET) ---
    if (isset($_GET["delete"])) {
        $deleteId = $_GET["delete"];
        debug_log('Starting complete deletion for instance: ' . $deleteId);

        $deleteResult = deleteInstanceCompletely($deleteId);

        if ($deleteResult['ok']) {
            debug_log('Instance completely deleted: ' . $deleteId);
        } else {
            debug_log('Instance deletion completed with some failures: ' . $deleteId);
        }

        debug_log('Redirecting to /api/envio/wpp/ after delete');
        header("Cache-Control: no-cache, no-store, must-revalidate");
        header("Pragma: no-cache");
        header("Expires: 0");
        header("Location: " . $baseRedirectUrl);
        return true;
    }

    // --- qr (GET) ---
    if (isset($_GET["qr"])) {
        debug_log('QR requested for instance: ' . $_GET['qr']);
        $instanceId = $_GET['qr'];
        $qrResult = fetchInstanceQrImageUrl($instanceId);

        if (!$qrResult['ok']) {
            $code = $qrResult['status'] ?? 500;
            debug_log("QR request failed: {$qrResult['error']} (code: {$code})");
            http_response_code($code);
            return true;
        }

        header("Location: {$qrResult['qr_url']}");
        return true;
    }

    // --- qr_data (GET) ---
    if (isset($_GET['qr_data'])) {
        header('Content-Type: application/json; charset=utf-8');
        $instanceId = $_GET['qr_data'];
        $qrResult = fetchInstanceQrImageUrl($instanceId);

        if ($qrResult['ok']) {
            http_response_code(200);
            echo json_encode(['ok' => true, 'qr_url' => $qrResult['qr_url']]);
            return true;
        }

        $code = $qrResult['status'] ?? 500;
        debug_log("QR data request failed: {$qrResult['error']} (code: {$code})");
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $qrResult['error']]);
        return true;
    }

    // --- disconnect (POST) ---
    if (isset($_POST["disconnect"])) {
        debug_log('Disconnecting instance: ' . $_POST['disconnect']);
        $id = $_POST["disconnect"];
        if (isset($instances[$id])) {
            $port = $instances[$id]['port'] ?? null;
            if ($port) {
                $url = "http://127.0.0.1:{$port}/disconnect";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                $response = curl_exec($ch);
                $error = curl_error($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($error || $httpCode < 200 || $httpCode >= 300) {
                    debug_log("Disconnect API failed: " . ($error ?: "HTTP {$httpCode}") . " response=" . ($response ?: 'empty'));
                } else {
                    debug_log('Disconnect API success for ' . $id);
                }
            } else {
                debug_log('Disconnect requested but port not found for ' . $id);
            }
        }
        header("Location: " . $baseRedirectUrl);
        return true;
    }

    // --- logout (GET) ---
    if (isset($_GET['logout'])) {
        debug_log('Logout requested');
        session_destroy();
        header("Location: " . $baseRedirectUrl);
        return true;
    }

    // --- create_instance (GET) ---
    if (isset($_GET['create_instance'])) {
        debug_log('Create instance requested');

        $instanceId = 'inst_' . bin2hex(random_bytes(8));

        // Buscar a maior porta em uso no banco de dados para evitar conflitos
        $db = openInstanceDatabase(false);
        $basePort = 3010; // DEFAULT_WHATSAPP_PORT - consistente com master-server.js e InstanceController.php
        
        if ($db) {
            $stmt = $db->query("SELECT MAX(port) as maxPort FROM instances");
            $row = $stmt->fetch(SQLITE3_ASSOC);
            if ($row && isset($row['maxPort']) && is_numeric($row['maxPort'])) {
                $basePort = (int)$row['maxPort'] + 1;
                if ($basePort < 3010) $basePort = 3010;
            }
            $stmt->close();
            $db->close();
        }

        $port = $basePort;
        while (isPortOpen('localhost', $port)) {
            $port++;
            if ($port > 3999) {
                debug_log('No available ports for new instance');
                header("Location: ?error=no_ports");
                return true;
            }
        }

        $instanceName = 'Instância ' . $instanceId;
        $baseUrl = "http://127.0.0.1:{$port}";

        $db = openInstanceDatabase(false);
        if (!$db) {
            debug_log('Failed to open instance database');
            header("Location: ?error=db_open");
            return true;
        }

        $stmt = $db->prepare("
            INSERT INTO instances (instance_id, name, port, base_url, status, connection_status, created_at, updated_at)
            VALUES (:id, :name, :port, :base_url, 'running', 'disconnected', datetime('now'), datetime('now'))
        ");

        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $instanceName, SQLITE3_TEXT);
        $stmt->bindValue(':port', $port, SQLITE3_INTEGER);
        $stmt->bindValue(':base_url', $baseUrl, SQLITE3_TEXT);

        if (!$stmt->execute()) {
            debug_log('Failed to create instance record: ' . $db->lastErrorMsg());
            header("Location: ?error=db_insert");
            return true;
        }

        $stmt->close();
        $db->close();

        exec("bash create_instance.sh {$instanceId} {$port} >/dev/null 2>&1 &");
        debug_log('Executed create_instance.sh for ' . $instanceId . ' on port ' . $port);

        header("Location: ?instance={$instanceId}");
        return true;
    }

    // --- send (POST) ---
    if (isset($_POST["send"]) && $selectedInstance) {
        debug_log('Sending message for instance: ' . $selectedInstanceId);
        $phone = $_POST['phone'];
        $message = $_POST['message'];
        $url = "http://127.0.0.1:{$selectedInstance['port']}/send";
        $data = json_encode(['phone' => $phone, 'message' => $message]);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            debug_log("Send failed: $error");
        } else {
            debug_log("Send response: $response");
        }
        header("Location: ?instance=$selectedInstanceId");
        return true;
    }

    // --- update_instance (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_instance'])) {
        return handleUpdateInstance();
    }

    return false;
}

function handleUpdateInstance(): bool {
    global $instances, $selectedInstance, $selectedInstanceId, $statuses, $connectionStatuses;

    $postInstanceId = trim($_POST['instance'] ?? '');
    $getInstanceId = trim($_GET['instance'] ?? '');
    $targetInstanceId = $postInstanceId ?: $getInstanceId;

    error_log('Quick config AJAX - target instance: ' . $targetInstanceId . ', POST: ' . json_encode($_POST));

    $instances = loadInstancesFromDatabase();
    $targetInstance = $instances[$targetInstanceId] ?? null;

    if (!$targetInstance) {
        $quickConfigError = 'Instância não encontrada: ' . $targetInstanceId;
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => $quickConfigError]);
            return true;
        }
        return false;
    }

    $selectedInstance = $targetInstance;
    $selectedInstanceId = $targetInstanceId;

    $newName = trim($_POST['instance_name'] ?? '');
    $submittedBaseUrl = null;
    if (isset($_POST['instance_base_url_b64'])) {
        $decoded = base64_decode((string)$_POST['instance_base_url_b64'], true);
        if ($decoded !== false) {
            $submittedBaseUrl = trim($decoded);
        }
    }
    if ($submittedBaseUrl === null && isset($_POST['instance_base_url'])) {
        $submittedBaseUrl = trim($_POST['instance_base_url']);
    }

    $integrationType = in_array($_POST['integration_type'] ?? 'baileys', ['baileys', 'meta', 'web']) ? $_POST['integration_type'] : 'baileys';
    $metaAccessToken = trim($_POST['meta_access_token'] ?? '');
    $metaBusinessAccountId = trim($_POST['meta_business_account_id'] ?? '');
    $metaTelephoneId = trim($_POST['meta_telephone_id'] ?? '');
    $instancePort = trim($_POST['instance_port'] ?? '');
    $previousPort = isset($selectedInstance['port']) ? (int)$selectedInstance['port'] : 0;
    $resolvedPort = $previousPort;
    $quickConfigError = '';

    if ($instancePort !== '') {
        if (!ctype_digit($instancePort)) {
            $quickConfigError = 'Porta inválida. Use apenas números.';
        } else {
            $resolvedPort = (int)$instancePort;
            if ($resolvedPort < 1 || $resolvedPort > 65535) {
                $quickConfigError = 'Porta inválida. Informe um valor entre 1 e 65535.';
            }
        }
    }
    if (!$quickConfigError && $resolvedPort > 0 && $resolvedPort !== $previousPort && isPortOpen('127.0.0.1', $resolvedPort)) {
        $quickConfigError = "A porta {$resolvedPort} já está em uso.";
    }

    if ($newName === '') {
        $quickConfigError = 'Nome da instância é obrigatório.';
    }

    if ($quickConfigError) {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $quickConfigError]);
            return true;
        }
        return false;
    }

    if ($integrationType !== 'meta') {
        $resolvedBaseUrl = "http://127.0.0.1:{$resolvedPort}";
    } else {
        $resolvedBaseUrl = $submittedBaseUrl ?: ($selectedInstance['base_url'] ?? ("http://127.0.0.1:{$selectedInstance['port']}"));
    }

    if ($submittedBaseUrl !== null) {
        $decodedCandidate = rawurldecode($submittedBaseUrl);
        if (preg_match('#^https?://#i', $decodedCandidate)) {
            $resolvedBaseUrl = $decodedCandidate;
        }
    }

    $portChanged = $resolvedPort > 0 && $resolvedPort !== $previousPort;
    $updatePayload = [
        'name' => $newName,
        'base_url' => $resolvedBaseUrl,
        'integration_type' => $integrationType,
        'port' => $resolvedPort ?: null,
        'api_key' => $selectedInstance['api_key'] ?? null,
        'status' => $selectedInstance['status'] ?? null,
        'connection_status' => $selectedInstance['connection_status'] ?? null,
        'phone' => $selectedInstance['phone'] ?? null
    ];
    $updateResult = upsertInstanceRecordToSql($selectedInstanceId, $updatePayload);

    $saveSettingsResult = saveInstanceSettings($selectedInstanceId, [
        'meta_access_token' => $metaAccessToken,
        'meta_business_account_id' => $metaBusinessAccountId,
        'meta_telephone_id' => $metaTelephoneId
    ]);

    if (!$updateResult['ok'] || !$saveSettingsResult['ok']) {
        $quickConfigError = 'Falha ao salvar configurações: ' . ($updateResult['message'] ?: $saveSettingsResult['message']);
        debug_log('AI config quick save failed: ' . ($updateResult['message'] ?: $saveSettingsResult['message']));

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $quickConfigError]);
            return true;
        }
        return false;
    }

    $instances = loadInstancesFromDatabase();
    list($statuses, $connectionStatuses) = buildInstanceStatuses($instances);
    $selectedInstance = $instances[$selectedInstanceId] ?? null;
    $nodeSyncError = '';
    $restartError = '';
    $nodePort = isset($selectedInstance['port']) ? (int)$selectedInstance['port'] : null;

    if ($portChanged && $selectedInstance && $nodePort) {
        $stopScript = dirname(__DIR__) . '/stop_instance.sh';
        $createScript = dirname(__DIR__) . '/create_instance.sh';
        if (!is_file($stopScript) || !is_file($createScript)) {
            $restartError = 'Scripts de restart não encontrados (stop_instance.sh/create_instance.sh).';
        } else {
            @exec('bash ' . escapeshellarg($stopScript) . ' ' . escapeshellarg($selectedInstanceId) . ' >/dev/null 2>&1');
            @exec('bash ' . escapeshellarg($createScript) . ' ' . escapeshellarg($selectedInstanceId) . ' ' . escapeshellarg((string)$nodePort) . ' >/dev/null 2>&1 &');

            $ready = false;
            for ($attempt = 0; $attempt < 12; $attempt++) {
                if (isPortOpen('127.0.0.1', $nodePort, 1)) {
                    $ready = true;
                    break;
                }
                usleep(500000);
            }
            if (!$ready) {
                $restartError = "Não foi possível iniciar o servidor na nova porta {$nodePort}.";
            } else {
                debug_log("Porta alterada para {$nodePort} e processo reiniciado para {$selectedInstanceId}");
            }
        }
    }

    if ($selectedInstance && $nodePort) {
        $nodePayload = [
            'name' => $newName,
            'base_url' => $resolvedBaseUrl,
            'port' => $nodePort,
            'api_key' => $selectedInstance['api_key'] ?? null,
            'phone' => $selectedInstance['phone'] ?? null
        ];
        $nodeUrl = "http://127.0.0.1:{$nodePort}/api/instance?instance=" . urlencode($selectedInstanceId);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $ch = curl_init($nodeUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($nodePayload));
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $nodeResp = curl_exec($ch);
            $nodeErr = curl_error($ch);
            $nodeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!$nodeErr && $nodeCode >= 200 && $nodeCode < 300) {
                $nodeSyncError = '';
                break;
            }
            $decodedNodeResp = is_string($nodeResp) ? json_decode($nodeResp, true) : null;
            $nodeSyncError = $nodeErr ?: ($decodedNodeResp['error'] ?? trim((string)($nodeResp ?: "HTTP {$nodeCode}")));
            usleep(500000);
        }
    }

    if ($restartError) {
        debug_log('Quick config restart failed: ' . $restartError);
    }
    if ($nodeSyncError) {
        debug_log('Quick config node sync failed: ' . $nodeSyncError);
    }

    $quickConfigWarning = '';
    if ($restartError) {
        $quickConfigWarning = $restartError;
    } elseif ($nodeSyncError) {
        $quickConfigWarning = "Configuração salva, mas não foi possível sincronizar no servidor Node ({$nodeSyncError}).";
    }

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $quickConfigWarning ?: 'Configurações salvas com sucesso!',
            'warning' => $quickConfigWarning ?: null
        ]);
        return true;
    }

    header("Location: ?instance=$selectedInstanceId");
    return true;
}
