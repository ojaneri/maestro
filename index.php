<?php
/**
 * Maestro Dashboard — Orquestrador WhatsApp
 *
 * Slim orchestrator: all logic is modularized into includes/ajax/views.
 * This file handles session setup, auth, routing and renders the dashboard.
 */

// --- Core dependencies ---
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/timezone.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/database-helpers.php';
require_once __DIR__ . '/includes/log-helpers.php';
require_once __DIR__ . '/includes/baileys-logs.php';
require_once __DIR__ . '/includes/instance-status.php';
require_once __DIR__ . '/instance_data.php';
require_once __DIR__ . '/external_auth.php';
require_once __DIR__ . '/views/sidebar.php';
require_once __DIR__ . '/ajax/handlers.php';
require_once __DIR__ . '/includes/actions.php';
require_once __DIR__ . '/includes/ai-config-vars.php';

// --- View partials (tabs) ---
require_once __DIR__ . '/views/tabs/tab-messages.php';
require_once __DIR__ . '/views/tabs/tab-general.php';
require_once __DIR__ . '/views/tabs/tab-ia.php';
require_once __DIR__ . '/views/tabs/tab-agenda.php';
require_once __DIR__ . '/views/tabs/tab-automacao.php';
require_once __DIR__ . '/views/tabs/tab-templates.php';
require_once __DIR__ . '/views/tabs/tab-web-access.php';
require_once __DIR__ . '/views/tabs/tab-monitoramento.php';
require_once __DIR__ . '/views/tabs/tab-api.php';

// --- View partials (modals) ---
require_once __DIR__ . '/views/modals/debug-log.php';
require_once __DIR__ . '/views/modals/create-instance.php';
require_once __DIR__ . '/views/modals/qr-code.php';
require_once __DIR__ . '/views/modals/qr-reset.php';
require_once __DIR__ . '/views/modals/ai-test.php';

// --- Dotenv ---
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
try {
    $dotenv->load();
    debug_log('Dotenv load successful');
} catch (Exception $e) {
    debug_log('Dotenv load failed: ' . $e->getMessage());
}
debug_log('PANEL_USER_EMAIL from _ENV: ' . ($_ENV['PANEL_USER_EMAIL'] ?? 'not set'));
debug_log('PANEL_PASSWORD from _ENV: ' . (isset($_ENV['PANEL_PASSWORD']) ? '***' : 'not set'));
debug_log('PANEL_USER_EMAIL from getenv: ' . (getenv('PANEL_USER_EMAIL') ?: 'not set'));

// --- Authentication ---
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
perf_mark('session.ready');
ensureExternalUsersSchema();
$externalUser = $_SESSION['external_user'] ?? null;
$isAdmin = isset($_SESSION['auth']) && $_SESSION['auth'];
$isManager = $externalUser && ($externalUser['role'] ?? '') === 'manager';
if (!$isAdmin && !$isManager) {
    debug_log('Auth not set, redirecting to login.php');
    perf_mark('auth.redirect');
    perf_log('index.php auth.redirect', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
    include "login.php";
    exit;
}

// --- Load instances & statuses ---
perf_mark('instances.load.start');
$instances = loadInstancesFromDatabase();
perf_mark('instances.loaded', ['count' => count($instances)]);
debug_log('Loaded ' . count($instances) . ' instances from SQLite');

$sidebarInstances = $instances;
if ($isManager) {
    $allowedIds = array_map(fn($entry) => $entry['instance_id'], $externalUser['instances'] ?? []);
    $sidebarInstances = array_filter($instances, function($inst, $identifier) use ($allowedIds) {
        return in_array($identifier, $allowedIds, true);
    }, ARRAY_FILTER_USE_BOTH);
}

perf_mark('statuses.build.start');
list($statuses, $connectionStatuses) = buildInstanceStatuses($instances);
perf_mark('statuses.built', ['count' => count($statuses)]);
$dashboardBaseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($dashboardBaseUrl === '') {
  $dashboardBaseUrl = '/';
}
$baseRedirectUrl = rtrim($dashboardBaseUrl, '/') . '/';
$dashboardLogoUrl = buildPublicBaseUrl($dashboardBaseUrl . '/assets/maestro-logo.png');

// --- Resolve selected instance ---
$totalInstances = count($instances);
$runningInstances = count(array_filter($statuses, fn($status) => $status === 'Running'));
$connectedInstances = count(array_filter($connectionStatuses, fn($conn) => strtolower($conn) === 'connected'));
$disconnectedInstances = $totalInstances - $connectedInstances;
$activePercent = $totalInstances ? round($runningInstances / $totalInstances * 100) : 0;
$connectedPercent = $totalInstances ? round($connectedInstances / $totalInstances * 100) : 0;
$disconnectedPercent = $totalInstances ? round(max(0, $disconnectedInstances) / $totalInstances * 100) : 0;

$requestedInstanceId = trim((string)($_GET['instance'] ?? ($_POST['instance'] ?? '')));
$selectedInstanceId = $requestedInstanceId !== '' ? $requestedInstanceId : null;
if ($selectedInstanceId && !isset($sidebarInstances[$selectedInstanceId])) {
    $selectedInstanceId = null;
}
$selectedInstanceId = $selectedInstanceId ?? (array_key_first($sidebarInstances) ?: null);
$selectedInstance = $sidebarInstances[$selectedInstanceId] ?? null;

// --- AJAX handlers (each exits if matched) ---
// Now called after $selectedInstance is resolved
if (handleAjaxRequest()) exit;

$webAccessBaseUrl = buildPublicBaseUrl('/web/');
$webAccessUrl = $selectedInstanceId ? $webAccessBaseUrl . '?id=' . urlencode($selectedInstanceId) : null;
$webEmbedSnippet = '';
$webFloatingSnippet = '';
if ($webAccessUrl) {
    $encodedWebUrl = htmlspecialchars($webAccessUrl, ENT_QUOTES, 'UTF-8');
    $webEmbedSnippet = <<<HTML
<iframe src="$encodedWebUrl" width="420" height="720" style="border:0;border-radius:32px;box-shadow:0 20px 40px rgba(15,23,42,0.25);" loading="lazy" allow="microphone"></iframe>
HTML;
    $webFloatingSnippet = <<<HTML
<div id="maestro-web-floating-chat" style="position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;align-items:flex-end;gap:0.5rem;">
  <button id="maestroFloatingToggle" type="button" style="border:none;background:#2563eb;color:#fff;font-weight:600;padding:0.65rem 1.25rem;border-radius:999px;cursor:pointer;">Abrir chat</button>
  <div id="maestroFloatingWrapper" style="display:none;width:360px;height:620px;border-radius:32px;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,0.25);">
    <iframe src="$encodedWebUrl" width="100%" height="100%" style="border:0;" allow="microphone"></iframe>
  </div>
</div>
<script>
(function () {
  const toggle = document.getElementById('maestroFloatingToggle');
  const wrapper = document.getElementById('maestroFloatingWrapper');
  if (!toggle || !wrapper) {
    return;
  }
  toggle.addEventListener('click', () => {
    const isOpen = wrapper.style.display === 'block';
    wrapper.style.display = isOpen ? 'none' : 'block';
    toggle.textContent = isOpen ? 'Abrir chat' : 'Fechar chat';
  });
})();
</script>
HTML;
}
$selectedPhoneLabel = $selectedInstance ? formatInstancePhoneLabel($selectedInstance['phone'] ?? '') : '';

$logRange = resolveLogRangeFromRequest();

// --- Action handlers (each exits if matched) ---
if (handleActions()) exit;

// --- Prepare data for rendering ---
$logSummary = $selectedInstanceId ? getInstanceLogSummary($selectedInstanceId, $logRange['start'], $logRange['end']) : [
    'total_messages' => 0,
    'total_contacts' => 0,
    'total_inbound' => 0,
    'total_outbound' => 0,
    'total_commands' => 0,
    'scheduled_pending' => 0,
    'scheduled_sent' => 0,
    'scheduled_failed' => 0,
    'first_message_at' => '',
    'last_message_at' => ''
];

$baileysDebugLogs = getBaileysDebugLogs($selectedInstanceId);
$baileysDebugPaths = buildBaileysLogPaths($selectedInstanceId);

$logQueryParams = [
    'instance' => $selectedInstanceId ?? '',
    'log_range' => $logRange['preset'],
    'log_start' => $logRange['custom_start'],
    'log_end' => $logRange['custom_end']
];
$exportLogUrl = $selectedInstanceId ? ('?' . http_build_query(array_merge($logQueryParams, ['export_conversations' => 1]))) : '#';

$curlEndpointPort = $selectedInstance['port'] ?? 3010;
$curlEndpoint = "http://127.0.0.1:{$curlEndpointPort}";
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

// Video sending curl examples
$curlVideoUrlPayload = json_encode([
    'to' => '5585999999999@s.whatsapp.net',
    'video_url' => 'https://example.com/video.mp4',
    'caption' => 'Assista este vídeo!'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sampleCurlVideoUrlCommand = <<<CURL
curl -X POST "{$curlEndpoint}" \\
  -H "Content-Type: application/json" \\
  -d '{$curlVideoUrlPayload}'
CURL;

$curlVideoBase64Payload = json_encode([
    'to' => '5585999999999@s.whatsapp.net',
    'video_base64' => 'data:video/mp4;base64,AAAAHGZ0eXBpc29tAAAC...',
    'caption' => 'Vídeo em base64'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sampleCurlVideoBase64Command = <<<CURL
curl -X POST "{$curlEndpoint}" \\
  -H "Content-Type: application/json" \\
  -d '{$curlVideoBase64Payload}'
CURL;

$curlImageUrlPayload = json_encode([
    'to' => '5585999999999@s.whatsapp.net',
    'image_url' => 'https://example.com/image.jpg',
    'caption' => 'Veja esta imagem!'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sampleCurlImageUrlCommand = <<<CURL
curl -X POST "{$curlEndpoint}" \\
  -H "Content-Type: application/json" \\
  -d '{$curlImageUrlPayload}'
CURL;

$curlAudioUrlPayload = json_encode([
    'to' => '5585999999999@s.whatsapp.net',
    'audio_url' => 'https://example.com/audio.mp3'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sampleCurlAudioUrlCommand = <<<CURL
curl -X POST "{$curlEndpoint}" \\
  -H "Content-Type: application/json" \\
  -d '{$curlAudioUrlPayload}'
CURL;

$curlAudioBase64Payload = json_encode([
    'to' => '5585999999999@s.whatsapp.net',
    'audio_base64' => 'data:audio/mp3;base64,SUQzBAAAAA...'
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sampleCurlAudioBase64Command = <<<CURL
curl -X POST "{$curlEndpoint}" \\
  -H "Content-Type: application/json" \\
  -d '{$curlAudioBase64Payload}'
CURL;

$assetUploadMessage = '';
$assetUploadError = '';
$assetUploadCode = '';
$assetUploadUrl = '';
if (!empty($_SESSION['asset_upload_result']) && is_array($_SESSION['asset_upload_result'])) {
    $result = $_SESSION['asset_upload_result'];
    $assetUploadMessage = $result['message'] ?? '';
    $assetUploadError = $result['error'] ?? '';
    $assetUploadCode = $result['code'] ?? '';
    $assetUploadUrl = $result['url'] ?? '';
    unset($_SESSION['asset_upload_result']);
}

$quickConfigMessage = '';
$quickConfigError = '';
$quickConfigWarning = '';

perf_mark('render.ready', [
    'instance' => $selectedInstanceId ?? '',
    'instances' => count($instances),
    'manager' => $isManager ? 1 : 0
]);
perf_log('index.php render', [
    'path' => $_SERVER['REQUEST_URI'] ?? '',
    'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
]);

// --- Render HTML (views) ---
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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intro.js/minified/introjs.min.css">
  <link rel="stylesheet" href="assets/css/dashboard.css">
</head>

<script>
  window.APP_TIMEZONE = <?= json_encode(getApplicationTimezone()) ?>;
</script>

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
    <div class="instance-sticky-header">
      <div class="text-sm text-slate-500">Instância selecionada</div>
      <?php
        $integrationType = $selectedInstance['integration_type'] ?? 'baileys';
        $integrationLabel = match ($integrationType) {
          'meta' => 'Meta',
          'baileys' => 'Baileys',
          'web' => 'Web',
          default => 'Web'
        };
      ?>
      <div class="flex items-baseline gap-2">
        <div class="font-semibold text-dark"><?= htmlspecialchars($selectedInstance['name'] ?? 'Nenhuma instância') ?></div>
        <span class="text-[11px] tracking-[0.3em] uppercase text-slate-500"><?= htmlspecialchars($integrationLabel) ?></span>
      </div>
    </div>

    <!-- HEADER -->
    <?php
      $instanceStatus = $statuses[$selectedInstanceId] ?? 'Stopped';
      $connectionState = strtolower($connectionStatuses[$selectedInstanceId] ?? 'disconnected');
      $serverBadge = $instanceStatus === 'Running' ? 'Servidor OK' : 'Parado';
      $quickConfigIntegrationType = $selectedInstance['integration_type'] ?? 'baileys';
      $connectionBadge = $quickConfigIntegrationType === 'meta'
        ? 'Meta API'
        : ($connectionState === 'connected' ? 'WhatsApp Conectado' : 'WhatsApp Desconectado');
      $connectionBadgeClass = $quickConfigIntegrationType === 'meta'
        ? 'connection'
        : ($connectionState === 'connected' ? 'connection' : 'disconnect');
    ?>
    <section class="card-soft mt-4 border-0">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div class="text-xs uppercase tracking-[0.3em] text-slate-400">Instância</div>
          <div class="text-3xl font-semibold text-dark"><?= htmlspecialchars($selectedInstance['name'] ?? 'Nenhuma instância') ?></div>
          <div class="mt-3 flex flex-wrap gap-2">
            <span class="badge-pill <?= $instanceStatus === 'Running' ? 'server' : 'disconnect' ?>">
              <?= htmlspecialchars($serverBadge) ?>
            </span>
            <span class="badge-pill <?= $connectionBadgeClass ?>">
              <?= htmlspecialchars($connectionBadge) ?>
            </span>
            <?php if ($selectedPhoneLabel): ?>
              <span class="text-xs text-slate-500"><?= htmlspecialchars($selectedPhoneLabel) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($selectedInstanceId): ?>
            <div class="mt-4 flex flex-wrap gap-2 text-[11px]">
              <a href="conversas.php?instance=<?= urlencode($selectedInstanceId) ?>" class="text-[11px] px-2.5 py-1 rounded-full border border-primary/60 text-primary flex items-center gap-1 hover:bg-primary/10 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path d="M3 4.5A1.5 1.5 0 014.5 3h11A1.5 1.5 0 0117 4.5v6A1.5 1.5 0 0115.5 12H8l-4 4V4.5z"></path>
                </svg>
                Conversas
              </a>
              <a href="grupos.php?instance=<?= urlencode($selectedInstanceId) ?>" class="text-[11px] px-2.5 py-1 rounded-full border border-slate-300 text-slate-600 flex items-center gap-1 hover:bg-slate-100 transition">
                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path d="M7 7a3 3 0 116 0v1h1a2 2 0 012 2v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5a2 2 0 012-2h1V7z"></path>
                </svg>
                Grupos
              </a>
            </div>
          <?php endif; ?>
        </div>
        <div class="flex flex-col gap-3 items-start lg:items-end">
          <div class="flex flex-wrap gap-2">
            <button id="saveChangesButton" onclick="document.getElementById('quickConfigForm').submit()" class="px-4 py-2 rounded-[18px] bg-primary text-white font-semibold hover:opacity-90 transition">Salvar alterações</button>
            <?php if ($selectedInstance): ?>
              <a id="deleteInstanceButton" href="?delete=<?= $selectedInstanceId ?>" onclick="return confirm('Tem certeza?')" class="px-4 py-2 rounded-[18px] border border-red-300 text-red-600 font-semibold hover:bg-red-50 transition">Deletar</a>
            <?php endif; ?>
          </div>
          <div id="instanceActions" class="flex flex-wrap gap-2">
            <?php if ($selectedInstance && $quickConfigIntegrationType === 'baileys' && strtolower($connectionStatuses[$selectedInstanceId] ?? '') !== 'connected' && $statuses[$selectedInstanceId] === 'Running'): ?>
              <button id="connectQrButton" onclick="openQRModal('<?= $selectedInstanceId ?>')" class="px-4 py-2 rounded-[18px] border border-primary text-primary hover:bg-primary/5 transition">Conectar QR</button>
            <?php endif; ?>
            <?php if ($selectedInstance && $quickConfigIntegrationType === 'baileys' && strtolower($connectionStatuses[$selectedInstanceId] ?? '') === 'connected'): ?>
              <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="disconnect" value="<?= $selectedInstanceId ?>">
                <button id="disconnectButton" type="submit" class="px-4 py-2 rounded-[18px] bg-error text-white font-semibold hover:opacity-90 transition">Desconectar</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- Tabs -->
    <div class="tabs-shell mt-6">
        <div class="flex flex-wrap gap-3 border-b border-slate-200 pb-3">
        <button type="button" data-tab-target="tab-messages" class="tab-button active">Estatísticas</button>
        <button type="button" data-tab-target="tab-general" class="tab-button">Configurações</button>
        <button type="button" data-tab-target="tab-ia" class="tab-button">IA</button>
        <button type="button" data-tab-target="tab-agenda" class="tab-button">Agenda</button>
        <button type="button" data-tab-target="tab-automacao" class="tab-button">Automação</button>
        <?php if ($quickConfigIntegrationType === 'meta'): ?>
        <button type="button" data-tab-target="tab-templates" class="tab-button" id="templatesTab">Templates</button>
        <?php endif; ?>
        <button type="button" data-tab-target="tab-web-access" class="tab-button">Acesso Web</button>
        <button type="button" data-tab-target="tab-monitoramento" class="tab-button">Status</button>
        <button type="button" data-tab-target="tab-api" class="tab-button">API</button>
      </div>
      <div class="tab-contents mt-6 space-y-6">
        <?php if ($quickConfigIntegrationType === 'meta'): ?>
          <?php renderTabTemplates(); ?>
        <?php endif; ?>
        <?php renderTabGeneral(); ?>
        <?php renderTabMessages(); ?>

    <?php extractAiConfigVars(); ?>
        <?php renderTabIA(); ?>
        <?php renderTabAgenda(); ?>
        <?php renderTabAutomacao(); ?>
        <?php renderTabWebAccess(); ?>
        <?php renderTabMonitoramento(); ?>
        <?php renderTabAPI(); ?>
  </div>
</div>
  </main>
</div>
<?php renderDebugLogOverlay(); ?>
<footer class="w-full bg-slate-900 text-slate-200 text-xs text-center py-3 mt-6">
  Por <strong>Osvaldo J. Filho</strong> |
  <a href="https://linkedin.com/in/ojaneri" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">LinkedIn</a> |
  <a href="https://github.com/ojaneri/maestro" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">GitHub</a>
</footer>
<button id="helpTourButton" class="tour-help-button" type="button" aria-label="Abrir tour guiado" title="Ajuda">
  ?
</button>
<?php renderCreateModal(); ?>
<?php renderQrModal(); ?>
<?php renderQrResetOverlay(); ?>
<?php renderAiTestModal(); ?>
<script>
let activeQrInstanceId = null;
let qrPollingId = null;
let qrCountdownTimer = null;
const qrCsrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

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
  if (qrPollingId) {
    clearInterval(qrPollingId);
  }
  qrPollingId = setInterval(() => refreshQR(activeQrInstanceId), 5000);
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
  const connectedCard = document.getElementById('qrConnectedCard');
  const actions = document.getElementById('qrActions');
  const qrBox = document.getElementById('qrBox');
  if (connectedCard) {
    connectedCard.classList.add('hidden');
  }
  if (actions) {
    actions.classList.remove('hidden');
  }
  if (qrBox) {
    qrBox.classList.remove('hidden');
  }
  if (qrPollingId) {
    clearInterval(qrPollingId);
    qrPollingId = null;
  }
  if (qrCountdownTimer) {
    clearInterval(qrCountdownTimer);
    qrCountdownTimer = null;
  }
}

function updateQrStatusGrid(serverStatus) {
  const statusConnection = document.getElementById('qrStatusConnection');
  const statusConnected = document.getElementById('qrStatusConnected');
  const statusHasQr = document.getElementById('qrStatusHasQr');
  const statusError = document.getElementById('qrStatusError');
  const statusNote = document.getElementById('qrStatusNote');
  if (!statusConnection || !statusConnected || !statusHasQr || !statusError || !statusNote) return;
  if (!serverStatus) {
    statusConnection.textContent = '-';
    statusConnected.textContent = '-';
    statusHasQr.textContent = '-';
    statusError.textContent = '-';
    statusNote.textContent = 'Aguardando status da instancia...';
    return;
  }
  statusConnection.textContent = serverStatus.connectionStatus || serverStatus.status || 'desconhecido';
  statusConnected.textContent = serverStatus.whatsappConnected ? 'sim' : 'nao';
  statusHasQr.textContent = serverStatus.hasQR ? 'sim' : 'nao';
  statusError.textContent = serverStatus.lastConnectionError || '-';
  if (serverStatus.whatsappConnected) {
    statusNote.textContent = 'WhatsApp conectado. Nao e necessario escanear um novo QR.';
  } else if (serverStatus.lastConnectionError) {
    statusNote.textContent = 'A conexao caiu. Reinicie a sessao e aguarde o QR ser gerado.';
  } else if (!serverStatus.hasQR) {
    statusNote.textContent = 'QR ainda nao disponivel. Aguarde alguns minutos e tente novamente.';
  } else {
    statusNote.textContent = 'QR disponivel. Escaneie para reconectar.';
  }
}

function setQrConnectedState(isConnected) {
  const connectedCard = document.getElementById('qrConnectedCard');
  const actions = document.getElementById('qrActions');
  const qrBox = document.getElementById('qrBox');
  if (isConnected) {
    connectedCard?.classList.remove('hidden');
    actions?.classList.add('hidden');
    qrBox?.classList.add('hidden');
    if (qrPollingId) {
      clearInterval(qrPollingId);
      qrPollingId = null;
    }
  } else {
    connectedCard?.classList.add('hidden');
    actions?.classList.remove('hidden');
    qrBox?.classList.remove('hidden');
  }
}

function openQrResetConfirm() {
  const overlay = document.getElementById('qrResetOverlay');
  const checkbox = document.getElementById('qrResetConfirm');
  if (checkbox) checkbox.checked = false;
  overlay?.classList.add('active');
}

function closeQrResetConfirm() {
  const overlay = document.getElementById('qrResetOverlay');
  overlay?.classList.remove('active');
}

async function confirmQrReset() {
  const checkbox = document.getElementById('qrResetConfirm');
  if (!checkbox || !checkbox.checked) {
    const statusEl = document.getElementById('qrStatus');
    if (statusEl) {
      statusEl.textContent = 'Confirme que desconectou todas as sessoes antes de reiniciar.';
    }
    return;
  }
  closeQrResetConfirm();
  await resetQrSession();
}

function startQrCountdown(seconds) {
  const statusNote = document.getElementById('qrStatusNote');
  let remaining = seconds;
  if (qrCountdownTimer) {
    clearInterval(qrCountdownTimer);
  }
  if (statusNote) {
    statusNote.innerHTML = `Aguarde <span class=\"countdown\">${remaining}s</span> para o QR ser regenerado.`;
  }
  qrCountdownTimer = setInterval(() => {
    remaining -= 1;
    if (remaining <= 0) {
      clearInterval(qrCountdownTimer);
      qrCountdownTimer = null;
      if (statusNote) statusNote.textContent = 'Tentando buscar o QR novamente...';
      refreshQR();
      return;
    }
    if (statusNote) {
      statusNote.innerHTML = `Aguarde <span class=\"countdown\">${remaining}s</span> para o QR ser regenerado.`;
    }
  }, 1000);
}

async function resetQrSession() {
  const targetInstanceId = activeQrInstanceId;
  if (!targetInstanceId) return;
  const statusEl = document.getElementById('qrStatus');
  if (statusEl) statusEl.textContent = 'Reiniciando sessao...';
  try {
    const response = await fetch('index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        csrf_token: qrCsrfToken,
        qr_reset: targetInstanceId
      })
    });
    const data = await response.json().catch(() => null);
    const message = data?.message || 'Sessao reiniciada. Aguarde alguns minutos para o QR aparecer.';
    if (statusEl) statusEl.textContent = message;
    startQrCountdown(30);
  } catch (err) {
    if (statusEl) statusEl.textContent = 'Falha ao reiniciar sessao. Tente novamente.';
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
    const response = await fetch(`./qr-proxy.php?id=${encodeURIComponent(targetInstanceId)}&t=${Date.now()}`, {
      headers: { 'Accept': 'application/json' }
    });
    let payload;
    try {
      payload = await response.json();
    } catch (err) {
      throw new Error('Resposta inválida do servidor');
    }

    updateQrStatusGrid(payload?.server_status || null);

    if (payload?.server_status?.whatsappConnected) {
      setQrConnectedState(true);
      statusEl.textContent = 'Conectado ao WhatsApp.';
      return;
    }

    setQrConnectedState(false);

    if (payload?.success) {
      if (payload.qr_png) {
        img.src = `data:image/png;base64,${payload.qr_png}`;
      } else if (payload.qr_text) {
        img.src = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(payload.qr_text)}`;
      }
      img.onload = () => {
        statusEl.textContent = 'Escaneie o QR acima com o WhatsApp';
      };
      img.style.display = 'block';
      statusEl.textContent = 'Escaneie o QR acima com o WhatsApp';
      return;
    }

    const payloadError = payload && payload.error;
    if (!response.ok || !payload) {
      const pendingMessage = payloadError || 'QR indisponível';
      if (response.status === 404 || response.status === 503) {
        statusEl.textContent = pendingMessage;
        return;
      }
      throw new Error(pendingMessage);
    }

    statusEl.textContent = payloadError || 'QR indisponível';
  } catch (error) {
    console.error('Falha ao carregar o QR', error);
    const errorMessage = (error && error.message) ? error.message : 'desconhecido';
    statusEl.textContent = `Erro ao carregar o QR: ${errorMessage}`;
  }
}

document.getElementById('qrResetConfirmBtn')?.addEventListener('click', (event) => {
  event.preventDefault();
  confirmQrReset();
});
document.getElementById('qrResetCancelBtn')?.addEventListener('click', (event) => {
  event.preventDefault();
  closeQrResetConfirm();
});
</script>
<script>
(function () {
  const instanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const averageTaxarEl = document.getElementById('averageTaxarValue');
  if (instanceId && averageTaxarEl) {
    fetch(`?ajax_average_taxar=1&instance=${encodeURIComponent(instanceId)}`)
      .then(response => response.json())
      .then(data => {
        if (data.ok && typeof data.average_taxar === 'number') {
          averageTaxarEl.textContent = data.average_taxar.toFixed(1) + '%';
        } else {
          averageTaxarEl.textContent = '-';
        }
      })
      .catch(err => {
        console.error('Error fetching average TaxaR:', err);
        averageTaxarEl.textContent = '-';
      });
  }
})();
</script>
<script>
(function () {
  const form = document.getElementById('autoPauseForm');
  const saveBtn = document.getElementById('saveAutoPauseButton');
  const statusEl = document.getElementById('autoPauseStatus');
  const instanceApiKey = <?= json_encode($selectedInstance['api_key'] ?? '') ?>;
  const aiInstanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const logTag = `[auto-pause ${aiInstanceId || 'unknown'}]`;

  if (!form || !saveBtn || !statusEl) {
    console.warn(logTag, 'formulário do auto pause incompleto');
    return;
  }

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
    return;
  }

  saveBtn.addEventListener('click', async () => {
    const payload = {
      auto_pause_enabled: document.getElementById('autoPauseEnabled').checked,
      auto_pause_minutes: Number(document.getElementById('autoPauseMinutes').value) || 5
    };
    console.groupCollapsed(logTag, 'salvar configurações auto pause');
    console.log(logTag, 'payload', payload);
    updateStatus('Salvando configurações...', 'info');
    saveBtn.disabled = true;

    try {
      const saveEndpointUrl = new URL(window.location.href);
      saveEndpointUrl.searchParams.set('ajax_save_ai', '1');
      saveEndpointUrl.searchParams.set('instance', aiInstanceId);
      const saveEndpoint = saveEndpointUrl.toString();
      const formPayload = new URLSearchParams({
        csrf_token: document.querySelector('input[name="csrf_token"]')?.value || ''
      });
      formPayload.set('auto_pause_enabled', payload.auto_pause_enabled ? '1' : '0');
      formPayload.set('auto_pause_minutes', String(payload.auto_pause_minutes));

      const response = await fetch(saveEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formPayload.toString()
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
        const rawMessage = (resultText || '').trim();
        const rawFallback = rawMessage && !rawMessage.startsWith('{') ? rawMessage.slice(0, 240) : '';
        const errorMessage = result?.error || rawFallback || response.statusText || 'Erro ao salvar';
        throw new Error(errorMessage);
      }

      updateStatus('Configurações salvas com sucesso', 'success');
    } catch (error) {
      console.error(logTag, 'falha ao salvar auto pause', error);
      updateStatus(`Erro ao salvar: ${error.message}`, 'error');
    } finally {
      saveBtn.disabled = false;
      console.groupEnd();
    }
  });
})();
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

  const csrfTokenInput = form.querySelector('[name="csrf_token"]');

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
      const payloadBody = new URLSearchParams({
        phone,
        message,
        csrf_token: csrfTokenInput?.value || ''
      });
      const response = await fetch(sendEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: payloadBody.toString()
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
  const aiInstanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const logTag = `[ai-card ${aiInstanceId || 'unknown'}]`;

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
  const OPENROUTER_MODEL_PRESETS = [
    'meta-llama/llama-3.1-405b-instruct:free',
    'nousresearch/hermes-3-llama-3.1-405b:free',
    'deepseek/deepseek-r1:free',
    'tng/deepseek-r1t2-chimera:free',
    'meta-llama/llama-3.3-70b-instruct:free',
    'google/gemini-2.0-flash-exp:free',
    'mistralai/mistral-small-24b-instruct-2501:free',
    'qwen/qwen3-next-80b-a3b-instruct:free',
    'google/gemma-3-27b-it:free',
    'xiaomi/mimo-v2-flash:free',
    'z-ai/glm-4.5-air:free',
    'moonshotai/kimi-k2:free',
    'venice/venice-uncensored:free',
    'qwen/qwen3-coder-480b-instruct:free',
    'mistralai/devstral-2-2512:free',
    'openai/gpt-oss-120b:free',
    'google/gemma-3-12b-it:free',
    'nvidia/nemotron-3-nano-30b-a3b:free',
    'arcee-ai/trinity-mini:free',
    'openai/gpt-oss-20b:free',
    'qwen/qwen2.5-vl-7b-instruct:free',
    'nvidia/nemotron-nano-12b-2-vl:free',
    'nvidia/nemotron-nano-9b-v2:free',
    'allenai/molmo2-8b:free',
    'google/gemma-3-4b-it:free',
    'meta-llama/llama-3.2-3b-instruct:free',
    'google/gemma-3n-4b-it:free',
    'google/gemma-3n-2b-it:free',
    'qwen/qwen3-4b:free'
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
  toggleKeyVisibility('openrouterApiKey', 'toggleOpenrouterKey');

  const geminiExpandBtn = document.getElementById('geminiExpandBtn');
  const geminiInstruction = document.getElementById('geminiInstruction');
  const openaiSystemExpandBtn = document.getElementById('openaiSystemExpandBtn');
  const openaiAssistantExpandBtn = document.getElementById('openaiAssistantExpandBtn');
  const systemPromptField = document.getElementById('aiSystemPrompt');
  const assistantPromptField = document.getElementById('aiAssistantPrompt');

  const promptOverlay = document.createElement('div');
  promptOverlay.className = 'prompt-overlay';
  promptOverlay.innerHTML = `
    <div class="prompt-panel">
      <div class="text-sm text-slate-500" data-title>Editor de instruções</div>
      <textarea class="w-full px-3 py-2 rounded-xl border border-mid bg-light"></textarea>
      <button type="button" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">Retornar</button>
    </div>
  `;
  document.body.appendChild(promptOverlay);

  const overlayTitle = promptOverlay.querySelector('[data-title]');
  const overlayTextarea = promptOverlay.querySelector('textarea');
  const overlayCloseBtn = promptOverlay.querySelector('button');
  let activePromptField = null;

  const closePromptOverlay = () => {
    promptOverlay.classList.remove('active');
    activePromptField = null;
  };
  window.__closePromptOverlay = closePromptOverlay;

  const closeAllPromptOverlays = () => {
    closePromptOverlay();
    if (typeof window.__closeQuickReplyOverlay === 'function') {
      window.__closeQuickReplyOverlay();
    }
  };

  const openPromptOverlay = (field, titleText) => {
    if (!field) return;
    activePromptField = field;
    if (overlayTitle) {
      overlayTitle.textContent = titleText || 'Editor de instruções';
    }
    overlayTextarea.value = field.value || '';
    if (typeof window.__closeQuickReplyOverlay === 'function') {
      window.__closeQuickReplyOverlay();
    }
    promptOverlay.classList.add('active');
    overlayTextarea.focus();
  };

  overlayTextarea?.addEventListener('input', () => {
    if (activePromptField) {
      activePromptField.value = overlayTextarea.value;
    }
  });

  overlayCloseBtn?.addEventListener('click', closePromptOverlay);
  promptOverlay?.addEventListener('click', (event) => {
    if (event.target === promptOverlay) {
      closeAllPromptOverlays();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && promptOverlay.classList.contains('active')) {
      closeAllPromptOverlays();
    }
  });

  geminiExpandBtn?.addEventListener('click', () => {
    openPromptOverlay(geminiInstruction, 'Instruções do Gemini');
  });

  openaiSystemExpandBtn?.addEventListener('click', () => {
    openPromptOverlay(systemPromptField, 'System prompt (OpenAI)');
  });

  openaiAssistantExpandBtn?.addEventListener('click', () => {
    openPromptOverlay(assistantPromptField, 'Assistant instructions (OpenAI)');
  });

  const populateModelPresetOptions = () => {
    if (!modelPresetSelect || !modelInputField) return;
    const provider = providerSelect?.value || 'openai';
    const presets = provider === 'gemini'
      ? GEMINI_MODEL_PRESETS
      : provider === 'openrouter'
        ? OPENROUTER_MODEL_PRESETS
        : OPENAI_MODEL_PRESETS;
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
    const openrouterFields = document.getElementById('openrouterFields');
    if (openaiFields) {
      openaiFields.classList.toggle('hidden', provider !== 'openai');
    }
    if (geminiFields) {
      geminiFields.classList.toggle('hidden', provider !== 'gemini');
    }
    if (openrouterFields) {
      openrouterFields.classList.toggle('hidden', provider !== 'openrouter');
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
      model_fallback_1: getFieldValue('aiModelFallback1'),
      model_fallback_2: getFieldValue('aiModelFallback2'),
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
      gemini_instruction: getFieldValue('geminiInstruction'),
      openrouter_api_key: getFieldValue('openrouterApiKey'),
      openrouter_base_url: getFieldValue('openrouterBaseUrl'),
      auto_pause_enabled: document.getElementById('autoPauseEnabled').checked,
      auto_pause_minutes: Number(getFieldValue('autoPauseMinutes', 5)),
      meta_access_token: getFieldValue('metaAccessToken'),
      meta_business_account_id: getFieldValue('metaBusinessAccountId'),
      meta_telephone_id: getFieldValue('metaTelephoneId')
    };
  };

  saveBtn.addEventListener('click', async () => {
    const payload = collectPayload();
    console.groupCollapsed(logTag, 'salvar configurações IA');
    console.log(logTag, 'payload', payload);
    updateStatus('Salvando configurações...', 'info');
    saveBtn.disabled = true;

    try {
      const saveEndpointUrl = new URL(window.location.href);
      saveEndpointUrl.searchParams.set('ajax_save_ai', '1');
      saveEndpointUrl.searchParams.set('instance', aiInstanceId);
      const saveEndpoint = saveEndpointUrl.toString();
      const formPayload = new URLSearchParams({
        csrf_token: document.querySelector('input[name="csrf_token"]')?.value || ''
      });
      formPayload.set('ai_enabled', payload.enabled ? '1' : '0');
      formPayload.set('ai_provider', payload.provider || 'openai');
      formPayload.set('ai_model', payload.model || 'gpt-4.1-mini');
      formPayload.set('ai_system_prompt', payload.system_prompt || '');
      formPayload.set('ai_assistant_prompt', payload.assistant_prompt || '');
      formPayload.set('ai_assistant_id', payload.assistant_id || '');
      formPayload.set('ai_history_limit', String(payload.history_limit || 20));
      formPayload.set('ai_temperature', String(payload.temperature ?? 0.3));
      formPayload.set('ai_max_tokens', String(payload.max_tokens || 600));
      formPayload.set('ai_multi_input_delay', String(payload.multi_input_delay || 0));
      formPayload.set('openai_api_key', payload.openai_api_key || '');
      formPayload.set('openai_mode', payload.openai_mode || 'responses');
      formPayload.set('gemini_api_key', payload.gemini_api_key || '');
      formPayload.set('gemini_instruction', payload.gemini_instruction || '');
      formPayload.set('ai_model_fallback_1', payload.model_fallback_1 || '');
      formPayload.set('ai_model_fallback_2', payload.model_fallback_2 || '');
      formPayload.set('openrouter_api_key', payload.openrouter_api_key || '');
      formPayload.set('openrouter_base_url', payload.openrouter_base_url || '');
      formPayload.set('auto_pause_enabled', payload.auto_pause_enabled ? '1' : '0');
      formPayload.set('auto_pause_minutes', String(payload.auto_pause_minutes || 5));
      formPayload.set('meta_access_token', payload.meta_access_token || '');
      formPayload.set('meta_business_account_id', payload.meta_business_account_id || '');
      formPayload.set('meta_telephone_id', payload.meta_telephone_id || '');

      const response = await fetch(saveEndpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formPayload.toString()
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
        const rawMessage = (resultText || '').trim();
        const rawFallback = rawMessage && !rawMessage.startsWith('{') ? rawMessage.slice(0, 240) : '';
        const errorMessage = result?.error || rawFallback || response.statusText || 'Erro ao salvar';
        throw new Error(errorMessage);
      }

      // Use message from server response or default
      const serverMessage = result?.message;
      const warning = result?.warning;
      const message = warning 
        ? `Config salva, porém: ${warning}` 
        : (serverMessage || 'Configurações salvas com sucesso');
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
    const aiTestInstanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
    const testEndpointUrl = new URL(window.location.href);
    testEndpointUrl.searchParams.set('ajax_ai_test', '1');
    testEndpointUrl.searchParams.set('instance', aiTestInstanceId);
    const testEndpoint = testEndpointUrl.toString();
    const csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

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
          const formPayload = new URLSearchParams({
            message: text,
            csrf_token: csrfToken || ''
          });
          const response = await fetch(testEndpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: formPayload.toString()
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
  const section = document.getElementById('calendarSettingsSection');
  if (!section) return;

  const calendarProxyEndpoint = <?= json_encode('/api/envio/wpp/api.php') ?>;
  const instanceId = <?= json_encode($selectedInstanceId ?? '') ?>;

  const statusEl = document.getElementById('calendarStatus');
  const connectBtn = document.getElementById('calendarConnectButton');
  const disconnectBtn = document.getElementById('calendarDisconnectButton');
  const refreshBtn = document.getElementById('calendarRefreshButton');
  const googleSelect = document.getElementById('calendarGoogleSelect');
  const calendarIdInput = document.getElementById('calendarIdInput');
  const timezoneInput = document.getElementById('calendarTimezoneInput');
  const availabilityInput = document.getElementById('calendarAvailabilityInput');
  const availabilityBuilder = document.getElementById('calendarAvailabilityBuilder');
  const defaultCheckbox = document.getElementById('calendarDefaultCheckbox');
  const saveBtn = document.getElementById('calendarSaveButton');
  const saveStatus = document.getElementById('calendarSaveStatus');
  const listEl = document.getElementById('calendarConfigsList');
  const forceConnectBtn = document.getElementById('calendarForceConnectButton');

  const AVAILABILITY_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

  if (!instanceId) {
    if (statusEl) statusEl.textContent = 'Instância inválida.';
    [connectBtn, disconnectBtn, refreshBtn, saveBtn].forEach(btn => {
      if (btn) btn.disabled = true;
    });
    return;
  }

  const buildUrl = (path, params = {}) => {
    const trimmedPath = path.replace(/^\//, '');
    const url = new URL(calendarProxyEndpoint, window.location.origin);
    url.searchParams.set('calendar_proxy', '1');
    url.searchParams.set('path', trimmedPath);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        url.searchParams.set(key, String(value));
      }
    });
    return url.toString();
  };

  const setStatus = (text, mode = 'info') => {
    if (!statusEl) return;
    const typeClass = mode === 'error' ? 'text-error'
      : mode === 'success' ? 'text-success'
      : 'text-slate-500';
    statusEl.className = `text-xs ${typeClass}`;
    statusEl.textContent = text;
  };

  const setSaveStatus = (text, mode = 'info') => {
    if (!saveStatus) return;
    const typeClass = mode === 'error' ? 'text-error'
      : mode === 'success' ? 'text-success'
      : 'text-slate-500';
    saveStatus.className = `text-xs ${typeClass}`;
    saveStatus.textContent = text;
  };

  const fetchJson = async (url, options = {}) => {
    try {
      const response = await fetch(url, options);
      const rawText = await response.text();
      let payload = null;
      try {
        payload = JSON.parse(rawText);
      } catch (err) {
        payload = null;
      }
      if (!response.ok || payload?.ok === false) {
        const errorMessage = payload?.detail || payload?.error || response.statusText || 'Falha na requisição';
        console.error('[calendar] request failed', { url, status: response.status, statusText: response.statusText, body: rawText });
        throw new Error(errorMessage);
      }
      return payload;
    } catch (error) {
      console.error('[calendar] fetch error', { url, error });
      throw error;
    }
  };

  const getAvailabilityRowsContainer = (dayKey) => {
    return availabilityBuilder?.querySelector(`[data-availability-day="${dayKey}"] [data-availability-rows]`) || null;
  };

  const createAvailabilityRow = (day) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'flex flex-wrap items-center gap-2';
    wrapper.dataset.availabilityRow = day;
    wrapper.innerHTML = `
      <input type="time" class="availability-input rounded-xl border border-mid/60 bg-white px-2 py-1 text-xs"
             data-availability-start value="">
      <span class="text-xs text-slate-400">até</span>
      <input type="time" class="availability-input rounded-xl border border-mid/60 bg-white px-2 py-1 text-xs"
             data-availability-end value="">
      <button type="button" data-remove-range
              class="text-xs text-rose-500 font-semibold hover:underline">
        Remover
      </button>
    `;
    return wrapper;
  };

  const addAvailabilityRow = (dayKey, start = '', end = '') => {
    const container = getAvailabilityRowsContainer(dayKey);
    if (!container) return;
    const row = createAvailabilityRow(dayKey);
    const startInput = row.querySelector('[data-availability-start]');
    const endInput = row.querySelector('[data-availability-end]');
    if (startInput) startInput.value = start;
    if (endInput) endInput.value = end;
    container.appendChild(row);
    updateAvailabilityInput();
  };

  const collectAvailabilityPayload = () => {
    if (!availabilityBuilder) return null;
    const payload = { timezone: timezoneInput?.value.trim() || null, days: {} };
    let hasData = false;
    AVAILABILITY_DAYS.forEach(dayKey => {
      const container = getAvailabilityRowsContainer(dayKey);
      if (!container) return;
      const entries = [];
      container.querySelectorAll('[data-availability-row]').forEach(row => {
        const start = row.querySelector('[data-availability-start]')?.value?.trim();
        const end = row.querySelector('[data-availability-end]')?.value?.trim();
        if (start && end) {
          entries.push({ start, end });
        }
      });
      if (entries.length) {
        payload.days[dayKey] = entries;
        hasData = true;
      }
    });
    if (!hasData) {
      return null;
    }
    return payload;
  };

  const updateAvailabilityInput = () => {
    const payload = collectAvailabilityPayload();
    availabilityInput.value = payload ? JSON.stringify(payload) : '';
  };

  const resetAvailabilityBuilder = () => {
    AVAILABILITY_DAYS.forEach(dayKey => {
      const container = getAvailabilityRowsContainer(dayKey);
      if (container) {
        container.innerHTML = '';
      }
    });
    updateAvailabilityInput();
  };

  availabilityBuilder?.addEventListener('click', event => {
    const addButton = event.target.closest('[data-add-range-day]');
    if (addButton) {
      event.preventDefault();
      addAvailabilityRow(addButton.dataset.addRangeDay);
      return;
    }
    if (event.target.matches('[data-remove-range]')) {
      const row = event.target.closest('[data-availability-row]');
      row?.remove();
      updateAvailabilityInput();
    }
  });

  availabilityBuilder?.addEventListener('input', event => {
    if (event.target.matches('[data-availability-start], [data-availability-end]')) {
      updateAvailabilityInput();
    }
  });

  resetAvailabilityBuilder();

  timezoneInput?.addEventListener('input', updateAvailabilityInput);

  let pendingAuthState = null;
  let lastCalendarConnected = false;

  const updateCalendarControls = (config = {}) => {
    const connected = config.connected ?? lastCalendarConnected;
    const waiting = config.waiting ?? Boolean(pendingAuthState);
    if (connectBtn) connectBtn.disabled = Boolean(waiting);
    if (disconnectBtn) disconnectBtn.disabled = waiting || !connected;
    if (refreshBtn) refreshBtn.disabled = waiting || !connected;
    if (forceConnectBtn) forceConnectBtn.disabled = !Boolean(waiting);
  };

  const setPendingAuthState = (state) => {
    pendingAuthState = state ? String(state) : null;
    updateCalendarControls();
  };

  const renderCalendarList = (items) => {
    if (!listEl) return;
    if (!Array.isArray(items) || items.length === 0) {
      listEl.innerHTML = '<div class="text-xs text-slate-400">Nenhum calendário cadastrado.</div>';
      return;
    }
    // Deduplicate and normalize calendars
    const seen = new Set();
    const uniqueItems = items.filter(item => {
      const id = (item.calendar_id || '').trim();
      if (!id || seen.has(id)) return false;
      seen.add(id);
      return true;
    });
    // Find the active/default calendar index
    const activeIndex = uniqueItems.findIndex(item => item.is_default);
    const activeNum = activeIndex >= 0 ? activeIndex + 1 : 1;
    listEl.innerHTML = '';
    uniqueItems.forEach((item, idx) => {
      const num = idx + 1;
      const isActive = item.is_default;
      const wrapper = document.createElement('div');
      wrapper.className = 'rounded-xl border ' + (isActive ? 'border-primary/50 bg-primary/5' : 'border-mid/70 bg-light/60') + ' p-3 space-y-2';
      const summary = item.summary || item.calendar_id || 'Calendário';
      const timezone = item.timezone || 'Timezone não definido';
      const availabilityHint = item.availability ? 'Disponibilidade configurada' : 'Sem disponibilidade';
      wrapper.innerHTML = `
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="flex items-center gap-2">
              <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-primary text-white text-xs font-bold">${num}</span>
              <div class="font-medium text-slate-800">${summary}</div>
              ${isActive ? '<span class="text-[10px] px-2 py-0.5 rounded-full bg-primary text-white">Ativo</span>' : ''}
            </div>
            <div class="text-xs text-slate-500 ml-8">ID: ${item.calendar_id}</div>
            <div class="text-[11px] text-slate-400 ml-8">${timezone} • ${availabilityHint}</div>
          </div>
          <div class="flex flex-col gap-2">
            <button type="button" data-action="default" data-calendar-num="${num}" data-calendar-id="${item.calendar_id}"
                    class="text-xs px-3 py-1 rounded-full border ${isActive ? 'border-slate-300 text-slate-400 cursor-not-allowed' : 'border-primary text-primary hover:bg-primary/5'}"
                    ${isActive ? 'disabled' : ''}>
              ${isActive ? 'Ativo' : 'Ativar'}
            </button>
            <button type="button" data-action="remove" data-calendar-num="${num}" data-calendar-id="${item.calendar_id}"
                    class="text-xs px-3 py-1 rounded-full border border-red-300 text-red-500 hover:bg-red-50">
              Remover
            </button>
          </div>
        </div>
      `;
      listEl.appendChild(wrapper);
    });
  };

  const populateGoogleCalendars = (items) => {
    if (!googleSelect) return;
    googleSelect.innerHTML = '<option value="">Selecione um calendário</option>';
    if (!Array.isArray(items)) return;
    items.forEach(item => {
      const option = document.createElement('option');
      option.value = item.id;
      option.textContent = item.summary || item.id;
      option.dataset.timezone = item.timezone || '';
      googleSelect.appendChild(option);
    });
  };

  const loadGoogleCalendars = async () => {
    setStatus('Carregando calendários do Google...', 'info');
    const url = buildUrl('api/calendar/google-calendars', { instance: instanceId });
    const payload = await fetchJson(url);
    populateGoogleCalendars(payload.calendars || []);
    setStatus('Calendários do Google carregados.', 'success');
  };

  const restoreAvailabilityRanges = (calendars) => {
    console.log('restoreAvailabilityRanges called with:', JSON.stringify(calendars, null, 2));
    if (!Array.isArray(calendars) || calendars.length === 0) {
      console.log('No calendars array or empty');
      return;
    }
    // Find the first calendar with availability data
    const calendarWithAvailability = calendars.find(cal => cal.availability && cal.availability.days);
    console.log('calendarWithAvailability:', JSON.stringify(calendarWithAvailability, null, 2));
    if (!calendarWithAvailability) {
      return;
    }
    const availability = calendarWithAvailability.availability;
    console.log('availability:', JSON.stringify(availability, null, 2));
    if (!availability || !availability.days) {
      console.log('No availability or days');
      return;
    }
    // Reset the builder first
    resetAvailabilityBuilder();
    // Iterate over availability.days and add rows
    const days = availability.days;
    for (const [dayKey, ranges] of Object.entries(days)) {
      if (Array.isArray(ranges)) {
        for (const range of ranges) {
          if (range && range.start && range.end) {
            addAvailabilityRow(dayKey, range.start, range.end);
          }
        }
      }
    }
    // Update the hidden input with the restored availability data
    if (typeof updateAvailabilityInput === 'function') {
      updateAvailabilityInput();
    }
  };

  const loadCalendarConfig = async () => {
    setStatus('Carregando configuração...', 'info');
    const url = buildUrl('api/calendar/config', { instance: instanceId });
    const payload = await fetchJson(url);
    const connected = Boolean(payload.connected);
    lastCalendarConnected = connected;
    const pendingAuth = payload.pending_auth?.state ? payload.pending_auth : null;
    setPendingAuthState(pendingAuth?.state ?? null);
    if (pendingAuth) {
      setStatus('Aguardando autenticação do Google Calendar...', 'warning');
    } else {
      setStatus(connected ? `Conectado: ${payload.account?.calendar_email || 'conta Google'}` : 'Não conectado', connected ? 'success' : 'warning');
    }
    renderCalendarList(payload.calendars || []);
    restoreAvailabilityRanges(payload.calendars || []);
    if (connected && !pendingAuth && googleSelect && googleSelect.options.length <= 1) {
      try {
        await loadGoogleCalendars();
      } catch (err) {
        setStatus(`Erro ao listar calendários: ${err.message}`, 'error');
      }
    }
    return payload;
  };

  connectBtn?.addEventListener('click', async () => {
    try {
      setStatus('Gerando link de conexão...', 'info');
      const url = buildUrl('api/calendar/auth-url', { instance: instanceId });
      const payload = await fetchJson(url);
      if (payload?.url) {
        window.open(payload.url, '_blank', 'noopener');
        setPendingAuthState(payload.state ?? null);
        if (payload.state) {
          setStatus('Abra a nova aba e autorize o Google Calendar. Aguardando confirmação...', 'warning');
        } else {
          setStatus('Abra a nova aba para autorizar o Google Calendar.', 'success');
        }
      } else {
        setStatus('URL OAuth não retornada.', 'error');
      }
    } catch (error) {
      console.error('[calendar] connect failed', { url: `api/calendar/auth-url?instance=${instanceId}`, error });
      setStatus(`Erro ao conectar: ${error.message}`, 'error');
    }
  });

  forceConnectBtn?.addEventListener('click', async () => {
    try {
      setStatus('Liberando bloqueio de autenticação...', 'info');
      const url = buildUrl('api/calendar/force-clear', { instance: instanceId });
      await fetchJson(url, { method: 'POST' });
      setPendingAuthState(null);
      setStatus('Bloqueio removido. Tente conectar novamente.', 'success');
      await loadCalendarConfig();
    } catch (error) {
      setStatus(`Erro ao forçar conexão: ${error.message}`, 'error');
    }
  });

  disconnectBtn?.addEventListener('click', async () => {
    try {
      setStatus('Desconectando...', 'info');
      const url = buildUrl('api/calendar/disconnect', { instance: instanceId });
      await fetchJson(url, { method: 'POST' });
      setStatus('Desconectado.', 'success');
      populateGoogleCalendars([]);
      renderCalendarList([]);
    } catch (error) {
      setStatus(`Erro ao desconectar: ${error.message}`, 'error');
    }
  });

  refreshBtn?.addEventListener('click', async () => {
    try {
      await loadGoogleCalendars();
    } catch (error) {
      setStatus(`Erro ao listar calendários: ${error.message}`, 'error');
    }
  });

  googleSelect?.addEventListener('change', () => {
    const selected = googleSelect.selectedOptions?.[0];
    if (!selected || !calendarIdInput || !timezoneInput) return;
    const id = selected.value;
    if (id) {
      calendarIdInput.value = id;
    }
    if (!timezoneInput.value.trim() && selected.dataset.timezone) {
      timezoneInput.value = selected.dataset.timezone;
    }
  });

  saveBtn?.addEventListener('click', async () => {
    try {
      setSaveStatus('Salvando...', 'info');
      const calendarId = calendarIdInput?.value.trim() || googleSelect?.value || '';
      if (!calendarId) {
        throw new Error('Informe o calendar_id');
      }
      const timezone = timezoneInput?.value.trim() || null;
      const availabilityPayload = collectAvailabilityPayload();
      console.log('Availability payload:', JSON.stringify(availabilityPayload, null, 2));
      const payload = {
        calendar_id: calendarId,
        timezone,
        availability: availabilityPayload,
        is_default: Boolean(defaultCheckbox?.checked)
      };
      console.log('Full payload:', JSON.stringify(payload, null, 2));
      const url = buildUrl('api/calendar/calendars', { instance: instanceId });
      await fetchJson(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      setSaveStatus('Calendário salvo. Duplicatas serão removidas.', 'success');
      await loadCalendarConfig();
    } catch (error) {
      setSaveStatus(`Erro: ${error.message}`, 'error');
    }
  });

    listEl?.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const action = target.dataset.action;
      const calendarNum = target.dataset.calendarNum;
      const calendarId = target.dataset.calendarId;
      if (!action || !calendarId) return;
      try {
        if (action === 'remove') {
          const confirmDelete = confirm('Deseja apagar este calendário?');
          if (!confirmDelete) return;
          const url = buildUrl('api/calendar/calendars', { instance: instanceId, calendar_id: calendarId });
          await fetchJson(url, { method: 'DELETE' });
          await loadCalendarConfig();
        } else if (action === 'default') {
          // Set as active by calendar_num
          const url = buildUrl('api/calendar/default', { instance: instanceId });
          await fetchJson(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ calendar_num: parseInt(calendarNum, 10) })
          });
          await loadCalendarConfig();
        }
      } catch (error) {
        setStatus(`Erro ao atualizar calendário: ${error.message}`, 'error');
      }
    });

  loadCalendarConfig().catch(error => {
    setStatus(`Erro ao carregar: ${error.message}`, 'error');
  });
})();
</script>
<script>
(function () {
  const form = document.getElementById('audioTranscriptionForm');
  const saveBtn = document.getElementById('saveAudioTranscriptionButton');
  const statusEl = document.getElementById('audioTranscriptionStatus');
  const instanceApiKey = <?= json_encode($selectedInstance['api_key'] ?? '') ?>;
  const uploadInstanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const logTag = `[audio-transcription ${uploadInstanceId || 'unknown'}]`;

  if (!form || !saveBtn || !statusEl) {
    return;
  }

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
  toggleKeyVisibility('audioTranscriptionGeminiKey', 'toggleAudioGeminiKey');

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
    updateStatus('Chave da instância não disponível para salvar', 'error');
    saveBtn.disabled = true;
    return;
  }

  const getFieldValue = (id, fallback = '') => {
    const el = document.getElementById(id);
    if (!el) return fallback;
    if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
      return el.value.trim();
    }
    return fallback;
  };

  const collectPayload = () => ({
    enabled: document.getElementById('audioTranscriptionEnabled')?.checked || false,
    gemini_api_key: getFieldValue('audioTranscriptionGeminiKey'),
    prefix: getFieldValue('audioTranscriptionPrefix')
  });

  saveBtn.addEventListener('click', async () => {
    const payload = collectPayload();
    console.groupCollapsed(logTag, 'salvar transcrição de áudio');
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
          action: 'save_audio_transcription_config',
          audio: payload
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
      updateStatus(message, warning ? 'warning' : 'success');
    } catch (error) {
      console.error(logTag, 'falha ao salvar transcrição', error);
      updateStatus(`Erro ao salvar: ${error.message}`, 'error');
    } finally {
      saveBtn.disabled = false;
      console.groupEnd();
    }
  });
})();
</script>
<script>
(function () {
  const form = document.getElementById('secretaryForm');
  const saveBtn = document.getElementById('saveSecretaryButton');
  const statusEl = document.getElementById('secretaryStatus');
  const repliesWrap = document.getElementById('secretaryQuickReplies');
  const addReplyBtn = document.getElementById('addSecretaryReply');
  const instanceApiKey = <?= json_encode($selectedInstance['api_key'] ?? '') ?>;
  const uploadInstanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const initialReplies = <?= json_encode($secretaryQuickReplies, JSON_UNESCAPED_UNICODE) ?>;
  const logTag = `[secretary ${uploadInstanceId || 'unknown'}]`;

  if (!form || !saveBtn || !statusEl || !repliesWrap || !addReplyBtn) {
    return;
  }

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
    updateStatus('Chave da instância não disponível para salvar', 'error');
    saveBtn.disabled = true;
    return;
  }

  const getFieldValue = (id, fallback = '') => {
    const el = document.getElementById(id);
    if (!el) return fallback;
    if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
      return el.value.trim();
    }
    return fallback;
  };

  const collectPayload = () => ({
    enabled: document.getElementById('secretaryEnabled')?.checked || false,
    idle_hours: Number(getFieldValue('secretaryIdleHours', '0')) || 0,
    initial_response: getFieldValue('secretaryInitialResponse'),
    quick_replies: collectQuickReplies()
  });

  const normalizeQuickReply = (entry) => {
    if (!entry) return null;
    const term = (entry.term || '').trim();
    const response = (entry.response || '').trim();
    if (!term || !response) return null;
    return { term, response };
  };

  const collectQuickReplies = () => {
    const rows = Array.from(repliesWrap.querySelectorAll('[data-quick-reply="row"]'));
    const collected = rows.map(row => {
      const term = row.querySelector('input')?.value || '';
      const response = row.querySelector('textarea')?.value || '';
      return normalizeQuickReply({ term, response });
    }).filter(Boolean);
    return collected;
  };

  const escapeAttribute = (value) => {
    return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  };

  const escapeHtml = (value) => {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  };

  const createQuickReplyRow = (value = {}) => {
    const row = document.createElement('div');
    row.className = 'grid grid-cols-1 lg:grid-cols-3 gap-3 items-start';
    row.dataset.quickReply = 'row';
    row.innerHTML = `
      <input class="px-3 py-2 rounded-xl border border-mid bg-light"
             placeholder="termo" value="${escapeAttribute(value.term)}">
      <div class="lg:col-span-2 flex gap-2 items-start">
        <textarea rows="2" class="flex-1 px-3 py-2 rounded-xl border border-mid bg-light"
                  placeholder="resposta automática">${escapeHtml(value.response)}</textarea>
        <button type="button" class="text-xs text-slate-600 border border-slate-300 rounded-full px-3 py-1 hover:border-primary hover:text-primary transition" data-expand="1">
          Expandir
        </button>
        <button type="button" class="text-xs text-alert border border-alert/60 rounded-full px-3 py-1 hover:bg-alert/10 transition" data-remove="1">
          Remover
        </button>
      </div>
    `;
    const removeBtn = row.querySelector('[data-remove="1"]');
    removeBtn?.addEventListener('click', () => {
      row.remove();
    });
    const expandBtn = row.querySelector('[data-expand="1"]');
    expandBtn?.addEventListener('click', () => {
      openQuickReplyOverlay(row);
    });
    return row;
  };

  const ensureInitialReplies = () => {
    const normalized = Array.isArray(initialReplies) ? initialReplies.map(normalizeQuickReply).filter(Boolean) : [];
    if (normalized.length) {
      normalized.forEach(entry => repliesWrap.appendChild(createQuickReplyRow(entry)));
      return;
    }
    repliesWrap.appendChild(createQuickReplyRow());
  };

  addReplyBtn.addEventListener('click', () => {
    repliesWrap.appendChild(createQuickReplyRow());
  });

  ensureInitialReplies();

  const overlay = document.createElement('div');
  overlay.className = 'quick-reply-overlay';
  overlay.innerHTML = `
    <div class="quick-reply-panel">
      <div class="text-sm text-slate-500">Resposta rápida</div>
      <textarea class="w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Digite a resposta"></textarea>
      <button type="button" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">Retornar</button>
    </div>
  `;
  document.body.appendChild(overlay);
  const overlayTextarea = overlay.querySelector('textarea');
  const overlayCloseBtn = overlay.querySelector('button');
  let activeRow = null;

  const closeQuickReplyOverlay = () => {
    overlay.classList.remove('active');
    activeRow = null;
  };
  window.__closeQuickReplyOverlay = closeQuickReplyOverlay;

  const closeAllQuickReplyOverlays = () => {
    closeQuickReplyOverlay();
    if (typeof window.__closePromptOverlay === 'function') {
      window.__closePromptOverlay();
    }
  };

  const openQuickReplyOverlay = (row) => {
    const textarea = row?.querySelector('textarea');
    if (!textarea) return;
    activeRow = row;
    overlayTextarea.value = textarea.value;
    if (typeof window.__closePromptOverlay === 'function') {
      window.__closePromptOverlay();
    }
    overlay.classList.add('active');
    overlayTextarea.focus();
  };

  overlayTextarea?.addEventListener('input', () => {
    if (!activeRow) return;
    const textarea = activeRow.querySelector('textarea');
    if (textarea) {
      textarea.value = overlayTextarea.value;
    }
  });

  overlayCloseBtn?.addEventListener('click', closeQuickReplyOverlay);
  overlay?.addEventListener('click', (event) => {
    if (event.target === overlay) {
      closeAllQuickReplyOverlays();
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && overlay.classList.contains('active')) {
      closeAllQuickReplyOverlays();
    }
  });

  saveBtn.addEventListener('click', async () => {
    const payload = collectPayload();
    console.groupCollapsed(logTag, 'salvar secretaria virtual');
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
          action: 'save_secretary_config',
          secretary: payload
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
      updateStatus(message, warning ? 'warning' : 'success');
    } catch (error) {
      console.error(logTag, 'falha ao salvar secretaria', error);
      updateStatus(`Erro ao salvar: ${error.message}`, 'error');
    } finally {
      saveBtn.disabled = false;
      console.groupEnd();
    }
  });
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
  const events = ['whatsapp', 'server', 'error'];
  const saveBtn = document.getElementById('saveAlarmButton');
  const statusEl = document.getElementById('alarmStatus');
  const instanceApiKey = <?= json_encode($selectedInstance['api_key'] ?? '') ?>;
  const logTag = `[alarm-card ${<?= json_encode($selectedInstanceId ?? '') ?> || 'unknown'}]`;

  if (!saveBtn || !statusEl) {
    return;
  }

  const updateStatus = (message, mode = 'info') => {
    const baseClass = 'text-xs transition-colors';
    const typeClass = mode === 'error'
      ? 'text-error font-medium'
      : mode === 'warning'
        ? 'text-alert font-medium'
        : mode === 'success'
          ? 'text-success font-medium'
          : 'text-slate-500';
    statusEl.className = `${baseClass} ${typeClass}`;
    statusEl.textContent = message;
  };

  const collectPayload = () => {
    const payload = { action: 'save_alarm_config' };
    events.forEach(event => {
      const enabledEl = document.getElementById(`alarm_${event}_enabled`);
      const recipientsEl = document.getElementById(`alarm_${event}_recipients`);
      const intervalEl = document.getElementById(`alarm_${event}_interval`);
      payload[`alarm_${event}_enabled`] = enabledEl && enabledEl.checked ? '1' : '0';
      payload[`alarm_${event}_recipients`] = recipientsEl ? recipientsEl.value.trim() : '';
      payload[`alarm_${event}_interval`] = intervalEl ? intervalEl.value : '120';
      payload[`alarm_${event}_interval_unit`] = 'minutes';
    });
    return payload;
  };

  const formatIntervalMinutes = (value) => {
    const minutes = Number(value || 0);
    if (!Number.isFinite(minutes) || minutes <= 0) {
      return 'N/A';
    }
    if (minutes < 60) {
      return `${minutes} min`;
    }
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    if (!remainder) {
      return `${hours}h`;
    }
    return `${hours}h ${remainder}m`;
  };

  const syncIntervalLabels = () => {
    events.forEach(event => {
      const intervalEl = document.getElementById(`alarm_${event}_interval`);
      const labelEl = document.getElementById(`alarm_${event}_interval_label`);
      if (!intervalEl || !labelEl) return;
      const value = intervalEl.value || '120';
      labelEl.textContent = formatIntervalMinutes(value);
    });
  };

  events.forEach(event => {
    const intervalEl = document.getElementById(`alarm_${event}_interval`);
    if (!intervalEl) return;
    intervalEl.addEventListener('input', syncIntervalLabels);
  });

  syncIntervalLabels();

  saveBtn.addEventListener('click', async () => {
    if (!instanceApiKey) {
      console.error(logTag, 'Chave da instância ausente para alarmes');
      updateStatus('Chave da instância não disponível', 'error');
      return;
    }

    const payload = collectPayload();
    updateStatus('Salvando alarmes...', 'info');
    saveBtn.disabled = true;

    try {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': instanceApiKey
        },
        body: JSON.stringify(payload)
      });

      const text = await response.text();
      let data;
      try {
        data = text ? JSON.parse(text) : null;
      } catch (err) {
        console.error(logTag, 'Falha ao parsear resposta de alarmes', err);
        throw new Error('Resposta inválida do servidor');
      }

      if (!response.ok || !data?.success) {
        const errorDetail = data?.error || data?.warning || 'Erro ao salvar alarmes';
        throw new Error(errorDetail);
      }

      const warning = data?.warning;
      const message = warning ? `Config salva com advertência: ${warning}` : 'Alarmes salvos com sucesso';
      updateStatus(message, warning ? 'warning' : 'success');
    } catch (error) {
      console.error(logTag, 'Erro ao salvar alarmes', error);
      updateStatus(`Erro: ${error.message}`, 'error');
    } finally {
      saveBtn.disabled = false;
    }
  });
})();
</script>
<script>
(function () {
  const uploadForm = document.getElementById('assetUploadForm');
  const uploadButton = document.getElementById('assetUploadButton');
  const fileInput = document.getElementById('assetFileInput');
  const progressWrap = document.getElementById('assetUploadProgress');
  const progressBar = document.getElementById('assetUploadProgressBar');
  const codeWrap = document.getElementById('assetUploadCodeWrap');
  const codeEl = document.getElementById('assetUploadCode');
  const csrfTokenEl = document.querySelector('#assetUploadForm [name="csrf_token"]');
  const uploadInstanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const chunkEndpointBase = 'assets/upload_chunk.php';
  const chunkSize = 1024 * 1024;

  const setProgress = (percent) => {
    if (!progressBar || !progressWrap) return;
    progressWrap.classList.remove('hidden');
    const safe = Math.min(100, Math.max(0, percent));
    progressBar.style.width = `${safe}%`;
  };

  const resetProgress = () => {
    if (!progressBar || !progressWrap) return;
    progressBar.style.width = '0%';
    progressWrap.classList.add('hidden');
  };

  const uploadChunk = (formData, progressCb) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', `${chunkEndpointBase}?instance=${encodeURIComponent(uploadInstanceId)}`, true);
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          const payload = JSON.parse(xhr.responseText || '{}');
          resolve(payload);
        } catch (err) {
          reject(new Error('Resposta inválida do servidor'));
        }
      } else {
        reject(new Error(xhr.responseText || `Erro HTTP ${xhr.status}`));
      }
    };
    xhr.onerror = () => reject(new Error('Falha na conexão'));
    if (xhr.upload && typeof progressCb === 'function') {
      xhr.upload.onprogress = (evt) => {
        if (evt.lengthComputable) {
          progressCb(evt.loaded / evt.total);
        }
      };
    }
    xhr.send(formData);
  });

  if (uploadButton && uploadForm && fileInput) {
    uploadButton.addEventListener('click', async () => {
      if (!fileInput.files || !fileInput.files.length) return;
      const file = fileInput.files[0];
      const totalChunks = Math.ceil(file.size / chunkSize);
      const uploadId = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
      const csrfToken = csrfTokenEl?.value || '';

      if (codeEl) {
        codeEl.textContent = '';
      }
      if (codeWrap) {
        codeWrap.classList.add('hidden');
      }
      uploadButton.disabled = true;
      setProgress(0);

      try {
        for (let index = 0; index < totalChunks; index += 1) {
          const start = index * chunkSize;
          const end = Math.min(file.size, start + chunkSize);
          const chunk = file.slice(start, end);
          const formData = new FormData();
          formData.append('csrf_token', csrfToken);
          formData.append('upload_id', uploadId);
          formData.append('chunk_index', String(index));
          formData.append('total_chunks', String(totalChunks));
          formData.append('file_name', file.name || 'arquivo');
          formData.append('file_type', file.type || '');
          formData.append('chunk', chunk);

          const payload = await uploadChunk(formData, (ratio) => {
            const overall = ((index + ratio) / totalChunks) * 100;
            setProgress(overall);
          });

          if (!payload?.ok) {
            throw new Error(payload?.error || 'Falha no upload');
          }
          if (payload?.code && codeEl) {
            codeEl.textContent = payload.code;
            if (codeWrap) {
              codeWrap.classList.remove('hidden');
            }
          }
        }
        setProgress(100);
      } catch (error) {
        alert(`Falha no upload: ${error.message}`);
      } finally {
        uploadButton.disabled = false;
        setTimeout(() => resetProgress(), 1200);
      }
    });
  }

  const rangeSelect = document.getElementById('logRangeSelect');
  const customFields = document.getElementById('logRangeCustomFields');
  if (rangeSelect && customFields) {
    rangeSelect.addEventListener('change', () => {
      const isCustom = rangeSelect.value === 'custom';
      customFields.classList.toggle('hidden', !isCustom);
    });
  }
 
})();
</script>

<script>
(function () {
  const form = document.getElementById('quickConfigForm');
  const baseUrlInput = document.getElementById('quickConfigBaseUrlInput');
  const encodedInput = document.getElementById('quickConfigBaseUrlEncoded');
  const integrationTypeSelect = document.getElementById('quickConfigIntegrationType');
  const baileysFields = document.getElementById('quickConfigBaileysFields');
  const metaFields = document.getElementById('quickConfigMetaFields');
  if (!form || !baseUrlInput || !encodedInput || !integrationTypeSelect || !baileysFields || !metaFields) {
    return;
  }
  const templatesTab = document.getElementById('templatesTab');
  const autoPauseSection = document.getElementById('autoPauseSection');
  const audioTranscriptionSection = document.getElementById('audioTranscriptionSection');
  const secretarySection = document.getElementById('secretarySection');
  const metaFieldIds = ['quickConfigMetaAccessToken', 'quickConfigMetaBusinessAccountId', 'quickConfigMetaTelephoneId'];
  const metaFieldInputs = metaFieldIds.map(id => document.getElementById(id)).filter(Boolean);

  const toBase64 = (value) => {
    const normalized = value === null || value === undefined ? '' : String(value);
    try {
      return window.btoa(normalized);
    } catch {
      try {
        return window.btoa(unescape(encodeURIComponent(normalized)));
      } catch {
        return '';
      }
    }
  };
  const syncEncodedValue = () => {
    encodedInput.value = toBase64(baseUrlInput.value || '');
  };
  const setMetaFieldRequired = (isMeta) => {
    metaFieldInputs.forEach(input => {
      input.required = isMeta;
    });
  };
  const updateBaseUrlRequirement = (isBaileysOrWeb) => {
    baseUrlInput.required = isBaileysOrWeb;
  };
  const syncIntegrationFields = () => {
    const integrationType = integrationTypeSelect.value;
    const isBaileysOrWeb = integrationType === 'baileys' || integrationType === 'web';
    if (isBaileysOrWeb) {
      baileysFields.classList.remove('hidden');
      metaFields.classList.add('hidden');
    } else {
      baileysFields.classList.add('hidden');
      metaFields.classList.remove('hidden');
    }
    updateBaseUrlRequirement(isBaileysOrWeb);
    setMetaFieldRequired(integrationType === 'meta');
  };
  const applyIntegrationVisibility = () => {
    const integrationType = integrationTypeSelect.value;
    const isBaileysOrWeb = integrationType === 'baileys' || integrationType === 'web';
    const isMeta = integrationType === 'meta';

    if (templatesTab) {
      templatesTab.style.display = isMeta ? '' : 'none';
    }
    if (autoPauseSection) {
      autoPauseSection.style.display = isBaileysOrWeb ? '' : 'none';
    }
    if (audioTranscriptionSection) {
      audioTranscriptionSection.style.display = isBaileysOrWeb ? '' : 'none';
    }
    if (secretarySection) {
      secretarySection.style.display = isBaileysOrWeb ? '' : 'none';
    }

    const alarmEvents = document.querySelectorAll('[data-alarm-event]');
    alarmEvents.forEach(event => {
      if (!event) return;
      const eventKey = event.getAttribute('data-alarm-event');
      if (isMeta) {
        event.style.display = eventKey === 'error' ? '' : 'none';
      } else if (isBaileysOrWeb) {
        event.style.display = '';
      } else {
        event.style.display = 'none';
      }
    });
  };

  syncEncodedValue();
  syncIntegrationFields();
  applyIntegrationVisibility();

  // Quick Config AJAX Save
  // Auto-update Base URL when porta changes - set up on page load
  (function() {
    const portaInput = document.querySelector('input[name="instance_port"]');
    const baseUrlInput = document.getElementById('quickConfigBaseUrlInput');
    if (portaInput && baseUrlInput) {
      // Make Base URL readonly - it should be auto-calculated from porta
      baseUrlInput.setAttribute('readonly', true);
      baseUrlInput.title = 'Este campo é calculado automaticamente a partir da Porta';
      
      portaInput.addEventListener('change', function() {
        const porta = this.value || '3000';
        baseUrlInput.value = 'http://127.0.0.1:' + porta;
        // Also update the encoded value
        const encodedInput = document.getElementById('quickConfigBaseUrlEncoded');
        if (encodedInput) {
          encodedInput.value = btoa(baseUrlInput.value);
        }
      });
    }
  })();
  
  window.saveQuickConfig = async function() {
    const form = document.getElementById('quickConfigForm');
    const messageArea = document.getElementById('quickConfigMessageArea');
    const saveBtn = document.getElementById('quickConfigSaveButton');
    
    if (!form) {
      console.error('Quick config form not found');
      return;
    }
    
    // Show loading
    saveBtn.disabled = true;
    saveBtn.textContent = 'Salvando...';
    messageArea.innerHTML = '';
    
    try {
      // Get form data
      const formData = new FormData(form);
      
      // Get the base URL value and encode it (use existing baseUrlInput from closure)
      const baseUrlInput = document.getElementById('quickConfigBaseUrlInput');
      if (baseUrlInput) {
        const base64Value = btoa(baseUrlInput.value || '');
        formData.set('instance_base_url_b64', base64Value);
      }
      
      // Debug: log form data
      console.log('Saving quick config with data:');
      for (let [key, value] of formData.entries()) {
        console.log(key + ': ' + value);
      }
      
      // Use explicit POST URL
      const url = window.location.pathname + window.location.search;
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams(formData).toString()
      });
      
      console.log('Response status:', response.status);
      const text = await response.text();
      console.log('Response text:', text.substring(0, 500));
      
      // Try to parse JSON
      let result;
      try {
        result = JSON.parse(text);
      } catch (e) {
        // If not JSON, check if it was successful
        if (response.ok && text.includes('success')) {
          result = { success: true, message: 'Configurações salvas!' };
        } else {
          result = { success: false, error: 'Resposta inválida do servidor' };
        }
      }
      
      if (result.success) {
        messageArea.innerHTML = '<p class="text-xs text-success mt-1">' + (result.message || 'Configurações salvas com sucesso!') + '</p>';
        setTimeout(() => {
          window.location.reload();
        }, 1500);
      } else {
        messageArea.innerHTML = '<p class="text-xs text-error mt-1">' + (result.error || 'Erro ao salvar') + '</p>';
      }
      
    } catch (error) {
      console.error('Quick save error:', error);
      messageArea.innerHTML = '<p class="text-xs text-error mt-1">Erro: ' + error.message + '</p>';
    } finally {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Salvar';
    }
  };

  form.addEventListener('submit', () => {
    syncEncodedValue();
  });
  integrationTypeSelect.addEventListener('change', () => {
    syncIntegrationFields();
    applyIntegrationVisibility();
  });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/intro.js/minified/intro.min.js"></script>
<script>
(() => {
const helpTourButton = document.getElementById('helpTourButton');

const averageTaxarDisplay = document.getElementById('averageTaxar');
  if (!helpTourButton || typeof introJs !== 'function') {
    return;
  }

  const buildSteps = () => {
    const steps = [
      {
        intro: 'Bem-vindo! Este tour apresenta cada área e botão principal da instância.'
      }
    ];

    const pushStep = (selector, title, intro) => {
      const element = document.querySelector(selector);
      if (!element) return;
      steps.push({ element, title, intro });
    };

    pushStep('#instanceTitle', 'Instância selecionada', 'Mostra qual instância você está configurando agora.');
    pushStep('#instanceActions', 'Ações rápidas', 'Aqui ficam os botões de conexão, exclusão e outras ações críticas.');
    pushStep('#connectQrButton', 'Conectar WhatsApp', 'Abre o QR Code para conectar esta instância ao WhatsApp.');
    pushStep('#disconnectButton', 'Desconectar', 'Encerra a sessão atual do WhatsApp desta instância.');
    pushStep('#deleteInstanceButton', 'Deletar instância', 'Remove a instância e seus dados. Use com cuidado.');
    pushStep('#saveChangesButton', 'Salvar alterações', 'Guarda mudanças gerais feitas na tela.');

    pushStep('#sendMessageSection', 'Enviar mensagem', 'Envio manual de mensagens para testes rápidos.');
    pushStep('#sendButton', 'Enviar mensagem', 'Dispara a mensagem preenchida nos campos acima.');
    pushStep('#assetUploadSection', 'Upload de arquivos', 'Gera códigos IMG/VIDEO/AUDIO para usar no bot.');

    pushStep('#quickConfigSection', 'Configuração rápida', 'Ajustes essenciais da instância: nome e base URL.');
    pushStep('#quickConfigSaveButton', 'Salvar config rápida', 'Aplica as mudanças do bloco de configuração rápida.');

    pushStep('#curlExampleSection', 'Exemplo CURL', 'Exemplo pronto para integração via API com a instância atual.');

    pushStep('#aiSettingsSection', 'IA (OpenAI / Gemini / OpenRouter)', 'Configura o comportamento do bot e a integração com IA.');
    pushStep('#aiEnabled', 'Habilitar IA', 'Liga ou desliga as respostas automáticas.');
    pushStep('#audioTranscriptionSection', 'Transcrever áudio', 'Ativa a transcrição automática de áudios recebidos.');
    pushStep('#secretarySection', 'Secretária virtual', 'Define respostas de retorno e termos automáticos.');
    pushStep('#aiProvider', 'Provider', 'Seleciona o provedor que gera as respostas.');
    pushStep('#aiModel', 'Modelo', 'Define o modelo que será utilizado pelo bot.');
    pushStep('#aiSystemPrompt', 'System prompt', 'Define o papel do assistente e o tom das respostas.');
    pushStep('#aiAssistantPrompt', 'Instruções do assistente', 'Detalha comportamentos e regras específicas.');
    pushStep('#aiMultiInputDelay', 'Delay multi-input', 'Espera alguns segundos para juntar mensagens antes de responder.');
    pushStep('#saveAIButton', 'Salvar IA', 'Grava as configurações de IA desta instância.');
    pushStep('#testAIButton', 'Testar IA', 'Envia um prompt de teste e mostra a resposta do provedor.');

    pushStep('#alarmSettingsSection', 'Alarmes', 'Configura alertas por e-mail para eventos críticos.');
    pushStep('#saveAlarmButton', 'Salvar alarmes', 'Confirma as configurações de alerta.');

    pushStep('#logSummarySection', 'Painel de logs', 'Resumo e exportação do período selecionado.');

    return steps;
  };

  helpTourButton.addEventListener('click', () => {
    const tour = introJs();
    tour.setOptions({
      steps: buildSteps(),
      nextLabel: 'Próximo',
      prevLabel: 'Voltar',
      doneLabel: 'Finalizar',
      skipLabel: 'Pular',
      showProgress: true,
      showBullets: false,
      exitOnOverlayClick: false
    });
    tour.start();
  });
})();
</script>
<script>
(function () {
  const buttons = Array.from(document.querySelectorAll('[data-tab-target]'));
  const panes = Array.from(document.querySelectorAll('[data-tab-pane]'));
  if (!buttons.length || !panes.length) {
    return;
  }

  const activateTab = (targetId) => {
    buttons.forEach((btn) => {
      btn.classList.toggle('active', btn.dataset.tabTarget === targetId);
    });
    panes.forEach((pane) => {
      pane.classList.toggle('active', pane.dataset.tabPane === targetId);
    });
  };

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      activateTab(button.dataset.tabTarget);
    });
  });

  const defaultTarget = buttons.find((btn) => btn.classList.contains('active'))?.dataset.tabTarget || buttons[0].dataset.tabTarget;
  activateTab(defaultTarget);
})();
</script>
<script>
(function () {
  document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-copy-snippet]');
    if (!trigger) return;
    const targetId = trigger.dataset.copySnippet;
    const textarea = document.getElementById(targetId);
    if (!textarea) return;
    const text = textarea.value || textarea.textContent || '';
    if (!text.trim()) {
      return;
    }
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        textarea.select();
        document.execCommand('copy');
      }
      const original = trigger.textContent;
      trigger.textContent = 'Copiado';
      setTimeout(() => {
        trigger.textContent = original;
      }, 1200);
    } catch (err) {
      console.error('copy snippet failed', err);
    }
  });
})();
</script>

<script>
(function () {
  const searchInput = document.querySelector('input[placeholder="Buscar instância..."]');
  if (!searchInput) return;
  searchInput.addEventListener('input', () => {
    const query = searchInput.value.toLowerCase().trim();
    const cards = document.querySelectorAll('.instance-card');
    cards.forEach(card => {
      const name = (card.dataset.instanceName || '').toLowerCase();
      card.style.display = name.includes(query) ? '' : 'none';
    });
  });
})();
</script>

<script>
(function () {
  const instanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const instanceApiKey = <?= json_encode($selectedInstance['api_key'] ?? '') ?>;
  const csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

  const approvedList = document.getElementById('approvedTemplatesList');
  const pendingList = document.getElementById('pendingTemplatesList');
  const rejectedList = document.getElementById('rejectedTemplatesList');
  const refreshBtn = document.getElementById('refreshTemplatesBtn');
  if (!approvedList || !pendingList || !rejectedList || !refreshBtn) {
    return;
  }
  const createTemplateItem = (template) => {
    const item = document.createElement('div');
    item.className = 'p-3 bg-white border border-mid rounded-xl flex items-center justify-between';
    const isApproved = template.status === 'APPROVED';
    const sendButton = isApproved ? `<button type="button" class="px-3 py-1 text-xs bg-primary text-white rounded-lg hover:opacity-90" onclick="sendTemplate('${template.name}')">Enviar</button>` : '';
    item.innerHTML = `
      <div>
        <div class="font-medium text-dark">${template.name}</div>
        <div class="text-xs text-slate-500">${template.category} • ${template.language || 'pt_BR'}</div>
      </div>
      <div class="flex items-center gap-2">
        <div class="text-xs text-slate-400">${template.status}</div>
        ${sendButton}
      </div>
    `;
    return item;
  };

  const loadTemplates = async () => {
    if (!instanceId) return;
    try {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': instanceApiKey
        },
        body: JSON.stringify({
          action: 'check_all_meta_template_statuses',
          instance_id: instanceId
        })
      });
      const data = await response.json();
      if (!data.ok) throw new Error(data.error || 'Erro ao carregar templates');

      const templates = data.templates || [];
      const approved = templates.filter(t => t.status === 'APPROVED');
      const pending = templates.filter(t => t.status === 'PENDING');
      const rejected = templates.filter(t => t.status === 'REJECTED');

      approvedList.innerHTML = approved.length ? approved.map(createTemplateItem).map(item => item.outerHTML).join('') : '<div class="text-xs text-slate-500">Nenhum template aprovado</div>';
      pendingList.innerHTML = pending.length ? pending.map(createTemplateItem).map(item => item.outerHTML).join('') : '<div class="text-xs text-slate-500">Nenhum template pendente</div>';
      rejectedList.innerHTML = rejected.length ? rejected.map(createTemplateItem).map(item => item.outerHTML).join('') : '<div class="text-xs text-slate-500">Nenhum template rejeitado</div>';
    } catch (error) {
      console.error('Erro ao carregar templates:', error);
      [approvedList, pendingList, rejectedList].forEach(list => {
        list.innerHTML = '<div class="text-xs text-error">Erro ao carregar templates</div>';
      });
    }
  };

  if (refreshBtn) {
    refreshBtn.addEventListener('click', loadTemplates);
  }

  // Load templates on page load
  if (instanceId) {
    loadTemplates();
  }

  // Template sending functionality
  const testSendForm = document.getElementById('testSendForm');
  const bulkSendForm = document.getElementById('bulkSendForm');
  const testTemplateSelect = document.getElementById('testTemplateSelect');
  const bulkTemplateSelect = document.getElementById('bulkTemplateSelect');
  const testVariablesContainer = document.getElementById('testVariablesContainer');
  const bulkVariablesContainer = document.getElementById('bulkVariablesContainer');
  const testSendStatus = document.getElementById('testSendStatus');
  const bulkSendStatus = document.getElementById('bulkSendStatus');

  let templatesData = [];

  const updateTemplateSelects = () => {
    const approvedTemplates = templatesData.filter(t => t.status === 'APPROVED');
    const options = '<option value="">Selecione um template...</option>' +
      approvedTemplates.map(t => `<option value="${t.name}">${t.name}</option>`).join('');

    if (testTemplateSelect) testTemplateSelect.innerHTML = options;
    if (bulkTemplateSelect) bulkTemplateSelect.innerHTML = options;
  };

  const updateVariablesForTemplate = (templateName, container) => {
    const template = templatesData.find(t => t.name === templateName);
    if (!template || !template.components) {
      container.innerHTML = '';
      return;
    }

    const bodyComponent = template.components.find(c => c.type === 'BODY');
    if (!bodyComponent || !bodyComponent.text) {
      container.innerHTML = '';
      return;
    }

    const matches = bodyComponent.text.match(/\{\{(\d+)\}\}/g);
    if (!matches) {
      container.innerHTML = '';
      return;
    }

    const variables = [...new Set(matches.map(m => parseInt(m.match(/(\d+)/)[1])))].sort((a, b) => a - b);
    container.innerHTML = variables.map(varNum => `
      <div>
        <label class="text-xs text-slate-500">Variável {{${varNum}}}</label>
        <input type="text" name="var_${varNum}" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Valor para {{${varNum}}}" required>
      </div>
    `).join('');
  };

  const sendTemplate = async (templateName) => {
    const phone = prompt('Digite o número de destino (ex: 5585999999999):');
    if (!phone) return;

    const template = templatesData.find(t => t.name === templateName);
    if (!template) return;

    const variables = {};
    const bodyComponent = template.components?.find(c => c.type === 'BODY');
    if (bodyComponent?.text) {
      const matches = bodyComponent.text.match(/\{\{(\d+)\}\}/g);
      if (matches) {
        const varNums = [...new Set(matches.map(m => parseInt(m.match(/(\d+)/)[1])))].sort((a, b) => a - b);
        for (const varNum of varNums) {
          const value = prompt(`Digite o valor para {{${varNum}}}:`);
          if (value === null) return; // Cancelled
          variables[varNum] = value;
        }
      }
    }

    try {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': instanceApiKey
        },
        body: JSON.stringify({
          action: 'send_meta_template',
          instance_id: instanceId,
          template_name: templateName,
          to: phone,
          variables: variables
        })
      });
      const data = await response.json();
      if (data.ok) {
        alert('Template enviado com sucesso!');
      } else {
        alert(`Erro ao enviar: ${data.error || 'Erro desconhecido'}`);
      }
    } catch (error) {
      console.error('Erro ao enviar template:', error);
      alert('Erro ao enviar template. Verifique o console para detalhes.');
    }
  };

  const handleTestSend = async (e) => {
    e.preventDefault();
    const formData = new FormData(testSendForm);
    const templateName = formData.get('template_name');
    const to = formData.get('to');

    const variables = {};
    for (const [key, value] of formData.entries()) {
      if (key.startsWith('var_')) {
        const varNum = key.replace('var_', '');
        variables[varNum] = value;
      }
    }

    setStatus(testSendStatus, 'Enviando template de teste...', 'info');
    document.getElementById('testSendBtn').disabled = true;

    try {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': instanceApiKey
        },
        body: JSON.stringify({
          action: 'send_meta_template',
          instance_id: instanceId,
          template_name: templateName,
          to: to,
          variables: variables
        })
      });
      const data = await response.json();
      if (data.ok) {
        setStatus(testSendStatus, 'Template de teste enviado com sucesso!', 'success');
        testSendForm.reset();
        updateVariablesForTemplate('', testVariablesContainer);
      } else {
        setStatus(testSendStatus, `Erro: ${data.error || 'Erro desconhecido'}`, 'error');
      }
    } catch (error) {
      console.error('Erro ao enviar template de teste:', error);
      setStatus(testSendStatus, 'Erro ao enviar template de teste', 'error');
    } finally {
      document.getElementById('testSendBtn').disabled = false;
    }
  };

  const handleBulkSend = async (e) => {
    e.preventDefault();
    const formData = new FormData(bulkSendForm);
    const templateName = formData.get('template_name');
    const recipientsText = formData.get('recipients');

    const recipients = recipientsText.split('\n').map(r => r.trim()).filter(r => r);
    if (recipients.length === 0) {
      setStatus(bulkSendStatus, 'Nenhum destinatário informado', 'error');
      return;
    }

    const variables = {};
    for (const [key, value] of formData.entries()) {
      if (key.startsWith('var_')) {
        const varNum = key.replace('var_', '');
        variables[varNum] = value;
      }
    }

    setStatus(bulkSendStatus, 'Enviando templates em massa...', 'info');
    document.getElementById('bulkSendBtn').disabled = true;

    try {
      const response = await fetch('api.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'x-api-key': instanceApiKey
        },
        body: JSON.stringify({
          action: 'send_meta_template_bulk',
          instance_id: instanceId,
          template_name: templateName,
          recipients: recipients,
          variables: variables
        })
      });
      const data = await response.json();
      if (data.ok) {
        const successCount = data.results?.filter(r => r.ok).length || 0;
        const totalCount = data.results?.length || 0;
        setStatus(bulkSendStatus, `Enviado com sucesso para ${successCount}/${totalCount} destinatários`, 'success');
        bulkSendForm.reset();
        updateVariablesForTemplate('', bulkVariablesContainer);
      } else {
        setStatus(bulkSendStatus, `Erro: ${data.error || 'Erro desconhecido'}`, 'error');
      }
    } catch (error) {
      console.error('Erro ao enviar templates em massa:', error);
      setStatus(bulkSendStatus, 'Erro ao enviar templates em massa', 'error');
    } finally {
      document.getElementById('bulkSendBtn').disabled = false;
    }
  };

  // Override loadTemplates to store data and update selects
  const originalLoadTemplates = loadTemplates;
  loadTemplates = async () => {
    await originalLoadTemplates();
    // Update templatesData from the loaded templates
    // This is a simplified approach - in practice you might want to store the data during loading
    setTimeout(() => {
      // Assuming templates are loaded, we need to fetch them again to get full data
      fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'check_all_meta_template_statuses',
          instance_id: instanceId
        })
      }).then(r => r.json()).then(data => {
        if (data.ok) {
          templatesData = data.templates || [];
          updateTemplateSelects();
        }
      }).catch(err => console.error('Error fetching templates data:', err));
    }, 100);
  };

  if (testTemplateSelect) {
    testTemplateSelect.addEventListener('change', () => {
      updateVariablesForTemplate(testTemplateSelect.value, testVariablesContainer);
    });
  }

  if (bulkTemplateSelect) {
    bulkTemplateSelect.addEventListener('change', () => {
      updateVariablesForTemplate(bulkTemplateSelect.value, bulkVariablesContainer);
    });
  }

  if (testSendForm) {
    testSendForm.addEventListener('submit', handleTestSend);
  }

  if (bulkSendForm) {
    bulkSendForm.addEventListener('submit', handleBulkSend);
  }

  // Make sendTemplate function global
  window.sendTemplate = sendTemplate;
})();
</script>
<script>
(function () {
  const overlay = document.getElementById('debugLogOverlay');
  const openButton = document.getElementById('openDebugLogsButton');
  const closeButton = document.getElementById('closeDebugLogOverlay');
  const outputEl = document.getElementById('debugLogOutput');
  const sourceLabel = document.getElementById('debugLogSourceLabel');
  const logButtons = overlay ? Array.from(overlay.querySelectorAll('[data-log-type]')) : [];
  const logs = <?= json_encode($baileysDebugLogs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  const logPaths = <?= json_encode($baileysDebugPaths, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  let activeType = 'out';

  if (!overlay || !outputEl || !sourceLabel) {
    return;
  }

  function setActiveType(type) {
    activeType = type;
    logButtons.forEach(btn => {
      const isActive = btn.dataset.logType === type;
      btn.classList.toggle('bg-slate-900', isActive);
      btn.classList.toggle('text-white', isActive);
      btn.classList.toggle('border-slate-900', isActive);
    });
    outputEl.textContent = logs[type] || 'Nenhum log disponível para ' + type + '.';
    const pathText = logPaths[type] || 'não disponível';
    sourceLabel.textContent = 'Arquivo: ' + pathText;
  }

  logButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      setActiveType(btn.dataset.logType || 'out');
    });
  });

  openButton?.addEventListener('click', event => {
    event.preventDefault();
    if (openButton.disabled) {
      return;
    }
    overlay.classList.remove('hidden');
    setActiveType(activeType);
  });

  closeButton?.addEventListener('click', () => {
    overlay.classList.add('hidden');
  });

  overlay.addEventListener('click', event => {
    if (event.target === overlay) {
      overlay.classList.add('hidden');
    }
  });
})();
</script>

</body>
</html>
