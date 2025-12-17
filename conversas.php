<?php
// conversas.php - Intelligent Chat Dashboard
// WhatsApp-style interface matching the existing design

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';
require_once __DIR__ . '/external_auth.php';
date_default_timezone_set('America/Fortaleza');
ensureExternalUsersSchema();
if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

session_start();
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

debug_log('Conversas dashboard loaded for instance: ' . $instanceId);

// Get instance details
$instance = loadInstanceRecordFromDatabase($instanceId);

if (!$instance) {
    header("Location: /api/envio/wpp/");
    exit;
}

$instancePhoneLabel = formatInstancePhoneLabel($instance['phone'] ?? '');

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
    }
}

debug_log('Instance status: ' . $connectionStatus);

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

if (isset($_GET['ajax_chats'])) {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $path = buildNodePath("/api/chats/{$instanceId}", ['limit' => $limit, 'offset' => $offset]);
    proxyNodeRequest($instance, $path, 'GET');
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
    $to = trim((string)($payload['to'] ?? ''));
    $message = trim((string)($payload['message'] ?? ''));
    if ($to === '' || $message === '') {
        respondJson(['ok' => false, 'error' => 'Parâmetros obrigatórios ausentes'], 400);
    }
    $body = json_encode(['to' => $to, 'message' => $message]);
    proxyNodeRequest($instance, '/send-message', 'POST', $body);
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
  </style>
</head>

<body class="bg-light text-dark overflow-hidden">
<div class="h-screen flex overflow-hidden">

  <!-- SIDEBAR / INSTÂNCIAS (PRESERVED FROM ORIGINAL) -->
  <aside class="w-80 bg-white border-r border-mid hidden lg:flex flex-col h-screen overflow-hidden">
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

      <div class="mt-4 w-full px-4 py-2 rounded-xl bg-light border border-mid text-sm text-slate-500 text-center">
        <?= htmlspecialchars($instance['name']) ?>
      </div>

      <a href="/api/envio/wpp/?instance=<?= urlencode($instanceId) ?>" class="mt-4 w-full px-4 py-2 rounded-xl bg-mid text-dark font-medium hover:bg-primary hover:text-white transition text-center block">
        ← Voltar ao Painel
      </a>
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
    <div class="flex flex-col flex-1 min-h-0 relative">
    <!-- CHAT HEADER -->
    <div class="bg-white border border-mid rounded-2xl p-4 mb-6 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-white font-medium">
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
          <?php if ($instancePhoneLabel): ?>
            <div class="text-[11px] text-slate-500 mt-1">WhatsApp local: <?= htmlspecialchars($instancePhoneLabel) ?></div>
          <?php endif; ?>
        </div>
      </div>
      
        <div class="flex items-center gap-2">
            <?php if ($connectionStatus === 'connected'): ?>
              <span class="px-3 py-1 rounded-full bg-success/10 text-success text-sm font-medium">
                Conectado
              </span>
            <?php else: ?>
              <span class="px-3 py-1 rounded-full bg-error/10 text-error text-sm font-medium">
                Desconectado
              </span>
            <?php endif; ?>
            <button id="contactDetailsBtn" class="px-3 py-1 rounded-xl border border-mid text-xs text-slate-600 hover:border-primary hover:text-primary">
              Detalhes
            </button>
            <button id="clearChatBtn" disabled class="px-3 py-1 rounded-xl border border-mid text-xs text-slate-600 hover:border-primary hover:text-primary">
              Limpar conversa
            </button>
            <button id="deleteChatBtn" disabled class="px-3 py-1 rounded-xl border border-error text-xs text-error hover:bg-error/10">
              Apagar conversa
            </button>
            <button id="newConversationBtn" class="px-3 py-1 rounded-xl border border-primary text-xs text-primary hover:bg-primary/10">
              + Nova conversa
            </button>
            <button id="toggleAgBtn" class="px-3 py-1.5 rounded-lg border border-mid bg-white hover:bg-slate-50 text-sm flex items-center gap-2">
              <span id="toggleAgBtnText">Ver agendamentos</span>
              <span id="scheduleBadge" class="hidden text-[11px] font-semibold gap-1 flex items-center"></span>
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
          <div class="flex items-center justify-between">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Agendamentos</p>
              <p id="scheduledSummary" class="text-[11px] text-slate-400">Sem agendamentos para este contato.</p>
            </div>
            <button id="refreshScheduleBtn" class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
              Atualizar
            </button>
          </div>
          <div id="scheduledList" class="space-y-2 text-sm text-slate-500"></div>
        </div>
      </div>
    </div>
    <div id="chatDebugFooter" class="mt-3 text-[11px] text-slate-400 flex flex-wrap gap-3">
      <span>Conversa: <span id="debugConversationId">—</span></span>
      <span id="debugScheduledHint" class="text-[10px] text-slate-400"></span>
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

<script>
const INSTANCE_ID = '<?= $instanceId ?>';
const API_BASE_URL = `${window.location.origin}${window.location.pathname}`;

// State management
let selectedContact = null;
let selectedContactData = null;
let contacts = [];
let messages = {};
let isLoading = false;
const timeOptions = { hour: '2-digit', minute: '2-digit', timeZone: 'America/Fortaleza' };
const dateTimeOptions = { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'America/Fortaleza' };
const encodeAttrValue = value => encodeURIComponent(value || '');
const urlParams = new URLSearchParams(window.location.search);
let pendingInitialContact = urlParams.get('contact') || null;
const logPrefix = `[conversas ${INSTANCE_ID}]`;
const CHAT_SCROLL_THRESHOLD = 48;
const scheduleTimeOptions = {
    hour: '2-digit',
    minute: '2-digit',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'America/Fortaleza'
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
const newConversationClose = document.getElementById('newConversationClose');
const newConversationCancel = document.getElementById('newConversationCancel');
const newConversationForm = document.getElementById('newConversationForm');
const newConversationPhone = document.getElementById('newConversationPhone');
const newConversationMessage = document.getElementById('newConversationMessage');
const newConversationStatus = document.getElementById('newConversationStatus');
const scheduledPanel = document.getElementById('scheduledPanel');
const scheduledList = document.getElementById('scheduledList');
const scheduledSummary = document.getElementById('scheduledSummary');
const debugConversationId = document.getElementById('debugConversationId');
const debugScheduledHint = document.getElementById('debugScheduledHint');
const refreshScheduleBtn = document.getElementById('refreshScheduleBtn');
const statusBroadcastAlert = document.getElementById('statusBroadcastAlert');
const statusBroadcastText = document.getElementById('statusBroadcastText');
const statusBroadcastClose = document.getElementById('statusBroadcastClose');

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
        const seconds = Math.max(0, payload.remaining_seconds ?? payload.delay_seconds ?? 0);
        chatQueueStatus.textContent = `Aguardando ${seconds}s para enviar resposta automática`;
        chatQueueStatus.classList.remove('hidden');
    } else {
        chatQueueStatus.textContent = '';
        chatQueueStatus.classList.add('hidden');
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
    const timestamp = notification.timestamp ? (new Date(notification.timestamp)).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Fortaleza' }) : '';
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
function formatRemoteJid(remoteJid) {
  if (!remoteJid) return 'Não definido';
  const clean = (remoteJid.split('@')[0] || '').replace(/\D/g, '');
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
  const formatted = clean.replace(/^(\d{2})(\d{4,5})(\d{4})$/, '$1 $2-$3');
  return formatted || clean;
}

function getContactStatusLabel(contact) {
  if (!contact) return 'Não definido';
  const status = (contact.status_name || '').trim();
  if (status) return status;
  return formatRemoteJid(contact.remote_jid);
}

function formatScheduledTime(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }
  return date.toLocaleString('pt-BR', scheduleTimeOptions);
}

async function loadScheduledList(remoteJid) {
  if (!remoteJid || !scheduledPanel) {
    scheduledPanel?.classList.add('hidden');
    updateScheduleBadge(0, 0);
    return;
  }
  scheduledPanel.classList.remove('hidden');
  scheduledList.innerHTML = '<div class="text-xs text-slate-400">Carregando agendamentos...</div>';

  try {
    const response = await fetchWithCreds(buildAjaxUrl({ ajax_scheduled: '1', remote: remoteJid }));
    const data = await response.json();
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Falha ao buscar agendamentos');
    }
    renderScheduledList(data.schedules || []);
  } catch (error) {
    console.error(logPrefix, 'loadScheduledList error', error);
    scheduledList.innerHTML = `<div class="text-xs text-error">Erro ao carregar agendamentos</div>`;
    scheduledSummary?.classList?.add('text-error');
    scheduledSummary.textContent = 'Erro ao listar agendamentos.';
    updateScheduleBadge(0, 0);
  }
}

function renderScheduledList(rows) {
  if (!rows || !rows.length) {
    scheduledList.innerHTML = '<div class="text-xs text-slate-400">Nenhum agendamento encontrado.</div>';
    scheduledSummary.textContent = 'Sem agendamentos para este contato.';
    if (debugScheduledHint) {
      debugScheduledHint.textContent = '';
    }
    updateScheduleBadge(0, 0);
    return;
  }
  const pendingCount = rows.filter(row => row.status === 'pending').length;
  const sentCount = rows.filter(row => row.status === 'sent').length;
  updateScheduleBadge(pendingCount, sentCount);
  scheduledSummary.textContent = `${rows.length} agendamento${rows.length > 1 ? 's' : ''} pendente${rows.length > 1 ? 's' : ''}.`;
  if (debugScheduledHint) {
    debugScheduledHint.textContent = `${rows.length} agendamento${rows.length > 1 ? 's' : ''} pendente${rows.length > 1 ? 's' : ''}.`;
  }
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

// ===== API FUNCTIONS =====

// Load contacts from API
async function loadContacts() {
    console.log(logPrefix, 'loadContacts');
    try {
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_chats: '1' }));
        const data = await response.json();
        
        if (data.ok) {
            contacts = data.chats;
            console.log(logPrefix, 'loadContacts success', { count: contacts.length });
            renderContacts();
            if (pendingInitialContact) {
                selectContactByRemote(pendingInitialContact);
                pendingInitialContact = null;
            }
        } else {
            throw new Error(data.error || 'Failed to load contacts');
        }
    } catch (error) {
        console.error(logPrefix, 'Error loading contacts:', error);
        contactsList.innerHTML = '<div class="p-4 text-center text-error">Erro ao carregar conversas</div>';
    }
}

// Load messages for specific contact
async function loadMessages(remoteJid) {
    console.log(logPrefix, 'loadMessages', { remote: remoteJid });
    try {
        isLoading = true;
        chatStatus.textContent = 'Carregando mensagens...';
        
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_messages: '1', remote: remoteJid }));
        const data = await response.json();
        
        if (data.ok) {
            messages[remoteJid] = data.messages;
            console.log(logPrefix, 'loadMessages success', { remote: remoteJid, count: data.messages.length });
            renderMessages(remoteJid);
            chatStatus.textContent = `${data.messages.length} mensagens`;
        } else {
            throw new Error(data.error || 'Failed to load messages');
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
        if (isStatusBroadcastJid(contact.remote_jid)) {
            return false;
        }
        const target = (contact.remote_jid || '').toLowerCase();
        const statusLabel = getContactStatusLabel(contact).toLowerCase();
        const contactName = (contact.contact_name || '').toLowerCase();
        const statusName = (contact.status_name || '').toLowerCase();
        return (
            target.includes(searchTerm) ||
            statusLabel.includes(searchTerm) ||
            contactName.includes(searchTerm) ||
            statusName.includes(searchTerm)
        );
    });
    filteredContacts.sort((a, b) => {
        const ta = new Date(a.last_timestamp).getTime();
        const tb = new Date(b.last_timestamp).getTime();
        return tb - ta;
    });
    
    if (filteredContacts.length === 0) {
        console.log(logPrefix, 'renderContacts none found', { searchTerm });
        contactsList.innerHTML = '<div class="p-4 text-center text-slate-500">Nenhuma conversa encontrada</div>';
        return;
    }
    
    contactsList.innerHTML = '';
    filteredContacts.forEach(contact => {
        const lastMessage = contact.last_message || 'Nenhuma mensagem';
        const lastRole = contact.last_role === 'user' ? 'Você: ' : 'IA: ';
        const time = contact.last_timestamp ? new Date(contact.last_timestamp).toLocaleTimeString('pt-BR', timeOptions) : '--:--';
        const statusLabel = getContactStatusLabel(contact);
        const attrRemote = encodeAttrValue(contact.remote_jid);
        const attrLabel = encodeAttrValue(statusLabel);
        const contactName = (contact.contact_name || '').trim();
        const previewText = `${lastRole}${lastMessage.substring(0, 40)}...`;
        const subtitleText = contactName ? `${contactName} • ${previewText}` : previewText;

        const item = document.createElement('button');
        item.type = 'button';
        item.className = `contact-item w-full text-left p-4 cursor-pointer ${selectedContact === contact.remote_jid ? 'active' : ''}`;
        item.dataset.remote = attrRemote;
        item.dataset.label = attrLabel;

        const wrapper = document.createElement('div');
        wrapper.className = 'flex items-center gap-3';

        const avatar = document.createElement('div');
        avatar.className = 'w-10 h-10 rounded-full bg-primary flex items-center justify-center text-white font-medium overflow-hidden';
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
    const contact = contacts.find(c => c.remote_jid === remoteJid);
    if (!contact) return;
    const label = getContactStatusLabel(contact);
    console.log(logPrefix, 'selectContactByRemote', { remote: remoteJid, label });
    setTimeout(() => {
        const element = contactsList.querySelector(`[data-remote="${encodeAttrValue(remoteJid)}"]`);
        selectContact(encodeAttrValue(remoteJid), encodeAttrValue(label), element);
    }, 0);
}

// Render messages for selected contact
function renderMessages(remoteJid) {
    const currentMessages = messages[remoteJid] || [];
    console.log(logPrefix, 'renderMessages', { remote: remoteJid, count: currentMessages.length });
    const contactMessages = [...currentMessages].sort((a, b) => {
        const ta = new Date(a.timestamp).getTime();
        const tb = new Date(b.timestamp).getTime();
        return ta - tb;
    });
    
    const previousScrollTop = messagesArea.scrollTop;
    const previousScrollHeight = messagesArea.scrollHeight;

    if (contactMessages.length === 0) {
        messagesArea.innerHTML = `
            <div class="text-center text-slate-500 py-8">
                <p>Nenhuma mensagem ainda</p>
                <p class="text-sm mt-2">Inicie a conversa!</p>
            </div>
        `;
        if (shouldAutoScrollMessages) {
            scrollMessagesToBottom();
        } else {
            messagesArea.scrollTop = Math.min(previousScrollTop, Math.max(0, messagesArea.scrollHeight - messagesArea.clientHeight));
        }
        return;
    }
    
    messagesArea.innerHTML = contactMessages.map(msg => {
        const direction = msg.direction || (msg.role === 'assistant' ? 'outbound' : 'inbound');
        const isOutgoing = direction === 'outbound';
        const metadata = parseMetadata(msg.metadata);
        const isDebug = metadata?.debug === true;
        const isErrorMessage = metadata?.severity === 'error';
        const errorText = metadata?.error;
        const time = new Date(msg.timestamp).toLocaleTimeString('pt-BR', { 
            hour: '2-digit', 
            minute: '2-digit',
            timeZone: 'America/Fortaleza'
        });
        const errorDetails = errorText ? `<div class="text-[10px] ${isErrorMessage ? 'text-error' : 'text-slate-500'} mt-1">Erro: ${escapeHtml(errorText)}</div>` : '';
        
        const commandLines = metadata?.commands || [];
        const functionResults = commandLines.map(formatCommandResult).filter(Boolean);
        const hasFunctionResult = functionResults.length > 0;
        const commandSection = commandLines.length ? `
            <div class="mt-2 space-y-2">
                ${commandLines.map(cmd => {
                    const argsText = (cmd.args || []).map(arg => escapeHtml(String(arg || ''))).filter(Boolean).join(', ');
                    const displayArgs = argsText || 'sem argumentos';
                    return `
                    <div class="px-3 py-2 rounded-2xl border border-orange-200 bg-orange-50 text-[12px] text-orange-900">
                        Função <span class="font-semibold text-orange-800">${escapeHtml(cmd.type)}()</span>
                        <div class="text-[11px] text-orange-800/80 mt-1">Parâmetros: ${displayArgs}</div>
                    </div>
                    `;
                }).join('')}
            </div>
        ` : '';

        const bubbleClasses = [
            isOutgoing ? 'message-outgoing' : 'message-incoming',
            hasFunctionResult ? 'message-function' : '',
            isDebug ? 'message-debug' : '',
            isErrorMessage ? 'message-error' : ''
        ].filter(Boolean).join(' ');

        return `
            <div class="flex ${isOutgoing ? 'justify-end' : 'justify-start'} flex-col">
                <div class="message-bubble ${bubbleClasses} p-3">
                    <div class="text-sm">${escapeHtml(msg.content)}</div>
                    <div class="text-xs mt-1 opacity-70">${time}</div>
                    ${functionResults.length ? `<div class="function-result">${functionResults.map(text => escapeHtml(text)).join('<br>')}</div>` : ''}
                    ${errorDetails}
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
}

function updateChatActions(active) {
    [contactDetailsBtn, clearChatBtn, deleteChatBtn].forEach(btn => {
        if (btn) btn.disabled = !active;
    });
}

function formatTimestamp(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString('pt-BR', timeOptions);
}

function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
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

async function openContactDetails() {
    if (!selectedContact) return;
    const meta = selectedContactData || contacts.find(c => c.remote_jid === selectedContact) || {};
    const rows = [
        ['Remote JID', meta.remote_jid || '-'],
        ['Telefone formatado', formatRemoteJid(meta.remote_jid) || '-'],
        ['Nome do contato', meta.contact_name || meta.status_name || '-'],
        ['Status name', meta.status_name || '-'],
        ['Mensagens', meta.message_count ?? 0],
        ['Última mensagem', formatDateTime(meta.last_timestamp)]
    ];
    const statusLabel = getContactStatusLabel(meta);
    const contactSubtitle = (meta.contact_name || meta.status_name || formatRemoteJid(meta.remote_jid) || '').trim() || 'Não definido';
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

function closeContactDetails() {
    contactDetailsModal.classList.add('hidden');
}

function clearConversationUI() {
    if (!selectedContact) return;
    console.log(logPrefix, 'clearConversationUI', { remote: selectedContact });
    messages[selectedContact] = [];
    renderMessages(selectedContact);
    chatStatus.textContent = 'Conversa limpa';
    if (debugScheduledHint) {
        debugScheduledHint.textContent = '';
    }
}

async function deleteConversation() {
    if (!selectedContact) return;
    if (!confirm('Tem certeza que deseja apagar todo o histórico desta conversa?')) return;
    try {
        console.log(logPrefix, 'deleteConversation start', { remote: selectedContact });
        const response = await fetchWithCreds(buildAjaxUrl({ ajax_messages: '1', remote: selectedContact }), {
            method: 'DELETE'
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
        loadContacts();
        stopMultiInputPolling();
        if (debugConversationId) {
            debugConversationId.textContent = '—';
        }
        if (debugScheduledHint) {
            debugScheduledHint.textContent = '';
        }
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
    const decodedName = decodeURIComponent(contactName || '');
    if (isStatusBroadcastJid(decodedRemote)) {
        chatStatus.textContent = 'Conversa do status não pode ser aberta.';
        updateChatActions(false);
        messageInput.disabled = true;
        sendBtn.disabled = true;
        if (element) {
            element.classList.remove('active');
        }
        return;
    }
    selectedContact = decodedRemote;
    if (debugConversationId) {
        debugConversationId.textContent = decodedRemote || '—';
    }
    if (debugScheduledHint) {
        debugScheduledHint.textContent = '';
    }
    selectedContactData = contacts.find(c => c.remote_jid === decodedRemote) || null;
    updateChatActions(true);
    console.log(logPrefix, 'selectContact', { remote: decodedRemote, name: decodedName || formatRemoteJid(decodedRemote) });
    chatContactName.textContent = decodedName || formatRemoteJid(decodedRemote);
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
    loadMessages(decodedRemote).finally(() => loadScheduledList(decodedRemote));
    startMultiInputPolling();
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
        chatContactName.textContent = targetLabel;
        chatStatus.textContent = 'Mensagem enviada';
        updateChatActions(true);
        renderMessages(remoteJid);
        loadContacts();
        closeNewConversationModal();
        startMultiInputPolling();
    } catch (error) {
        console.error(logPrefix, 'New conversation error', error);
        newConversationStatus.textContent = `Erro: ${error.message}`;
        newConversationStatus.className = 'text-xs text-error';
    }
}

function parseMetadata(raw) {
    if (!raw) return null;
    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

// ===== INITIALIZATION =====

// Event listeners
searchInput.addEventListener('input', renderContacts);
messageForm.addEventListener('submit', sendMessage);
refreshBtn.addEventListener('click', () => {
    if (selectedContact) {
        loadMessages(selectedContact);
    } else {
        loadContacts();
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
if (clearChatBtn) {
    clearChatBtn.addEventListener('click', () => {
        clearConversationUI();
    });
}
if (deleteChatBtn) {
    deleteChatBtn.addEventListener('click', deleteConversation);
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
    if (selectedContact && !isLoading) {
        loadMessages(selectedContact);
    }
    loadContacts();
}, AUTO_REFRESH_INTERVAL);

function initializeConversationView() {
    loadContacts();
    checkSystemHealth();

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
      setToggleAgText('Ver agendamentos');
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

<footer class="w-full bg-slate-900 text-slate-200 text-xs text-center py-3 mt-6">
  Por <strong>Osvaldo J. Filho</strong> |
  <a href="https://linkedin.com/in/ojaneri" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">LinkedIn</a> |
  <a href="https://github.com/ojaneri/maestro" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">GitHub</a>
</footer>
</body>
</html>
