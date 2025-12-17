<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';

session_start();
if (file_exists('debug')) {
    if (!function_exists('debug_log')) {
        function debug_log($message) {
            file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
} else {
    if (!function_exists('debug_log')) {
        function debug_log($message) { }
    }
}

$externalUser = $_SESSION['external_user'] ?? null;
if (!$externalUser || ($externalUser['role'] ?? '') === 'manager') {
    header('Location: login.php');
    exit;
}

$instances = loadInstancesFromDatabase();
$allowed = [];
foreach ($externalUser['instances'] as $entry) {
    $instanceId = $entry['instance_id'] ?? '';
    if ($instanceId && isset($instances[$instanceId])) {
        $allowed[$instanceId] = $instances[$instanceId];
    }
}

?><!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conversas • <?= htmlspecialchars($externalUser['name'] ?? 'Usuário') ?></title>
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin:0;
      background:#0f172a;
      color:#f8fafc;
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:2rem;
    }
    .card {
      width:100%;
      max-width:640px;
      background:#020617;
      border:1px solid #475569;
      border-radius:16px;
      padding:28px;
      box-shadow:0 25px 60px rgba(15,23,42,0.6);
    }
    h1 { margin-top:0; font-size:24px; }
    p { margin:0 0 1rem; color:#94a3b8; }
    .grid {
      display:grid;
      gap:14px;
      margin-bottom:1.5rem;
    }
    .instance {
      border:1px solid #334155;
      border-radius:12px;
      padding:14px 16px;
      background:#0f172a;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:1rem;
    }
    .instance-name { font-weight:600; }
    .links button {
      border:none;
      border-radius:999px;
      padding:8px 14px;
      background:#2563eb;
      color:#fff;
      font-size:13px;
      cursor:pointer;
    }
    .links button:hover { background:#1d4ed8; }
    .logout {
      text-decoration:none;
      color:#94a3b8;
      font-size:14px;
      border-bottom:1px dotted transparent;
    }
    .logout:hover {
      border-color:#94a3b8;
    }
    .empty {
      color:#cbd5f5;
      padding:14px;
      border:1px dashed #64748b;
      border-radius:12px;
      text-align:center;
    }
  </style>
</head>
<body>
<div class="card">
  <h1>Bem-vindo, <?= htmlspecialchars($externalUser['name'] ?? 'usuário') ?></h1>
  <p>Selecione a instância autorizada para iniciar a conversa.</p>

  <?php if (empty($allowed)): ?>
    <div class="empty">Nenhuma instância disponível. Contate o administrador.</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($allowed as $instanceId => $instance): ?>
        <div class="instance">
          <div>
            <div class="instance-name"><?= htmlspecialchars($instance['name'] ?? $instanceId) ?></div>
            <div class="text-xs" style="color:#94a3b8;">ID: <?= htmlspecialchars($instanceId) ?></div>
          </div>
          <div class="links">
            <button onclick="window.location.href='conversas.php?instance=<?= urlencode($instanceId) ?>'">
              Abrir conversas
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <a class="logout" href="logout.php">Sair</a>
</div>
</body>
</html>
