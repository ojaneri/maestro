<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';
require_once __DIR__ . '/external_auth.php';
date_default_timezone_set('America/Fortaleza');
$displayTz = new DateTimeZone('America/Fortaleza');
$dbTz = new DateTimeZone('UTC');
ensureExternalUsersSchema();

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$externalUser = $_SESSION['external_user'] ?? null;
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

$instance = loadInstanceRecordFromDatabase($instanceId);
if (!$instance) {
    header("Location: /api/envio/wpp/");
    exit;
}

function respondJson($payload, $status = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function proxyNodeRequest(array $instance, string $path, string $method = 'GET', ?string $body = null) {
    $url = "http://127.0.0.1:{$instance['port']}{$path}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
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

function formatTimestampUtcToLocal(?string $value, DateTimeZone $displayTz): string {
    if (!$value) {
        return '-';
    }
    try {
        $dt = new DateTime($value, new DateTimeZone('UTC'));
        $dt->setTimezone($displayTz);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $value;
    }
}

if (!function_exists('sqliteTableExists')) {
    function sqliteTableExists(SQLite3 $db, string $table): bool {
        $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->bindValue(':name', $table, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return !empty($row['name']);
    }
}

if (isset($_GET['ajax_groups'])) {
    proxyNodeRequest($instance, "/api/groups/{$instanceId}", 'GET');
}

if (isset($_GET['ajax_groups_update']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!isset($payload['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $payload['csrf_token'])) {
        respondJson(['ok' => false, 'error' => 'Token CSRF inválido'], 403);
    }
    $body = json_encode([
        'groups' => $payload['groups'] ?? []
    ]);
    proxyNodeRequest($instance, "/api/groups/{$instanceId}/monitor", 'POST', $body);
}

if (isset($_GET['ajax_group_leave']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!isset($payload['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $payload['csrf_token'])) {
        respondJson(['ok' => false, 'error' => 'Token CSRF inválido'], 403);
    }
    $body = json_encode([
        'group_jid' => $payload['group_jid'] ?? ''
    ]);
    proxyNodeRequest($instance, "/api/groups/{$instanceId}/leave", 'POST', $body);
}

if (isset($_GET['ajax_group_replies']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!isset($payload['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $payload['csrf_token'])) {
        respondJson(['ok' => false, 'error' => 'Token CSRF inválido'], 403);
    }
    $body = json_encode([
        'group_jid' => $payload['group_jid'] ?? '',
        'replies' => $payload['replies'] ?? [],
        'enabled' => isset($payload['enabled']) ? (bool)$payload['enabled'] : true
    ]);
    proxyNodeRequest($instance, "/api/groups/{$instanceId}/auto-replies", 'POST', $body);
}

if (isset($_GET['ajax_group_replies_get'])) {
    $groupJid = $_GET['group_jid'] ?? '';
    if ($groupJid === '') {
        respondJson(['ok' => false, 'error' => 'group_jid é obrigatório'], 400);
    }
    $query = http_build_query(['group_jid' => $groupJid]);
    proxyNodeRequest($instance, "/api/groups/{$instanceId}/auto-replies?{$query}", 'GET');
}

if (isset($_GET['ajax_group_schedule']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!isset($payload['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $payload['csrf_token'])) {
        respondJson(['ok' => false, 'error' => 'Token CSRF inválido'], 403);
    }
    $body = json_encode([
        'group_jid' => $payload['group_jid'] ?? '',
        'message' => $payload['message'] ?? '',
        'scheduled_at' => $payload['scheduled_at'] ?? ''
    ]);
    proxyNodeRequest($instance, "/api/groups/{$instanceId}/schedules", 'POST', $body);
}

if (isset($_GET['ajax_group_send']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!isset($payload['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $payload['csrf_token'])) {
        respondJson(['ok' => false, 'error' => 'Token CSRF inválido'], 403);
    }
    $body = json_encode([
        'groups' => $payload['groups'] ?? [],
        'message' => $payload['message'] ?? ''
    ]);
    proxyNodeRequest($instance, "/api/groups/{$instanceId}/send-bulk", 'POST', $body);
}

if (isset($_GET['ajax_group_contacts']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    if (!isset($payload['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $payload['csrf_token'])) {
        respondJson(['ok' => false, 'error' => 'Token CSRF inválido'], 403);
    }
    $groups = $payload['groups'] ?? [];
    if (!is_array($groups) || !$groups) {
        respondJson(['ok' => false, 'error' => 'Selecione ao menos um grupo'], 400);
    }

    $url = "http://127.0.0.1:{$instance['port']}/api/groups/{$instanceId}/contacts";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['groups' => $groups]));
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        respondJson(['ok' => false, 'error' => 'Erro ao conectar ao serviço interno', 'detail' => $error], 502);
    }
    $data = json_decode($response ?: '', true);
    if (!is_array($data) || empty($data['ok'])) {
        $detail = $data['error'] ?? $data['detail'] ?? 'Falha ao buscar contatos';
        respondJson(['ok' => false, 'error' => $detail], $httpCode >= 400 ? $httpCode : 500);
    }

    $filename = "contatos-grupos-{$instanceId}-" . date('Ymd-His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Grupo', 'Nome', 'Remote JID', 'Telefone']);
    foreach ($data['contacts'] ?? [] as $row) {
        $groupName = $row['group_name'] ?? $row['group_jid'] ?? '';
        $name = $row['name'] ?? '';
        $jid = $row['jid'] ?? '';
        $phone = $row['phone'] ?? '';
        fputcsv($out, [$groupName, $name, $jid, $phone]);
    }
    fclose($out);
    exit;
}

$dbPath = __DIR__ . '/chat_data.db';
$groups = [];
$groupStats = [];
$groupMessages = [];
$contactMeta = [];
$range = $_GET['range'] ?? 'today';
$customStart = $_GET['start'] ?? '';
$customEnd = $_GET['end'] ?? '';
$selectedGroup = $_GET['group'] ?? '';

$startDate = null;
$endDate = null;
$now = new DateTime('now', $displayTz);
if ($range === 'today') {
    $startDate = (clone $now)->setTime(0, 0, 0);
    $endDate = (clone $now)->setTime(23, 59, 59);
} elseif ($range === 'last2days') {
    $startDate = (clone $now)->modify('-2 days')->setTime(0, 0, 0);
    $endDate = (clone $now)->setTime(23, 59, 59);
} elseif ($range === 'last7days') {
    $startDate = (clone $now)->modify('-7 days')->setTime(0, 0, 0);
    $endDate = (clone $now)->setTime(23, 59, 59);
} elseif ($range === 'maxperiod') {
    // no date filter
} elseif ($range === 'custom' && $customStart && $customEnd) {
    $startDate = new DateTime($customStart . ' 00:00:00', $displayTz);
    $endDate = new DateTime($customEnd . ' 23:59:59', $displayTz);
}

$startDateUtc = null;
$endDateUtc = null;
if ($startDate && $endDate) {
    $startDateUtc = (clone $startDate)->setTimezone($dbTz);
    $endDateUtc = (clone $endDate)->setTimezone($dbTz);
}

if (file_exists($dbPath)) {
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE);
    $hasMonitoring = sqliteTableExists($db, 'group_monitoring');
    $hasMessages = sqliteTableExists($db, 'group_messages');

    if ($hasMonitoring) {
        $stmt = $db->prepare("
            SELECT group_jid, group_name, enabled, updated_at
            FROM group_monitoring
            WHERE instance_id = :instance
            ORDER BY group_name ASC
        ");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groups[] = $row;
        }
    }

    if ($hasMessages) {
        if (sqliteTableExists($db, 'contact_metadata')) {
            $stmt = $db->prepare("
                SELECT remote_jid, contact_name, status_name
                FROM contact_metadata
                WHERE instance_id = :instance
            ");
            $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $contactMeta[$row['remote_jid']] = [
                    'name' => $row['contact_name'] ?? '',
                    'status' => $row['status_name'] ?? ''
                ];
            }
        }
        $filters = ["instance_id = :instance"];
        $params = [':instance' => $instanceId];
        if ($startDateUtc && $endDateUtc) {
            $filters[] = "timestamp BETWEEN :start AND :end";
            $params[':start'] = $startDateUtc->format('Y-m-d H:i:s');
            $params[':end'] = $endDateUtc->format('Y-m-d H:i:s');
        }
        $sql = "
            SELECT group_jid,
                   COUNT(*) AS total,
                   SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS inbound,
                   SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) AS outbound,
                   COUNT(DISTINCT participant_jid) AS participants,
                   MAX(timestamp) AS last_message
            FROM group_messages
            WHERE " . implode(' AND ', $filters) . "
            GROUP BY group_jid
        ";
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groupStats[$row['group_jid']] = $row;
        }

        $filters = ["m.instance_id = :instance"];
        $params = [':instance' => $instanceId];
        if ($startDateUtc && $endDateUtc) {
            $filters[] = "m.timestamp BETWEEN :start AND :end";
            $params[':start'] = $startDateUtc->format('Y-m-d H:i:s');
            $params[':end'] = $endDateUtc->format('Y-m-d H:i:s');
        }
        if ($selectedGroup) {
            $filters[] = "m.group_jid = :group";
            $params[':group'] = $selectedGroup;
        }
        $sql = "
            SELECT m.group_jid, m.participant_jid, m.direction, m.content, m.timestamp
            FROM group_messages m
            WHERE " . implode(' AND ', $filters) . "
            ORDER BY m.timestamp DESC
            LIMIT 500
        ";
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groupMessages[$row['group_jid']][] = $row;
        }
    }

    $db->close();
}

if (isset($_GET['download']) && $_GET['download'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="grupos-log-' . $instanceId . '-' . date('Ymd-His') . '.txt"');
    $columns = [
        'timestamp',
        'direction',
        'group_jid',
        'participant_jid',
        'participant_phone',
        'participant_name',
        'participant_status',
        'content'
    ];
    echo implode("\t", $columns) . "\n";
    foreach ($groupMessages as $groupJid => $rows) {
        if ($selectedGroup && $selectedGroup !== $groupJid) {
            continue;
        }
        foreach ($rows as $row) {
            $time = formatTimestampUtcToLocal($row['timestamp'] ?? '', $displayTz);
            $participant = $row['participant_jid'] ?: '-';
            $participantName = $contactMeta[$participant]['name'] ?? '';
            $participantStatus = $contactMeta[$participant]['status'] ?? '';
            $participantPhone = '';
            if (strpos($participant, '@s.whatsapp.net') !== false) {
                $participantPhone = preg_replace('/\D+/', '', explode('@', $participant)[0]);
            }
            $direction = $row['direction'] ?: '-';
            $content = str_replace(["\r", "\n", "\t"], ' ', $row['content'] ?? '');
            $values = [
                $time,
                $direction,
                $groupJid,
                $participant,
                $participantPhone,
                $participantName,
                $participantStatus,
                $content
            ];
            echo implode("\t", $values) . "\n";
        }
    }
    exit;
}

$dashboardBaseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($dashboardBaseUrl === '') {
    $dashboardBaseUrl = '/';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Grupos Monitorados</title>

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
  <div class="min-h-screen">
    <header class="bg-white border-b border-mid sticky top-0 z-20">
      <div class="max-w-6xl mx-auto px-6 py-4 flex items-center justify-between">
        <a href="index.php?instance=<?= htmlspecialchars($instanceId) ?>" class="flex items-center gap-3">
          <img src="<?= htmlspecialchars($dashboardBaseUrl) ?>/assets/maestro-logo.png" alt="Maestro" class="w-10 h-10">
          <div>
            <div class="text-sm text-slate-500">Instância</div>
            <div class="font-semibold text-dark"><?= htmlspecialchars($instance['name'] ?: 'Sem nome') ?></div>
            <div class="text-xs text-slate-500"><?= htmlspecialchars($instanceId) ?></div>
          </div>
        </a>
        <div class="flex gap-3">
          <a href="conversas.php?instance=<?= htmlspecialchars($instanceId) ?>" class="text-sm text-primary font-medium">Conversas</a>
          <a href="/api/envio/wpp/?instance=<?= htmlspecialchars($instanceId) ?>" class="text-sm text-slate-500">Painel</a>
        </div>
      </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-6 space-y-6">
      <section class="bg-white border border-mid rounded-2xl p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h2 class="text-lg font-semibold text-dark">Grupos monitorados</h2>
            <p class="text-xs text-slate-500">Selecione grupos e escolha uma ação para monitorar, sair ou enviar mensagens.</p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button id="sendGroupsBtn" class="px-4 py-2 rounded-xl bg-dark text-white text-sm font-medium">Mandar mensagem</button>
            <button id="downloadContactsBtn" class="px-4 py-2 rounded-xl border border-mid text-sm font-medium text-slate-700">Baixar contatos</button>
            <button id="leaveGroupsBtn" class="px-4 py-2 rounded-xl border border-error/40 text-error text-sm font-medium">Sair dos grupos selecionados</button>
            <button id="monitorGroupsBtn" class="px-4 py-2 rounded-xl bg-primary text-white text-sm font-medium">Monitorar os grupos selecionados</button>
          </div>
        </div>
        <div id="groupsList" class="mt-4 grid gap-3 md:grid-cols-2"></div>
        <p id="groupsStatus" class="text-xs text-slate-500 mt-3"></p>
        <p id="groupsSelectionInfo" class="text-xs text-slate-500 mt-1"></p>
        <div id="groupsToast" class="hidden mt-3 px-3 py-2 rounded-xl bg-emerald-50 text-emerald-700 text-xs border border-emerald-100">Seleção salva com sucesso.</div>
      </section>

      <section class="bg-white border border-mid rounded-2xl p-6">
        <h3 class="text-base font-semibold text-dark">Filtro de logs</h3>
        <form class="mt-4 grid gap-3 md:grid-cols-4">
          <input type="hidden" name="instance" value="<?= htmlspecialchars($instanceId) ?>">
          <div>
            <label class="text-xs text-slate-500">Grupo</label>
            <select name="group" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light">
              <option value="">Todos</option>
              <?php foreach ($groups as $group): ?>
                <option value="<?= htmlspecialchars($group['group_jid']) ?>" <?= $selectedGroup === $group['group_jid'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($group['group_name'] ?: $group['group_jid']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="text-xs text-slate-500">Período</label>
            <select name="range" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light">
              <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Hoje</option>
              <option value="last2days" <?= $range === 'last2days' ? 'selected' : '' ?>>Últimos 2 dias</option>
              <option value="last7days" <?= $range === 'last7days' ? 'selected' : '' ?>>Últimos 7 dias</option>
              <option value="maxperiod" <?= $range === 'maxperiod' ? 'selected' : '' ?>>Periodo Máximo</option>
              <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Periodo personalizado</option>
            </select>
          </div>
          <div>
            <label class="text-xs text-slate-500">Início</label>
            <input type="date" name="start" value="<?= htmlspecialchars($customStart) ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light">
          </div>
          <div>
            <label class="text-xs text-slate-500">Fim</label>
            <input type="date" name="end" value="<?= htmlspecialchars($customEnd) ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light">
          </div>
          <div class="md:col-span-4 flex flex-wrap gap-3">
            <button type="submit" class="px-4 py-2 rounded-xl bg-primary text-white text-sm font-medium">Atualizar</button>
          </div>
        </form>
      </section>

      <section class="space-y-4">
        <?php if (!$groups): ?>
          <div class="bg-white border border-mid rounded-2xl p-6 text-sm text-slate-500">Nenhum grupo monitorado ainda.</div>
        <?php else: ?>
          <?php foreach ($groups as $group): ?>
            <?php
              $jid = $group['group_jid'];
              $stats = $groupStats[$jid] ?? ['total' => 0, 'inbound' => 0, 'outbound' => 0, 'participants' => 0, 'last_message' => null];
              $rows = $groupMessages[$jid] ?? [];
            ?>
            <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-6 shadow-sm">
              <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                  <div class="flex items-center gap-2">
                    <h3 class="text-base font-semibold text-dark"><?= htmlspecialchars($group['group_name'] ?: $jid) ?></h3>
                    <span class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded-full bg-emerald-200 text-emerald-900">Monitorado</span>
                  </div>
                  <div class="text-xs text-slate-500"><?= htmlspecialchars($jid) ?></div>
                </div>
                <div class="flex flex-wrap gap-2">
                  <a class="px-3 py-1.5 rounded-xl border border-mid text-xs text-slate-600" href="?instance=<?= htmlspecialchars($instanceId) ?>&range=<?= htmlspecialchars($range) ?>&group=<?= urlencode($jid) ?>&start=<?= htmlspecialchars($customStart) ?>&end=<?= htmlspecialchars($customEnd) ?>&download=1">Baixar TXT</a>
                </div>
              </div>
              <div class="mt-4 grid gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-mid bg-light px-3 py-2">
                  <div class="text-[11px] text-slate-500">Mensagens</div>
                  <div class="text-sm font-semibold text-dark"><?= (int)$stats['total'] ?></div>
                </div>
                <div class="rounded-xl border border-mid bg-light px-3 py-2">
                  <div class="text-[11px] text-slate-500">Entrada</div>
                  <div class="text-sm font-semibold text-dark"><?= (int)$stats['inbound'] ?></div>
                </div>
                <div class="rounded-xl border border-mid bg-light px-3 py-2">
                  <div class="text-[11px] text-slate-500">Saída</div>
                  <div class="text-sm font-semibold text-dark"><?= (int)$stats['outbound'] ?></div>
                </div>
                <div class="rounded-xl border border-mid bg-light px-3 py-2">
                  <div class="text-[11px] text-slate-500">Participantes</div>
                  <div class="text-sm font-semibold text-dark"><?= (int)$stats['participants'] ?></div>
                </div>
              </div>
              <div class="mt-4">
                <div class="text-xs text-slate-500">Última atividade: <?= htmlspecialchars(formatTimestampUtcToLocal($stats['last_message'] ?? '', $displayTz)) ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <section class="bg-white border border-mid rounded-2xl p-6">
        <h3 class="text-base font-semibold text-dark">Resposta automática (@menção)</h3>
        <p class="text-xs text-slate-500 mt-1">Escolha um grupo e adicione frases (uma por linha). O bot seleciona aleatoriamente.</p>
        <div class="mt-4 grid gap-3 md:grid-cols-3">
          <select id="replyGroupSelect" class="px-3 py-2 rounded-xl border border-mid bg-light">
            <option value="">Selecione um grupo</option>
            <?php foreach ($groups as $group): ?>
              <option value="<?= htmlspecialchars($group['group_jid']) ?>"><?= htmlspecialchars($group['group_name'] ?: $group['group_jid']) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" id="replyEnabled" checked> Ativar
          </label>
          <button id="saveRepliesBtn" class="px-4 py-2 rounded-xl bg-primary text-white text-sm font-medium">Salvar respostas</button>
        </div>
        <textarea id="replyList" class="mt-3 w-full min-h-[120px] px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Digite uma resposta por linha"></textarea>
        <p id="replyStatus" class="text-xs text-slate-500 mt-2"></p>
      </section>

      <section class="bg-white border border-mid rounded-2xl p-6">
        <h3 class="text-base font-semibold text-dark">Agendar mensagem para grupo</h3>
        <div class="mt-4 grid gap-3 md:grid-cols-3">
          <select id="scheduleGroupSelect" class="px-3 py-2 rounded-xl border border-mid bg-light">
            <option value="">Selecione um grupo</option>
            <?php foreach ($groups as $group): ?>
              <option value="<?= htmlspecialchars($group['group_jid']) ?>"><?= htmlspecialchars($group['group_name'] ?: $group['group_jid']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="datetime-local" id="scheduleAt" class="px-3 py-2 rounded-xl border border-mid bg-light">
          <button id="scheduleBtn" class="px-4 py-2 rounded-xl bg-primary text-white text-sm font-medium">Agendar</button>
        </div>
        <textarea id="scheduleMessage" class="mt-3 w-full min-h-[120px] px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Mensagem para o grupo"></textarea>
        <p id="scheduleStatus" class="text-xs text-slate-500 mt-2"></p>
      </section>
    </main>
  </div>

  <div id="groupMessageModal" class="fixed inset-0 hidden items-center justify-center bg-slate-900/40">
    <div class="bg-white rounded-2xl w-[min(520px,92vw)] p-6 border border-mid shadow-xl">
      <div class="flex items-center justify-between">
        <h3 class="text-base font-semibold text-dark">Enviar mensagem para grupos</h3>
        <button id="groupMessageCloseBtn" class="text-slate-400 hover:text-slate-600">✕</button>
      </div>
      <p class="text-xs text-slate-500 mt-2">A mensagem será enviada para todos os grupos selecionados.</p>
      <textarea id="groupMessageText" class="mt-4 w-full min-h-[140px] px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Digite a mensagem"></textarea>
      <div class="mt-4 flex justify-end gap-2">
        <button id="groupMessageCancelBtn" class="px-4 py-2 rounded-xl border border-mid text-sm text-slate-600">Cancelar</button>
        <button id="groupMessageSendBtn" class="px-4 py-2 rounded-xl bg-primary text-white text-sm font-medium">Enviar</button>
      </div>
      <p id="groupMessageStatus" class="text-xs text-slate-500 mt-3"></p>
    </div>
  </div>

  <script>
    const csrfToken = <?= json_encode($_SESSION['csrf_token']) ?>;
    const instanceId = <?= json_encode($instanceId) ?>;

    const groupsList = document.getElementById('groupsList');
    const groupsStatus = document.getElementById('groupsStatus');
    const groupsSelectionInfo = document.getElementById('groupsSelectionInfo');
    const sendGroupsBtn = document.getElementById('sendGroupsBtn');
    const downloadContactsBtn = document.getElementById('downloadContactsBtn');
    const leaveGroupsBtn = document.getElementById('leaveGroupsBtn');
    const monitorGroupsBtn = document.getElementById('monitorGroupsBtn');
    const groupMessageModal = document.getElementById('groupMessageModal');
    const groupMessageText = document.getElementById('groupMessageText');
    const groupMessageSendBtn = document.getElementById('groupMessageSendBtn');
    const groupMessageCancelBtn = document.getElementById('groupMessageCancelBtn');
    const groupMessageCloseBtn = document.getElementById('groupMessageCloseBtn');
    const groupMessageStatus = document.getElementById('groupMessageStatus');

    const setActionDisabled = (disabled) => {
      if (sendGroupsBtn) sendGroupsBtn.disabled = disabled;
      if (downloadContactsBtn) downloadContactsBtn.disabled = disabled;
      if (leaveGroupsBtn) leaveGroupsBtn.disabled = disabled;
      if (monitorGroupsBtn) monitorGroupsBtn.disabled = disabled;
      if (groupMessageSendBtn) groupMessageSendBtn.disabled = disabled;
      if (groupMessageCancelBtn) groupMessageCancelBtn.disabled = disabled;
      if (groupMessageCloseBtn) groupMessageCloseBtn.disabled = disabled;
    };

    const getSelectedGroups = () => Array.from(groupsList.querySelectorAll('input[type="checkbox"]:checked'))
      .map(input => ({ jid: input.dataset.groupJid, name: input.dataset.groupName }));

    const updateSelectionInfo = () => {
      const count = getSelectedGroups().length;
      if (groupsSelectionInfo) {
        groupsSelectionInfo.textContent = `Selecionados: ${count}`;
      }
    };

    const loadGroups = async () => {
      groupsStatus.textContent = 'Carregando grupos...';
      try {
        const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_groups=1`);
        const raw = await response.text();
        let payload = null;
        try {
          payload = JSON.parse(raw);
        } catch (parseErr) {
          throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
        }
        if (!payload.ok) throw new Error(payload.error || 'Falha ao carregar grupos');
        const monitored = new Set((payload.monitored || []).map(row => row.group_jid));
        const groupsRaw = payload.groups || [];
        const groupsSorted = groupsRaw.slice().sort((a, b) => {
          const aMon = monitored.has(a.jid) ? 0 : 1;
          const bMon = monitored.has(b.jid) ? 0 : 1;
          if (aMon !== bMon) return aMon - bMon;
          const aName = (a.name || a.jid || '').toLowerCase();
          const bName = (b.name || b.jid || '').toLowerCase();
          return aName.localeCompare(bName);
        });
        groupsList.innerHTML = groupsSorted.map(group => {
          const checked = '';
          const label = group.name || group.jid;
          const monitorBadge = monitored.has(group.jid)
            ? '<span class="ml-2 text-[10px] uppercase tracking-wide px-2 py-0.5 rounded-full bg-emerald-200 text-emerald-900">Monitorado</span>'
            : '';
          const stopButton = monitored.has(group.jid)
            ? `<button type="button" data-stop-monitor="${group.jid}" class="ml-auto text-xs text-amber-700 border border-amber-200 rounded-lg px-2 py-1 hover:bg-amber-50">Parar de monitorar</button>`
            : '';
          const extraClass = monitored.has(group.jid) ? 'border-emerald-200 bg-emerald-50/40' : 'border-mid';
          return `\n            <label class="flex items-start gap-3 border ${extraClass} rounded-xl p-3 text-sm">\n              <input type="checkbox" data-group-jid="${group.jid}" data-group-name="${label}" ${checked}>\n              <div>\n                <div class="font-medium text-dark flex items-center">${label}${monitorBadge}</div>\n                <div class="text-xs text-slate-500">${group.jid} • ${group.size || 0} membros</div>\n              </div>\n              ${stopButton}\n            </label>\n          `;
        }).join('');
        groupsStatus.textContent = `Total de grupos: ${payload.groups?.length || 0}`;
        updateSelectionInfo();
      } catch (err) {
        groupsStatus.textContent = `Erro: ${err.message}`;
      }
    };

    if (monitorGroupsBtn) {
      monitorGroupsBtn.addEventListener('click', async () => {
        const selections = getSelectedGroups();
        groupsStatus.textContent = 'Processando...';
        setActionDisabled(true);
        const toast = document.getElementById('groupsToast');
        if (toast) { toast.classList.add('hidden'); }
        try {
          const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_groups_update=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: csrfToken, groups: selections })
          });
          const raw = await response.text();
          let payload = null;
          try {
            payload = JSON.parse(raw);
          } catch (parseErr) {
            throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
          }
          if (!payload.ok) throw new Error(payload.error || 'Falha ao salvar');
          groupsStatus.textContent = 'Sucesso: grupos monitorados atualizados.';
          if (toast) {
            toast.classList.remove('hidden');
            setTimeout(() => toast.classList.add('hidden'), 2500);
          }
        } catch (err) {
          groupsStatus.textContent = `Falha: ${err.message}`;
          if (toast) { toast.classList.add('hidden'); }
        } finally {
          setActionDisabled(false);
        }
      });
    }

    groupsList?.addEventListener('change', updateSelectionInfo);
    groupsList?.addEventListener('click', async (event) => {
      const stopBtn = event.target.closest('[data-stop-monitor]');
      if (!stopBtn) return;
      const jid = stopBtn.getAttribute('data-stop-monitor');
      if (!jid) return;
      const selections = getSelectedGroups().filter(group => group.jid !== jid);
      groupsStatus.textContent = 'Atualizando monitoramento...';
      try {
        const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_groups_update=1`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ csrf_token: csrfToken, groups: selections })
        });
        const raw = await response.text();
        let payload = null;
        try {
          payload = JSON.parse(raw);
        } catch (parseErr) {
          throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
        }
        if (!payload.ok) throw new Error(payload.error || 'Falha ao salvar');
        groupsStatus.textContent = 'Grupo removido do monitoramento.';
        loadGroups();
      } catch (err) {
        groupsStatus.textContent = `Erro: ${err.message}`;
      }
    });

    if (leaveGroupsBtn) {
      leaveGroupsBtn.addEventListener('click', async () => {
        const selections = getSelectedGroups();
        if (!selections.length) {
          groupsStatus.textContent = 'Selecione ao menos um grupo.';
          return;
        }
        if (!confirm(`Deseja sair de ${selections.length} grupo(s)?`)) return;
        groupsStatus.textContent = 'Processando...';
        setActionDisabled(true);
        for (const group of selections) {
          try {
            const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_group_leave=1`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ csrf_token: csrfToken, group_jid: group.jid })
            });
            const raw = await response.text();
            let payload = null;
            try {
              payload = JSON.parse(raw);
            } catch (parseErr) {
              throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
            }
            if (!payload.ok) throw new Error(payload.error || 'Falha ao sair do grupo');
          } catch (err) {
            groupsStatus.textContent = `Falha: ${group.name} (${err.message})`;
            setActionDisabled(false);
            return;
          }
        }
        groupsStatus.textContent = 'Sucesso: saiu dos grupos selecionados.';
        loadGroups();
        setActionDisabled(false);
      });
    }

    const openGroupMessageModal = () => {
      if (!groupMessageModal) return;
      groupMessageText.value = '';
      groupMessageStatus.textContent = '';
      groupMessageModal.classList.remove('hidden');
      groupMessageModal.classList.add('flex');
      groupMessageText.focus();
    };

    const closeGroupMessageModal = () => {
      if (!groupMessageModal) return;
      groupMessageModal.classList.add('hidden');
      groupMessageModal.classList.remove('flex');
    };

    if (sendGroupsBtn) {
      sendGroupsBtn.addEventListener('click', () => {
        const selections = getSelectedGroups();
        if (!selections.length) {
          groupsStatus.textContent = 'Selecione ao menos um grupo.';
          return;
        }
        openGroupMessageModal();
      });
    }

    if (downloadContactsBtn) {
      downloadContactsBtn.addEventListener('click', async () => {
        const selections = getSelectedGroups();
        if (!selections.length) {
          groupsStatus.textContent = 'Selecione ao menos um grupo.';
          return;
        }
        groupsStatus.textContent = 'Processando...';
        setActionDisabled(true);
        try {
          const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_group_contacts=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              csrf_token: csrfToken,
              groups: selections.map(group => group.jid)
            })
          });
          if (!response.ok) {
            let message = 'Falha ao baixar contatos';
            try {
              const raw = await response.text();
              const payload = JSON.parse(raw);
              message = payload.error || message;
            } catch {}
            throw new Error(message);
          }
          const blob = await response.blob();
          const disposition = response.headers.get('content-disposition') || '';
          const match = disposition.match(/filename=\"([^\"]+)\"/);
          const filename = match ? match[1] : 'contatos-grupos.csv';
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.href = url;
          link.download = filename;
          document.body.appendChild(link);
          link.click();
          link.remove();
          URL.revokeObjectURL(url);
          groupsStatus.textContent = 'Sucesso: CSV baixado.';
        } catch (err) {
          groupsStatus.textContent = `Falha: ${err.message}`;
        } finally {
          setActionDisabled(false);
        }
      });
    }

    if (groupMessageCloseBtn) {
      groupMessageCloseBtn.addEventListener('click', closeGroupMessageModal);
    }
    if (groupMessageCancelBtn) {
      groupMessageCancelBtn.addEventListener('click', closeGroupMessageModal);
    }

    if (groupMessageSendBtn) {
      groupMessageSendBtn.addEventListener('click', async () => {
        const selections = getSelectedGroups();
        const message = (groupMessageText.value || '').trim();
        if (!selections.length) {
          groupMessageStatus.textContent = 'Selecione ao menos um grupo.';
          return;
        }
        if (!message) {
          groupMessageStatus.textContent = 'Digite a mensagem.';
          return;
        }
        groupMessageStatus.textContent = 'Processando...';
        setActionDisabled(true);
        try {
          const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_group_send=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              csrf_token: csrfToken,
              groups: selections.map(group => group.jid),
              message
            })
          });
          const raw = await response.text();
          let payload = null;
          try {
            payload = JSON.parse(raw);
          } catch (parseErr) {
            throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
          }
          if (!payload.ok && !(payload.sent > 0)) {
            throw new Error(payload.error || 'Falha ao enviar mensagens');
          }
          if (payload.failed && payload.failed.length) {
            groupMessageStatus.textContent = `Sucesso: enviado para ${payload.sent} grupo(s). Falhas: ${payload.failed.length}.`;
          } else {
            groupMessageStatus.textContent = `Sucesso: enviado para ${payload.sent || selections.length} grupo(s).`;
          }
          setTimeout(() => {
            closeGroupMessageModal();
          }, 900);
        } catch (err) {
          groupMessageStatus.textContent = `Falha: ${err.message}`;
        } finally {
          setActionDisabled(false);
        }
      });
    }

    const saveRepliesBtn = document.getElementById('saveRepliesBtn');
    const replyGroupSelect = document.getElementById('replyGroupSelect');
    const replyList = document.getElementById('replyList');
    const replyStatus = document.getElementById('replyStatus');
    const replyEnabled = document.getElementById('replyEnabled');
    const loadReplies = async (groupJid) => {
      if (!groupJid) {
        replyList.value = '';
        replyEnabled.checked = true;
        replyStatus.textContent = '';
        return;
      }
      replyStatus.textContent = 'Carregando respostas...';
      try {
        const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_group_replies_get=1&group_jid=${encodeURIComponent(groupJid)}`);
        const raw = await response.text();
        let payload = null;
        try {
          payload = JSON.parse(raw);
        } catch (parseErr) {
          throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
        }
        if (!payload.ok) throw new Error(payload.error || 'Falha ao carregar respostas');
        const data = payload.data || {};
        let list = [];
        if (data.replies_json) {
          try {
            list = JSON.parse(data.replies_json) || [];
          } catch {
            list = [];
          }
        } else if (Array.isArray(data.replies)) {
          list = data.replies;
        }
        replyList.value = Array.isArray(list) ? list.join('\n') : '';
        replyEnabled.checked = data.enabled !== 0;
        replyStatus.textContent = '';
      } catch (err) {
        replyStatus.textContent = `Erro: ${err.message}`;
      }
    };
    if (saveRepliesBtn) {
      saveRepliesBtn.addEventListener('click', async () => {
        const groupJid = replyGroupSelect.value;
        if (!groupJid) {
          replyStatus.textContent = 'Selecione um grupo.';
          return;
        }
        const replies = replyList.value.split('\n').map(line => line.trim()).filter(Boolean);
        replyStatus.textContent = 'Salvando respostas...';
        try {
          const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_group_replies=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              csrf_token: csrfToken,
              group_jid: groupJid,
              replies,
              enabled: replyEnabled.checked
            })
          });
          const raw = await response.text();
          let payload = null;
          try {
            payload = JSON.parse(raw);
          } catch (parseErr) {
            throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
          }
          if (!payload.ok) throw new Error(payload.error || 'Falha ao salvar');
          replyStatus.textContent = 'Respostas salvas.';
        } catch (err) {
          replyStatus.textContent = `Erro: ${err.message}`;
        }
      });
    }
    if (replyGroupSelect) {
      replyGroupSelect.addEventListener('change', () => {
        loadReplies(replyGroupSelect.value);
      });
    }

    const scheduleBtn = document.getElementById('scheduleBtn');
    const scheduleGroupSelect = document.getElementById('scheduleGroupSelect');
    const scheduleAt = document.getElementById('scheduleAt');
    const scheduleMessage = document.getElementById('scheduleMessage');
    const scheduleStatus = document.getElementById('scheduleStatus');
    if (scheduleBtn) {
      scheduleBtn.addEventListener('click', async () => {
        const groupJid = scheduleGroupSelect.value;
        if (!groupJid) {
          scheduleStatus.textContent = 'Selecione um grupo.';
          return;
        }
        const when = scheduleAt.value;
        const message = scheduleMessage.value.trim();
        if (!when || !message) {
          scheduleStatus.textContent = 'Preencha data/hora e mensagem.';
          return;
        }
        scheduleStatus.textContent = 'Agendando mensagem...';
        try {
          const response = await fetch(`?instance=${encodeURIComponent(instanceId)}&ajax_group_schedule=1`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              csrf_token: csrfToken,
              group_jid: groupJid,
              message,
              scheduled_at: new Date(when).toISOString()
            })
          });
          const raw = await response.text();
          let payload = null;
          try {
            payload = JSON.parse(raw);
          } catch (parseErr) {
            throw new Error(`Resposta inválida do servidor (status ${response.status}).`);
          }
          if (!payload.ok) throw new Error(payload.error || 'Falha ao agendar');
          scheduleStatus.textContent = 'Mensagem agendada com sucesso.';
        } catch (err) {
          scheduleStatus.textContent = `Erro: ${err.message}`;
        }
      });
    }

    loadGroups();
  </script>
</body>
</html>
