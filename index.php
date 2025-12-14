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

// --- Carregar instâncias ---
$instancesFile = __DIR__ . "/instances.json";
if (!file_exists($instancesFile)) {
    debug_log('instances.json does not exist, creating empty file');
    file_put_contents($instancesFile, json_encode([]));
}
$instances = json_decode(file_get_contents($instancesFile), true);
if ($instances === null) {
    $fileContent = file_get_contents($instancesFile);
    debug_log('json_decode failed for instances.json, content: ' . $fileContent);
    $instances = [];
}
debug_log('Loaded ' . count($instances) . ' instances from instances.json');

// Check status for each instance using proper API endpoints
$statuses = [];
$connectionStatuses = [];
foreach ($instances as $id => $inst) {
    $status = isPortOpen('localhost', $inst['port']) ? 'Running' : 'Stopped';
    $statuses[$id] = $status;
    debug_log("Status check for {$id} on port {$inst['port']}: {$status}");

    // Use connection_status from instances.json as fallback
    $connectionStatuses[$id] = $inst['connection_status'] ?? 'disconnected';

    if ($status === 'Running') {
        // Try health check first (faster)
        $ch = curl_init("http://127.0.0.1:{$inst['port']}/health");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$err && $resp) {
            $data = json_decode($resp, true);
            if ($data && isset($data['status'])) {
                // Health endpoint response
                $connectionStatuses[$id] = $data['whatsappConnected'] ? 'connected' : 'disconnected';
            }
        } else {
            // If health check fails, try detailed status
            $ch = curl_init("http://127.0.0.1:{$inst['port']}/status");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);

            if (!$err && $resp) {
                $data = json_decode($resp, true);
                if ($data && isset($data['connectionStatus'])) {
                    // Status endpoint response
                    $connectionStatuses[$id] = $data['connectionStatus'];
                }
            }
        }
        debug_log("Connection status for {$id}: {$connectionStatuses[$id]}");
    }
}

$totalInstances = count($instances);
$runningInstances = count(array_filter($statuses, fn($status) => $status === 'Running'));
$connectedInstances = count(array_filter($connectionStatuses, fn($conn) => strtolower($conn) === 'connected'));
$disconnectedInstances = $totalInstances - $connectedInstances;
$activePercent = $totalInstances ? round($runningInstances / $totalInstances * 100) : 0;
$connectedPercent = $totalInstances ? round($connectedInstances / $totalInstances * 100) : 0;
$disconnectedPercent = $totalInstances ? round(max(0, $disconnectedInstances) / $totalInstances * 100) : 0;

// --- Criar nova instância ---
if (isset($_POST['create'])) {
    debug_log('Creating new instance: name=' . $_POST['name']);
    $nextPort = 3010 + count($instances) + 1;

    $id = uniqid("inst_");
    $apiKey = bin2hex(random_bytes(16));

    $instances[$id] = [
        "id" => $id,
        "name" => $_POST["name"],
        "port" => $nextPort,
        "api_key" => $apiKey,
        "status" => "stopped",
        "created_at" => date("Y-m-d H:i:s")
    ];

    debug_log('Instance details: id=' . $id . ', port=' . $nextPort . ', api_key=' . $apiKey);
    file_put_contents($instancesFile, json_encode($instances, JSON_PRETTY_PRINT));
    debug_log('Saved instances to file');

    // executa script que cria a instância
    exec("bash create_instance.sh {$id} {$nextPort} >/dev/null 2>&1 &");
    debug_log('Executed create_instance.sh for ' . $id . ' on port ' . $nextPort);

    debug_log('Redirecting to /api/envio/wpp/ after create');
    header("Location: /api/envio/wpp/");
    exit;
}

// --- Ações ---
if (isset($_GET["delete"])) {
    debug_log('Deleting instance: ' . $_GET['delete']);
    unset($instances[$_GET["delete"]]);
    file_put_contents($instancesFile, json_encode($instances, JSON_PRETTY_PRINT));
    debug_log('Instance deleted, saved to file, redirecting to /api/envio/wpp/');
    header("Location: /api/envio/wpp/");
    exit;
}

if (isset($_GET["qr"])) {
    debug_log('QR requested for instance: ' . $_GET['qr']);
    $instanceId = $_GET['qr'];

    if (!isset($instances[$instanceId])) {
        header("HTTP/1.1 404 Not Found");
        echo "Instance not found";
        exit;
    }

    $instance = $instances[$instanceId];
    $url = "http://127.0.0.1:{$instance['port']}/qr";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200 || !$response) {
        debug_log("QR request failed: $error, code: $httpCode");
        header("HTTP/1.1 204 No Content");
        exit;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['qr'])) {
        debug_log("QR response invalid: $response");
        header("HTTP/1.1 204 No Content");
        exit;
    }

    $qrData = $data['qr'];
    $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qrData);
    header("Location: $qrImageUrl");
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

// Select instance
$selectedInstanceId = $_GET['instance'] ?? array_key_first($instances);
$selectedInstance = $instances[$selectedInstanceId] ?? null;

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
  </style>
</head>

<body class="bg-light text-dark">
<div class="min-h-screen flex">

  <!-- SIDEBAR / INSTÂNCIAS -->
  <aside class="w-80 bg-white border-r border-mid hidden lg:flex flex-col">
    <div class="p-6 border-b border-mid">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-primary"></div>
        <div>
          <div class="text-lg font-semibold text-dark">Maestro</div>
          <div class="text-xs text-slate-500">WhatsApp Orchestrator</div>
        </div>
      </div>

      <button onclick="openCreateModal()" class="mt-4 w-full px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90 transition">
        Nova instância
      </button>

      <input class="mt-4 w-full px-3 py-2 rounded-xl bg-light border border-mid text-sm"
             placeholder="Buscar instância...">
    </div>

    <div class="p-3 space-y-2">
      <div class="text-xs text-slate-500 px-2">INSTÂNCIAS</div>

      <?php foreach ($instances as $id => $inst): ?>
        <a href="?instance=<?= $id ?>" class="block w-full p-3 rounded-xl border <?= $id === $selectedInstanceId ? 'border-primary bg-light' : 'border-mid bg-white hover:bg-light' ?> transition">
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
            </div>
          </div>
        </a>
      <?php endforeach; ?>

    </div>

    <div class="mt-auto p-6 border-t border-mid">
      <button onclick="logout()" class="w-full text-left text-sm text-slate-500 hover:text-dark">Logout</button>
      <div class="text-xs text-slate-500 mt-2">Maestro • MVP</div>
    </div>
  </aside>

  <!-- ÁREA CENTRAL -->
  <main class="flex-1 p-8 space-y-6">

    <!-- HEADER -->
    <div class="flex justify-between items-start">
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
      </div>

      <div class="flex gap-2">
        <?php if ($selectedInstance && strtolower($connectionStatuses[$selectedInstanceId] ?? '') !== 'connected' && $statuses[$selectedInstanceId] === 'Running'): ?>
          <button onclick="openQRModal('<?= $selectedInstanceId ?>')" class="px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">
            Conectar QR
          </button>
        <?php endif; ?>
        <?php if ($selectedInstance && strtolower($connectionStatuses[$selectedInstanceId] ?? '') === 'connected'): ?>
          <form method="POST" class="inline">
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

        <form method="POST" action="?instance=<?= $selectedInstanceId ?>">
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

          <button type="submit" name="send" class="mt-4 px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
            Enviar mensagem
          </button>
          <?php if (isset($sendSuccess)): ?>
            <p class="text-success mt-2 text-sm"><?php echo $sendSuccess; ?></p>
          <?php endif; ?>
          <?php if (isset($sendError)): ?>
            <p class="text-error mt-2 text-sm"><?php echo $sendError; ?></p>
          <?php endif; ?>
          </form>
      </section>

      <!-- CONFIG RÁPIDA -->
      <aside class="bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-4">Configuração rápida</div>

        <div class="space-y-3">
          <div>
            <label class="text-xs text-slate-500">Nome da instância</label>
            <input class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($selectedInstance['name'] ?? '') ?>">
          </div>

          <div>
            <label class="text-xs text-slate-500">Base URL</label>
            <input class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="http://127.0.0.1:<?= $selectedInstance['port'] ?? '' ?>">
          </div>

          <div>
            <label class="text-xs text-slate-500">Provider</label>
            <select class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light">
              <option <?= ($selectedInstance['provider'] ?? 'custom') === 'custom' ? 'selected' : '' ?>>custom</option>
              <option <?= ($selectedInstance['provider'] ?? 'custom') === 'baileys' ? 'selected' : '' ?>>baileys</option>
              <option <?= ($selectedInstance['provider'] ?? 'custom') === 'evolution' ? 'selected' : '' ?>>evolution</option>
            </select>
          </div>
        </div>
      </aside>

    </div>

    <!-- OPENAI + GUIA -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

      <!-- OPENAI -->
      <section class="xl:col-span-2 bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-1">OpenAI – Responses API</div>
        <p class="text-sm text-slate-500 mb-4">Configuração específica desta instância</p>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div>
            <label class="text-xs text-slate-500">Modelo</label>
            <input class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="gpt-4.1-mini">

            <label class="text-xs text-slate-500 mt-3 block">API Key</label>
            <input type="password" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   placeholder="sk-...">
          </div>

          <div class="lg:col-span-2">
            <label class="text-xs text-slate-500">System prompt</label>
            <textarea rows="4" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"></textarea>

            <label class="text-xs text-slate-500 mt-3 block">Instructions</label>
            <textarea rows="5" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"></textarea>
          </div>
        </div>

        <div class="mt-4 flex gap-2">
          <button class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
            Salvar
          </button>
          <button class="px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">
            Testar
          </button>
        </div>
      </section>

      <!-- COMO CONECTAR -->
      <aside class="bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-3">Como conectar</div>

        <div class="space-y-3 text-sm text-slate-600">
          <div class="p-3 rounded-xl bg-light border border-mid">
            <strong>GET /health</strong><br>
            Deve retornar ok true
          </div>
          <div class="p-3 rounded-xl bg-light border border-mid">
            <strong>GET /status</strong><br>
            Informa se o WhatsApp está conectado
          </div>
          <div class="p-3 rounded-xl bg-light border border-mid">
            <strong>POST /send</strong><br>
            Envia mensagens
          </div>
        </div>
      </aside>

    </div>

  </main>
</div>

<!-- Modal for Create Instance -->
<div id="createModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-lg font-semibold">Criar nova instância</h2>
      <button onclick="closeCreateModal()" class="text-slate-500 hover:text-dark">&times;</button>
    </div>
    <form method="POST">
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
    <div class="text-center">
      <img id="qrImage" src="" alt="Código QR" class="mx-auto">
    </div>
    <button onclick="refreshQR()" class="mt-4 w-full px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">Atualizar QR</button>
  </div>
</div>

<script>
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
  document.getElementById('qrModal').classList.remove('hidden');
  refreshQR(instanceId);
}

function closeQRModal() {
  document.getElementById('qrModal').classList.add('hidden');
}

function refreshQR(instanceId) {
  if (!instanceId) return;
  const img = document.getElementById('qrImage');
  img.src = '?qr=' + instanceId + '&t=' + Date.now();
  img.style.display = 'block';
}
</script>
</body>
</html>
