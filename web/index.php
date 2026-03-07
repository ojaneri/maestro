<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../instance_data.php';
require_once __DIR__ . '/../includes/timezone.php';

define('WEB_SESSION_COOKIE_NAME', 'maestro_web_session');
define('WEB_SESSION_COOKIE_PATH', '/web/');
define('WEB_DEBUG_LOG_PATH', __DIR__ . '/debug.log');

function appendWebDebugLog(string $message): void
{
    $payload = '[' . date('Y-m-d H:i:s') . '] ' . trim($message) . PHP_EOL;
    @file_put_contents(WEB_DEBUG_LOG_PATH, $payload, FILE_APPEND | LOCK_EX);
}

function ensureWebSessionsTable(SQLite3 $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS web_sessions (
            instance_id TEXT NOT NULL,
            cookie_id TEXT NOT NULL,
            email TEXT NOT NULL,
            remote_jid TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(instance_id, cookie_id)
        )
    ");
}

function persistWebSessionLink(string $instanceId, string $cookieId, string $email, string $remoteJid): bool
{
    if ($instanceId === '' || $cookieId === '' || $email === '' || $remoteJid === '') {
        return false;
    }
    $db = openInstanceDatabase(false);
    if (!$db) {
        appendWebDebugLog("persistWebSessionLink failed to open database");
        return false;
    }
    ensureWebSessionsTable($db);
    $stmt = $db->prepare("
        INSERT INTO web_sessions (instance_id, cookie_id, email, remote_jid)
        VALUES (:instance, :cookie, :email, :remote)
        ON CONFLICT(instance_id, cookie_id) DO UPDATE SET
            email = excluded.email,
            remote_jid = excluded.remote_jid,
            updated_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        appendWebDebugLog("persistWebSessionLink failed to prepare statement");
        $db->close();
        return false;
    }
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    $stmt->bindValue(':cookie', $cookieId, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':remote', $remoteJid, SQLITE3_TEXT);
    $result = $stmt->execute();
    $stmt->close();
    $db->close();
    if ($result === false) {
        appendWebDebugLog("persistWebSessionLink execute failed");
        return false;
    }
    return true;
}

function getWebSessionId(): string
{
    $cookieName = WEB_SESSION_COOKIE_NAME;
    $cookie = $_COOKIE[$cookieName] ?? '';
    if (!is_string($cookie) || $cookie === '') {
        try {
            $cookie = bin2hex(random_bytes(16));
        } catch (Exception $e) {
            $cookie = bin2hex(hash('sha256', uniqid('', true)));
        }
        $options = [
            'expires' => time() + 365 * 86400,
            'path' => WEB_SESSION_COOKIE_PATH,
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax'
        ];
        setcookie($cookieName, $cookie, $options);
        $_COOKIE[$cookieName] = $cookie;
    }
    return $cookie;
}

function respondJson($payload, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!function_exists('buildPublicBaseUrl')) {
    function buildPublicBaseUrl(string $basePath): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $normalized = rtrim($basePath, '/');
        return "{$scheme}://{$host}{$normalized}";
    }
}

function buildNodePath(string $base, array $query = []): string
{
    if (empty($query)) {
        return $base;
    }
    return $base . '?' . http_build_query($query);
}

function proxyNodeRequest(array $instance, string $path, string $method = 'GET', ?string $body = null, array $extraHeaders = [])
{
    $url = "http://127.0.0.1:{$instance['port']}{$path}";
    appendWebDebugLog("proxyNodeRequest {$method} {$url} body=" . ($body !== null ? substr($body, 0, 512) : ''));
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (!empty($extraHeaders)) {
        $headers = array_merge($headers, $extraHeaders);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        respondJson(['ok' => false, 'error' => 'Erro ao conectar ao serviço interno', 'detail' => $error], 502);
    }

    appendWebDebugLog("proxyNodeRequest response code={$httpCode} payload=" . substr($response ?: '', 0, 512));
    if ($httpCode >= 400) {
        $detailText = strip_tags($response ?: 'Sem detalhes');
        respondJson([
            'ok' => false,
            'error' => 'Serviço interno retornou erro',
            'status' => $httpCode,
            'detail' => $detailText
        ], max($httpCode, 400));
    }
    http_response_code($httpCode ?: 200);
    header('Content-Type: application/json; charset=utf-8');
    echo $response ?: json_encode(['ok' => true]);
    exit;
}

$instanceId = $_GET['id'] ?? null;
if (!$instanceId) {
    http_response_code(404);
    echo 'Instância inválida';
    exit;
}

$instance = loadInstanceRecordFromDatabase($instanceId);
if (!$instance) {
    http_response_code(404);
    echo 'Instância não encontrada';
    exit;
}

$webSessionId = getWebSessionId();
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
appendWebDebugLog("web_session start instance={$instanceId} session={$webSessionId} ip={$clientIp}");

if (isset($_GET['ajax_messages'])) {
    $remoteJid = $_GET['remote'] ?? '';
    if (!$remoteJid) {
        respondJson(['ok' => false, 'error' => 'remote JID é obrigatório'], 400);
    }
    appendWebDebugLog("ajax_messages remote={$remoteJid} session={$webSessionId} ip={$clientIp}");
    $path = buildNodePath("/api/messages/{$instanceId}/" . rawurlencode($remoteJid), ['limit' => 60]);
    proxyNodeRequest($instance, $path, 'GET');
}

if (isset($_GET['ajax_web_link']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $email = trim((string)($payload['email'] ?? ''));
    $remote = trim((string)($payload['remote_jid'] ?? ''));
    $cookieId = $_COOKIE[WEB_SESSION_COOKIE_NAME] ?? '';
    appendWebDebugLog("ajax_web_link cookie={$cookieId} email={$email} remote={$remote} session={$webSessionId} ip={$clientIp}");
    if ($email === '' || $remote === '' || $cookieId === '') {
        respondJson(['ok' => false, 'error' => 'E-mail, Remote JID e cookie são obrigatórios'], 400);
    }
    $linked = persistWebSessionLink($instanceId, $cookieId, $email, $remote);
    respondJson(['ok' => $linked]);
}

if (isset($_GET['ajax_web_send']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $payload = array_map(fn($value) => $value ?? null, $payload);
    $remoteForLog = trim((string)($payload['remote_jid'] ?? ''));
    $messageForLog = trim((string)($payload['message'] ?? ''));
    $payloadPreview = substr(json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 1024);
    appendWebDebugLog("ajax_web_send session={$webSessionId} ip={$clientIp} remote={$remoteForLog} msg_len=" . mb_strlen($messageForLog) . " payload={$payloadPreview}");
    $path = "/api/web/{$instanceId}/message";
    proxyNodeRequest($instance, $path, 'POST', json_encode($payload));
}
$pageTitle = $instance['name'] ? "Acesso Web — " . $instance['name'] : 'Acesso Web';
$webAccessUrl = buildPublicBaseUrl('/web/') . '?id=' . urlencode($instanceId);
$instanceLabel = htmlspecialchars($instance['name'] ?? $instanceId, ENT_QUOTES, 'UTF-8');
$escapedWebUrl = htmlspecialchars($webAccessUrl, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --wa-bg: #e5ddd5;
      --wa-bubble-in: #ffffff;
      --wa-bubble-out: #dcf8c6;
      --wa-chat-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
    }
    * {
      box-sizing: border-box;
    }
    body {
      margin: 0;
      font-family: "Inter", "Segoe UI", sans-serif;
      background: var(--wa-bg);
      color: #0f172a;
    }
    .page-shell {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 2rem 1rem 3rem;
    }
    .wa-header {
      width: min(960px, 100%);
      max-width: 960px;
      background: #fff;
      border-radius: 20px;
      box-shadow: var(--wa-chat-shadow);
      border: 1px solid rgba(148, 163, 184, 0.25);
      padding: 1.25rem 1.5rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .wa-title {
      display: flex;
      align-items: center;
      gap: 0.85rem;
    }
    .wa-avatar {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      background: #128c7e;
      box-shadow: inset 0 0 0 3px rgba(255,255,255,0.4);
    }
    .wa-name {
      font-weight: 700;
    }
    .wa-status {
      font-size: 0.85rem;
      color: #10b981;
    }
    .wa-link {
      text-align: right;
      font-size: 0.9rem;
      color: #475569;
    }
    .wa-link a {
      color: #128c7e;
      font-weight: 600;
      display: block;
    }
    .wa-chat-shell {
      width: min(960px, 100%);
      display: flex;
      gap: 1rem;
      flex-wrap: wrap;
    }
    .wa-contact-info,
    .wa-conversation {
      background: #fff;
      border-radius: 20px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      box-shadow: var(--wa-chat-shadow);
      padding: 1.25rem;
      width: 100%;
    }
    .wa-identity-form {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      margin-top: 0.75rem;
    }
    .wa-identity-form input {
      flex: 1;
      min-width: 260px;
      border-radius: 50px;
      border: 1px solid #d1d5db;
      padding: 0.65rem 1rem;
      font-size: 0.9rem;
    }
    .wa-identity-actions {
      display: flex;
      gap: 0.5rem;
    }
    .wa-btn {
      border-radius: 50px;
      border: none;
      padding: 0.65rem 1.3rem;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
    }
    .wa-btn-primary {
      background: #128c7e;
      color: #fff;
    }
    .wa-btn-outline {
      background: #fff;
      border: 1px solid #d1d5db;
      color: #475569;
    }
    .wa-remote-display {
      margin-top: 0.85rem;
      font-size: 0.85rem;
      color: #475569;
    }
    .wa-status-line {
      font-size: 0.85rem;
      color: #6b7280;
      margin-top: 0.4rem;
    }
    .wa-messages {
      min-height: 340px;
      max-height: 530px;
      background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png');
      background-size: cover;
      padding: 1.5rem;
      border-radius: 20px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      overflow-y: auto;
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }
    .wa-placeholder {
      font-size: 0.9rem;
      color: #4b5563;
      text-align: center;
      padding: 1rem 0;
    }
    .wa-row {
      display: flex;
    }
    .wa-row.outgoing {
      justify-content: flex-end;
    }
    .wa-bubble {
      max-width: 80%;
      padding: 0.75rem 1rem;
      font-size: 0.95rem;
      line-height: 1.4;
      border-radius: 0 16px 16px 16px;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
    }
    .wa-row.outgoing .wa-bubble {
      background: var(--wa-bubble-out);
      border-radius: 16px 0 16px 16px;
      color: #1f2937;
    }
    .wa-row.incoming .wa-bubble {
      background: var(--wa-bubble-in);
      border-radius: 0 16px 16px 16px;
    }
    .wa-meta {
      margin-top: 0.4rem;
      font-size: 0.75rem;
      color: rgba(15, 23, 42, 0.6);
    }
    .wa-composer {
      margin-top: 1rem;
      display: flex;
      gap: 0.75rem;
      align-items: center;
    }
    .wa-composer textarea {
      flex: 1;
      border-radius: 28px;
      border: 1px solid #d1d5db;
      padding: 0.85rem 1.2rem;
      min-height: 70px;
      font-size: 0.95rem;
      resize: none;
      font-family: inherit;
    }
    .wa-composer button {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      border: none;
      background: #128c7e;
      color: #fff;
      font-size: 1.2rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 12px 20px rgba(18, 140, 126, 0.35);
    }
    .wa-send-status {
      margin-top: 0.5rem;
      font-size: 0.85rem;
      color: #4b5563;
      min-height: 1em;
    }
    @media (max-width: 840px) {
      .wa-chat-shell {
        flex-direction: column;
      }
      .wa-header {
        flex-direction: column;
        align-items: flex-start;
      }
      .wa-link {
        text-align: left;
      }
    }
    .api-docs {
      width: min(960px, 100%);
      background: #fff;
      border-radius: 20px;
      border: 1px solid rgba(148, 163, 184, 0.25);
      box-shadow: var(--wa-chat-shadow);
      padding: 1.25rem 1.5rem;
      margin-bottom: 1.5rem;
    }
    .api-docs h2 {
      margin-top: 0;
      color: #128c7e;
    }
    .api-docs h3 {
      color: #475569;
    }
    .api-docs pre {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 1rem;
      overflow-x: auto;
      font-size: 0.9rem;
    }
    .api-docs code {
      font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    }
  </style>
</head>
<body>
  <div class="page-shell">
      <div class="wa-header">
        <div class="wa-title">
          <div class="wa-avatar"></div>
          <div>
            <div class="wa-name">Conversas IA</div>
            <div class="wa-status">Online</div>
          </div>
        </div>
        <div class="wa-link">
          <span>Instância <?= $instanceLabel ?></span>
          <a href="<?= $escapedWebUrl ?>" target="_blank" rel="noopener noreferrer">Abrir em nova aba</a>
        </div>
      </div>

    <div class="wa-chat-shell">
      <div class="wa-contact-info">
        <p class="wa-info-text">Converse como no WhatsApp. A IA pedirá o e-mail quando necessário.</p>
        <div class="wa-remote-display">
          Seu ID temporário: <span id="webRemoteIdDisplay">carregando...</span>
        </div>
        <div id="identityMessage" class="wa-status-line">Conexão segura e anônima.</div>
      </div>

      <div class="wa-conversation">
        <div id="messagesList" class="wa-messages">
          <div class="wa-placeholder">Carregando conversa...</div>
        </div>
        <div class="wa-send-status" id="webStatus">&nbsp;</div>
        <form id="webSendForm" class="wa-composer">
          <textarea id="messageInput" placeholder="Digite uma mensagem..."></textarea>
          <button type="submit" id="webSendButton">
            <i class="fas fa-paper-plane"></i>
          </button>
        </form>
      </div>
    </div>
</div>

<div class="api-docs">
<h2>API Documentation</h2>
<h3>Sending Text Messages</h3>
<p><strong>Endpoint:</strong> POST /send-message</p>
<p><strong>Example cURL:</strong></p>
<pre><code>curl -X POST "http://localhost:3010/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "558586030781",
    "message": "Test message"
  }'</code></pre>
<h3>Sending Images via URL</h3>
<p><strong>Endpoint:</strong> POST /send-message</p>
<p><strong>Example cURL:</strong></p>
<pre><code>curl -X POST "http://localhost:3010/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "558586030781",
    "image_url": "https://example.com/image.jpg",
    "caption": "Veja esta imagem!"
  }'</code></pre>
<h3>Sending Images via Base64</h3>
<p><strong>Endpoint:</strong> POST /send-message</p>
<p><strong>Example cURL:</strong></p>
<pre><code>curl -X POST "http://localhost:3010/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "558586030781",
    "image_base64": "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD...",
    "caption": "Imagem em base64"
  }'</code></pre>
</div>


  <script>
    (function () {
      const instanceId = <?= json_encode($instanceId) ?>;
      const webStatus = document.getElementById('webStatus');
      const messagesList = document.getElementById('messagesList');
      const messageInput = document.getElementById('messageInput');
      const webSendForm = document.getElementById('webSendForm');
      const webRemoteDisplay = document.getElementById('webRemoteIdDisplay');
      const identityMessage = document.getElementById('identityMessage');
      const webSessionId = <?= json_encode($webSessionId) ?>;
      const storageKeys = {
        remote: 'maestroWebRemoteId',
        session: 'maestroWebSessionId'
      };
      let currentRemoteJid = null;
      let autoRefreshTimer = null;

      function getSessionRemote() {
        // Use cookie-based session ID as browser fingerprint for persistence
        return `${webSessionId}@lid`;
      }

      function refreshRemoteId() {
        // Always use the cookie-based session ID for consistency across sessions
        currentRemoteJid = getSessionRemote();
        webRemoteDisplay.textContent = currentRemoteJid;
        identityMessage.textContent = 'Sessão iniciada. A IA perguntará o e-mail quando necessário.';
      }

      function buildAjaxUrl(params = {}) {
        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('id', instanceId);
        Object.entries(params).forEach(([key, value]) => {
          if (value === undefined || value === null) {
            return;
          }
          url.searchParams.set(key, value);
        });
        return url.toString();
      }

      async function fetchJson(url, options = {}) {
        const response = await fetch(url, { credentials: 'include', ...options });
        const raw = await response.text();
        let payload = null;
        try {
          payload = JSON.parse(raw);
        } catch {
          payload = null;
        }
        return { response, payload, raw };
      }

      function setStatus(message, isError = false) {
        webStatus.textContent = message;
        webStatus.style.color = isError ? '#dc2626' : '#475569';
      }

      async function linkEmailAddress(email) {
        if (!email) {
          throw new Error('E-mail obrigatório para link');
        }
        if (!currentRemoteJid) {
          throw new Error('Remote JID desconhecido');
        }
        const { response, payload, raw } = await fetchJson(buildAjaxUrl({ ajax_web_link: '1' }), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ email, remote_jid: currentRemoteJid, session_id: webSessionId })
        });
        if (!response.ok) {
          throw new Error(payload?.error || raw || 'Falha ao linkar email');
        }
        if (!payload?.ok) {
          throw new Error(payload?.error || raw || 'Falha ao linkar email');
        }
        return payload;
      }

      window.linkWebEmail = linkEmailAddress;

      function escapeHtml(value) {
        return String(value || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function formatTimestamp(value) {
        if (!value) return '';
        const date = new Date(value);
        if (!date || Number.isNaN(date.getTime())) {
          return '';
        }
        return date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
      }

      let emailPromptShown = false;
      function renderMessages(items) {
        if (!messagesList) return;
        if (!items || !items.length) {
          if (!emailPromptShown) {
            const promptTime = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
            messagesList.innerHTML = `
              <div class="wa-row incoming">
                <div class="wa-bubble incoming">
                  Olá! Antes de continuarmos, você pode me dizer seu e-mail para sincronizar com o WhatsApp?
                  <div class="wa-meta">${promptTime}</div>
                </div>
              </div>
            `;
            emailPromptShown = true;
          } else {
            messagesList.innerHTML = '<div class="wa-placeholder">Nenhuma mensagem ainda.</div>';
          }
          return;
        }
        messagesList.innerHTML = items.map(msg => {
          const isAssistant = (msg.role || '').toLowerCase() === 'assistant';
          const directionClass = isAssistant ? 'wa-row outgoing' : 'wa-row incoming';
          const bubbleClass = isAssistant ? 'wa-bubble outgoing' : 'wa-bubble incoming';
          const content = escapeHtml(msg.content || '(sem texto)');
          const time = formatTimestamp(msg.timestamp);
          return `
            <div class="${directionClass}">
              <div class="${bubbleClass}">
                ${content}
                <div class="wa-meta">${time}</div>
              </div>
            </div>
          `;
        }).join('');
        messagesList.scrollTop = messagesList.scrollHeight;
      }

      async function loadMessages(showStatus = true) {
        if (!currentRemoteJid) return;
        if (showStatus) {
          setStatus('Atualizando conversa...');
        }
        try {
        const { response, payload, raw } = await fetchJson(buildAjaxUrl({
          ajax_messages: '1',
          remote: currentRemoteJid
        }));
        if (!response.ok) {
          throw new Error(payload?.error || raw || 'Falha ao carregar mensagens (não JSON)');
        }
        if (!payload?.ok) {
          throw new Error(payload?.error || raw || 'Falha ao carregar mensagens');
        }
          renderMessages(payload.messages || []);
        setStatus('Última atualização há instantes.');
      } catch (error) {
        console.error('loadMessages error', error);
          setStatus('Erro ao carregar mensagens: ' + (error.message || 'indisponível'), true);
        }
      }

      function mapSendErrorMessage(error) {
        const message = (error?.message || '').toLowerCase();
        if (message.includes('respostas automáticas estão desabilitadas')) {
          return 'Erro ID #01: Este chat esta desativado. Ligue as Respostas automáticas';
        }
        return null;
      }

      async function sendMessage(text) {
        const payload = {
          remote_jid: currentRemoteJid,
          message: text
        };
        const storedEmail = (localStorage.getItem(storageKeys.email) || '').trim();
        if (storedEmail) {
          payload.email = storedEmail;
        }
        const { response, payload: sendPayload, raw } = await fetchJson(buildAjaxUrl({ ajax_web_send: '1' }), {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });
        if (!response.ok) {
          throw new Error(sendPayload?.error || raw || 'Falha no envio');
        }
        if (!sendPayload?.ok) {
          throw new Error(sendPayload?.error || raw || 'Falha no envio');
        }
        return sendPayload;
      }

      webSendForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const text = messageInput.value.trim();
        if (!text) {
          return;
        }
        webSendForm.querySelector('button').disabled = true;
        setStatus('Enviando...');
        try {
          await sendMessage(text);
          messageInput.value = '';
          loadMessages(true);
        } catch (error) {
          console.error('sendMessage error', error);
          const friendlyMessage = mapSendErrorMessage(error);
          const text = friendlyMessage || 'Erro no envio: ' + (error.message || 'recarregue a página');
          setStatus(text, true);
        } finally {
          webSendForm.querySelector('button').disabled = false;
        }
      });

      // Optionally, we allow refreshing the remote ID by clearing storage via devtools if needed

      refreshRemoteId();
      loadMessages(true);
      autoRefreshTimer = setInterval(() => loadMessages(false), 9000);

      window.addEventListener('beforeunload', () => {
        if (autoRefreshTimer) {
          clearInterval(autoRefreshTimer);
        }
      });
    })();
  </script>
</body>
</html>
