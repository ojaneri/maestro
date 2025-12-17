<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';
require_once __DIR__ . '/external_auth.php';
date_default_timezone_set('America/Fortaleza');
if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

define('DEFAULT_GEMINI_INSTRUCTION', 'Você é um assistente atencioso e prestativo. Mantenha o tom profissional e informal. Sempre separe claramente o texto visível ao usuário do bloco de instruções/funções usando o marcador lógico &&& antes de iniciar os comandos.');
define('DEFAULT_MULTI_INPUT_DELAY', 0);

function isPortOpen($host, $port, $timeout = 1) {
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    } else {
        return false;
    }
}

function dados(string $email): array {
    $email = trim($email);
    if ($email === '') {
        throw new InvalidArgumentException('Email requerido');
    }

    $host = 'localhost';
    $db   = 'kitpericia';
    $user = 'kitpericia';
    $pass = 'kitpericia';
    $charset = 'utf8mb4';
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $user, $pass, $options);
    $sql = "
        SELECT 
            username,
            email,
            phone,
            expiration_date,
            DATEDIFF(expiration_date, CURDATE()) AS dias_restantes
        FROM users2
        WHERE email = :email
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException("Usuário não encontrado para {$email}");
    }

    $diasRestantes = (int)($row['dias_restantes'] ?? 0);
    if ($diasRestantes >= 0) {
        $status = 'ATIVO';
        $assinaturaInfo = "{$diasRestantes} dias restantes";
    } else {
        $status = 'EXPIRADO';
        $assinaturaInfo = abs($diasRestantes) . ' dias vencidos';
    }

    return [
        'nome'            => $row['username'] ?? '',
        'email'           => $row['email'] ?? '',
        'telefone'        => $row['phone'] ?? '',
        'status'          => $status,
        'assinatura_info' => $assinaturaInfo,
        'data_expiracao'  => isset($row['expiration_date']) ? date('d/m/Y', strtotime($row['expiration_date'])) : ''
    ];
}

function tableExists(SQLite3 $db, string $tableName): bool {
    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
    $stmt->bindValue(':name', $tableName, SQLITE3_TEXT);
    $result = $stmt->execute();
    $exists = false;
    if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $exists = true;
    }
    $result->finalize();
    $stmt->close();
    return $exists;
}

function fetchFromStorage(SQLite3 $db, string $instanceId, int $limit, string $table): array {
    if ($table === 'messages') {
        $query = "
            SELECT 
                remote_jid,
                (
                    SELECT content
                    FROM messages m2
                    WHERE m2.instance_id = :instance
                      AND m2.remote_jid = m.remote_jid
                    ORDER BY timestamp DESC
                    LIMIT 1
                ) AS last_message,
                MAX(timestamp) AS last_timestamp,
                (
                    SELECT role
                    FROM messages m2
                    WHERE m2.instance_id = :instance
                      AND m2.remote_jid = m.remote_jid
                    ORDER BY timestamp DESC
                    LIMIT 1
                ) AS last_role,
                COUNT(*) AS message_count
            FROM messages m
            WHERE m.instance_id = :instance
            GROUP BY remote_jid
            ORDER BY last_timestamp DESC
            LIMIT :limit
        ";
    } else {
        $query = "
            SELECT 
                remote_jid,
                MAX(contact_name) AS contact_name,
                (
                    SELECT content
                    FROM chat_history ch2
                    WHERE ch2.instance_id = ch.instance_id
                      AND ch2.remote_jid = ch.remote_jid
                    ORDER BY timestamp DESC
                    LIMIT 1
                ) AS last_message,
                (
                    SELECT timestamp
                    FROM chat_history ch2
                    WHERE ch2.instance_id = ch.instance_id
                      AND ch2.remote_jid = ch.remote_jid
                    ORDER BY timestamp DESC
                    LIMIT 1
                ) AS last_timestamp,
                (
                    SELECT role
                    FROM chat_history ch2
                    WHERE ch2.instance_id = ch.instance_id
                      AND ch2.remote_jid = ch.remote_jid
                    ORDER BY timestamp DESC
                    LIMIT 1
                ) AS last_role,
                COUNT(*) AS message_count
            FROM chat_history ch
            WHERE ch.instance_id = :instance
            GROUP BY remote_jid
            ORDER BY last_timestamp DESC
            LIMIT :limit
        ";
    }

    $stmt = $db->prepare($query);
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $result = $stmt->execute();

    $chats = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $chats[] = $row;
    }

    $result->finalize();
    $stmt->close();
    return $chats;
}

function fetchChatHistory($instanceId, $limit = 10) {
    $dbPath = __DIR__ . '/chat_data.db';
    if (!file_exists($dbPath)) {
        return [];
    }

    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $chats = [];

    if (tableExists($db, 'messages')) {
        $chats = fetchFromStorage($db, $instanceId, $limit, 'messages');
    }

    if (empty($chats) && tableExists($db, 'chat_history')) {
        $chats = fetchFromStorage($db, $instanceId, $limit, 'chat_history');
    }

    $db->close();
    return $chats;
}

function formatInstancePhoneLabel($jid) {
    if (!$jid) {
        return '';
    }
    $parts = explode('@', $jid, 2);
    $local = $parts[0];
    $domain = $parts[1] ?? 's.whatsapp.net';
    $digits = preg_replace('/\\D/', '', $local);
    $formatted = '';
    if (preg_match('/^55(\\d{2})(\\d{4,5})(\\d{4})$/', $digits, $matches)) {
        $formatted = "55 {$matches[1]} {$matches[2]}-{$matches[3]}";
    } elseif (preg_match('/^(\\d{2})(\\d{4,5})(\\d{4})$/', $digits, $matches)) {
        $formatted = "{$matches[1]} {$matches[2]}-{$matches[3]}";
    } elseif ($digits) {
        $formatted = $digits;
    }
    $label = $formatted ?: $local;
    return "{$label} @{$domain}";
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
try {
    $dotenv->load();
    debug_log('Dotenv load successful');
} catch (Exception $e) {
    debug_log('Dotenv load failed: ' . $e->getMessage());
}
debug_log('PANEL_USER_EMAIL from _ENV: ' . ($_ENV['PANEL_USER_EMAIL'] ?? 'not set'));
debug_log('PANEL_PASSWORD from _ENV: ' . ($_ENV['PANEL_PASSWORD'] ?? 'not set'));
debug_log('PANEL_USER_EMAIL from getenv: ' . (getenv('PANEL_USER_EMAIL') ?: 'not set'));
debug_log('PANEL_PASSWORD from getenv: ' . (getenv('PANEL_PASSWORD') ?: 'not set'));

// --- Autenticação ---
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF check for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        debug_log('CSRF token mismatch on POST request.');
        http_response_code(403);
        echo "Requisição inválida: Token CSRF ausente ou incorreto.";
        exit;
    }
}

debug_log('Session started. Auth: ' . (isset($_SESSION['auth']) ? 'true' : 'false'));
ensureExternalUsersSchema();
$externalUser = $_SESSION['external_user'] ?? null;
$isAdmin = isset($_SESSION['auth']) && $_SESSION['auth'];
$isManager = $externalUser && ($externalUser['role'] ?? '') === 'manager';
if (!$isAdmin && !$isManager) {
    debug_log('Auth not set, redirecting to login.php');
    include "login.php";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_ai_config']) && isset($_GET['instance'])) {
    $instanceIdForAjax = $_GET['instance'];
    $instanceRecord = loadInstanceRecordFromDatabase($instanceIdForAjax);
    $aiPayload = $instanceRecord['ai'] ?? [];
    header('Content-Type: application/json; charset=utf-8');
    if (!$instanceRecord) {
        echo json_encode([
            'ok' => false,
            'error' => 'Instância não encontrada'
        ]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'ai' => $aiPayload,
        'instance' => $instanceRecord['instance_id'] ?? $instanceIdForAjax
    ]);
    exit;
}

// --- Carregar instâncias ---
$instances = loadInstancesFromDatabase();
debug_log('Loaded ' . count($instances) . ' instances from SQLite');

$sidebarInstances = $instances;
if ($isManager) {
    $allowedIds = array_map(fn($entry) => $entry['instance_id'], $externalUser['instances'] ?? []);
    $sidebarInstances = array_filter($instances, function($inst, $identifier) use ($allowedIds) {
        return in_array($identifier, $allowedIds, true);
    }, ARRAY_FILTER_USE_BOTH);
}

if (!function_exists('buildInstanceStatuses')) {
    function buildInstanceStatuses(array $instances): array
    {
        $statuses = [];
        $connectionStatuses = [];
        foreach ($instances as $id => $inst) {
            $port = $inst['port'] ?? null;
            $status = $port && isPortOpen('localhost', $port) ? 'Running' : 'Stopped';
            $statuses[$id] = $status;
            debug_log("Status check for {$id} on port {$port}: {$status}");

            $connStatus = strtolower($inst['connection_status'] ?? '');
            if ($connStatus === '' && $status === 'Running') {
                $connStatus = 'connected';
            }
            $connectionStatuses[$id] = $connStatus ?: 'disconnected';
            debug_log("Connection status for {$id}: {$connectionStatuses[$id]}");
        }
        return [$statuses, $connectionStatuses];
    }
}

list($statuses, $connectionStatuses) = buildInstanceStatuses($instances);

if (!function_exists('renderSidebarContent')) {
    function renderSidebarContent(array $instances, ?string $selectedInstanceId, array $statuses, array $connectionStatuses, bool $showAdminControls = true)
    {
    ?>
    <div class="p-6 border-b border-mid">
      <a href="/api/envio/wpp/" class="flex items-center gap-3 inline-flex group">
        <div class="flex items-center justify-center h-12">
          <img src="assets/maestro-logo.png" width="56" style="height:auto;" alt="Logomarca Maestro">
        </div>
        <div>
          <div class="text-lg font-semibold text-dark">Maestro</div>
          <div class="text-xs text-slate-500">WhatsApp Orchestrator</div>
        </div>
      </a>

      <?php if ($showAdminControls): ?>
      <button onclick="openCreateModal()" class="mt-4 w-full px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90 transition">
        Nova instância
      </button>
      <button onclick="window.location.href='campanhas.php'" class="mt-3 w-full px-4 py-2 rounded-xl border border-primary text-primary font-medium hover:bg-primary/5 transition">
        Campanhas
      </button>
      <button onclick="window.location.href='external_access.php'" class="mt-3 w-full px-4 py-2 rounded-xl border border-primary text-primary font-medium hover:bg-primary/5 transition">
        Acessos
      </button>
      <?php endif; ?>

      <input class="mt-4 w-full px-3 py-2 rounded-xl bg-light border border-mid text-sm"
             placeholder="Buscar instância...">
    </div>

    <div class="p-3 space-y-2 flex-1 overflow-y-auto">
      <div class="text-xs text-slate-500 px-2">INSTÂNCIAS</div>

      <?php foreach ($instances as $id => $inst): ?>
        <?php
          $isSelected = $id === $selectedInstanceId;
          $aiDetails = $inst['ai'] ?? [];
          $aiProviderLabel = ucfirst($aiDetails['provider'] ?? ($inst['openai']['mode'] ?? 'ai'));
          $aiEnabledTag = !empty($aiDetails['enabled'] ?? $inst['openai']['enabled'] ?? false);
        ?>
        <div class="block w-full p-3 rounded-xl border <?= $isSelected ? 'border-primary bg-light' : 'border-mid bg-white hover:bg-light' ?> transition">
          <a href="?instance=<?= $id ?>" class="block">
            <div class="flex justify-between items-center">
              <div>
                <div class="font-medium"><?= htmlspecialchars($inst['name']) ?></div>
                <div class="text-xs text-slate-500">http://127.0.0.1:<?= $inst['port'] ?></div>
              </div>
              <div class="flex flex-col items-end gap-1">
                <?php if ($statuses[$id] === 'Running'): ?>
                  <span class="text-[11px] px-2 py-0.5 rounded bg-success/10 text-success">Servidor OK</span>
                <?php else: ?>
                  <span class="text-[11px] px-2 py-0.5 rounded bg-error/10 text-error">Parado</span>
                <?php endif; ?>
                <?php if (strtolower($connectionStatuses[$id]) === 'connected'): ?>
                  <span class="text-[11px] px-2 py-0.5 rounded bg-success/10 text-success">Conectado</span>
                <?php elseif ($statuses[$id] === 'Running'): ?>
                  <span class="text-[11px] px-2 py-0.5 rounded bg-alert/10 text-alert">Atenção</span>
                <?php else: ?>
                  <span class="text-[11px] px-2 py-0.5 rounded bg-mid text-dark">Desconectado</span>
                <?php endif; ?>
                <?php if ($aiEnabledTag): ?>
                  <span class="text-[11px] px-2 py-0.5 rounded bg-success/10 text-success flex items-center gap-1">
                    <svg class="w-3 h-3" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                      <circle cx="8" cy="5" r="2" stroke="currentColor" stroke-width="1.2"></circle>
                      <circle cx="5.5" cy="5" r="0.6" fill="currentColor"></circle>
                      <circle cx="10.5" cy="5" r="0.6" fill="currentColor"></circle>
                      <path d="M4 11c1 1 3 1 4 0s3-1 4 0" stroke="currentColor" stroke-linecap="round" stroke-width="1.2" fill="none"/>
                    </svg>
                    <?= htmlspecialchars($aiProviderLabel) ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </a>
          <?php $phoneLabel = formatInstancePhoneLabel($inst['phone'] ?? '') ?>
          <?php if ($phoneLabel): ?>
            <div class="text-[11px] text-slate-500 mt-2"><?= htmlspecialchars($phoneLabel) ?></div>
          <?php endif; ?>
          <div class="mt-3 flex justify-end">
            <a
              href="conversas.php?instance=<?= urlencode($id) ?>"
              class="text-[11px] px-2.5 py-1 rounded-full border border-primary/60 text-primary flex items-center gap-1 hover:bg-primary/10 transition"
            >
              <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M3 4.5A1.5 1.5 0 014.5 3h11A1.5 1.5 0 0117 4.5v6A1.5 1.5 0 0115.5 12H8l-4 4V4.5z"></path>
              </svg>
              Conversas
            </a>
          </div>
        </div>
      <?php endforeach; ?>

    </div>

    <div class="mt-auto p-6 border-t border-mid">
      <button onclick="logout()" class="w-full text-left text-sm text-slate-500 hover:text-dark">Logout</button>
      <div class="text-xs text-slate-500 mt-2">Maestro • MVP</div>
    </div>
    <?php
    }
}

$totalInstances = count($instances);
$runningInstances = count(array_filter($statuses, fn($status) => $status === 'Running'));
$connectedInstances = count(array_filter($connectionStatuses, fn($conn) => strtolower($conn) === 'connected'));
$disconnectedInstances = $totalInstances - $connectedInstances;
$activePercent = $totalInstances ? round($runningInstances / $totalInstances * 100) : 0;
$connectedPercent = $totalInstances ? round($connectedInstances / $totalInstances * 100) : 0;
$disconnectedPercent = $totalInstances ? round(max(0, $disconnectedInstances) / $totalInstances * 100) : 0;

// Select instance early so handlers can reuse it
$selectedInstanceId = $_GET['instance'] ?? null;
if ($selectedInstanceId && !isset($sidebarInstances[$selectedInstanceId])) {
    $selectedInstanceId = null;
}
$selectedInstanceId = $selectedInstanceId ?? (array_key_first($sidebarInstances) ?: null);
$selectedInstance = $sidebarInstances[$selectedInstanceId] ?? null;
$selectedPhoneLabel = $selectedInstance ? formatInstancePhoneLabel($selectedInstance['phone'] ?? '') : '';

$curlEndpointPort = $selectedInstance['port'] ?? 3010;
$curlEndpoint = "http://127.0.0.1:{$curlEndpointPort}/send-message";
$curlPayloadArray = [
    'to' => '5585999999999@s.whatsapp.net',
    'message' => 'Mensagem enviada via API'
];
$curlPayload = json_encode($curlPayloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sampleCurlCommand = <<<CURL
curl -X POST "{$curlEndpoint}" \\
  -H "Content-Type: application/json" \\
  -d '{$curlPayload}'
CURL;

// Handle AJAX send-card requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_send'])) {
    header('Content-Type: application/json; charset=utf-8');
    $payloadRaw = file_get_contents('php://input');
    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $phone = trim($payload['phone'] ?? '');
    $message = trim($payload['message'] ?? '');

    if (!$selectedInstance) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Instância não encontrada para envio']);
        exit;
    }

    if (!$phone || !$message) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Telefone e mensagem são obrigatórios']);
        exit;
    }

    debug_log("AJAX send-card request for {$selectedInstanceId}: phone={$phone}");
    $sendUrl = "http://127.0.0.1:{$selectedInstance['port']}/send-message";
    $ch = curl_init($sendUrl);
    $body = json_encode(['to' => $phone, 'message' => $message]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    debug_log("AJAX send-card response ({$httpCode}) for {$selectedInstanceId}: {$response}");

    $responsePayload = json_decode($response, true);
    if ($curlError) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => "Falha ao enviar mensagem: {$curlError}"]);
        exit;
    }

    if (!$responsePayload || !isset($responsePayload['ok']) || !$responsePayload['ok'] || $httpCode >= 400) {
        $errorMessage = $responsePayload['error'] ?? ($responsePayload['detail'] ?? "Erro HTTP {$httpCode}");
        http_response_code($httpCode >= 400 ? $httpCode : 500);
        echo json_encode(['ok' => false, 'error' => $errorMessage]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Mensagem encaminhada com sucesso',
        'remoteJid' => $responsePayload['to'] ?? null,
        'apiResponse' => $responsePayload
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_history'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$selectedInstance) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Instância não encontrada']);
        exit;
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
                exit;
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
    } catch (Exception $err) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erro ao ler histórico']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_ai_test'])) {
    if (!$selectedInstance) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Instância não encontrada']);
        exit;
    }

    $dataRaw = file_get_contents('php://input');
    $payload = json_decode($dataRaw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $userMessage = trim($payload['message'] ?? '');
    $remoteJid = trim($payload['remote_jid'] ?? '');
    if (!$userMessage) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Mensagem é obrigatória']);
        exit;
    }

    $nodeBody = ['message' => $userMessage];
    if ($remoteJid) {
        $nodeBody['remote_jid'] = $remoteJid;
    }

    $nodeUrl = "http://127.0.0.1:{$selectedInstance['port']}/api/ai-test";
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
        exit;
    }

    $result = json_decode($resp, true);
    if ($httpCode >= 400 || !is_array($result)) {
        http_response_code($httpCode >= 400 ? $httpCode : 500);
        $message = $result['error'] ?? 'Resposta inválida do servidor AI';
        echo json_encode(['ok' => false, 'error' => $message, 'raw' => $result]);
        exit;
    }

    echo json_encode($result);
    exit;
}

// --- Criar nova instância ---
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
    header("Location: /api/envio/wpp/");
    exit;
}

// --- Ações ---
if (isset($_GET["delete"])) {
    $deleteId = $_GET["delete"];
    debug_log('Deleting instance: ' . $deleteId);
    $deletedFromSql = deleteInstanceRecordFromSql($deleteId);
    if ($deletedFromSql) {
        debug_log('Instance removed from SQLite: ' . $deleteId);
    } else {
        debug_log('Instance could not be removed from SQLite (maybe missing): ' . $deleteId);
    }
    debug_log('Redirecting to /api/envio/wpp/ after delete');
    header("Location: /api/envio/wpp/");
    exit;
}

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
        return [
            'ok' => false,
            'status' => $statusCode,
            'error' => "QR request retornou código HTTP {$httpCode}"
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

if (isset($_GET["qr"])) {
    debug_log('QR requested for instance: ' . $_GET['qr']);
    $instanceId = $_GET['qr'];
    $qrResult = fetchInstanceQrImageUrl($instanceId);

    if (!$qrResult['ok']) {
        $code = $qrResult['status'] ?? 500;
        debug_log("QR request failed: {$qrResult['error']} (code: {$code})");
        http_response_code($code);
        exit;
    }

    header("Location: {$qrResult['qr_url']}");
    exit;
}

if (isset($_GET['qr_data'])) {
    header('Content-Type: application/json; charset=utf-8');
    $instanceId = $_GET['qr_data'];
    $qrResult = fetchInstanceQrImageUrl($instanceId);

    if ($qrResult['ok']) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'qr_url' => $qrResult['qr_url']]);
        exit;
    }

    $code = $qrResult['status'] ?? 500;
    debug_log("QR data request failed: {$qrResult['error']} (code: {$code})");
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $qrResult['error']]);
    exit;
}

if (isset($_POST["disconnect"])) {
    debug_log('Disconnecting instance: ' . $_POST['disconnect']);
    $id = $_POST["disconnect"];
    if (isset($instances[$id])) {
        exec("bash stop_instance.sh {$id} >/dev/null 2>&1 &");
        debug_log('Executed stop_instance.sh for ' . $id);
    }
    header("Location: /api/envio/wpp/");
    exit;
}

if (isset($_GET['logout'])) {
    debug_log('Logout requested');
    session_destroy();
    header("Location: /api/envio/wpp/");
    exit;
}

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
        $sendError = "Erro ao enviar: $error";
    } else {
        debug_log("Send response: $response");
        $sendSuccess = "Mensagem enviada com sucesso!";
    }
    // Redirect to avoid resubmit
    header("Location: ?instance=$selectedInstanceId");
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_instance']) && $selectedInstance) {
    $newName = trim($_POST['instance_name'] ?? '');
    $newBaseUrl = trim($_POST['instance_base_url'] ?? '');

    if ($newName === '') {
        $quickConfigError = 'Nome da instância é obrigatório.';
    } else {
        $resolvedBaseUrl = $newBaseUrl ?: ($selectedInstance['base_url'] ?? ("http://127.0.0.1:{$selectedInstance['port']}"));
        $updatePayload = [
            'name' => $newName,
            'base_url' => $resolvedBaseUrl,
            'port' => $selectedInstance['port'] ?? null,
            'api_key' => $selectedInstance['api_key'] ?? null,
            'status' => $selectedInstance['status'] ?? null,
            'connection_status' => $selectedInstance['connection_status'] ?? null,
            'phone' => $selectedInstance['phone'] ?? null
        ];
        $updateResult = upsertInstanceRecordToSql($selectedInstanceId, $updatePayload);
        if (!$updateResult['ok']) {
            $quickConfigError = 'Falha ao salvar configurações: ' . $updateResult['message'];
            debug_log('AI config quick save failed: ' . $updateResult['message']);
        } else {
            $instances = loadInstancesFromDatabase();
            list($statuses, $connectionStatuses) = buildInstanceStatuses($instances);
            $selectedInstance = $instances[$selectedInstanceId] ?? null;
            $quickConfigMessage = 'Configurações salvas com sucesso.';
        }
    }
}

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maestro – Orquestrador WhatsApp</title>

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
    body { overflow-x: hidden; }
    .min-h-screen.flex { min-width: 0; }
    main { min-width: 0; }
    .grid { min-width: 0; }
  </style>
</head>

<body class="bg-light text-dark">
  <div class="min-h-screen flex">

  <!-- SIDEBAR / INSTÂNCIAS -->
  <aside id="desktopSidebar" class="w-80 bg-white border-r border-mid hidden lg:flex flex-col">
    <?php renderSidebarContent($sidebarInstances, $selectedInstanceId, $statuses, $connectionStatuses, $isAdmin); ?>
  </aside>

  <div id="mobileSidebarContainer" class="fixed inset-0 z-50 hidden lg:hidden">
    <div id="mobileSidebarOverlay" class="absolute inset-0 bg-black/50"></div>
    <aside class="relative z-10 h-full w-72 max-w-xs bg-white border-r border-mid flex flex-col">
      <div class="flex items-center justify-between p-4 border-b border-mid">
        <span class="text-base font-semibold">Instâncias</span>
        <button id="closeMobileSidebar" class="text-slate-500 hover:text-dark">
          &times;
        </button>
      </div>
      <?php renderSidebarContent($sidebarInstances, $selectedInstanceId, $statuses, $connectionStatuses, $isAdmin); ?>
    </aside>
  </div>
  <!-- ÁREA CENTRAL -->
  <main class="flex-1 p-8 space-y-6">

    <!-- HEADER -->
      <div class="flex justify-between items-start">
        <div class="flex items-start gap-3">
        <button id="openSidebarBtn" class="lg:hidden inline-flex items-center justify-center rounded-xl border border-mid bg-white text-slate-600 p-2 hover:border-primary hover:text-primary transition">
          <span class="sr-only">Abrir menu de instâncias</span>
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
          </svg>
        </button>
        <div>
          <h1 class="text-2xl font-semibold"><?= htmlspecialchars($selectedInstance['name'] ?? 'Nenhuma instância') ?></h1>
          <p class="text-slate-500 mt-1">Configurações da instância selecionada</p>

          <div class="mt-3 flex gap-2 text-xs">
            <?php if (($statuses[$selectedInstanceId] ?? '') === 'Running'): ?>
              <span class="px-2 py-1 rounded bg-success/10 text-success">Servidor OK</span>
            <?php endif; ?>
            <?php if (strtolower($connectionStatuses[$selectedInstanceId] ?? '') === 'connected'): ?>
              <span class="px-2 py-1 rounded bg-success/10 text-success">WhatsApp Conectado</span>
            <?php endif; ?>
          </div>
          <?php $selectedPhoneLabel = formatInstancePhoneLabel($selectedInstance['phone'] ?? '') ?>
          <?php if ($selectedPhoneLabel): ?>
            <div class="text-sm text-slate-500 mt-2">
              WhatsApp local: <?= htmlspecialchars($selectedPhoneLabel) ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="flex gap-2">
        <?php if ($selectedInstance && strtolower($connectionStatuses[$selectedInstanceId] ?? '') !== 'connected' && $statuses[$selectedInstanceId] === 'Running'): ?>
          <button onclick="openQRModal('<?= $selectedInstanceId ?>')" class="px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">
            Conectar QR
          </button>
        <?php endif; ?>
        <?php if ($selectedInstance && strtolower($connectionStatuses[$selectedInstanceId] ?? '') === 'connected'): ?>
          <form method="POST" class="inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="disconnect" value="<?= $selectedInstanceId ?>">
            <button type="submit" class="px-4 py-2 rounded-xl bg-error text-white font-medium hover:opacity-90">
              Desconectar
            </button>
          </form>
        <?php endif; ?>
        <?php if ($selectedInstance): ?>
          <a href="?delete=<?= $selectedInstanceId ?>" onclick="return confirm('Tem certeza?')" class="px-4 py-2 rounded-xl bg-error text-white font-medium hover:opacity-90">
            Deletar
          </a>
        <?php endif; ?>
        <button class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
          Salvar alterações
        </button>
      </div>
    </div>

    <!-- GRID -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

      <!-- ENVIO -->
      <section class="xl:col-span-2 bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-4">Enviar mensagem</div>

        <?php
        $sendStatusClass = 'text-slate-500';
        $sendStatusMessage = '';
        if (isset($sendSuccess)) {
            $sendStatusClass = 'text-success font-medium';
            $sendStatusMessage = $sendSuccess;
        } elseif (isset($sendError)) {
            $sendStatusClass = 'text-error font-medium';
            $sendStatusMessage = $sendError;
        }
        ?>
        <form id="sendForm" method="POST" action="?instance=<?= $selectedInstanceId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div>
              <label class="text-xs text-slate-500">Número destino</label>
              <input name="phone" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     placeholder="5585999999999" required>
            </div>

            <div class="lg:col-span-2">
              <label class="text-xs text-slate-500">Mensagem</label>
              <textarea name="message" rows="4" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                        placeholder="Digite sua mensagem..." required></textarea>
            </div>
          </div>

          <button type="submit" name="send" id="sendButton"
                  class="mt-4 px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
            Enviar mensagem
          </button>
          <p id="sendStatus" aria-live="polite" class="mt-2 text-sm <?= $sendStatusClass ?>">
            <?= $sendStatusMessage ? htmlspecialchars($sendStatusMessage) : '&nbsp;' ?>
          </p>
        </form>
      </section>

      <!-- CONFIG RÁPIDA -->
    <aside class="bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-4">Configuração rápida</div>

      <?php
      $quickConfigName = $selectedInstance['name'] ?? '';
      $quickConfigBaseUrl = $selectedInstance['base_url'] ?? ("http://127.0.0.1:" . ($selectedInstance['port'] ?? ''));
      ?>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div>
          <label class="text-xs text-slate-500">Nome da instância</label>
          <input name="instance_name" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                 value="<?= htmlspecialchars($quickConfigName) ?>" required>
        </div>

        <div>
          <label class="text-xs text-slate-500">Base URL</label>
          <input name="instance_base_url" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                 value="<?= htmlspecialchars($quickConfigBaseUrl) ?>" required>
        </div>

        <input type="hidden" name="update_instance" value="1">
        <button type="submit"
                class="w-full px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
          Salvar
        </button>

        <?php if (!empty($quickConfigMessage ?? null)): ?>
          <p class="text-xs text-success mt-1"><?= htmlspecialchars($quickConfigMessage) ?></p>
<?php elseif (!empty($quickConfigError ?? null)): ?>
          <p class="text-xs text-error mt-1"><?= htmlspecialchars($quickConfigError) ?></p>
        <?php endif; ?>
      </form>
    </aside>

    </div>

    <section class="bg-white border border-mid rounded-2xl p-6">
      <div class="flex items-start justify-between">
        <div>
          <div class="font-medium mb-1">Exemplo CURL para enviar mensagem</div>
          <p class="text-sm text-slate-500">
            Copie e cole este comando ajustando o número e a mensagem. Ele usa a instância selecionada
            (porta <?= htmlspecialchars($curlEndpointPort) ?>).
          </p>
        </div>
        <?php if (!$selectedInstance): ?>
          <span class="text-xs px-2 py-1 rounded-full bg-alert/10 text-alert">Instância padrão</span>
        <?php endif; ?>
      </div>
    <pre class="mt-4 overflow-auto text-xs rounded-xl bg-black/90 text-white p-4 max-w-xl"><code><?= htmlspecialchars($sampleCurlCommand) ?></code></pre>
    </section>

    <?php
    $legacyOpenAIConfig = $selectedInstance['openai'] ?? [];
    $aiConfig = $selectedInstance['ai'] ?? [];
    $aiEnabled = isset($aiConfig['enabled']) ? (bool)$aiConfig['enabled'] : !empty($legacyOpenAIConfig['enabled']);
    $aiProviderRaw = $aiConfig['provider'] ?? 'openai';
    $aiProvider = in_array(strtolower($aiProviderRaw), ['openai', 'gemini'], true) ? strtolower($aiProviderRaw) : 'openai';
    $aiModel = $aiConfig['model'] ?? $legacyOpenAIConfig['model'] ?? 'gpt-4.1-mini';
    $aiHistoryLimit = max(1, (int)($aiConfig['history_limit'] ?? $legacyOpenAIConfig['history_limit'] ?? 20));
    $aiTemperature = $aiConfig['temperature'] ?? $legacyOpenAIConfig['temperature'] ?? 0.3;
    $aiMaxTokens = max(64, (int)($aiConfig['max_tokens'] ?? $legacyOpenAIConfig['max_tokens'] ?? 600));
    $aiMultiInputDelay = max(0, (int)($aiConfig['multi_input_delay'] ?? DEFAULT_MULTI_INPUT_DELAY));
    $defaultSystemPrompt = 'You are a helpful WhatsApp assistant. Respond naturally and concisely.';
    $aiSystemPrompt = $aiConfig['system_prompt'] ?? $legacyOpenAIConfig['system_prompt'] ?? $defaultSystemPrompt;
    $aiAssistantPrompt = $aiConfig['assistant_prompt'] ?? $legacyOpenAIConfig['assistant_prompt'] ?? '';
    $aiAssistantId = $aiConfig['assistant_id'] ?? $legacyOpenAIConfig['assistant_id'] ?? '';
    $aiOpenaiMode = $aiConfig['openai_mode'] ?? $legacyOpenAIConfig['mode'] ?? 'responses';
    $aiOpenaiApiKey = $aiConfig['openai_api_key'] ?? $legacyOpenAIConfig['api_key'] ?? '';
    $aiGeminiApiKey = $aiConfig['gemini_api_key'] ?? '';
    $aiGeminiInstruction = $aiConfig['gemini_instruction'] ?? DEFAULT_GEMINI_INSTRUCTION;
    ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

      <section class="xl:col-span-2 bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-1">IA – OpenAI &amp; Gemini</div>
        <p class="text-sm text-slate-500 mb-4">Defina o comportamento das respostas automáticas desta instância.</p>

        <form id="aiSettingsForm" class="space-y-4" onsubmit="return false;">
          <div class="flex items-center gap-2">
            <input type="checkbox" id="aiEnabled" class="h-4 w-4 rounded" <?= $aiEnabled ? 'checked' : '' ?>>
            <label for="aiEnabled" class="text-sm text-slate-600">
              Habilitar respostas automáticas
            </label>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label class="text-xs text-slate-500">Provider</label>
              <select id="aiProvider" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm">
                <option value="openai" <?= $aiProvider === 'openai' ? 'selected' : '' ?>>OpenAI (Responses / Assistants)</option>
                <option value="gemini" <?= $aiProvider === 'gemini' ? 'selected' : '' ?>>Gemini 2.5 Flash</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-slate-500">Modelo</label>
              <div class="space-y-2 mt-1">
                <select id="aiModelPreset" class="w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
                  <!-- preenchido via JS -->
                </select>
                <input id="aiModel" class="w-full px-3 py-2 rounded-xl border border-mid bg-light"
                       value="<?= htmlspecialchars($aiModel) ?>" placeholder="Digite outro modelo">
              </div>
            </div>
          </div>

          <div id="openaiFields" class="space-y-4 <?= $aiProvider === 'openai' ? '' : 'hidden' ?>">
            <div>
              <label class="text-xs text-slate-500">OpenAI API Key</label>
              <div class="relative mt-1">
                <input id="openaiApiKey" type="password" autocomplete="new-password"
                       class="w-full px-3 py-2 rounded-xl border border-mid bg-light pr-10"
                       placeholder="sk-..." value="<?= htmlspecialchars($aiOpenaiApiKey) ?>">
                <button id="toggleOpenaiKey" type="button"
                        class="absolute inset-y-0 right-2 flex items-center justify-center text-slate-500 hover:text-primary"
                        aria-pressed="false" aria-label="Mostrar ou ocultar chave">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M1.5 12s4.5-8.5 10.5-8.5S22.5 12 22.5 12s-4.5 8.5-10.5 8.5S1.5 12 1.5 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
              <p class="text-[11px] text-slate-500 mt-1">
                Use uma chave com acesso ao Responses e Assistants.
              </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div>
                <label class="text-xs text-slate-500">API Mode</label>
                <select id="openaiMode" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm">
                  <option value="responses" <?= $aiOpenaiMode === 'responses' ? 'selected' : '' ?>>Responses API</option>
                  <option value="assistants" <?= $aiOpenaiMode === 'assistants' ? 'selected' : '' ?>>Assistants API</option>
                </select>
                <p class="text-xs text-slate-500 mt-1">
                  Choose if the instance keeps a thread (Assistants) or uses context snapshots (Responses).
                </p>
              </div>
              <div id="openaiAssistantRow" style="<?= $aiOpenaiMode === 'assistants' ? '' : 'display:none;' ?>">
                <label class="text-xs text-slate-500">Assistant ID</label>
                <input id="openaiAssistantId" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm"
                       placeholder="assistant_id" value="<?= htmlspecialchars($aiAssistantId) ?>">
                <p class="text-xs text-slate-500 mt-1">
                  Obrigatório apenas no modo Assistants API.
                </p>
              </div>
            </div>

          <div>
            <label class="text-xs text-slate-500">System prompt</label>
            <textarea id="aiSystemPrompt" rows="4"
                      class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                      placeholder="Descreva o papel do assistente"><?= htmlspecialchars($aiSystemPrompt) ?></textarea>
          </div>

          <div class="space-y-2">
            <div class="flex items-start justify-between gap-2">
              <label class="text-xs text-slate-500">Assistant instructions</label>
              <button id="aiFunctionsButton" type="button"
                      class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                Funções disponíveis
              </button>
            </div>
            <textarea id="aiAssistantPrompt" rows="4"
                      class="w-full px-3 py-2 rounded-xl border border-mid bg-light"
                      placeholder="Como o assistente deve responder?"><?= htmlspecialchars($aiAssistantPrompt) ?></textarea>
          </div>
          </div>

          <div id="geminiFields" class="space-y-4 <?= $aiProvider === 'gemini' ? '' : 'hidden' ?>">
            <div>
              <label class="text-xs text-slate-500">Gemini API Key</label>
              <div class="relative mt-1">
                <input id="geminiApiKey" type="password" autocomplete="new-password"
                       class="w-full px-3 py-2 rounded-xl border border-mid bg-light pr-10"
                       placeholder="GAPI..." value="<?= htmlspecialchars($aiGeminiApiKey) ?>">
                <button id="toggleGeminiKey" type="button"
                        class="absolute inset-y-0 right-2 flex items-center justify-center text-slate-500 hover:text-primary"
                        aria-pressed="false" aria-label="Mostrar ou ocultar chave">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M1.5 12s4.5-8.5 10.5-8.5S22.5 12 22.5 12s-4.5 8.5-10.5 8.5S1.5 12 1.5 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
              <p class="text-xs text-slate-500 mt-1">
                Utilize sua chave da Google Generative AI.
              </p>
            </div>
            <div class="space-y-2">
              <div class="flex items-start justify-between gap-2">
                <label class="text-xs text-slate-500">Instruções do Gemini</label>
                <button id="geminiFunctionsButton" type="button"
                        class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                  Funções disponíveis
                </button>
              </div>
              <textarea id="geminiInstruction" rows="4"
                        class="w-full px-3 py-2 rounded-xl border border-mid bg-light"
                        placeholder="Instrua o Gemini"><?= htmlspecialchars($aiGeminiInstruction) ?></textarea>
            </div>
            <div>
              <label class="text-xs text-slate-500">Credencial Gemini</label>
              <p class="text-[11px] text-slate-500 mt-1">
                O Gemini aceita apenas a API key configurada acima; não é necessário enviar um arquivo JSON de credenciais.
              </p>
            </div>
          </div>

            <div id="functionsPanel" class="hidden border border-mid/70 rounded-2xl bg-white p-4 shadow-sm text-sm text-slate-600 space-y-3">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-slate-400">Funções disponíveis</div>
            <ul class="space-y-2">
              <li>
                <span class="font-semibold text-slate-800">dados("email")</span> – traz cadastro do cliente (nome, status, assinatura e expiração) para enriquecer o contexto.
              </li>
              <li>
                <span class="font-semibold text-slate-800">agendar("DD/MM/AAAA","HH:MM","Texto","tag","tipo")</span> – agenda lembrete fixo em UTC-3 e retorna ID, horário, tag e tipo (tag padrão <code>default</code>, tipo <code>followup</code>).
              </li>
              <li>
                <span class="font-semibold text-slate-800">agendar2("+5m","Texto","tag","tipo")</span> – lembra em tempo relativo (m/h/d), também com tag/tipo configuráveis.
              </li>
              <li>
                <span class="font-semibold text-slate-800">cancelar_e_agendar2("+24h","Texto","tag","tipo")</span> – cancela pendentes, dispara novo lembrete e devolve quantos foram cancelados.
              </li>
              <li>
                <span class="font-semibold text-slate-800">listar_agendamentos("tag","tipo") / apagar_agenda("scheduledId") / apagar_agendas_por_tag("tag") / apagar_agendas_por_tipo("tipo")</span> – controlam o inventário de lembretes.
              </li>
              <li>
                <span class="font-semibold text-slate-800">set_estado("estado") / get_estado()</span> – mantém o estágio atual do funil.
              </li>
              <li>
                <span class="font-semibold text-slate-800">set_contexto("chave","valor") / get_contexto("chave") / limpar_contexto(["chave"])</span> – memória curta por contato para pistas extras.
              </li>
              <li>
                <span class="font-semibold text-slate-800">optout()</span> – cancela follow-ups e marca o contato para não receber novas tentativas.
              </li>
              <li>
                <span class="font-semibold text-slate-800">status_followup()</span> – resumo de estado, trilhas ativas e próximos agendamentos.
              </li>
              <li>
                <span class="font-semibold text-slate-800">tempo_sem_interacao()</span> – responde quanto tempo passou desde a última resposta do cliente.
              </li>
              <li>
                <span class="font-semibold text-slate-800">log_evento("categoria","descrição","json_opcional")</span> – auditoria leve com categoria e mensagem.
              </li>
              <li>
                <span class="font-semibold text-slate-800">boomerang()</span> – dispara imediatamente outra resposta (“Boomerang acionado”) e registra o aviso.
              </li>
              <li>
                <span class="font-semibold text-slate-800">whatsapp("numero","mensagem")</span> – envia mensagem direta via WhatsApp.
              </li>
              <li>
                <span class="font-semibold text-slate-800">mail("destino","assunto","corpo")</span> – envia um e-mail com sendmail local.
              </li>
              <li>
                <span class="font-semibold text-slate-800">get_web("URL")</span> – busca até 1.200 caracteres de outra página para contexto.
              </li>
            </ul>
            <p class="text-[11px] text-slate-500">
              É possível encadear várias funções em uma única resposta; elas serão executadas na ordem em que aparecem e não serão expostas ao usuário final.
            </p>
            <p class="text-[11px] text-slate-400">
              Clique novamente em “Funções disponíveis” para esconder este card.
            </p>
            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3 text-[12px] space-y-2">
              <div class="font-medium text-slate-800">Guia para prompts</div>
              <p class="text-[11px] text-slate-500">
                Copie esse texto para o prompt da IA que alimenta o bot. Ele explica o comportamento esperado e todas as funções já disponíveis.
              </p>
              <pre id="functionsGuide" class="p-3 rounded-xl bg-slate-100 text-xs overflow-auto max-h-48" style="white-space: pre-wrap;">
Instruções de funções:

- dados("email"): traz nome, email, telefone, status e validade da assinatura do cadastro no MySQL kitpericia.
- agendar("DD/MM/AAAA","HH:MM","Texto","tag","tipo") / agendar2("+5m","Texto","tag","tipo"): agendam lembretes com tag/tipo (padrões tag=default, tipo=followup) e retornam ID + horário.
- cancelar_e_agendar2("+24h","Texto","tag","tipo"): cancela tudo pendente, cria novo lembrete e informa quantos foram cancelados.
- listar_agendamentos("tag","tipo"): lista agendamentos do contato; apagar_agenda("scheduledId"), apagar_agendas_por_tag("tag") e apagar_agendas_por_tipo("tipo") mantêm o painel limpo.
- set_estado("estado") / get_estado(): salva e consulta o estágio do funil.
- set_contexto("chave","valor") / get_contexto("chave") / limpar_contexto(["chave"]): memória curta por contato para pistas extras.
- optout(): cancela follow-ups pendentes e marca que o cliente não deve receber novas tentativas.
- status_followup(): resumo de estado, trilhas ativas e próximos agendamentos pendentes.
- tempo_sem_interacao(): retorna há quantos segundos o cliente está em silêncio, útil para ajustar o tom (curto = gentil, longo = acolhedor).
- log_evento("categoria","descrição","json_opcional"): auditoria leve para métricas.
- boomerang(): sinaliza envio imediato de "Boomerang acionado".
- whatsapp("numero","mensagem"), mail("destino","assunto","corpo") e get_web("URL") seguem como antes.

Retorno recomendado:
{
  ok: true|false,
  code: "OK"|"ERR_INVALID_ARGS"|...,
  message: "texto curto",
  data: { ... }
}

Como usar:
1. Sempre finalize sua resposta com as funções desejadas no formato `funcao("arg1","arg2",...)`; múltiplas funções podem ser separadas por linha ou espaço.
2. Evite texto livre extra quando quiser apenas acionar funções; explicações podem vir antes dos comandos.
3. O bot remove esses comandos antes de responder ao usuário.
4. Ajuste o tom usando `tempo_sem_interacao()` e, quando necessário, `status_followup()` para acompanhar o funil.
5. Separe o texto destinado ao usuário das instruções/funções com `&&&`; o que vier depois do marcador será tratado como comandos e não será enviado ao WhatsApp.
</pre>
              <div class="flex justify-end gap-2">
                <button id="copyFunctionsGuide" class="px-3 py-1 text-[11px] font-medium rounded-full border border-primary text-primary hover:bg-primary/10 transition">Copiar guia</button>
                <span id="functionsGuideFeedback" class="text-[11px] text-success hidden">Copiado!</span>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div>
              <label class="text-xs text-slate-500">Histórico (últimas mensagens)</label>
              <input id="aiHistoryLimit" type="number" min="1" step="1"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     value="<?= htmlspecialchars($aiHistoryLimit) ?>">
            </div>
            <div>
              <label class="text-xs text-slate-500">Temperatura</label>
              <input id="aiTemperature" type="number" min="0" max="2" step="0.1"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     value="<?= htmlspecialchars($aiTemperature) ?>">
            </div>
            <div>
              <label class="text-xs text-slate-500">Tokens máximos</label>
              <input id="aiMaxTokens" type="number" min="64" step="1"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     value="<?= htmlspecialchars($aiMaxTokens) ?>">
            </div>
          </div>

          <div>
            <label class="text-xs text-slate-500">Delay multi-input (segundos)</label>
            <input id="aiMultiInputDelay" type="number" min="0" step="1"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($aiMultiInputDelay) ?>">
            <p class="text-[11px] text-slate-500 mt-1">
              Aguarda esta quantidade de segundos antes de responder para coletar mensagens adicionais do usuário.
            </p>
          </div>

          <div class="flex flex-wrap gap-2 items-center">
            <button type="button" id="saveAIButton"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar
            </button>
            <button type="button" id="testAIButton"
                    class="px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">
              Testar IA
            </button>
            <p id="aiStatus" aria-live="polite" class="text-sm text-slate-500 mt-2 sm:mt-0">
              &nbsp;
            </p>
          </div>
        </form>
      </section>

    </div>

    <section id="chatHistorySection" class="bg-white border border-mid rounded-2xl p-6 mt-6 hidden">
      <div class="flex items-center justify-between">
        <div>
          <h2 class="text-lg font-semibold text-dark">Histórico de conversas</h2>
          <p class="text-xs text-slate-500">Últimos contatos com mensagens salvas</p>
        </div>
        <button id="refreshHistoryBtn" class="px-3 py-1 rounded-xl border border-mid text-xs text-slate-600 hover:bg-light">
          Atualizar
        </button>
      </div>
      <div id="historyStatus" class="text-xs text-slate-500 mt-3">Carregando histórico...</div>
      <div id="historyList" class="mt-4 space-y-3"></div>
    </section>
  </main>
</div>
<footer class="w-full bg-slate-900 text-slate-200 text-xs text-center py-3 mt-6">
  Por <strong>Osvaldo J. Filho</strong> |
  <a href="https://linkedin.com/in/ojaneri" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">LinkedIn</a> |
  <a href="https://github.com/ojaneri/maestro" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">GitHub</a>
</footer>
<!-- Modal for Create Instance -->
<div id="createModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-lg font-semibold">Criar nova instância</h2>
      <button onclick="closeCreateModal()" class="text-slate-500 hover:text-dark">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
      <div class="mb-4">
        <label class="text-xs text-slate-500">Nome da instância</label>
        <input type="text" name="name" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Ex: Instância Principal" required>
      </div>
      <button type="submit" name="create" class="w-full px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">Criar instância</button>
    </form>
  </div>
</div>

<!-- Modal for QR Code -->
<div id="qrModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-lg font-semibold">Conectar WhatsApp</h2>
      <button onclick="closeQRModal()" class="text-slate-500 hover:text-dark">&times;</button>
    </div>
    <p class="text-sm text-slate-600 mb-4">Escaneie o código QR abaixo com o WhatsApp para conectar esta instância.</p>
    <div class="text-center space-y-3">
      <img id="qrImage" src="" alt="Código QR" class="mx-auto" style="display:none;">
      <p id="qrStatus" class="text-sm text-slate-500 mx-auto"></p>
    </div>
    <button onclick="refreshQR()" class="mt-4 w-full px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">Atualizar QR</button>
  </div>
</div>

<!-- Modal for AI Test -->
<div id="aiTestModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 px-4">
  <div class="bg-white rounded-2xl p-6 w-full max-w-lg mx-auto relative">
    <button id="closeAiTestModal" class="absolute top-3 right-3 text-slate-400 hover:text-dark">&times;</button>
    <h3 class="text-lg font-semibold mb-2">Testar IA</h3>
    <p class="text-xs text-slate-500 mb-4">Envie uma mensagem e veja como o provedor configurado responde.</p>
    <form id="aiTestForm" class="space-y-3">
      <label class="text-xs text-slate-500">Mensagem para teste</label>
      <textarea id="aiTestMessage" rows="4" class="w-full px-3 py-2 rounded-xl border border-mid bg-light" required></textarea>
      <div class="flex gap-3 items-center">
        <button type="submit" id="aiTestSubmit" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
          Enviar para IA
        </button>
        <span id="aiTestStatus" class="text-xs text-slate-500"></span>
      </div>
      <div id="aiTestResult" class="text-sm text-dark bg-light border border-mid rounded-xl px-3 py-2 min-h-[80px] whitespace-pre-line"></div>
    </form>
  </div>
</div>

<script>
let activeQrInstanceId = null;

function logout() {
  window.location.href = '?logout=1';
}

function openCreateModal() {
  document.getElementById('createModal').classList.remove('hidden');
}

function closeCreateModal() {
  document.getElementById('createModal').classList.add('hidden');
}

function openQRModal(instanceId) {
  if (!instanceId) {
    alert('Nenhuma instância selecionada');
    return;
  }
  activeQrInstanceId = instanceId;
  document.getElementById('qrModal').classList.remove('hidden');
  refreshQR(instanceId);
}

function closeQRModal() {
  activeQrInstanceId = null;
  document.getElementById('qrModal').classList.add('hidden');
  const statusEl = document.getElementById('qrStatus');
  const img = document.getElementById('qrImage');
  if (statusEl) {
    statusEl.textContent = '';
  }
  if (img) {
    img.style.display = 'none';
    img.src = '';
  }
}

async function refreshQR(instanceId) {
  const targetInstanceId = instanceId || activeQrInstanceId;
  if (!targetInstanceId) return;
  activeQrInstanceId = targetInstanceId;

  const img = document.getElementById('qrImage');
  const statusEl = document.getElementById('qrStatus');
  if (!img || !statusEl) return;

  statusEl.textContent = 'Buscando o QR...';
  img.style.display = 'none';
  img.src = '';

  try {
    const response = await fetch(`?qr_data=${encodeURIComponent(targetInstanceId)}&t=${Date.now()}`, {
      headers: { 'Accept': 'application/json' }
    });
    let payload;
    try {
      payload = await response.json();
    } catch (err) {
      throw new Error('Resposta inválida do servidor');
    }

    const payloadError = payload && payload.error;
    if (!response.ok || !payload || !payload.ok) {
      throw new Error(payloadError || 'QR indisponível');
    }

    img.src = payload.qr_url;
    img.onload = () => {
      statusEl.textContent = 'Escaneie o QR acima com o WhatsApp';
    };
    img.style.display = 'block';
    statusEl.textContent = 'Escaneie o QR acima com o WhatsApp';
  } catch (error) {
    console.error('Falha ao carregar o QR', error);
    const errorMessage = (error && error.message) ? error.message : 'desconhecido';
    statusEl.textContent = `Erro ao carregar o QR: ${errorMessage}`;
  }
}
</script>
<script>
(function () {
  const mobileSidebar = document.getElementById('mobileSidebarContainer');
  const openBtn = document.getElementById('openSidebarBtn');
  const closeBtn = document.getElementById('closeMobileSidebar');
  const overlay = document.getElementById('mobileSidebarOverlay');

  if (!mobileSidebar) {
    return;
  }

  const setVisible = (visible) => {
    mobileSidebar.classList.toggle('hidden', !visible);
    document.body.classList.toggle('overflow-hidden', visible);
  };

  openBtn?.addEventListener('click', (event) => {
    event.preventDefault();
    setVisible(true);
  });
  closeBtn?.addEventListener('click', () => setVisible(false));
  overlay?.addEventListener('click', () => setVisible(false));
})();
</script>
<script>
(function () {
  const logTag = `[send-card ${<?= json_encode($selectedInstanceId ?? '') ?> || 'unknown'}]`;
  const form = document.getElementById('sendForm');
  if (!form) {
    console.warn(logTag, 'formulário de envio não encontrado');
    return;
  }

  const phoneInput = form.querySelector('[name="phone"]');
  const messageInput = form.querySelector('[name="message"]');
  const statusEl = document.getElementById('sendStatus');
  const button = document.getElementById('sendButton');
  const endpointUrl = new URL(window.location.href);
  endpointUrl.searchParams.set('ajax_send', '1');
  endpointUrl.searchParams.set('instance', <?= json_encode($selectedInstanceId ?? '') ?>);
  const sendEndpoint = endpointUrl.toString();

  const updateStatus = (message, mode = 'info') => {
    if (!statusEl) return;
    const baseClass = 'mt-2 text-sm';
    const typeClass = mode === 'error' ? 'text-error font-medium'
      : mode === 'success' ? 'text-success font-medium'
      : 'text-slate-500';
    statusEl.className = `${baseClass} ${typeClass}`;
    statusEl.textContent = message;
  };

  console.log(logTag, 'formulário pronto para envios', { sendEndpoint });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    console.groupCollapsed(logTag, 'enviar mensagem');
    const phone = phoneInput?.value.trim() || '';
    const message = messageInput?.value.trim() || '';
    console.log(logTag, 'dados coletados', { phone, message });

    if (!phone || !message) {
      console.warn(logTag, 'campos obrigatórios ausentes');
      updateStatus('Telefone e mensagem são obrigatórios', 'error');
      console.groupEnd();
      return;
    }

    updateStatus('Enviando mensagem...', 'info');
    if (button) button.disabled = true;

    try {
      console.log(logTag, 'fazendo POST para o endpoint', sendEndpoint);
      const response = await fetch(sendEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ phone, message })
      });

      console.log(logTag, 'resposta HTTP', response.status, response.statusText);
      const rawText = await response.text();
      console.log(logTag, 'corpo da resposta', rawText);
      let payload = null;

      try {
        payload = JSON.parse(rawText);
        console.log(logTag, 'payload JSON', payload);
      } catch (parseError) {
        console.debug(logTag, 'não foi possível interpretar JSON', parseError);
      }

      if (!response.ok) {
        const errorMessage = payload?.detail || payload?.error || response.statusText || `Erro HTTP ${response.status}`;
        throw new Error(errorMessage);
      }

      const target = payload?.to || phone;
      updateStatus(`Mensagem enviada para ${target}`, 'success');
      console.log(logTag, 'mensagem enviada com sucesso para', target);
    } catch (error) {
      console.error(logTag, 'falha ao enviar mensagem', error);
      updateStatus(`Erro ao enviar mensagem: ${error.message}`, 'error');
    } finally {
      if (button) button.disabled = false;
      console.groupEnd();
    }
  });
})();
</script>
<script>
(function () {
  const form = document.getElementById('aiSettingsForm');
  const saveBtn = document.getElementById('saveAIButton');
  const testBtn = document.getElementById('testAIButton');
  const statusEl = document.getElementById('aiStatus');
  const instanceApiKey = <?= json_encode($selectedInstance['api_key'] ?? '') ?>;
  const instanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const logTag = `[ai-card ${instanceId || 'unknown'}]`;

  if (!form || !saveBtn || !statusEl) {
    console.warn(logTag, 'formulário da IA incompleto');
    return;
  }

  const providerSelect = document.getElementById('aiProvider');
  const modeSelect = document.getElementById('openaiMode');
  const assistantRow = document.getElementById('openaiAssistantRow');
  const historyInput = document.getElementById('aiHistoryLimit');
  const temperatureInput = document.getElementById('aiTemperature');
  const maxTokensInput = document.getElementById('aiMaxTokens');
  const modelPresetSelect = document.getElementById('aiModelPreset');
  const modelInputField = document.getElementById('aiModel');

  const OPENAI_MODEL_PRESETS = [
    'gpt-4.1',
    'gpt-4.1-mini',
    'gpt-4o-mini',
    'gpt-4o',
    'gpt-3.5-turbo'
  ];
  const GEMINI_MODEL_PRESETS = [
    'gemini-2.5-flash',
    'gemini-1.5-pro',
    'gemini-1.5-pro-moderate'
  ];
  const CUSTOM_MODEL_OPTION = 'custom-model';

  const OPEN_EYE_SVG = `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
      d="M1.5 12s4.5-8.5 10.5-8.5S22.5 12 22.5 12s-4.5 8.5-10.5 8.5S1.5 12 1.5 12z"></path>
    <circle cx="12" cy="12" r="3"></circle>
  </svg>`;
  const CLOSED_EYE_SVG = `<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
      d="M3 3l18 18"></path>
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
      d="M7.745 7.77A9.454 9.454 0 0 1 12 6.5c5.25 0 9.5 5.5 9.5 5.5a19.29 19.29 0 0 1-2.16 3.076"></path>
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
      d="M7.75 16.23A9.458 9.458 0 0 0 12 17.5c5.25 0 9.5-5.5 9.5-5.5a19.287 19.287 0 0 0-4.386-4.194"></path>
  </svg>`;

  const toggleKeyVisibility = (inputId, buttonId) => {
    const input = document.getElementById(inputId);
    const button = document.getElementById(buttonId);
    if (!input || !button) return;
    const setState = (show) => {
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
      button.innerHTML = show ? CLOSED_EYE_SVG : OPEN_EYE_SVG;
    };
    button.addEventListener('click', () => {
      const currentlyHidden = input.type === 'password';
      setState(currentlyHidden);
    });
    setState(false);
  };
  toggleKeyVisibility('openaiApiKey', 'toggleOpenaiKey');
  toggleKeyVisibility('geminiApiKey', 'toggleGeminiKey');

  const populateModelPresetOptions = () => {
    if (!modelPresetSelect || !modelInputField) return;
    const provider = providerSelect?.value || 'openai';
    const presets = provider === 'gemini' ? GEMINI_MODEL_PRESETS : OPENAI_MODEL_PRESETS;
    const currentValue = modelInputField.value?.trim() || '';
    const matchesPreset = currentValue && presets.includes(currentValue);

    modelPresetSelect.innerHTML = presets
      .map(preset => `<option value="${preset}">${preset}</option>`)
      .join('');
    modelPresetSelect.insertAdjacentHTML('beforeend',
      `<option value="${CUSTOM_MODEL_OPTION}">Outro modelo</option>`);

    if (matchesPreset) {
      modelPresetSelect.value = currentValue;
    } else {
      modelPresetSelect.value = CUSTOM_MODEL_OPTION;
      if (!currentValue) {
        modelInputField.value = presets[0] || '';
        modelPresetSelect.value = presets[0] || CUSTOM_MODEL_OPTION;
      }
    }
  };

  const handleModelPresetChange = () => {
    if (!modelPresetSelect || !modelInputField) return;
    if (modelPresetSelect.value !== CUSTOM_MODEL_OPTION) {
      modelInputField.value = modelPresetSelect.value;
    }
  };

  const syncModelInputWithPreset = () => {
    if (!modelPresetSelect || !modelInputField) return;
    const value = modelInputField.value.trim();
    const provider = providerSelect?.value || 'openai';
    const presets = provider === 'gemini' ? GEMINI_MODEL_PRESETS : OPENAI_MODEL_PRESETS;
    modelPresetSelect.value = presets.includes(value) && value ? value : CUSTOM_MODEL_OPTION;
  };

  const toggleProviderFields = () => {
    const provider = providerSelect?.value;
    const openaiFields = document.getElementById('openaiFields');
    const geminiFields = document.getElementById('geminiFields');
    if (openaiFields) {
      openaiFields.classList.toggle('hidden', provider !== 'openai');
    }
    if (geminiFields) {
      geminiFields.classList.toggle('hidden', provider !== 'gemini');
    }
  };

  const toggleAssistantRow = () => {
    if (!assistantRow || !modeSelect) return;
    assistantRow.style.display = modeSelect.value === 'assistants' ? '' : 'none';
  };

  const handleProviderSelectChange = () => {
    toggleProviderFields();
    populateModelPresetOptions();
  };

  providerSelect?.addEventListener('change', handleProviderSelectChange);
  modeSelect?.addEventListener('change', toggleAssistantRow);
  modelPresetSelect?.addEventListener('change', handleModelPresetChange);
  modelInputField?.addEventListener('input', syncModelInputWithPreset);

  toggleProviderFields();
  toggleAssistantRow();
  populateModelPresetOptions();

  const getFieldValue = (id, fallback = '') => {
    const el = document.getElementById(id);
    if (!el) return fallback;
    if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
      return el.value.trim();
    }
    return fallback;
  };

  const updateStatus = (message, mode = 'info') => {
    const baseClass = 'text-sm transition-colors';
    const typeClass = mode === 'error' ? 'text-error font-medium'
      : mode === 'warning' ? 'text-alert font-medium'
      : mode === 'success' ? 'text-success font-medium'
      : 'text-slate-500';
    statusEl.className = `${baseClass} ${typeClass}`;
    statusEl.textContent = message;
  };

  if (!instanceApiKey) {
    console.error(logTag, 'chave da instância não disponível para chamada');
    updateStatus('Chave da instância não disponível para salvar', 'error');
    saveBtn.disabled = true;
    if (testBtn) testBtn.disabled = true;
    return;
  }

    const collectPayload = () => {
    const rawDelay = Number(getFieldValue('aiMultiInputDelay'));
    const multiDelay = Number.isFinite(rawDelay) ? Math.max(0, rawDelay) : 0;

    return {
      enabled: document.getElementById('aiEnabled').checked,
      provider: providerSelect?.value || 'openai',
      model: getFieldValue('aiModel', 'gpt-4.1-mini'),
      system_prompt: getFieldValue('aiSystemPrompt'),
      assistant_prompt: getFieldValue('aiAssistantPrompt'),
      assistant_id: getFieldValue('openaiAssistantId'),
      history_limit: Number(historyInput?.value) || 20,
      multi_input_delay: multiDelay,
      temperature: parseFloat(temperatureInput?.value) || 0.3,
      max_tokens: Number(maxTokensInput?.value) || 600,
      openai_api_key: getFieldValue('openaiApiKey'),
      openai_mode: modeSelect?.value || 'responses',
      gemini_api_key: getFieldValue('geminiApiKey'),
      gemini_instruction: getFieldValue('geminiInstruction')
    };
  };

  saveBtn.addEventListener('click', async () => {
    const payload = collectPayload();
    console.groupCollapsed(logTag, 'salvar configurações IA');
    console.log(logTag, 'payload', payload);
    updateStatus('Salvando configurações...', 'info');
    saveBtn.disabled = true;

    try {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': instanceApiKey
        },
        body: JSON.stringify({
          action: 'save_ai_config',
          ai: payload
        })
      });

      const resultText = await response.text();
      console.log(logTag, 'resposta HTTP', response.status, response.statusText);
      console.log(logTag, 'corpo da resposta', resultText);
      let result = null;
      try {
        result = JSON.parse(resultText);
        console.log(logTag, 'payload JSON', result);
      } catch (parseError) {
        console.debug(logTag, 'não foi possível interpretar JSON', parseError);
      }

      if (!response.ok || !result?.success) {
        const errorMessage = result?.error || response.statusText || 'Erro ao salvar';
        throw new Error(errorMessage);
      }

      const warning = result?.warning;
      const message = warning ? `Config salva, porém: ${warning}` : 'Configurações salvas com sucesso';
      const statusMode = warning ? 'warning' : 'success';
      updateStatus(message, statusMode);
    } catch (error) {
      console.error(logTag, 'falha ao salvar IA', error);
      updateStatus(`Erro ao salvar: ${error.message}`, 'error');
    } finally {
      saveBtn.disabled = false;
      console.groupEnd();
    }
  });

  if (testBtn) {
    const modal = document.getElementById('aiTestModal');
    const modalForm = document.getElementById('aiTestForm');
    const modalMessage = document.getElementById('aiTestMessage');
    const modalResult = document.getElementById('aiTestResult');
    const modalStatus = document.getElementById('aiTestStatus');
    const modalSubmit = document.getElementById('aiTestSubmit');
    const modalClose = document.getElementById('closeAiTestModal');
    const instanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
    const testEndpointUrl = new URL(window.location.href);
    testEndpointUrl.searchParams.set('ajax_ai_test', '1');
    testEndpointUrl.searchParams.set('instance', instanceId);
    const testEndpoint = testEndpointUrl.toString();

    const setModalStatus = (text, mode = 'info') => {
      if (!modalStatus) return;
      const typeClass = mode === 'error' ? 'text-error' : mode === 'success' ? 'text-success' : 'text-slate-500';
      modalStatus.textContent = text;
      modalStatus.className = `text-xs ${typeClass}`;
    };

    const openModal = () => {
      if (modal) {
        modal.classList.remove('hidden');
        modalMessage.value = '';
        modalResult.textContent = '';
        setModalStatus('');
        modalMessage.focus();
      }
    };

    const closeModal = () => {
      if (modal) {
        modal.classList.add('hidden');
      }
    };

    testBtn.addEventListener('click', openModal);
    if (modalClose) {
      modalClose.addEventListener('click', closeModal);
    }

    if (modalForm) {
      modalForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = modalMessage?.value.trim() || '';
        if (!text) {
          setModalStatus('Digite uma mensagem para testar', 'error');
          return;
        }

        if (modalSubmit) modalSubmit.disabled = true;
        setModalStatus('Solicitando resposta...', 'info');
        modalResult.textContent = '';
        console.groupCollapsed(logTag, 'teste IA');
        console.log(logTag, 'test payload', { message: text, endpoint: testEndpoint });
        let lastResultData = null;

        try {
          const response = await fetch(testEndpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ message: text })
          });

          const resultText = await response.text();
          console.log(logTag, 'test response', response.status, response.statusText);
          console.log(logTag, 'test raw', resultText);
          let resultData = null;
          try {
            resultData = JSON.parse(resultText);
            lastResultData = resultData;
          } catch (parseErr) {
            console.error(logTag, 'test JSON parse error', parseErr);
            throw new Error('Resposta inválida do servidor');
          }

          if (!response.ok || !resultData?.ok) {
            const errorMessage = resultData?.error || response.statusText || 'Falha no teste';
            const errorDetail = resultData?.detail ? ` (${resultData.detail})` : '';
            throw new Error(`${errorMessage}${errorDetail}`);
          }

          const providerLabel = resultData.provider ? resultData.provider.toUpperCase() : 'IA';
          modalResult.textContent = `[${providerLabel}] ${resultData.response || 'Sem resposta'}`;
          setModalStatus('Resposta recebida', 'success');
          console.log(logTag, 'teste ok', resultData);
        } catch (error) {
          if (lastResultData) {
            modalResult.textContent = `Resposta bruta: ${JSON.stringify(lastResultData)}`;
          } else {
            modalResult.textContent = '';
          }
          setModalStatus(`Erro: ${error.message}`, 'error');
          console.error(logTag, 'teste falhou', error);
        } finally {
          if (modalSubmit) modalSubmit.disabled = false;
          console.groupEnd();
        }
      });
    }
  }
})();
</script>
<script>
(function () {
  const panel = document.getElementById('functionsPanel');
  const triggerIds = ['aiFunctionsButton', 'geminiFunctionsButton'];
  const triggers = triggerIds.map(id => document.getElementById(id)).filter(Boolean);

  if (!panel || !triggers.length) {
    return;
  }

  const togglePanel = (event) => {
    event?.preventDefault();
    event?.stopPropagation();
    panel.classList.toggle('hidden');
    if (!panel.classList.contains('hidden')) {
      panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  };

  triggers.forEach(btn => {
    btn.addEventListener('click', togglePanel);
  });

  document.addEventListener('click', (event) => {
    if (panel.classList.contains('hidden')) return;
    if (triggers.some(btn => btn.contains(event.target))) return;
    if (panel.contains(event.target)) return;
    panel.classList.add('hidden');
  });
})();
</script>
<script>
(function () {
  const guide = document.getElementById('functionsGuide');
  const copyBtn = document.getElementById('copyFunctionsGuide');
  const feedback = document.getElementById('functionsGuideFeedback');

  if (!guide || !copyBtn) return;

  copyBtn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(guide.textContent.trim());
      if (feedback) {
        feedback.classList.remove('hidden');
        setTimeout(() => feedback.classList.add('hidden'), 2000);
      }
    } catch (err) {
      console.error('[functionsGuide]', 'copy failed', err);
    }
  });
})();
</script>
<script>
(function () {
  const section = document.getElementById('chatHistorySection');
  const list = document.getElementById('historyList');
  const status = document.getElementById('historyStatus');
  const refreshBtn = document.getElementById('refreshHistoryBtn');
  const instanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const port = <?= isset($selectedInstance['port']) ? (int)$selectedInstance['port'] : 'null' ?>;

  if (!section || !list || !status || !refreshBtn) return;
  if (!instanceId || !port) {
    section.classList.remove('hidden');
    status.textContent = 'Instância indisponível para carregar histórico';
    return;
  }

  const endpointUrl = new URL(window.location.href);
  endpointUrl.searchParams.set('ajax_history', '1');
  endpointUrl.searchParams.set('instance', instanceId);
  const endpoint = endpointUrl.toString();

  const formatRemoteJidLabel = (remoteJid) => {
    if (!remoteJid) return 'Contato desconhecido';
    const clean = (remoteJid.split('@')[0] || '').replace(/\\D/g, '');
    if (!clean) return remoteJid;
    if (clean.startsWith('55') && clean.length > 6) {
      const country = clean.slice(0, 2);
      const area = clean.slice(2, 4);
      const subscriber = clean.slice(4);
      const prefix = subscriber.slice(0, -4);
      const suffix = subscriber.slice(-4);
      const prefixPart = prefix ? `${prefix}-` : '';
      return `${country} ${area} ${prefixPart}${suffix}`;
    }
    const formatted = clean.replace(/^(\\d{2})(\\d{4,5})(\\d{4})$/, '$1 $2-$3');
    return formatted || clean;
  };

  const renderChats = (chats) => {
    if (!chats.length) {
      status.textContent = 'Nenhuma conversa encontrada ainda';
      list.innerHTML = '';
      return;
    }
    
    status.textContent = `Mostrando ${chats.length} conversa${chats.length > 1 ? 's' : ''}`;
    list.innerHTML = chats.map(chat => {
      const lastMessage = chat.last_message || 'Sem mensagens';
      const timestamp = chat.last_timestamp ? new Date(chat.last_timestamp).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '';
      const label = formatRemoteJidLabel(chat.remote_jid);
      return `
        <a href="conversas.php?instance=${instanceId}&contact=${encodeURIComponent(chat.remote_jid)}"
           class="block p-3 rounded-xl border border-mid hover:border-primary transition flex justify-between gap-4">
          <div class="min-w-0">
            <div class="text-sm font-medium text-dark truncate">${label}</div>
            <div class="text-[11px] text-slate-500 truncate">${lastMessage}</div>
          </div>
          <div class="text-xs text-slate-400 text-right">
            ${timestamp}
            <div class="text-[11px] text-slate-500 mt-1">${chat.message_count || 0} msgs</div>
          </div>
        </a>
      `;
    }).join('');
  };

  const updateStatusText = (message, isError = false) => {
    status.textContent = message;
    status.className = `text-xs ${isError ? 'text-error' : 'text-slate-500'} mt-3`;
  };

  const loadHistory = async () => {
    section.classList.remove('hidden');
    updateStatusText('Carregando histórico...');
    list.innerHTML = '<div class="p-4 text-center text-slate-500">Carregando...</div>';

    try {
      console.log('[history]', 'fetching', endpoint);
      const response = await fetch(endpoint);
      const rawText = await response.text();
      console.log('[history]', 'response status', response.status, response.statusText);
      console.log('[history]', 'raw response', rawText);
      let data = null;

      try {
        data = JSON.parse(rawText);
      } catch (parseErr) {
        console.error('[history]', 'invalid JSON', parseErr);
        throw new Error('Resposta inválida do servidor');
      }

      if (!response.ok || !data.ok) {
        const errorMessage = data?.error || response.statusText || 'Erro ao carregar histórico';
        throw new Error(errorMessage);
      }

      renderChats(data.chats || []);
    } catch (error) {
      updateStatusText(`Falha: ${error.message}`, true);
      list.innerHTML = '';
      console.error('[history]', error);
    }
  };

  refreshBtn.addEventListener('click', loadHistory);
  loadHistory();
})();
</script>
</body>
</html>
