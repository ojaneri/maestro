<?php
/**
 * QR Proxy for WhatsApp Instances
 * Secure proxy for retrieving QR codes from the SQLite registry
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to client

// Base headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Configuration
define('LOG_FILE', __DIR__ . '/logs/qr-proxy.log');
define('DEV_MODE', file_exists(__DIR__ . '/debug'));
define('MAX_QR_LIFETIME', 300); // 5 minutes default TTL
define('TOKEN_DIR', __DIR__ . '/storage/qr_tokens');

require_once __DIR__ . '/instance_data.php';

// Ensure log directory exists
$logDir = dirname(LOG_FILE);
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

/**
 * Log message to file
 */
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logEntry = "[{$timestamp}] [{$clientIp}] {$message}" . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function sanitize_token($token) {
    $token = trim((string) $token);
    if ($token === '') {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $token)) {
        return '';
    }
    return $token;
}

function load_token_payload($token) {
    if ($token === '') {
        return null;
    }
    $path = TOKEN_DIR . '/' . $token . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $expiresAt = isset($data['expires_at_ms']) ? (int) $data['expires_at_ms'] : 0;
    if ($expiresAt > 0 && $expiresAt < (int) (microtime(true) * 1000)) {
        @unlink($path);
        return null;
    }
    return $data;
}

function rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Validate and sanitize instance ID
 */
function validate_instance_id($id) {
    // Only allow alphanumeric characters, hyphens, and underscores (1-64 chars)
    if (!preg_match('/^[\w-]{1,64}$/', $id)) {
        return false;
    }
    return trim($id);
}

/**
 * Calculate QR freshness based on last_seen and ttl
 */
function is_qr_fresh($lastSeen, $ttl) {
    if (!$lastSeen || !$ttl) return false;
    
    $lastSeenTime = strtotime($lastSeen);
    if ($lastSeenTime === false) return false;
    
    $currentTime = time();
    $elapsed = $currentTime - $lastSeenTime;
    
    return $elapsed <= $ttl;
}

/**
 * Fetch QR code data from whatsapp-server-intelligent.js
 */
function fetch_real_qr_from_server($port) {
    if (!$port) return null;
    
    $url = "http://localhost:{$port}/qr";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: QR-Proxy/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        log_message("Failed to fetch QR from server on port {$port}: " . ($error ?: "HTTP {$httpCode}"));
        return null;
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['qr'])) {
        log_message("Invalid QR response from server on port {$port}: " . json_last_error_msg());
        return null;
    }
    
    return $data['qr'];
}

/**
 * Fetch instance status from whatsapp-server-intelligent.js
 */
function fetch_instance_status($port) {
    if (!$port) return null;
    
    $url = "http://localhost:{$port}/status";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: QR-Proxy/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$port}/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $healthResponse = curl_exec($ch);
    $healthHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Get detailed status if server is responsive
    if ($response !== false && $httpCode === 200) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
    }
    
    // Fall back to health check data
    if ($healthResponse !== false && $healthHttpCode === 200) {
        $healthData = json_decode($healthResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $healthData;
        }
    }
    
    return null;
}

/**
 * Generate QR PNG from text using GD library
 */
function generate_qr_png($qrText) {
    if (empty($qrText) || !function_exists('imagecreatefromstring')) {
        return false;
    }
    
    try {
        // Create a simple QR-like pattern using GD
        // This is a basic implementation - for production, use a proper QR library
        
        $width = 300;
        $height = 300;
        $image = imagecreate($width, $height);
        
        // Colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 128, 128, 128);
        
        // Fill background
        imagefill($image, 0, 0, $white);
        
        // Create a simple pattern based on QR text hash
        $hash = md5($qrText);
        $cellSize = 8;
        $gridSize = min(37, floor(min($width, $height) / $cellSize));
        
        // Draw border
        imagerectangle($image, 0, 0, $width-1, $height-1, $black);
        
        // Draw finder patterns (corners)
        $cornerSize = 3;
        // Top-left
        draw_finder_pattern($image, 1, 1, $cornerSize, $black, $white);
        // Top-right  
        draw_finder_pattern($image, $gridSize - $cornerSize - 1, 1, $cornerSize, $black, $white);
        // Bottom-left
        draw_finder_pattern($image, 1, $gridSize - $cornerSize - 1, $cornerSize, $black, $white);
        
        // Draw data pattern based on hash
        for ($i = 0; $i < strlen($hash); $i++) {
            $char = $hash[$i];
            for ($j = 0; $j < 4; $j++) {
                $bit = (hexdec($char) >> $j) & 1;
                $x = ($i * 4 + $j) % $gridSize;
                $y = floor(($i * 4 + $j) / $gridSize);
                
                if ($x >= $cornerSize + 1 && $x < $gridSize - $cornerSize - 1 && 
                    $y >= $cornerSize + 1 && $y < $gridSize - $cornerSize - 1) {
                    $color = $bit ? $black : $white;
                    $pixelX = $x * $cellSize;
                    $pixelY = $y * $cellSize;
                    imagefilledrectangle($image, $pixelX, $pixelY, 
                                       $pixelX + $cellSize - 1, $pixelY + $cellSize - 1, $color);
                }
            }
        }
        
        // Add QR text as title
        imagestring($image, 3, 10, $height - 25, 'QR for: ' . substr($qrText, 0, 20), $black);
        
        // Capture output
        ob_start();
        imagepng($image);
        $imageData = ob_get_contents();
        ob_end_clean();
        
        // Clean up
        imagedestroy($image);
        
        return base64_encode($imageData);
        
    } catch (Exception $e) {
        log_message("QR PNG generation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Draw finder pattern for QR code
 */
function draw_finder_pattern($image, $x, $y, $size, $black, $white) {
    // Outer black square
    imagerectangle($image, $x * 8, $y * 8, 
                  ($x + $size) * 8 - 1, ($y + $size) * 8 - 1, $black);
    
    // Inner white square
    imagerectangle($image, ($x + 1) * 8, ($y + 1) * 8,
                  ($x + $size - 1) * 8 - 1, ($y + $size - 1) * 8 - 1, $white);
    
    // Inner black square
    imagerectangle($image, ($x + 2) * 8, ($y + 2) * 8,
                  ($x + $size - 2) * 8 - 1, ($y + $size - 2) * 8 - 1, $black);
}

/**
 * Render QR reconnect page using token.
 */
function render_qr_token_page($instanceId, $instanceName, $expiresAt, $token, $errorTitle, $errorMessage) {
    header('Content-Type: text/html; charset=utf-8');
    $safeInstance = htmlspecialchars($instanceName ?: $instanceId, ENT_QUOTES, 'UTF-8');
    $safeExpires = htmlspecialchars((string) $expiresAt, ENT_QUOTES, 'UTF-8');
    $safeErrorTitle = htmlspecialchars((string) $errorTitle, ENT_QUOTES, 'UTF-8');
    $safeErrorMessage = htmlspecialchars((string) $errorMessage, ENT_QUOTES, 'UTF-8');
    $jsonInstance = json_encode((string) $instanceId);
    $jsonToken = json_encode((string) $token);
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conectar WhatsApp</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #eef4f3;
      --card: #ffffff;
      --accent: #0f766e;
      --text: #0f1f1e;
      --muted: #5b6d6b;
      --border: #d9e4e3;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "DM Sans", system-ui, -apple-system, sans-serif;
      background: radial-gradient(circle at top, rgba(15, 118, 110, 0.08), transparent 55%),
                  radial-gradient(circle at 20% 20%, rgba(245, 158, 11, 0.12), transparent 40%),
                  var(--bg);
      color: var(--text);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 18px;
    }
    .card {
      max-width: 720px;
      width: 100%;
      background: var(--card);
      border-radius: 24px;
      box-shadow: 0 30px 60px rgba(15, 118, 110, 0.15);
      padding: 36px;
      border: 1px solid var(--border);
      position: relative;
      overflow: hidden;
    }
    .header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 24px;
    }
    .logo {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      background: #e6f2f1;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      color: var(--accent);
      overflow: hidden;
    }
    .logo img {
      width: 34px;
      height: 34px;
      object-fit: contain;
    }
    h1 {
      font-family: "Space Grotesk", system-ui, sans-serif;
      font-size: 26px;
      margin: 0;
    }
    .subtitle {
      color: var(--muted);
      font-size: 15px;
      margin-top: 6px;
    }
    .qr-box {
      margin: 28px 0;
      padding: 24px;
      border-radius: 20px;
      border: 1px dashed var(--border);
      background: #f8fbfb;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
      min-height: 320px;
      justify-content: center;
    }
    .qr-box img {
      width: 260px;
      height: 260px;
      object-fit: contain;
      border-radius: 14px;
      display: none;
    }
    .status {
      text-align: center;
      font-size: 15px;
      color: var(--muted);
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 16px;
    }
    .btn {
      border: none;
      padding: 12px 18px;
      border-radius: 12px;
      font-family: "Space Grotesk", system-ui, sans-serif;
      font-weight: 600;
      cursor: pointer;
      background: var(--accent);
      color: #ffffff;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .btn.secondary {
      background: #ffffff;
      color: var(--accent);
      border: 1px solid var(--border);
    }
    .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 16px rgba(15, 118, 110, 0.18);
    }
    .meta {
      margin-top: 22px;
      font-size: 13px;
      color: #6e7f7e;
      display: flex;
      flex-wrap: wrap;
      gap: 12px 24px;
    }
    .meta span {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .pill {
      background: #eef8f7;
      color: var(--accent);
      padding: 3px 10px;
      border-radius: 999px;
      font-weight: 600;
    }
    .error {
      border-left: 4px solid #d64545;
      padding-left: 12px;
      color: #b42318;
      margin-top: 18px;
    }
    @media (max-width: 640px) {
      .card { padding: 26px; }
      .qr-box img { width: 220px; height: 220px; }
    }
    .status-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
      margin-top: 20px;
      padding: 16px;
      border-radius: 16px;
      background: #f7fbfb;
      border: 1px solid var(--border);
      font-size: 13px;
      color: #4b615f;
    }
    .status-item strong {
      display: block;
      font-size: 11px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #7a8b89;
      margin-bottom: 4px;
    }
    .status-item span {
      color: #0f1f1e;
      font-weight: 600;
      word-break: break-word;
    }
    .note {
      margin-top: 12px;
      font-size: 13px;
      color: #566866;
    }
    .overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.55);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      z-index: 50;
    }
    .overlay.active {
      display: flex;
    }
    .overlay-card {
      max-width: 520px;
      width: 100%;
      background: #ffffff;
      border-radius: 18px;
      padding: 22px;
      box-shadow: 0 24px 48px rgba(15, 118, 110, 0.2);
      border: 1px solid var(--border);
    }
    .overlay-card h2 {
      font-family: "Space Grotesk", system-ui, sans-serif;
      font-size: 18px;
      margin: 0 0 8px 0;
      color: var(--text);
    }
    .overlay-card p {
      font-size: 14px;
      color: #4b615f;
      line-height: 1.5;
      margin: 0 0 14px 0;
    }
    .overlay-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 14px;
    }
    .overlay-check {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      color: #45605f;
      margin-top: 10px;
    }
    .countdown {
      font-weight: 700;
      color: var(--accent);
    }
    .connected-card {
      display: none;
      margin: 28px 0 10px;
      padding: 26px;
      border-radius: 20px;
      border: 1px solid var(--border);
      background: linear-gradient(135deg, #ecfeff, #f0fdf4);
      position: relative;
      overflow: hidden;
    }
    .connected-card.active {
      display: block;
    }
    .celebration-visual {
      display: flex;
      align-items: center;
      gap: 18px;
    }
    .celebration-badge {
      width: 88px;
      height: 88px;
      border-radius: 24px;
      background: #ffffff;
      display: grid;
      place-items: center;
      box-shadow: 0 14px 24px rgba(15, 118, 110, 0.18);
    }
    .celebration-text h3 {
      font-family: "Space Grotesk", system-ui, sans-serif;
      font-size: 20px;
      margin: 0 0 6px 0;
      color: #0f1f1e;
    }
    .celebration-text p {
      margin: 0;
      color: #3b5653;
      font-size: 14px;
    }
    .confetti {
      position: absolute;
      inset: 0;
      pointer-events: none;
    }
    .confetti span {
      position: absolute;
      width: 10px;
      height: 18px;
      opacity: 0.8;
      border-radius: 4px;
      animation: confetti-fall 2.6s ease-in-out infinite;
    }
    .confetti span:nth-child(1) { left: 8%; background: #f97316; animation-delay: 0s; }
    .confetti span:nth-child(2) { left: 18%; background: #22c55e; animation-delay: 0.2s; }
    .confetti span:nth-child(3) { left: 32%; background: #06b6d4; animation-delay: 0.4s; }
    .confetti span:nth-child(4) { left: 46%; background: #facc15; animation-delay: 0.1s; }
    .confetti span:nth-child(5) { left: 60%; background: #fb7185; animation-delay: 0.35s; }
    .confetti span:nth-child(6) { left: 74%; background: #a855f7; animation-delay: 0.5s; }
    .confetti span:nth-child(7) { left: 86%; background: #34d399; animation-delay: 0.25s; }
    .sparkle {
      position: absolute;
      right: 24px;
      bottom: 20px;
      width: 120px;
      height: 120px;
      opacity: 0.45;
      animation: pulse 2.4s ease-in-out infinite;
    }
    @keyframes confetti-fall {
      0% { transform: translateY(-20px) rotate(0deg); opacity: 0; }
      30% { opacity: 0.9; }
      100% { transform: translateY(140px) rotate(220deg); opacity: 0; }
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); opacity: 0.35; }
      50% { transform: scale(1.08); opacity: 0.65; }
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="header">
      <div class="logo">
        <img src="./assets/maestro-logo.png" alt="Maestro">
      </div>
      <div>
        <h1>Conectar WhatsApp</h1>
        <div class="subtitle">Escaneie o QR Code abaixo para restabelecer a instância.</div>
      </div>
    </div>
HTML;

    if ($errorMessage) {
        echo <<<HTML
    <div class="error">
      <strong>{$safeErrorTitle}</strong><br>
      {$safeErrorMessage}
    </div>
HTML;
    } else {
        echo <<<HTML
    <div class="qr-box" id="qrBox">
      <img id="qrImage" alt="QR Code de conexão">
      <div class="status" id="qrStatus">Carregando QR Code...</div>
    </div>

    <div class="connected-card" id="connectedCard">
      <div class="confetti" aria-hidden="true">
        <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
      </div>
      <div class="celebration-visual">
        <div class="celebration-badge">
          <svg width="52" height="52" viewBox="0 0 64 64" fill="none" aria-hidden="true">
            <circle cx="32" cy="32" r="28" fill="#0f766e"/>
            <path d="M22 33.5l6.5 6.5L42 26" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="celebration-text">
          <h3>Conectado com sucesso!</h3>
          <p>WhatsApp está online e pronto para atender. Pode fechar esta página.</p>
        </div>
      </div>
      <svg class="sparkle" viewBox="0 0 120 120" fill="none">
        <path d="M60 0l8 24 24 8-24 8-8 24-8-24-24-8 24-8 8-24z" fill="#0f766e"/>
      </svg>
    </div>

    <div class="actions" id="actionsBar">
      <button class="btn" id="refreshBtn" type="button">Atualizar QR</button>
      <button class="btn secondary" id="resetBtn" type="button">Reiniciar sessão</button>
    </div>

    <div class="status-grid" id="statusGrid" aria-live="polite">
      <div class="status-item"><strong>Status</strong><span id="statusConnection">-</span></div>
      <div class="status-item"><strong>Conectado</strong><span id="statusConnected">-</span></div>
      <div class="status-item"><strong>QR ativo</strong><span id="statusHasQr">-</span></div>
      <div class="status-item"><strong>Último erro</strong><span id="statusError">-</span></div>
    </div>
    <div class="note" id="statusNote">Se o QR não aparecer, reinicie a sessão e aguarde alguns minutos.</div>

    <div class="meta">
      <span>Instância: <span class="pill">{$safeInstance}</span></span>
      <span>Token válido até: <span>{$safeExpires}</span></span>
    </div>
HTML;
    }

    echo <<<HTML
  </div>
  <div class="overlay" id="resetOverlay">
    <div class="overlay-card">
      <h2>Antes de reiniciar</h2>
      <p>Saia de todas as conexões WhatsApp Web/desktop vinculadas a este número. Isso evita conflito de sessão e permite gerar um novo QR.</p>
      <div class="overlay-check">
        <input id="resetConfirm" type="checkbox">
        <label for="resetConfirm">Já desconectei todas as sessões</label>
      </div>
      <div class="overlay-actions">
        <button class="btn" id="confirmResetBtn" type="button">Confirmar e reiniciar</button>
        <button class="btn secondary" id="cancelResetBtn" type="button">Cancelar</button>
      </div>
    </div>
  </div>
HTML;

    if (!$errorMessage) {
        echo <<<HTML
  <script>
    const instanceId = {$jsonInstance};
    const token = {$jsonToken};
    const qrImg = document.getElementById('qrImage');
    const statusEl = document.getElementById('qrStatus');
    const refreshBtn = document.getElementById('refreshBtn');
    const resetBtn = document.getElementById('resetBtn');
    const qrBox = document.getElementById('qrBox');
    const actionsBar = document.getElementById('actionsBar');
    const connectedCard = document.getElementById('connectedCard');
    const statusConnection = document.getElementById('statusConnection');
    const statusConnected = document.getElementById('statusConnected');
    const statusHasQr = document.getElementById('statusHasQr');
    const statusError = document.getElementById('statusError');
    const statusNote = document.getElementById('statusNote');
    const resetOverlay = document.getElementById('resetOverlay');
    const resetConfirm = document.getElementById('resetConfirm');
    const confirmResetBtn = document.getElementById('confirmResetBtn');
    const cancelResetBtn = document.getElementById('cancelResetBtn');
    let polling = null;
    let resetDone = false;
    let countdownTimer = null;

    function setStatus(message, tone = 'info') {
      statusEl.textContent = message;
      statusEl.style.color = tone === 'error' ? '#b42318' : '#5b6d6b';
    }

    function showQrFromData(data) {
      if (data.qr_png) {
        qrImg.src = 'data:image/png;base64,' + data.qr_png;
      } else if (data.qr_text) {
        qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' + encodeURIComponent(data.qr_text);
      } else {
        qrImg.src = '';
      }
      qrImg.style.display = 'block';
      setStatus('Escaneie o QR com o WhatsApp.');
    }

    function updateStatusGrid(serverStatus) {
      if (!serverStatus) {
        statusConnection.textContent = '-';
        statusConnected.textContent = '-';
        statusHasQr.textContent = '-';
        statusError.textContent = '-';
        return;
      }
      const status = serverStatus.connectionStatus || serverStatus.status || 'desconhecido';
      statusConnection.textContent = status;
      statusConnected.textContent = serverStatus.whatsappConnected ? 'sim' : 'não';
      statusHasQr.textContent = serverStatus.hasQR ? 'sim' : 'não';
      statusError.textContent = serverStatus.lastConnectionError || '-';
      if (serverStatus.whatsappConnected) {
        statusNote.textContent = 'WhatsApp conectado. Não é necessário escanear um novo QR.';
        return;
      }
      if (serverStatus.lastConnectionError) {
        statusNote.textContent = 'A conexão caiu. Reinicie a sessão e aguarde o QR ser gerado.';
      } else if (!serverStatus.hasQR) {
        statusNote.textContent = 'QR ainda não disponível. Aguarde alguns minutos e tente novamente.';
      } else {
        statusNote.textContent = 'QR disponível. Escaneie para reconectar.';
      }
    }

    function setConnectedState(isConnected) {
      if (isConnected) {
        connectedCard?.classList.add('active');
        qrBox?.style.setProperty('display', 'none');
        actionsBar?.style.setProperty('display', 'none');
        if (polling) {
          clearInterval(polling);
          polling = null;
        }
        if (countdownTimer) {
          clearInterval(countdownTimer);
          countdownTimer = null;
        }
      } else {
        connectedCard?.classList.remove('active');
        qrBox?.style.removeProperty('display');
        actionsBar?.style.removeProperty('display');
      }
    }

    function showResetOverlay() {
      if (resetOverlay) {
        resetConfirm.checked = false;
        resetOverlay.classList.add('active');
      }
    }

    function hideResetOverlay() {
      resetOverlay?.classList.remove('active');
    }

    function startCountdown(seconds) {
      let remaining = seconds;
      if (countdownTimer) {
        clearInterval(countdownTimer);
      }
      statusNote.innerHTML = `Aguarde <span class="countdown">\${remaining}s</span> para o QR ser regenerado.`;
      countdownTimer = setInterval(() => {
        remaining -= 1;
        if (remaining <= 0) {
          clearInterval(countdownTimer);
          countdownTimer = null;
          statusNote.textContent = 'Tentando buscar o QR novamente...';
          fetchQr();
          return;
        }
        statusNote.innerHTML = `Aguarde <span class="countdown">\${remaining}s</span> para o QR ser regenerado.`;
      }, 1000);
    }

    async function resetSession() {
      if (resetDone) return;
      resetDone = true;
      setStatus('Reiniciando sessão...');
      try {
        const response = await fetch(`./qr-proxy.php?token=\${encodeURIComponent(token)}&reset=1`, {
          method: 'POST',
          headers: { 'Accept': 'application/json' }
        });
        const data = await response.json().catch(() => null);
        const message = data?.message || 'Sessão reiniciada. Aguarde alguns minutos para o QR aparecer.';
        setStatus(message);
        startCountdown(30);
      } catch (err) {
        setStatus('Falha ao reiniciar sessão. Tente novamente.', 'error');
      }
    }

    async function fetchQr() {
      setStatus('Buscando QR Code...');
      try {
        const response = await fetch(`./qr-proxy.php?id=\${encodeURIComponent(instanceId)}`, {
          headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' }
        });
        const data = await response.json();
        if (data?.server_status?.whatsappConnected) {
          qrImg.style.display = 'none';
          updateStatusGrid(data.server_status);
          setStatus('Conectado ao WhatsApp.');
          setConnectedState(true);
          return;
        }
        if (data && data.success) {
          showQrFromData(data);
          updateStatusGrid(data.server_status || null);
          setConnectedState(false);
          if (polling) {
            clearInterval(polling);
            polling = null;
          }
          return;
        }
        qrImg.style.display = 'none';
        updateStatusGrid(data?.server_status || null);
        setConnectedState(false);
        setStatus(data?.error || 'QR ainda não disponível. Aguarde alguns minutos e tente novamente.');
      } catch (err) {
        qrImg.style.display = 'none';
        setStatus('Erro ao consultar o QR. Tentando novamente...');
      }
    }

    refreshBtn?.addEventListener('click', fetchQr);
    resetBtn?.addEventListener('click', async () => {
      showResetOverlay();
    });
    confirmResetBtn?.addEventListener('click', async () => {
      if (!resetConfirm.checked) {
        setStatus('Confirme que desconectou todas as sessões antes de reiniciar.', 'error');
        return;
      }
      hideResetOverlay();
      resetDone = false;
      await resetSession();
    });
    cancelResetBtn?.addEventListener('click', () => {
      hideResetOverlay();
    });

    fetchQr();
    polling = setInterval(fetchQr, 5000);
  </script>
HTML;
    }

    echo <<<HTML
</body>
</html>
HTML;
    exit;
}

/**
 * Send JSON response with appropriate status code
 */
function send_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Main execution
 */
try {
    $token = sanitize_token($_GET['token'] ?? '');
    if ($token !== '') {
        $payload = load_token_payload($token);
        if ($payload && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_GET['reset'])) {
            $instanceId = (string) ($payload['instance_id'] ?? '');
            if ($instanceId === '') {
                json_response(['ok' => false, 'message' => 'Instância inválida.'], 400);
            }
            $authDir = __DIR__ . '/auth_' . $instanceId;
            rrmdir($authDir);
            $restartScript = __DIR__ . '/restart_instance.sh';
            if (is_file($restartScript)) {
                @exec('bash ' . escapeshellarg($restartScript) . ' ' . escapeshellarg($instanceId) . ' >/dev/null 2>&1');
            }
            json_response([
                'ok' => true,
                'message' => 'Sessão reiniciada. Aguarde alguns minutos para o QR ser gerado.'
            ], 200);
        }

        if (!$payload) {
            render_qr_token_page('', '', '', $token, 'Token inválido ou expirado',
                'Este link não está mais válido. Solicite um novo alerta para obter outro acesso.');
        }

        $instanceId = (string) ($payload['instance_id'] ?? '');
        $expiresAt = (string) ($payload['expires_at'] ?? '');
        $instanceName = '';
        if ($instanceId !== '') {
            $instance = loadInstanceRecordFromDatabase($instanceId);
            if ($instance) {
                $instanceName = $instance['name'] ?? '';
            }
        }
        render_qr_token_page($instanceId, $instanceName, $expiresAt, $token, '', '');
    }
} catch (Exception $e) {
    render_qr_token_page('', '', '', '', 'Erro inesperado', 'Não foi possível preparar a página do QR.');
}

try {
    log_message("QR proxy request: " . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . " " . ($_SERVER['REQUEST_URI'] ?? ''));

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        log_message("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        send_response([
            'success' => false,
            'error' => 'Method not allowed'
        ], 405);
    }

    $instanceId = validate_instance_id($_GET['id'] ?? '');
    if (!$instanceId) {
        log_message("Invalid instance ID provided: " . ($_GET['id'] ?? 'empty'));
        send_response([
            'success' => false,
            'error' => 'Invalid instance ID format'
        ], 400);
    }

    log_message("Processing QR request for instance: {$instanceId}");

    $instance = loadInstanceRecordFromDatabase($instanceId);
    if (!$instance) {
        log_message("Instance not found: {$instanceId}");
        send_response([
            'success' => false,
            'error' => 'Instance not found',
            'instance_id' => $instanceId
        ], 404);
    }

    $port = $instance['port'] ?? null;
    if (!$port) {
        log_message("Instance port not configured: {$instanceId}");
        send_response([
            'success' => false,
            'error' => 'Porta da instância não configurada',
            'instance_id' => $instanceId
        ], 500);
    }

    $realQrCode = fetch_real_qr_from_server($port);
    $serverStatus = fetch_instance_status($port);

    if ($realQrCode) {
        log_message("Successfully fetched real QR for instance: {$instanceId}");
        $qrApiUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($realQrCode);
        $ch = curl_init($qrApiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $qrImageData = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($qrImageData && !$curlError) {
            $qrPngBase64 = base64_encode($qrImageData);
            send_response([
                'success' => true,
                'instance_id' => $instanceId,
                'qr_png' => $qrPngBase64,
                'qr_text' => $realQrCode,
                'last_seen' => gmdate('Y-m-d\TH:i:s\Z'),
                'ttl' => MAX_QR_LIFETIME,
                'fresh' => true,
                'generated_at' => time(),
                'source' => 'baileys_real',
                'server_status' => $serverStatus
            ]);
        }

        send_response([
            'success' => true,
            'instance_id' => $instanceId,
            'qr_text' => $realQrCode,
            'last_seen' => gmdate('Y-m-d\TH:i:s\Z'),
            'ttl' => MAX_QR_LIFETIME,
            'fresh' => true,
            'generated_at' => time(),
            'source' => 'baileys_real_text_only',
            'server_status' => $serverStatus
        ]);
    }

    log_message("Unable to fetch QR from server for instance: {$instanceId}");
    send_response([
        'success' => false,
        'error' => 'QR code not yet available',
        'instance_id' => $instanceId,
        'server_status' => $serverStatus
    ], 503);

} catch (Exception $e) {
    log_message("Unexpected error: " . $e->getMessage());
    send_response([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'An unexpected error occurred'
    ], 500);
}
?>
