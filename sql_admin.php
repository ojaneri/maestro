<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';
date_default_timezone_set('America/Fortaleza');

session_start();
if (!isset($_SESSION['auth'])) {
    header("Location: /api/envio/wpp/");
    exit;
}

function evaluateDatabaseStatus(): array
{
    $status = [
        'path' => INSTANCE_DB_PATH,
        'exists' => file_exists(INSTANCE_DB_PATH),
        'size' => null,
        'accessible' => false,
        'instances_table' => false,
        'settings_table' => false,
        'message' => '',
    ];

    if ($status['exists']) {
        $status['size'] = @filesize(INSTANCE_DB_PATH);
        $db = openInstanceDatabase();
        if ($db) {
            $status['accessible'] = true;
            $status['instances_table'] = sqliteTableExists($db, 'instances');
            $status['settings_table'] = sqliteTableExists($db, 'settings');
            $db->close();
        } else {
            $status['message'] = 'Falha ao abrir o SQLite — verifique permissões.';
        }
    } else {
        $status['message'] = 'Arquivo ausente.';
    }

    return $status;
}

$dbInstances = loadInstancesFromDatabase();
$dbStatus = evaluateDatabaseStatus();

function renderStatusDot(string $status): string
{
    $class = 'bg-gray-300';
    if (stripos($status, 'connected') !== false || stripos($status, 'accessible') !== false) {
        $class = 'bg-emerald-500';
    } elseif (stripos($status, 'running') !== false || stripos($status, 'ok') !== false) {
        $class = 'bg-yellow-500';
    }
    return sprintf('<span class="inline-flex w-2 h-2 rounded-full %s mr-2"></span>', $class);
}

function getReadableBytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = (int)floor(log($bytes, 1024));
    return round($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin SQL – Maestro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>body { font-family: system-ui, sans-serif; background: #f8fafc; }</style>
</head>
<body class="p-6">
  <div class="max-w-5xl mx-auto space-y-6">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Admin SQL</h1>
        <p class="text-sm text-slate-500">Status e registros armazenados em `chat_data.db`.</p>
      </div>
      <a href="/api/envio/wpp/" class="text-sm text-primary hover:underline">← Voltar ao painel</a>
    </header>

    <section class="bg-white shadow rounded-xl p-5">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-sm font-semibold text-slate-900">Integridade do banco SQLite</p>
          <p class="text-xs text-slate-500"><?= date('d/m/Y H:i:s') ?> • UTC-3</p>
        </div>
        <span class="text-xs font-semibold <?= $dbStatus['accessible'] ? 'text-emerald-600' : 'text-amber-500' ?>">
          <?= $dbStatus['accessible'] ? 'SQLite acessível' : 'Problemas detectados' ?>
        </span>
      </div>
      <div class="mt-4 grid md:grid-cols-2 gap-4 text-sm text-slate-600">
        <div>
          <div class="flex items-center">
            <?= renderStatusDot($dbStatus['accessible'] ? 'connected' : 'disconnected') ?>
            <span class="font-semibold text-slate-900">chat_data.db</span>
          </div>
          <ul class="mt-2 text-xs text-slate-500 list-disc list-inside space-y-1">
            <li>Arquivo: <?= $dbStatus['exists'] ? 'presente' : 'ausente' ?> (<?= htmlspecialchars($dbStatus['path']) ?>)</li>
            <li>Tamanho: <?= $dbStatus['size'] !== null ? getReadableBytes($dbStatus['size']) : 'n/d' ?></li>
            <li>Tabelas: instances <?= $dbStatus['instances_table'] ? '✓' : '✗' ?> • settings <?= $dbStatus['settings_table'] ? '✓' : '✗' ?></li>
            <?php if ($dbStatus['message']): ?>
              <li class="text-amber-500"><?= htmlspecialchars($dbStatus['message']) ?></li>
            <?php endif; ?>
          </ul>
        </div>
        <div>
          <div class="flex items-center">
            <?= renderStatusDot($dbStatus['accessible'] ? 'connected' : 'disconnected') ?>
            <span class="font-semibold text-slate-900">Registros</span>
          </div>
          <p class="mt-2 text-xs text-slate-500">
            <?= count($dbInstances) ?> instância(s) cadastrada(s) no SQLite.
          </p>
        </div>
      </div>
    </section>

    <section class="bg-white shadow rounded-xl p-5 space-y-3">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-900">Instâncias persistidas</h2>
        <span class="text-xs text-slate-500"><?= count($dbInstances) ?> registros</span>
      </div>
      <?php if (empty($dbInstances)): ?>
        <p class="text-xs text-slate-500">Nenhuma instância encontrada.</p>
      <?php else: ?>
        <div class="space-y-3 text-sm text-slate-600">
          <?php foreach ($dbInstances as $id => $inst): ?>
            <div class="border border-slate-100 rounded-xl p-4 bg-slate-50 flex items-start justify-between gap-4">
              <div>
                <div class="font-semibold text-slate-900"><?= htmlspecialchars($inst['name'] ?? $id) ?></div>
                <div class="text-xs text-slate-500">
                  ID: <?= htmlspecialchars($id) ?> • Porta: <?= htmlspecialchars($inst['port'] ?? 'n/d') ?>
                </div>
                <div class="text-xs text-slate-500">
                  API Key: <?= htmlspecialchars($inst['api_key'] ?? 'sem chave') ?>
                </div>
              </div>
              <div class="text-xs text-right">
                <span class="block"><?= htmlspecialchars($inst['connection_status'] ?? 'desconectado') ?></span>
                <span class="text-[11px] text-slate-400"><?= htmlspecialchars($inst['status'] ?? '') ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  </div>
</body>
</html>
