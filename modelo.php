<?php
require_once __DIR__ . '/vendor/autoload.php';
if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

function isPortOpen($host, $port, $timeout = 1) {
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    } else {
        return false;
    }
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
debug_log('Session started. Auth: ' . (isset($_SESSION['auth']) ? 'true' : 'false'));
if (!isset($_SESSION['auth'])) {
    debug_log('Auth not set, checking POST');
    if ($_POST['email'] ?? null) {
        debug_log('Login attempt with email: ' . $_POST['email']);
        if ($_POST['email'] === $_ENV['PANEL_USER_EMAIL'] &&
            $_POST['password'] === $_ENV['PANEL_PASSWORD']) {
            debug_log('Login successful, setting session auth=true, redirecting to /api/envio/wpp/');
            $_SESSION['auth'] = true;
            header("Location: /api/envio/wpp/");
            exit;
        }
        debug_log('Login failed: invalid credentials');
        $erro = "Login incorreto";
        debug_log('Setting error: ' . $erro);
    }

    debug_log('Including login.php');
    include "login.php";
    exit;
}

$instancesFile = __DIR__ . "/instances.json";

/**
 * Carrega instâncias do arquivo JSON.
 * Estrutura: [ id => [ 'id' => ..., 'name' => ..., 'node_base' => ..., 'description' => ... ] ]
 */
function loadInstances(string $file): array {
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Salva instâncias no arquivo JSON.
 */
function saveInstances(string $file, array $instances): void {
    file_put_contents($file, json_encode($instances, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Faz slug/ID simples a partir do nome.
 */
function makeId(string $name): string {
    $id = strtolower(trim($name));
    $id = preg_replace('~[^a-z0-9]+~', '-', $id);
    $id = trim($id, '-');
    if ($id === '') {
        $id = 'inst-' . time();
    }
    return $id;
}

/**
 * Faz requisição cURL simples ao Node.
 */
function callNode(string $url, string $method = 'GET', ?array $jsonBody = null, bool $returnBinary = false): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'ok'          => $err === '',
        'error'       => $err,
        'httpCode'    => $httpCode,
        'contentType' => $contentType,
        'body'        => $response,
    ];
}

// ----------------------------------------------------------
// Carrega instâncias
// ----------------------------------------------------------
$instances = loadInstances($instancesFile);

// Se não tiver nenhuma, cria uma padrão apontando para localhost:3020
if (empty($instances)) {
    $instances['default'] = [
        'id'          => 'default',
        'name'        => 'Instância Padrão',
        'node_base'   => 'http://localhost:3020',
        'description' => 'Instância inicial usando Baileys em http://localhost:3020',
    ];
    saveInstances($instancesFile, $instances);
}

// ----------------------------------------------------------
// APIs internas (?api=...)
// ----------------------------------------------------------
if (isset($_GET['api'])) {
    $api = $_GET['api'];
    header_remove('X-Powered-By');

    // Helper para buscar instância
    $instanceId = $_GET['instance'] ?? null;
    $instance = null;
    if ($instanceId !== null && isset($instances[$instanceId])) {
        $instance = $instances[$instanceId];
    }

    if ($api === 'status') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$instance) {
            http_response_code(404);
            echo json_encode(['error' => 'Instância não encontrada']);
            exit;
        }

        // Try health endpoint first, then status endpoint
        $url = rtrim($instance['node_base'], '/') . '/health';
        $r = callNode($url);
        if (!$r['ok'] || $r['httpCode'] !== 200) {
            // Fallback to status endpoint
            $url = rtrim($instance['node_base'], '/') . '/status';
            $r = callNode($url);
        }
        
        if (!$r['ok']) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao contactar Node', 'detail' => $r['error']]);
            exit;
        }
        http_response_code($r['httpCode']);
        echo $r['body'];
        exit;
    }

    if ($api === 'qr') {
        if (!$instance) {
            http_response_code(404);
            exit;
        }
        $url = rtrim($instance['node_base'], '/') . '/qr';
        $r = callNode($url, 'GET', null, true);
        if (!$r['ok'] || $r['httpCode'] !== 200) {
            http_response_code(204); // sem QR disponível
            exit;
        }
        header('Content-Type: ' . ($r['contentType'] ?: 'image/png'));
        echo $r['body'];
        exit;
    }

    if ($api === 'disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!$instance) {
            http_response_code(404);
            echo json_encode(['error' => 'Instância não encontrada']);
            exit;
        }
        // Depende de endpoint /disconnect no Node
        $url = rtrim($instance['node_base'], '/') . '/disconnect';
        $r = callNode($url, 'POST');
        if (!$r['ok']) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao contactar Node', 'detail' => $r['error']]);
            exit;
        }
        http_response_code($r['httpCode']);
        echo $r['body'] ?: json_encode(['ok' => true]);
        exit;
    }

    if ($api === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: [];

        $iid     = $data['instance'] ?? null;
        $number  = trim($data['number'] ?? '');
        $message = trim($data['message'] ?? '');

        if (!$iid || !isset($instances[$iid])) {
            http_response_code(400);
            echo json_encode(['error' => 'Instância inválida']);
            exit;
        }
        if ($number === '' || $message === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Número e mensagem são obrigatórios']);
            exit;
        }

        $inst = $instances[$iid];
        $url  = rtrim($inst['node_base'], '/') . '/send-message';

        $r = callNode($url, 'POST', [
            'to'      => $number,
            'message' => $message,
        ]);

        if (!$r['ok']) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao contactar Node', 'detail' => $r['error']]);
            exit;
        }
        http_response_code($r['httpCode']);
        echo $r['body'];
        exit;
    }

    // API desconhecida
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'API inválida']);
    exit;
}

// ----------------------------------------------------------
// Ações de CRUD nas instâncias (via POST)
// ----------------------------------------------------------
$flashMessage = null;
$flashError   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['api'])) {
    $action = $_POST['action'] ?? null;

    if ($action === 'create_instance') {
        $name        = trim($_POST['name'] ?? '');
        $nodeBase    = trim($_POST['node_base'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '' || $nodeBase === '') {
            $flashError = 'Nome e Node Base são obrigatórios.';
        } else {
            $id = makeId($name);
            if (isset($instances[$id])) {
                $id = $id . '-' . time();
            }
            $instances[$id] = [
                'id'          => $id,
                'name'        => $name,
                'node_base'   => $nodeBase,
                'description' => $description,
                'openai'      => [
                    'enabled'        => false,
                    'api_key'        => '',
                    'system_prompt'  => 'You are a helpful WhatsApp assistant. Respond naturally and concisely.',
                    'assistant_prompt' => ''
                ]
            ];
            saveInstances($instancesFile, $instances);
            $flashMessage = 'Instância criada com sucesso.';
            $_GET['instance'] = $id;
        }
    }

    if ($action === 'update_instance') {
        $id          = $_POST['id'] ?? null;
        $name        = trim($_POST['name'] ?? '');
        $nodeBase    = trim($_POST['node_base'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!$id || !isset($instances[$id])) {
            $flashError = 'Instância não encontrada para edição.';
        } elseif ($name === '' || $nodeBase === '') {
            $flashError = 'Nome e Node Base são obrigatórios.';
        } else {
            $instances[$id]['name']        = $name;
            $instances[$id]['node_base']   = $nodeBase;
            $instances[$id]['description'] = $description;
            $instances[$id]['openai'] = [
                'enabled'         => isset($_POST['openai_enabled']),
                'api_key'         => trim($_POST['openai_api_key'] ?? ''),
                'system_prompt'   => trim($_POST['openai_system_prompt'] ?? ''),
                'assistant_prompt' => trim($_POST['openai_assistant_prompt'] ?? '')
            ];
            saveInstances($instancesFile, $instances);
            $flashMessage = 'Instância atualizada com sucesso.';
            $_GET['instance'] = $id;
        }
    }

    if ($action === 'delete_instance') {
        $id = $_POST['id'] ?? null;
        if (!$id || !isset($instances[$id])) {
            $flashError = 'Instância não encontrada para exclusão.';
        } elseif (count($instances) === 1) {
            $flashError = 'Não é possível apagar a única instância existente.';
        } else {
            unset($instances[$id]);
            saveInstances($instancesFile, $instances);
            $flashMessage = 'Instância apagada com sucesso.';
            $_GET['instance'] = array_key_first($instances);
        }
    }
}

// ----------------------------------------------------------
// Instância selecionada para exibição
// ----------------------------------------------------------
$selectedId = $_GET['instance'] ?? null;
if (!$selectedId || !isset($instances[$selectedId])) {
    $selectedId = array_key_first($instances);
}
$selectedInstance = $instances[$selectedId];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Painel WhatsApp – Multi-instância</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            color-scheme: dark;
            --bg: #050816;
            --bg-elevated: #0f172a;
            --bg-muted: #020617;
            --accent: #22c55e;
            --accent-soft: rgba(34, 197, 94, 0.15);
            --border: #1e293b;
            --text: #e5e7eb;
            --text-muted: #9ca3af;
            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #1e293b 0, #020617 45%, #000 100%);
            color: var(--text);
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .layout {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 280px;
            background: linear-gradient(to bottom, #020617, #000);
            border-right: 1px solid var(--border);
            padding: 18px 16px;
        }
        .logo {
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.05em;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logo span.badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: var(--accent);
        }
        .sidebar-section-title {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 18px 0 8px;
            letter-spacing: 0.08em;
        }
        .instance-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .instance-item {
            border-radius: 8px;
            padding: 8px 10px;
            margin-bottom: 6px;
            border: 1px solid transparent;
            cursor: pointer;
            display: block;
        }
        .instance-item.active {
            border-color: var(--accent);
            background: linear-gradient(to right, rgba(34,197,94,0.1), transparent);
        }
        .instance-name {
            font-size: 14px;
            font-weight: 500;
        }
        .instance-node {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .instance-actions {
            display: flex;
            gap: 6px;
            margin-top: 6px;
        }
        .btn-xs {
            font-size: 10px;
            padding: 3px 6px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--bg-elevated);
            color: var(--text-muted);
            cursor: pointer;
        }
        .btn-xs.danger {
            border-color: var(--danger);
            color: var(--danger);
        }

        .btn-xs:hover {
            border-color: var(--accent);
            color: var(--accent);
        }
        .btn-xs.danger:hover {
            background: var(--danger-soft);
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 999px;
            border: 1px solid var(--accent);
            background: var(--accent-soft);
            color: var(--accent);
            cursor: pointer;
        }
        .btn-primary:hover {
            background: rgba(34,197,94,0.25);
        }

        .main {
            flex: 1;
            padding: 18px 22px;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }
        .top-title {
            font-size: 18px;
            font-weight: 600;
        }
        .top-sub {
            font-size: 12px;
            color: var(--text-muted);
        }
        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
            gap: 16px;
        }
        .card {
            background: rgba(15,23,42,0.9);
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 14px 16px;
            backdrop-filter: blur(12px);
        }
        .card h2 {
            font-size: 14px;
            margin: 0 0 8px;
        }
        .card-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 10px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 11px;
        }
        .status-pill span.dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
        }
        .status-pill.online {
            background: var(--accent-soft);
            color: var(--accent);
        }
        .status-pill.online span.dot {
            background: var(--accent);
        }
        .status-pill.offline {
            background: var(--danger-soft);
            color: var(--danger);
        }
        .status-pill.offline span.dot {
            background: var(--danger);
        }

        #qrWrapper {
            border-radius: 10px;
            border: 1px dashed var(--border);
            padding: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: radial-gradient(circle at top, #0f172a, #020617);
            min-height: 260px;
        }
        #qrImage {
            max-width: 240px;
            max-height: 240px;
            border-radius: 8px;
            background: #fff;
        }

        label {
            font-size: 12px;
            display: block;
            margin-bottom: 4px;
            color: var(--text-muted);
        }
        input[type="text"], textarea {
            width: 100%;
            padding: 7px 9px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--bg-muted);
            color: var(--text);
            font-size: 13px;
            outline: none;
        }
        input[type="text"]:focus, textarea:focus {
            border-color: var(--accent);
        }
        textarea { resize: vertical; min-height: 70px; }

        .form-row {
            display: flex;
            gap: 10px;
        }
        .form-row > div {
            flex: 1;
        }

        .alert {
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 12px;
        }
        .alert.success {
            background: rgba(34,197,94,0.12);
            border: 1px solid var(--accent);
            color: var(--accent);
        }
        .alert.error {
            background: var(--danger-soft);
            border: 1px solid var(--danger);
            color: var(--danger);
        }
        .muted {
            font-size: 11px;
            color: var(--text-muted);
        }
        .panel-footer {
            margin-top: 10px;
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
        }

        .pill {
            padding: 2px 6px;
            border-radius: 999px;
            background: rgba(148,163,184,0.2);
            font-size: 10px;
            color: var(--text-muted);
        }

        .btn-ghost {
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
        }
        .btn-ghost:hover {
            border-color: var(--accent);
            color: var(--accent);
        }
        .btn-ghost.danger:hover {
            border-color: var(--danger);
            color: var(--danger);
        }
    </style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="logo">
            <span>WA Panel</span>
            <span class="badge">multi-instância</span>
        </div>

        <div class="sidebar-section-title">Instâncias</div>
        <ul class="instance-list">
            <?php foreach ($instances as $inst): ?>
                <?php
                    $active = $inst['id'] === $selectedId;
                    $url    = '?instance=' . urlencode($inst['id']);
                ?>
                <li>
                    <a href="<?php echo htmlspecialchars($url); ?>"
                       class="instance-item <?php echo $active ? 'active' : ''; ?>">
                        <div class="instance-name"><?php echo htmlspecialchars($inst['name']); ?></div>
                        <div class="instance-node"><?php echo htmlspecialchars($inst['node_base']); ?></div>
                    </a>
                    <div class="instance-actions">
                        <button class="btn-xs"
                                onclick="fillEditForm('<?php echo htmlspecialchars($inst['id']); ?>',
                                                      '<?php echo htmlspecialchars($inst['name']); ?>',
                                                      '<?php echo htmlspecialchars($inst['node_base']); ?>',
                                                      '<?php echo htmlspecialchars($inst['description']); ?>');">
                            editar
                        </button>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Apagar esta instância?');">
                            <input type="hidden" name="action" value="delete_instance">
                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($inst['id']); ?>">
                            <button type="submit" class="btn-xs danger">apagar</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <div style="margin-top: 14px;">
            <button class="btn-primary" onclick="newInstanceForm();">
                + Nova instância
            </button>
        </div>
    </aside>

    <main class="main">
        <div class="top-bar">
            <div>
                <div class="top-title">Instância ativa: <?php echo htmlspecialchars($selectedInstance['name']); ?></div>
                <div class="top-sub">
                    <?php echo htmlspecialchars($selectedInstance['description'] ?: 'Sem descrição.'); ?>
                </div>
            </div>
            <div>
                <span class="pill">Node: <?php echo htmlspecialchars($selectedInstance['node_base']); ?></span>
            </div>
        </div>

        <?php if ($flashMessage): ?>
            <div class="alert success"><?php echo $flashMessage; ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="alert error"><?php echo $flashError; ?></div>
        <?php endif; ?>

        <div class="grid">
            <!-- Coluna esquerda: QR + status -->
            <section class="card">
                <h2>Conexão WhatsApp</h2>
                <div class="card-sub">
                    Escaneie o QR abaixo em <strong>Aparelhos conectados</strong> do WhatsApp.
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <div id="statusPill" class="status-pill offline">
                        <span class="dot"></span>
                        <span id="statusText">Consultando...</span>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button class="btn-ghost" onclick="refreshNow();">Atualizar</button>
                        <button class="btn-ghost danger" onclick="disconnectInstance();">
                            Desconectar
                        </button>
                    </div>
                </div>

                <div id="qrWrapper">
                    <img id="qrImage" src="" alt="QR Code WhatsApp">
                </div>

                <div class="panel-footer">
                    <span class="muted">Atualiza automaticamente a cada 5s (QR) e 3s (status).</span>
                    <span class="muted">Instância: <?php echo htmlspecialchars($selectedInstance['id']); ?></span>
                </div>
            </section>

            <!-- Coluna direita: envio + edição de instância -->
            <section class="card">
                <h2>Enviar mensagem de teste</h2>
                <div class="card-sub">
                    Use o número em formato internacional: <code>55DDDNÚMERO</code>.
                </div>

                <div style="margin-bottom:10px;">
                    <label for="sendNumber">Número (com DDD):</label>
                    <input type="text" id="sendNumber" placeholder="ex: 558586030781">

                    <label for="sendMessage" style="margin-top:8px;">Mensagem:</label>
                    <textarea id="sendMessage">Olá, esta é uma mensagem de teste via Baileys + PHP.</textarea>

                    <button class="btn-primary" style="margin-top:10px;" onclick="sendTestMessage();">
                        Enviar mensagem
                    </button>

                    <div id="sendFeedback" class="muted" style="margin-top:6px;"></div>
                </div>

                <hr style="border-color: rgba(30,64,175,0.5); margin:12px 0;">

                <h2 style="margin-top:0;">Configurar instância</h2>
                <div class="card-sub">Edite os dados da instância atual ou crie uma nova.</div>

                <form method="post" id="instanceForm">
                    <input type="hidden" name="action" value="update_instance" id="instanceFormAction">
                    <input type="hidden" name="id" id="instId" value="<?php echo htmlspecialchars($selectedInstance['id']); ?>">

                    <div class="form-row">
                        <div>
                            <label>Nome</label>
                            <input type="text" name="name" id="instName"
                                   value="<?php echo htmlspecialchars($selectedInstance['name']); ?>">
                        </div>
                        <div>
                            <label>Node Base (URL)</label>
                            <input type="text" name="node_base" id="instNodeBase"
                                   value="<?php echo htmlspecialchars($selectedInstance['node_base']); ?>">
                        </div>
                    </div>

                    <div style="margin-top:8px;">
                        <label>Descrição</label>
                        <textarea name="description" id="instDescription"><?php
                            echo htmlspecialchars($selectedInstance['description']);
                        ?></textarea>
                    </div>

                    <hr style="border-color: rgba(30,64,175,0.5); margin:12px 0;">

                    <h2 style="margin-top:0;">Configuração OpenAI</h2>
                    <div class="card-sub">Integração com OpenAI para respostas automáticas a mensagens recebidas.</div>

                    <div style="margin-top:8px;">
                        <label style="display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="openai_enabled" id="openaiEnabled" <?php echo ($selectedInstance['openai']['enabled'] ?? false) ? 'checked' : ''; ?>>
                            Conectar com OpenAI Responses
                        </label>
                    </div>

                    <div style="margin-top:8px;">
                        <label>API Key OpenAI</label>
                        <input type="password" name="openai_api_key" id="openaiApiKey"
                               value="<?php echo htmlspecialchars($selectedInstance['openai']['api_key'] ?? ''); ?>"
                               placeholder="sk-...">
                    </div>

                    <div style="margin-top:8px;">
                        <label>System Prompt</label>
                        <textarea name="openai_system_prompt" id="openaiSystemPrompt" placeholder="Instruções para o assistente..."><?php
                            echo htmlspecialchars($selectedInstance['openai']['system_prompt'] ?? 'You are a helpful WhatsApp assistant. Respond naturally and concisely.');
                        ?></textarea>
                    </div>

                    <div style="margin-top:8px;">
                        <label>Assistant Prompt</label>
                        <textarea name="openai_assistant_prompt" id="openaiAssistantPrompt" placeholder="Prompt adicional (opcional)..."><?php
                            echo htmlspecialchars($selectedInstance['openai']['assistant_prompt'] ?? '');
                        ?></textarea>
                    </div>

                    <div style="margin-top:10px; display:flex; gap:8px;">
                        <button type="submit" class="btn-primary" id="instSaveBtn">
                            Salvar alterações
                        </button>
                        <button type="button" class="btn-ghost" onclick="newInstanceForm();">
                            Nova instância
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </main>
</div>

<script>
    const selectedInstanceId = <?php echo json_encode($selectedInstance['id']); ?>;

    function refreshQR() {
        const img = document.getElementById('qrImage');
        img.src = '?api=qr&instance=' + encodeURIComponent(selectedInstanceId) + '&t=' + Date.now();
    }

    function refreshStatus() {
        fetch('?api=status&instance=' + encodeURIComponent(selectedInstanceId))
            .then(r => r.json())
            .then(data => {
                const pill = document.getElementById('statusPill');
                const text = document.getElementById('statusText');

                if (data.connected) {
                    pill.classList.remove('offline');
                    pill.classList.add('online');
                    text.textContent = 'Conectado ao WhatsApp';
                } else {
                    pill.classList.remove('online');
                    pill.classList.add('offline');
                    text.textContent = 'Não conectado';
                }
            })
            .catch(() => {
                const pill = document.getElementById('statusPill');
                const text = document.getElementById('statusText');
                pill.classList.remove('online');
                pill.classList.add('offline');
                text.textContent = 'Erro ao consultar status';
            });
    }

    function refreshNow() {
        refreshQR();
        refreshStatus();
    }

    function disconnectInstance() {
        if (!confirm('Deseja desconectar esta instância do WhatsApp?')) return;

        fetch('?api=disconnect&instance=' + encodeURIComponent(selectedInstanceId), {
            method: 'POST'
        })
        .then(r => r.json().catch(() => ({})))
        .then(() => {
            refreshStatus();
            refreshQR();
        })
        .catch(() => {
            alert('Erro ao tentar desconectar.');
        });
    }

    function sendTestMessage() {
        const number  = document.getElementById('sendNumber').value.trim();
        const message = document.getElementById('sendMessage').value.trim();
        const fb      = document.getElementById('sendFeedback');

        if (!number || !message) {
            fb.textContent = 'Preencha número e mensagem.';
            return;
        }

        fb.textContent = 'Enviando...';

        fetch('?api=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                instance: selectedInstanceId,
                to: number,
                message: message
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                fb.textContent = 'Mensagem enviada com sucesso.';
            } else {
                fb.textContent = 'Falha ao enviar: ' + (data.error || JSON.stringify(data));
            }
        })
        .catch(() => {
            fb.textContent = 'Erro ao contactar servidor.';
        });
    }

    function fillEditForm(id, name, nodeBase, description) {
        document.getElementById('instanceFormAction').value = 'update_instance';
        document.getElementById('instId').value = id;
        document.getElementById('instName').value = name;
        document.getElementById('instNodeBase').value = nodeBase;
        document.getElementById('instDescription').value = description;
        // Load OpenAI settings from PHP data
        const openai = <?php echo json_encode($selectedInstance['openai'] ?? []); ?>;
        document.getElementById('openaiEnabled').checked = openai.enabled || false;
        document.getElementById('openaiApiKey').value = openai.api_key || '';
        document.getElementById('openaiSystemPrompt').value = openai.system_prompt || 'You are a helpful WhatsApp assistant. Respond naturally and concisely.';
        document.getElementById('openaiAssistantPrompt').value = openai.assistant_prompt || '';
        document.getElementById('instSaveBtn').textContent = 'Salvar alterações';
    }

    function newInstanceForm() {
        document.getElementById('instanceFormAction').value = 'create_instance';
        document.getElementById('instId').value = '';
        document.getElementById('instName').value = '';
        document.getElementById('instNodeBase').value = 'http://localhost:3020';
        document.getElementById('instDescription').value = '';
        document.getElementById('openaiEnabled').checked = false;
        document.getElementById('openaiApiKey').value = '';
        document.getElementById('openaiSystemPrompt').value = 'You are a helpful WhatsApp assistant. Respond naturally and concisely.';
        document.getElementById('openaiAssistantPrompt').value = '';
        document.getElementById('instSaveBtn').textContent = 'Criar instância';
    }

    // Inicialização
    refreshQR();
    refreshStatus();
    setInterval(refreshQR, 5000);
    setInterval(refreshStatus, 3000);
</script>

</body>
</html>

