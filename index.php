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

if (!function_exists('perf_mark')) {
    $perfEnabled = (getenv('PERF_LOG') === '1') || (isset($_GET['perf']) && $_GET['perf'] === '1');
    $perfStart = microtime(true);
    $perfMarks = [];

    function perf_mark(string $label, array $extra = []): void
    {
        global $perfEnabled, $perfMarks;
        if (!$perfEnabled) {
            return;
        }
        $perfMarks[] = [
            'label' => $label,
            'time' => microtime(true),
            'extra' => $extra
        ];
    }

    function perf_log(string $context, array $extra = []): void
    {
        global $perfEnabled, $perfMarks, $perfStart;
        if (!$perfEnabled) {
            return;
        }
        $now = microtime(true);
        $parts = [];
        $prev = $perfStart;
        foreach ($perfMarks as $mark) {
            $delta = ($mark['time'] - $prev) * 1000;
            $parts[] = $mark['label'] . ':' . round($delta) . 'ms';
            $prev = $mark['time'];
        }
        $total = round(($now - $perfStart) * 1000);
        $payload = array_merge(['total_ms' => $total, 'marks' => $parts], $extra);
        debug_log('PERF ' . $context . ' ' . json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
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
    $perfEnabled = (getenv('PERF_LOG') === '1') || (isset($_GET['perf']) && $_GET['perf'] === '1');
    $sqlStart = $perfEnabled ? microtime(true) : 0;
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
    if ($perfEnabled) {
        $durationMs = round((microtime(true) - $sqlStart) * 1000);
        debug_log('PERF sql.fetchFromStorage ' . json_encode([
            'table' => $table,
            'instance' => $instanceId,
            'limit' => $limit,
            'rows' => count($chats),
            'ms' => $durationMs
        ], JSON_UNESCAPED_SLASHES));
    }
    return $chats;
}

function fetchChatHistory($instanceId, $limit = 10) {
    perf_mark('fetchChatHistory.start', ['instance' => $instanceId, 'limit' => $limit]);
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
    perf_mark('fetchChatHistory.done', ['rows' => count($chats)]);
    return $chats;
}

function parseMessageMetadata(?string $raw): array {
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeLogDateRange(?string $startDate, ?string $endDate, string $timezone = 'America/Fortaleza'): array {
    if (!$startDate && !$endDate) {
        return [null, null];
    }
    $tz = new DateTimeZone($timezone);
    $start = null;
    $end = null;
    if ($startDate) {
        $start = DateTime::createFromFormat('Y-m-d H:i:s', $startDate . ' 00:00:00', $tz);
        if (!$start) {
            $start = new DateTime($startDate, $tz);
        }
        $start->setTime(0, 0, 0);
    }
    if ($endDate) {
        $end = DateTime::createFromFormat('Y-m-d H:i:s', $endDate . ' 23:59:59', $tz);
        if (!$end) {
            $end = new DateTime($endDate, $tz);
        }
        $end->setTime(23, 59, 59);
    }
    $startValue = $start ? $start->format('Y-m-d H:i:s') : null;
    $endValue = $end ? $end->format('Y-m-d H:i:s') : null;
    return [$startValue, $endValue];
}

function resolveLogRangeFromRequest(): array {
    $preset = isset($_GET['log_range']) ? trim((string)$_GET['log_range']) : 'today';
    $tz = new DateTimeZone('America/Fortaleza');
    $start = null;
    $end = null;
    $label = '';
    $customStart = isset($_GET['log_start']) ? trim((string)$_GET['log_start']) : '';
    $customEnd = isset($_GET['log_end']) ? trim((string)$_GET['log_end']) : '';

    if ($preset === 'today') {
        $now = new DateTime('now', $tz);
        $start = $now->format('Y-m-d');
        $end = $now->format('Y-m-d');
        $label = 'Hoje';
    } elseif ($preset === 'yesterday') {
        $now = new DateTime('now', $tz);
        $now->modify('-1 day');
        $start = $now->format('Y-m-d');
        $end = $now->format('Y-m-d');
        $label = 'Ontem';
    } elseif ($preset === 'all') {
        $label = 'Período total';
        return [
            'preset' => $preset,
            'label' => $label,
            'start' => null,
            'end' => null,
            'custom_start' => $customStart,
            'custom_end' => $customEnd
        ];
    } elseif ($preset === 'custom') {
        $label = 'Personalizado';
        $start = $customStart ?: null;
        $end = $customEnd ?: null;
    } else {
        $preset = 'today';
        $now = new DateTime('now', $tz);
        $start = $now->format('Y-m-d');
        $end = $now->format('Y-m-d');
        $label = 'Hoje';
    }

    [$normalizedStart, $normalizedEnd] = normalizeLogDateRange($start, $end);
    return [
        'preset' => $preset,
        'label' => $label,
        'start' => $normalizedStart,
        'end' => $normalizedEnd,
        'custom_start' => $customStart,
        'custom_end' => $customEnd
    ];
}

function formatLogDateTime(?string $value): string {
    if (!$value) {
        return '';
    }
    try {
        $date = new DateTime($value, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('America/Fortaleza'));
        return $date->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return $value;
    }
}

function formatLogDateForFilename(?string $value): string {
    if (!$value) {
        return '';
    }
    $parts = explode(' ', $value);
    return preg_replace('/[^0-9-]/', '', $parts[0] ?? $value);
}

function formatCommandArgsForLog($args): string {
    if (!is_array($args)) {
        return 'sem argumentos';
    }
    $clean = [];
    foreach ($args as $arg) {
        $text = trim((string)($arg ?? ''));
        if ($text !== '') {
            $clean[] = $text;
        }
    }
    return $clean ? implode(', ', $clean) : 'sem argumentos';
}

function formatCommandResultSummary(string $type, $result): ?string {
    if ($result === null || $result === '') {
        return null;
    }
    $type = strtolower($type);
    if ($type === 'dados' && is_array($result)) {
        $name = $result['nome'] ?? ($result['email'] ?? 'Cliente');
        $status = $result['status'] ?? 'sem status';
        $info = isset($result['assinatura_info']) ? ' • ' . $result['assinatura_info'] : '';
        return "{$name} está {$status}{$info}";
    }
    if (in_array($type, ['agendar', 'agendar2', 'cancelar_e_agendar2'], true) && is_array($result)) {
        $data = $result['data'] ?? [];
        $scheduledAt = $result['scheduledAt'] ?? $result['scheduled_at'] ?? $data['scheduledAt'] ?? $data['scheduled_at'] ?? null;
        $scheduledLabel = $scheduledAt ? formatLogDateTime((string)$scheduledAt) : 'horário indefinido';
        return "agendamento previsto para {$scheduledLabel}";
    }
    if (is_array($result)) {
        $payload = [];
        if (isset($result['ok'])) {
            $payload[] = 'ok=' . ($result['ok'] ? 'true' : 'false');
        }
        if (!empty($result['code'])) {
            $payload[] = 'code=' . $result['code'];
        }
        if (!empty($result['message'])) {
            $payload[] = 'msg=' . $result['message'];
        }
        if (!empty($result['data'])) {
            $payload[] = 'data=' . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($payload) {
            return implode(' | ', $payload);
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_string($result)) {
        return $result;
    }
    return (string)$result;
}

function getInstanceLogSummary(string $instanceId, ?string $start = null, ?string $end = null): array {
    $summary = [
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
    $dbPath = __DIR__ . '/chat_data.db';
    if (!file_exists($dbPath)) {
        return $summary;
    }
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $table = tableExists($db, 'messages') ? 'messages' : (tableExists($db, 'chat_history') ? 'chat_history' : '');
    if (!$table) {
        $db->close();
        return $summary;
    }

    $where = "instance_id = :instance";
    if ($start) {
        $where .= " AND timestamp >= :start";
    }
    if ($end) {
        $where .= " AND timestamp <= :end";
    }
    $stmt = $db->prepare("SELECT COUNT(*) as total, COUNT(DISTINCT remote_jid) as contacts, MIN(timestamp) as first_ts, MAX(timestamp) as last_ts FROM {$table} WHERE {$where}");
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    if ($start) {
        $stmt->bindValue(':start', $start, SQLITE3_TEXT);
    }
    if ($end) {
        $stmt->bindValue(':end', $end, SQLITE3_TEXT);
    }
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $summary['total_messages'] = (int)($row['total'] ?? 0);
    $summary['total_contacts'] = (int)($row['contacts'] ?? 0);
    $summary['first_message_at'] = $row['first_ts'] ?? '';
    $summary['last_message_at'] = $row['last_ts'] ?? '';
    $stmt->close();

    if ($table === 'messages') {
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM messages WHERE {$where} AND direction = :direction");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        if ($start) {
            $stmt->bindValue(':start', $start, SQLITE3_TEXT);
        }
        if ($end) {
            $stmt->bindValue(':end', $end, SQLITE3_TEXT);
        }
        $stmt->bindValue(':direction', 'inbound', SQLITE3_TEXT);
        $summary['total_inbound'] = (int)($stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'] ?? 0);
        $stmt->reset();
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        if ($start) {
            $stmt->bindValue(':start', $start, SQLITE3_TEXT);
        }
        if ($end) {
            $stmt->bindValue(':end', $end, SQLITE3_TEXT);
        }
        $stmt->bindValue(':direction', 'outbound', SQLITE3_TEXT);
        $summary['total_outbound'] = (int)($stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'] ?? 0);
        $stmt->close();

        $stmt = $db->prepare("SELECT metadata FROM messages WHERE {$where} AND metadata IS NOT NULL AND metadata != ''");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        if ($start) {
            $stmt->bindValue(':start', $start, SQLITE3_TEXT);
        }
        if ($end) {
            $stmt->bindValue(':end', $end, SQLITE3_TEXT);
        }
        $res = $stmt->execute();
        $commandCount = 0;
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $meta = parseMessageMetadata($row['metadata'] ?? '');
            $commands = $meta['commands'] ?? [];
            if (is_array($commands)) {
                $commandCount += count($commands);
            }
        }
        $res->finalize();
        $stmt->close();
        $summary['total_commands'] = $commandCount;
    }

    if (tableExists($db, 'scheduled_messages')) {
        $stmt = $db->prepare("SELECT status, COUNT(*) as total FROM scheduled_messages WHERE instance_id = :instance GROUP BY status");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $status = strtolower((string)($row['status'] ?? ''));
            if ($status === 'pending') {
                $summary['scheduled_pending'] = (int)$row['total'];
            } elseif ($status === 'sent') {
                $summary['scheduled_sent'] = (int)$row['total'];
            } elseif ($status === 'failed') {
                $summary['scheduled_failed'] = (int)$row['total'];
            }
        }
        $res->finalize();
        $stmt->close();
    }

    $db->close();
    return $summary;
}

function buildAllConversationsLog(string $instanceId, array $instanceInfo = [], ?string $start = null, ?string $end = null): string {
    $dbPath = __DIR__ . '/chat_data.db';
    if (!file_exists($dbPath)) {
        return "Nenhum banco de dados encontrado para exportar.\n";
    }
    $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
    $table = tableExists($db, 'messages') ? 'messages' : (tableExists($db, 'chat_history') ? 'chat_history' : '');
    if (!$table) {
        $db->close();
        return "Nenhuma tabela de mensagens encontrada.\n";
    }

    $summary = getInstanceLogSummary($instanceId, $start, $end);
    $instanceLabel = $instanceInfo['name'] ?? $instanceId;
    $lines = [];
    $lines[] = "Log completo de conversas";
    $lines[] = "Instância: {$instanceLabel}";
    $lines[] = "ID: {$instanceId}";
    $lines[] = "Gerado em: " . formatLogDateTime(gmdate('Y-m-d H:i:s'));
    $lines[] = "Total mensagens: {$summary['total_messages']}";
    $lines[] = "Total contatos: {$summary['total_contacts']}";
    $lines[] = "Mensagens recebidas: {$summary['total_inbound']}";
    $lines[] = "Mensagens enviadas: {$summary['total_outbound']}";
    $lines[] = "Comandos executados: {$summary['total_commands']}";
    $lines[] = "Agendamentos pendentes: {$summary['scheduled_pending']}";
    $lines[] = "Agendamentos enviados: {$summary['scheduled_sent']}";
    $lines[] = "Agendamentos falhados: {$summary['scheduled_failed']}";
    if (!empty($summary['last_message_at'])) {
        $lines[] = "Última atividade: " . formatLogDateTime($summary['last_message_at']);
    }
    $lines[] = str_repeat('-', 72);

    $contactMeta = [];
    if (tableExists($db, 'contact_metadata')) {
        $stmt = $db->prepare("SELECT remote_jid, contact_name, status_name FROM contact_metadata WHERE instance_id = :instance");
        $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $contactMeta[$row['remote_jid']] = $row;
        }
        $res->finalize();
        $stmt->close();
    }

    $contactStats = [];
    $where = "instance_id = :instance";
    if ($start) {
        $where .= " AND timestamp >= :start";
    }
    if ($end) {
        $where .= " AND timestamp <= :end";
    }
    $stmt = $db->prepare("SELECT remote_jid, COUNT(*) as total, MAX(timestamp) as last_ts FROM {$table} WHERE {$where} GROUP BY remote_jid");
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    if ($start) {
        $stmt->bindValue(':start', $start, SQLITE3_TEXT);
    }
    if ($end) {
        $stmt->bindValue(':end', $end, SQLITE3_TEXT);
    }
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $contactStats[$row['remote_jid']] = [
            'total' => (int)$row['total'],
            'last_ts' => $row['last_ts'] ?? ''
        ];
    }
    $res->finalize();
    $stmt->close();

    $selectColumns = $table === 'messages'
        ? "remote_jid, role, content, timestamp, direction, metadata"
        : "remote_jid, role, content, timestamp";
    $stmt = $db->prepare("SELECT {$selectColumns} FROM {$table} WHERE {$where} ORDER BY remote_jid ASC, timestamp ASC, id ASC");
    $stmt->bindValue(':instance', $instanceId, SQLITE3_TEXT);
    if ($start) {
        $stmt->bindValue(':start', $start, SQLITE3_TEXT);
    }
    if ($end) {
        $stmt->bindValue(':end', $end, SQLITE3_TEXT);
    }
    $res = $stmt->execute();
    $currentJid = null;

    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $remoteJid = $row['remote_jid'] ?? '';
        if ($remoteJid !== $currentJid) {
            $currentJid = $remoteJid;
            $meta = $contactMeta[$remoteJid] ?? [];
            $label = $meta['contact_name'] ?? $meta['status_name'] ?? $remoteJid;
            $stats = $contactStats[$remoteJid] ?? ['total' => 0, 'last_ts' => ''];
            $lines[] = "";
            $lines[] = "Conversa: {$label}";
            $lines[] = "Remote JID: {$remoteJid}";
            $lines[] = "Mensagens registradas: {$stats['total']}";
            if (!empty($stats['last_ts'])) {
                $lines[] = "Última mensagem: " . formatLogDateTime($stats['last_ts']);
            }
            $lines[] = str_repeat('-', 48);
        }

        $timestamp = formatLogDateTime($row['timestamp'] ?? '') ?: 'sem horário';
        $role = $row['role'] ?? 'desconhecido';
        $direction = $row['direction'] ?? '';
        if ($direction === '') {
            $direction = $role === 'assistant' ? 'outbound' : 'inbound';
        }
        $directionLabel = $direction === 'outbound' ? 'ENVIADA' : 'RECEBIDA';
        $content = trim((string)($row['content'] ?? ''));

        $lines[] = "[{$timestamp}] [{$directionLabel}] {$role}";
        $lines[] = $content;

        if ($table === 'messages') {
            $metadata = parseMessageMetadata($row['metadata'] ?? '');
            $commandList = isset($metadata['commands']) && is_array($metadata['commands']) ? $metadata['commands'] : [];
            if ($commandList) {
                foreach ($commandList as $cmd) {
                    $type = (string)($cmd['type'] ?? 'função');
                    $argsText = formatCommandArgsForLog($cmd['args'] ?? []);
                    $lines[] = "  [COMANDO] {$type}({$argsText})";
                    $summary = formatCommandResultSummary($type, $cmd['result'] ?? null);
                    if ($summary) {
                        $lines[] = "  [RETORNO] {$summary}";
                    }
                }
            }

            $metaNotes = [];
            if (!empty($metadata['severity'])) {
                $metaNotes[] = 'severity=' . $metadata['severity'];
            }
            if (!empty($metadata['error'])) {
                $metaNotes[] = 'error=' . $metadata['error'];
            }
            if (!empty($metadata['debug'])) {
                $metaNotes[] = 'debug=true';
            }
            if ($metaNotes) {
                $lines[] = "  [METADATA] " . implode(' | ', $metaNotes);
            }
        }

        $lines[] = "";
    }

    $res->finalize();
    $stmt->close();
    $db->close();

    return implode("\n", $lines);
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
debug_log('PANEL_PASSWORD from _ENV: ' . (isset($_ENV['PANEL_PASSWORD']) ? '***' : 'not set'));
debug_log('PANEL_USER_EMAIL from getenv: ' . (getenv('PANEL_USER_EMAIL') ?: 'not set'));

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

function rrmdir(string $dir): void
{
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_ai_config']) && isset($_GET['instance'])) {
    perf_mark('ajax.ai_config.start');
    $instanceIdForAjax = $_GET['instance'];
    $instanceRecord = loadInstanceRecordFromDatabase($instanceIdForAjax);
    $aiPayload = $instanceRecord['ai'] ?? [];
    header('Content-Type: application/json; charset=utf-8');
    if (!$instanceRecord) {
        echo json_encode([
            'ok' => false,
            'error' => 'Instância não encontrada'
        ]);
        perf_log('ajax.ai_config', ['status' => 'not_found', 'instance' => $instanceIdForAjax]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'ai' => $aiPayload,
        'instance' => $instanceRecord['instance_id'] ?? $instanceIdForAjax
    ]);
    perf_log('ajax.ai_config', ['status' => 'ok', 'instance' => $instanceIdForAjax]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_save_ai'])) {
    perf_mark('ajax.save_ai.start');
    header('Content-Type: application/json; charset=utf-8');
    $targetInstanceId = $_GET['instance'] ?? null;
    if (!$targetInstanceId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Instância não encontrada']);
        perf_log('ajax.save_ai', ['status' => 'not_found']);
        exit;
    }
    $instanceRecord = loadInstanceRecordFromDatabase($targetInstanceId);
    if (!$instanceRecord) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Instância não encontrada']);
        perf_log('ajax.save_ai', ['status' => 'not_found']);
        exit;
    }

    $payload = $_POST;
    $enabled = !empty($payload['ai_enabled']) && $payload['ai_enabled'] !== '0';
    $provider = in_array($payload['ai_provider'] ?? 'openai', ['openai', 'gemini'], true)
        ? $payload['ai_provider']
        : 'openai';
    $model = trim($payload['ai_model'] ?? 'gpt-4.1-mini');
    $systemPrompt = trim($payload['ai_system_prompt'] ?? '');
    $assistantPrompt = trim($payload['ai_assistant_prompt'] ?? '');
    $assistantId = trim($payload['ai_assistant_id'] ?? '');
    $historyLimit = max(1, (int)($payload['ai_history_limit'] ?? 20));
    $temperature = max(0, floatval($payload['ai_temperature'] ?? 0.3));
    $maxTokens = max(64, (int)($payload['ai_max_tokens'] ?? 600));
    $multiInputDelay = max(0, (int)($payload['ai_multi_input_delay'] ?? 0));
    $openaiMode = in_array($payload['openai_mode'] ?? 'responses', ['responses', 'assistants'], true)
        ? $payload['openai_mode']
        : 'responses';
    $openaiApiKey = trim($payload['openai_api_key'] ?? '');
    $geminiApiKey = trim($payload['gemini_api_key'] ?? '');
    $geminiInstruction = trim($payload['gemini_instruction'] ?? '');

    if ($enabled && $provider === 'openai') {
        if (!$openaiApiKey) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'OpenAI API key é obrigatória']);
            perf_log('ajax.save_ai', ['status' => 'invalid_openai_key']);
            exit;
        }
        if (!preg_match('/^sk-[A-Za-z0-9_.-]{48,}$/', $openaiApiKey)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Formato da OpenAI API key inválido']);
            perf_log('ajax.save_ai', ['status' => 'invalid_openai_format']);
            exit;
        }
        if ($openaiMode === 'assistants' && $assistantId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Assistant ID é obrigatório']);
            perf_log('ajax.save_ai', ['status' => 'missing_assistant_id']);
            exit;
        }
    }

    if ($enabled && $provider === 'gemini' && !$geminiApiKey) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Gemini API key é obrigatória']);
        perf_log('ajax.save_ai', ['status' => 'invalid_gemini_key']);
        exit;
    }

    $nodePayload = [
        'enabled' => $enabled,
        'provider' => $provider,
        'model' => $model,
        'system_prompt' => $systemPrompt,
        'assistant_prompt' => $assistantPrompt,
        'assistant_id' => $assistantId,
        'history_limit' => $historyLimit,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
        'multi_input_delay' => $multiInputDelay,
        'openai_api_key' => $openaiApiKey,
        'openai_mode' => $openaiMode,
        'gemini_api_key' => $geminiApiKey,
        'gemini_instruction' => $geminiInstruction,
    ];

    $port = (int)($instanceRecord['port'] ?? 0);
    if (!$port) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Porta da instância inválida']);
        perf_log('ajax.save_ai', ['status' => 'invalid_port']);
        exit;
    }

    $nodeUrl = "http://127.0.0.1:{$port}/api/ai-config";
    $ch = curl_init($nodeUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($nodePayload));
    $nodeResp = curl_exec($ch);
    $nodeErr = curl_error($ch);
    $nodeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($nodeErr) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => "Falha ao conectar no Node: {$nodeErr}"]);
        perf_log('ajax.save_ai', ['status' => 'node_error']);
        exit;
    }
    if ($nodeCode >= 400) {
        $decoded = json_decode($nodeResp, true);
        $detail = $decoded['error'] ?? ($decoded['detail'] ?? 'Erro no Node');
        http_response_code($nodeCode);
        echo json_encode(['success' => false, 'error' => $detail]);
        perf_log('ajax.save_ai', ['status' => 'node_fail', 'http' => $nodeCode]);
        exit;
    }

    echo json_encode(['success' => true]);
    perf_log('ajax.save_ai', ['status' => 'ok']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_reset'])) {
    perf_mark('ajax.qr_reset.start');
    $instanceId = trim((string) $_POST['qr_reset']);
    header('Content-Type: application/json; charset=utf-8');
    if ($instanceId === '' || !preg_match('/^[\w-]{1,64}$/', $instanceId)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Instância inválida.']);
        perf_log('ajax.qr_reset', ['status' => 'invalid']);
        exit;
    }
    $instanceRecord = loadInstanceRecordFromDatabase($instanceId);
    if (!$instanceRecord) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Instância não encontrada.']);
        perf_log('ajax.qr_reset', ['status' => 'not_found', 'instance' => $instanceId]);
        exit;
    }
    $authDir = __DIR__ . '/auth_' . $instanceId;
    rrmdir($authDir);
    $restartScript = __DIR__ . '/restart_instance.sh';
    if (is_file($restartScript)) {
        @exec('bash ' . escapeshellarg($restartScript) . ' ' . escapeshellarg($instanceId) . ' >/dev/null 2>&1');
    }
    echo json_encode([
        'ok' => true,
        'message' => 'Sessão reiniciada. Aguarde alguns minutos para o QR ser gerado.'
    ]);
    perf_log('ajax.qr_reset', ['status' => 'ok', 'instance' => $instanceId]);
    exit;
}

// --- Carregar instâncias ---
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

perf_mark('statuses.build.start');
list($statuses, $connectionStatuses) = buildInstanceStatuses($instances);
perf_mark('statuses.built', ['count' => count($statuses)]);
$dashboardBaseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($dashboardBaseUrl === '') {
  $dashboardBaseUrl = '/';
}
$dashboardLogoUrl = "{$dashboardBaseUrl}/assets/maestro-logo.png";

if (!function_exists('buildPublicBaseUrl')) {
    function buildPublicBaseUrl(string $basePath): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $normalized = rtrim($basePath, '/');
        return "{$scheme}://{$host}{$normalized}";
    }
}

if (!function_exists('renderSidebarContent')) {
    function renderSidebarContent(array $instances, ?string $selectedInstanceId, array $statuses, array $connectionStatuses, bool $showAdminControls = true)
    {
    global $dashboardBaseUrl, $dashboardLogoUrl;
    ?>
    <div class="p-6 border-b border-mid">
      <a href="<?= htmlspecialchars($dashboardBaseUrl) ?>" class="flex items-center gap-3 inline-flex group">
        <div class="flex items-center justify-center h-12">
          <img src="<?= htmlspecialchars($dashboardLogoUrl) ?>" width="56" style="height:auto;" alt="Logomarca Maestro">
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
          $secretaryDetails = $inst['secretary'] ?? [];
          $secretaryEnabledTag = !empty($secretaryDetails['enabled']);
          $quickReplies = $secretaryDetails['quick_replies'] ?? [];
          if (empty($quickReplies)) {
            $legacyTerm1 = trim((string)($secretaryDetails['term_1'] ?? ''));
            $legacyResp1 = trim((string)($secretaryDetails['response_1'] ?? ''));
            $legacyTerm2 = trim((string)($secretaryDetails['term_2'] ?? ''));
            $legacyResp2 = trim((string)($secretaryDetails['response_2'] ?? ''));
            if ($legacyTerm1 !== '' && $legacyResp1 !== '') {
              $quickReplies[] = ['term' => $legacyTerm1, 'response' => $legacyResp1];
            }
            if ($legacyTerm2 !== '' && $legacyResp2 !== '') {
              $quickReplies[] = ['term' => $legacyTerm2, 'response' => $legacyResp2];
            }
          }
          $quickRepliesEnabledTag = !empty($quickReplies);
          $transcriptionDetails = $inst['audio_transcription'] ?? [];
          $transcriptionEnabledTag = !empty($transcriptionDetails['enabled']);
          if ($statuses[$id] !== 'Running') {
            $statusClass = 'status-server-down';
          } elseif (strtolower($connectionStatuses[$id] ?? '') !== 'connected') {
            $statusClass = 'status-whatsapp-down';
          } else {
            $statusClass = 'status-ok';
          }
          $autoReplyClass = $aiEnabledTag ? 'auto-reply' : '';
          $serverRunning = $statuses[$id] === 'Running';
          $whatsappConnected = strtolower($connectionStatuses[$id] ?? '') === 'connected';
        ?>
        <div class="instance-card <?= $statusClass ?> <?= $autoReplyClass ?> <?= $serverRunning ? 'is-running' : '' ?> <?= $whatsappConnected ? 'whatsapp-connected' : '' ?> <?= $isSelected ? 'is-selected bg-light' : 'bg-white hover:bg-light' ?> block w-full p-3 rounded-xl border transition">
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
          <?php if ($aiEnabledTag || $secretaryEnabledTag || $quickRepliesEnabledTag || $transcriptionEnabledTag): ?>
            <div class="instance-icons">
              <?php if ($aiEnabledTag): ?>
                <div class="ai-corner" title="<?= htmlspecialchars($aiProviderLabel) ?>">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <rect x="4" y="7" width="16" height="12" rx="3" stroke-width="1.6"></rect>
                    <path d="M9 7V5a3 3 0 016 0v2" stroke-width="1.6"></path>
                    <circle cx="9.5" cy="13" r="1" fill="currentColor"></circle>
                    <circle cx="14.5" cy="13" r="1" fill="currentColor"></circle>
                    <path d="M9 16c1.5 1 4.5 1 6 0" stroke-width="1.4" stroke-linecap="round"></path>
                  </svg>
                </div>
              <?php endif; ?>
              <?php if ($secretaryEnabledTag): ?>
                <div class="feature-badge" title="Secretária virtual">📳</div>
              <?php endif; ?>
              <?php if ($quickRepliesEnabledTag): ?>
                <div class="feature-badge" title="Respostas rápidas">⏩</div>
              <?php endif; ?>
              <?php if ($transcriptionEnabledTag): ?>
                <div class="feature-badge" title="Transcrição de áudio">🔊</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
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

$logRange = resolveLogRangeFromRequest();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export_conversations']) && isset($_GET['instance'])) {
    $exportInstanceId = trim((string)$_GET['instance']);
    if ($exportInstanceId === '' || !isset($sidebarInstances[$exportInstanceId])) {
        http_response_code(404);
        echo "Instância não encontrada para exportação.";
        exit;
    }
    $rangeTag = 'hoje';
    if ($logRange['preset'] === 'all') {
        $rangeTag = 'total';
    } elseif ($logRange['preset'] === 'yesterday') {
        $rangeTag = 'ontem';
    } elseif ($logRange['preset'] === 'custom') {
        $startLabel = formatLogDateForFilename($logRange['start']) ?: 'inicio';
        $endLabel = formatLogDateForFilename($logRange['end']) ?: 'fim';
        $rangeTag = "{$startLabel}_{$endLabel}";
    }
    $filename = "conversas-{$exportInstanceId}-{$rangeTag}-" . date('Ymd-His') . ".txt";
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo buildAllConversationsLog(
        $exportInstanceId,
        $sidebarInstances[$exportInstanceId] ?? [],
        $logRange['start'],
        $logRange['end']
    );
    exit;
}

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

$logQueryParams = [
    'instance' => $selectedInstanceId ?? '',
    'log_range' => $logRange['preset'],
    'log_start' => $logRange['custom_start'],
    'log_end' => $logRange['custom_end']
];
$exportLogUrl = $selectedInstanceId ? ('?' . http_build_query(array_merge($logQueryParams, ['export_conversations' => 1]))) : '#';

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
    perf_mark('ajax.send.start');
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
        perf_log('ajax.send', ['status' => 'not_found']);
        exit;
    }

    if (!$phone || !$message) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Telefone e mensagem são obrigatórios']);
        perf_log('ajax.send', ['status' => 'invalid']);
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
        perf_log('ajax.send', ['status' => 'curl_error']);
        exit;
    }

    if (!$responsePayload || !isset($responsePayload['ok']) || !$responsePayload['ok'] || $httpCode >= 400) {
        $errorMessage = $responsePayload['error'] ?? ($responsePayload['detail'] ?? "Erro HTTP {$httpCode}");
        http_response_code($httpCode >= 400 ? $httpCode : 500);
        echo json_encode(['ok' => false, 'error' => $errorMessage]);
        perf_log('ajax.send', ['status' => 'error', 'http' => $httpCode]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Mensagem encaminhada com sucesso',
        'remoteJid' => $responsePayload['to'] ?? null,
        'apiResponse' => $responsePayload
    ]);
    perf_log('ajax.send', ['status' => 'ok']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax_history'])) {
    perf_mark('ajax.history.start');
    header('Content-Type: application/json; charset=utf-8');
    if (!$selectedInstance) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Instância não encontrada']);
        perf_log('ajax.history', ['status' => 'not_found']);
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
                perf_log('ajax.history', ['status' => 'ok', 'source' => 'node', 'rows' => count($nodeChats)]);
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
        perf_log('ajax.history', ['status' => 'ok', 'source' => 'sqlite', 'rows' => count($chats)]);
    } catch (Exception $err) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erro ao ler histórico']);
        perf_log('ajax.history', ['status' => 'error']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_ai_test'])) {
    perf_mark('ajax.ai_test.start');
    if (!$selectedInstance) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Instância não encontrada']);
        perf_log('ajax.ai_test', ['status' => 'not_found']);
        exit;
    }

    $payload = $_POST;
    if (empty($payload)) {
        $dataRaw = file_get_contents('php://input');
        $payload = json_decode($dataRaw, true);
        if (!is_array($payload)) {
            $payload = [];
        }
    }

    $userMessage = trim($payload['message'] ?? '');
    $remoteJid = trim($payload['remote_jid'] ?? '');
    if (!$userMessage) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Mensagem é obrigatória']);
        perf_log('ajax.ai_test', ['status' => 'invalid']);
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
        perf_log('ajax.ai_test', ['status' => 'curl_error']);
        exit;
    }

    $result = json_decode($resp, true);
    $isValidJson = is_array($result);
    if ($httpCode >= 400 || !$isValidJson) {
        http_response_code($httpCode >= 400 ? $httpCode : 500);
        $message = $isValidJson ? ($result['error'] ?? 'Resposta inválida do servidor AI') : 'Resposta inválida do servidor AI';
        $rawPayload = $isValidJson ? $result : trim($resp ?: '');
        echo json_encode(['ok' => false, 'error' => $message, 'raw' => $rawPayload]);
        perf_log('ajax.ai_test', ['status' => 'error', 'http' => $httpCode]);
        exit;
    }

    echo json_encode($result);
    perf_log('ajax.ai_test', ['status' => 'ok']);
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
    $markerPath = __DIR__ . '/deleted_instances.txt';
    file_put_contents($markerPath, $deleteId . PHP_EOL, FILE_APPEND | LOCK_EX);
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
        $errorMessage = "QR request retornou código HTTP {$httpCode}";
        if ($httpCode === 404) {
            $errorMessage = "QR ainda não disponível";
        }
        return [
            'ok' => false,
            'status' => $statusCode,
            'error' => $errorMessage
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
        $port = $instances[$id]['port'] ?? null;
        if ($port) {
            $url = "http://127.0.0.1:{$port}/disconnect";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($error || $httpCode < 200 || $httpCode >= 300) {
                debug_log("Disconnect API failed: " . ($error ?: "HTTP {$httpCode}") . " response=" . ($response ?: 'empty'));
            } else {
                debug_log('Disconnect API success for ' . $id);
            }
        } else {
            debug_log('Disconnect requested but port not found for ' . $id);
        }
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

perf_mark('render.ready', [
    'instance' => $selectedInstanceId ?? '',
    'instances' => count($instances),
    'manager' => $isManager ? 1 : 0
]);
perf_log('index.php render', [
    'path' => $_SERVER['REQUEST_URI'] ?? '',
    'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
]);

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

  <style>
    html, body { font-family: Inter, system-ui, sans-serif; }

    .gemini-instruction-expanded {
      position: fixed;
      inset: 24px;
      z-index: 60;
      background: #ffffff;
      padding: 16px;
      border-radius: 20px;
      border: 1px solid #CBD5E1;
      box-shadow: 0 24px 48px rgba(15, 23, 42, 0.2);
      display: flex;
      flex-direction: column;
    }

    .gemini-instruction-expanded textarea {
      flex: 1;
      min-height: 60vh;
    }

    body.gemini-instruction-lock {
      overflow: hidden;
    }
    body { overflow-x: hidden; }
    .min-h-screen.flex { min-width: 0; }
    main { min-width: 0; }
    .grid { min-width: 0; }
    .tour-help-button {
      position: fixed;
      right: 24px;
      bottom: 24px;
      width: 52px;
      height: 52px;
      border-radius: 999px;
      background: #2563EB;
      color: #fff;
      box-shadow: 0 14px 28px rgba(15, 23, 42, 0.22);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      font-weight: 700;
      border: none;
      cursor: pointer;
      z-index: 60;
    }
    .tour-help-button:hover {
      filter: brightness(0.95);
    }
    .tour-help-button:focus-visible {
      outline: 2px solid #1D4ED8;
      outline-offset: 3px;
    }

    .instance-card {
      position: relative;
      border-width: 2px;
      padding: 0.6rem 0.75rem;
      transform-origin: left center;
      transition: transform 0.18s ease, box-shadow 0.18s ease, background-color 0.18s ease;
    }
    .instance-card.is-running::before {
      content: "";
      position: absolute;
      top: -2px;
      left: -2px;
      width: 0;
      height: 0;
      border-top: 18px solid #22c55e;
      border-right: 18px solid transparent;
    }
    .instance-card.whatsapp-connected::after {
      content: "";
      position: absolute;
      top: -2px;
      right: -2px;
      width: 0;
      height: 0;
      border-top: 18px solid #3b82f6;
      border-left: 18px solid transparent;
    }
    .instance-card.status-server-down {
      border-color: #ef4444;
    }
    .instance-card.status-whatsapp-down {
      border-color: #f59e0b;
    }
    .instance-card.status-ok {
      border-color: #22c55e;
    }
    .instance-card.is-selected {
      transform: scale(1.04);
      z-index: 2;
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12), 0 0 0 1px rgba(30, 64, 175, 0.35) inset;
    }
    .instance-icons {
      position: absolute;
      left: 10px;
      bottom: 10px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .ai-corner {
      width: 24px;
      height: 24px;
      border-radius: 999px;
      background: rgba(15, 23, 42, 0.08);
      color: #0f172a;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .feature-badge {
      font-size: 16px;
      line-height: 1;
      filter: drop-shadow(0 4px 8px rgba(15, 23, 42, 0.15));
    }
    .instance-sticky-header {
      position: sticky;
      top: 0;
      z-index: 40;
      background: rgba(241, 245, 249, 0.96);
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 10px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
      backdrop-filter: blur(8px);
    }
    .quick-reply-overlay {
      position: fixed;
      inset: 0;
      z-index: 80;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(15, 23, 42, 0.55);
      backdrop-filter: blur(4px);
      padding: 24px;
    }
    .quick-reply-overlay.active {
      display: flex;
    }
    .quick-reply-panel {
      width: min(720px, 92vw);
      background: #fff;
      border-radius: 18px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 20px 40px rgba(15, 23, 42, 0.25);
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .quick-reply-panel textarea {
      min-height: 40vh;
    }
    .prompt-overlay {
      position: fixed;
      inset: 0;
      z-index: 85;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(15, 23, 42, 0.55);
      backdrop-filter: blur(4px);
      padding: 24px;
    }
    .prompt-overlay.active {
      display: flex;
    }
    .prompt-panel {
      width: min(760px, 92vw);
      background: #fff;
      border-radius: 18px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 20px 40px rgba(15, 23, 42, 0.25);
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .prompt-panel textarea {
      min-height: 45vh;
    }
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
    <div class="instance-sticky-header">
      <div class="text-sm text-slate-500">Instância selecionada</div>
      <div class="font-semibold text-dark"><?= htmlspecialchars($selectedInstance['name'] ?? 'Nenhuma instância') ?></div>
    </div>

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
          <h1 id="instanceTitle" class="text-2xl font-semibold"><?= htmlspecialchars($selectedInstance['name'] ?? 'Nenhuma instância') ?></h1>
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

      <div id="instanceActions" class="flex gap-2">
        <?php if ($selectedInstance && strtolower($connectionStatuses[$selectedInstanceId] ?? '') !== 'connected' && $statuses[$selectedInstanceId] === 'Running'): ?>
          <button id="connectQrButton" onclick="openQRModal('<?= $selectedInstanceId ?>')" class="px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">
            Conectar QR
          </button>
        <?php endif; ?>
        <?php if ($selectedInstance && strtolower($connectionStatuses[$selectedInstanceId] ?? '') === 'connected'): ?>
          <form method="POST" class="inline">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="disconnect" value="<?= $selectedInstanceId ?>">
            <button id="disconnectButton" type="submit" class="px-4 py-2 rounded-xl bg-error text-white font-medium hover:opacity-90">
              Desconectar
            </button>
          </form>
        <?php endif; ?>
        <?php if ($selectedInstance): ?>
          <a id="deleteInstanceButton" href="?delete=<?= $selectedInstanceId ?>" onclick="return confirm('Tem certeza?')" class="px-4 py-2 rounded-xl bg-error text-white font-medium hover:opacity-90">
            Deletar
          </a>
        <?php endif; ?>
        <button id="saveChangesButton" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
          Salvar alterações
        </button>
      </div>
    </div>

    <!-- GRID -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

      <!-- ENVIO -->
      <section id="sendMessageSection" class="xl:col-span-1 bg-white border border-mid rounded-2xl p-6">
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
          <div class="grid grid-cols-1 gap-4">
            <div>
              <label class="text-xs text-slate-500">Número destino</label>
              <input name="phone" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     placeholder="5585999999999" required>
            </div>

            <div>
              <label class="text-xs text-slate-500">Mensagem</label>
              <textarea name="message" rows="3" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
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

      <section id="assetUploadSection" class="xl:col-span-1 bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-4">Upload de arquivos</div>
        <p class="text-xs text-slate-500">
          Envie imagens, vídeos ou áudios para gerar o código que o bot pode usar (IMG, VIDEO, AUDIO). Agora o código sai como caminho local relativo (uploads/...).
        </p>
        <form id="assetUploadForm" method="POST" action="assets/upload_asset.php?instance=<?= urlencode($selectedInstanceId ?? '') ?>" enctype="multipart/form-data" class="mt-4 space-y-3">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
          <div>
            <label class="text-xs text-slate-500">Arquivo</label>
            <input id="assetFileInput" type="file" name="asset_file" accept="image/*,video/*,audio/*"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" required>
          </div>
          <button id="assetUploadButton" type="button" name="upload_asset"
                  class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
            Enviar arquivo
          </button>
          <div id="assetUploadProgress" class="hidden">
            <div class="text-xs text-slate-500 mb-2">Enviando...</div>
            <div class="w-full h-2 rounded-full bg-slate-200 overflow-hidden">
              <div id="assetUploadProgressBar" class="h-full bg-primary" style="width:0%"></div>
            </div>
          </div>
          <?php if ($assetUploadMessage): ?>
            <p class="text-sm text-success mt-2"><?= htmlspecialchars($assetUploadMessage) ?></p>
          <?php elseif ($assetUploadError): ?>
            <p class="text-sm text-error mt-2"><?= htmlspecialchars($assetUploadError) ?></p>
          <?php endif; ?>
          <div id="assetUploadCodeWrap" class="mt-3 rounded-xl border border-mid bg-slate-50 p-3 text-xs text-slate-600 <?= $assetUploadCode ? '' : 'hidden' ?>">
            <div class="text-[11px] text-slate-500 uppercase tracking-widest">Código para o bot</div>
            <div id="assetUploadCode" class="mt-2 font-semibold text-slate-800 break-all"><?= htmlspecialchars($assetUploadCode) ?></div>
          </div>
        </form>
      </section>

      <!-- CONFIG RÁPIDA -->
    <aside id="quickConfigSection" class="bg-white border border-mid rounded-2xl p-6">
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
        <button id="quickConfigSaveButton" type="submit"
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

    <section id="curlExampleSection" class="bg-white border border-mid rounded-2xl p-6">
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
    $alarmConfig = $selectedInstance['alarms'] ?? [];
    $audioTranscriptionConfig = $selectedInstance['audio_transcription'] ?? [];
    $audioTranscriptionEnabled = !empty($audioTranscriptionConfig['enabled']);
    $audioTranscriptionGeminiApiKey = $audioTranscriptionConfig['gemini_api_key'] ?? '';
    $audioTranscriptionPrefix = $audioTranscriptionConfig['prefix'] ?? '🔊';
    $secretaryConfig = $selectedInstance['secretary'] ?? [];
    $secretaryEnabled = !empty($secretaryConfig['enabled']);
    $secretaryIdleHours = max(0, (int)($secretaryConfig['idle_hours'] ?? 0));
    $secretaryInitialResponse = $secretaryConfig['initial_response'] ?? '';
    $secretaryQuickReplies = $secretaryConfig['quick_replies'] ?? [];
    ?>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

      <section id="aiSettingsSection" class="xl:col-span-2 bg-white border border-mid rounded-2xl p-6">
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
            <div class="flex items-start justify-between gap-2">
              <label class="text-xs text-slate-500">System prompt</label>
              <button id="openaiSystemExpandBtn" type="button"
                      class="text-xs text-slate-600 border border-slate-300 rounded-full px-3 py-1 hover:border-primary hover:text-primary transition">
                Expandir
              </button>
            </div>
            <textarea id="aiSystemPrompt" rows="4"
                      class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                      placeholder="Descreva o papel do assistente"><?= htmlspecialchars($aiSystemPrompt) ?></textarea>
          </div>

          <div class="space-y-2">
            <div class="flex items-start justify-between gap-2">
              <label class="text-xs text-slate-500">Assistant instructions</label>
              <div class="flex items-center gap-2">
                <button id="openaiAssistantExpandBtn" type="button"
                        class="text-xs text-slate-600 border border-slate-300 rounded-full px-3 py-1 hover:border-primary hover:text-primary transition">
                  Expandir
                </button>
                <button id="aiFunctionsButton" type="button"
                        class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                  Funções disponíveis
                </button>
              </div>
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
                <div class="flex items-center gap-2">
                  <button id="geminiExpandBtn" type="button"
                          class="text-xs text-slate-600 border border-slate-300 rounded-full px-3 py-1 hover:border-primary hover:text-primary transition">
                    Expandir
                  </button>
                  <button id="geminiFunctionsButton" type="button"
                          class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                    Funções disponíveis
                  </button>
                </div>
              </div>
              <div id="geminiInstructionWrap" class="relative">
                <textarea id="geminiInstruction" rows="4"
                          class="w-full px-3 py-2 rounded-xl border border-mid bg-light"
                          placeholder="Instrua o Gemini"><?= htmlspecialchars($aiGeminiInstruction) ?></textarea>
              </div>
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
                <span class="font-semibold text-slate-800">set_variavel("chave","valor") / get_variavel("chave")</span> – variáveis persistentes por instância (não vinculadas ao contato).
              </li>
              <li>
                <span class="font-semibold text-slate-800">Contexto automático</span> – estado, contexto e status_followup são injetados em todos os prompts para a IA (não aparecem para o usuário final).
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
                <span class="font-semibold text-slate-800">mail("destino","assunto","corpo","remetente")</span> – envia um e-mail com sendmail local; o remetente é opcional e, se omitido, usa <code>noreply@janeri.com.br</code>.
              </li>
              <li>
                <span class="font-semibold text-slate-800">get_web("URL")</span> – busca até 1.200 caracteres de outra página para contexto.
              </li>
              <li>
                <span class="font-semibold text-slate-800">IMG:uploads/imagem.jpg|Legenda opcional</span> – envia a imagem indicada para o usuário (local em assets/uploads). Também aceita URL remota (http/https) e faz cache.
              </li>
              <li>
                <span class="font-semibold text-slate-800">VIDEO:uploads/video.mp4|Legenda opcional</span> – envia o vídeo indicado para o usuário (local em assets/uploads). Também aceita URL remota (http/https) e faz cache.
              </li>
              <li>
                <span class="font-semibold text-slate-800">AUDIO:uploads/audio.mp3</span> – envia o áudio indicado para o usuário (local em assets/uploads). Também aceita URL remota (http/https) e faz cache.
              </li>
              <li>
                <span class="font-semibold text-slate-800">CONTACT:+55DDDNNNNNNNN|Nome|Nota opcional</span> – envia um cartão de contato (o nome e a nota são opcionais). O bot também entende quando o usuário envia um contato e repassa os dados para a IA.
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
- set_variavel("chave","valor") / get_variavel("chave"): variáveis persistentes por instância.
- optout(): cancela follow-ups pendentes e marca que o cliente não deve receber novas tentativas.
- status_followup(): resumo de estado, trilhas ativas e próximos agendamentos pendentes.
- estado, contexto e status_followup são injetados automaticamente em todo prompt enviado à IA.
- tempo_sem_interacao(): retorna há quantos segundos o cliente está em silêncio, útil para ajustar o tom (curto = gentil, longo = acolhedor).
- log_evento("categoria","descrição","json_opcional"): auditoria leve para métricas.
- boomerang(): sinaliza envio imediato de "Boomerang acionado".
- whatsapp("numero","mensagem"), mail("destino","assunto","corpo","remetente") e get_web("URL") seguem como antes (remetente opcional; padrão noreply@janeri.com.br).
- Use `IMG:uploads/<arquivo>` para enviar imagens direto de assets/uploads. Também aceita URL remota (http/https) e faz cache. Você pode anotar uma legenda com `|Legenda`. Combine com `#` para manter o texto organizado.
- Use `VIDEO:uploads/<arquivo>` para enviar vídeos direto de assets/uploads. Também aceita URL remota (http/https) e faz cache. Legenda opcional com `|Legenda`.
- Use `AUDIO:uploads/<arquivo>` para enviar áudios direto de assets/uploads. Também aceita URL remota (http/https) e faz cache.
- Use `CONTACT:<telefone>|Nome|Nota` para enviar um cartão vCard; o bot também repassa contatos recebidos para a IA no formato “CONTATO RECEBIDO”.

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

      <section id="audioTranscriptionSection" class="bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-1">Transcrever áudio</div>
        <p class="text-sm text-slate-500 mb-4">
          Responda automaticamente com a transcrição do áudio recebido nesta instância.
        </p>

        <form id="audioTranscriptionForm" class="space-y-4" onsubmit="return false;">
          <div class="flex items-center gap-2">
            <input type="checkbox" id="audioTranscriptionEnabled" class="h-4 w-4 rounded"
                   <?= $audioTranscriptionEnabled ? 'checked' : '' ?>>
            <label for="audioTranscriptionEnabled" class="text-sm text-slate-600">
              Habilitar transcrição de áudio
            </label>
          </div>

          <div>
            <label class="text-xs text-slate-500">Gemini API Key</label>
            <div class="relative mt-1">
              <input id="audioTranscriptionGeminiKey" type="password" autocomplete="new-password"
                     class="w-full px-3 py-2 rounded-xl border border-mid bg-light pr-10"
                     placeholder="GAPI..." value="<?= htmlspecialchars($audioTranscriptionGeminiApiKey) ?>">
              <button id="toggleAudioGeminiKey" type="button"
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
              Necessário para transcrever arquivos de áudio.
            </p>
          </div>

          <div>
            <label class="text-xs text-slate-500">Prefixo da transcrição</label>
            <input id="audioTranscriptionPrefix" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($audioTranscriptionPrefix) ?>" placeholder="🔊">
            <p class="text-[11px] text-slate-500 mt-1">
              Será enviado como “PREFIXO: texto transcrito”.
            </p>
          </div>

          <div class="flex flex-wrap gap-2 items-center">
            <button type="button" id="saveAudioTranscriptionButton"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar
            </button>
            <p id="audioTranscriptionStatus" aria-live="polite" class="text-sm text-slate-500 mt-2 sm:mt-0">
              &nbsp;
            </p>
          </div>
        </form>
      </section>

      <section id="secretarySection" class="bg-white border border-mid rounded-2xl p-6">
        <div class="font-medium mb-1">Secretária virtual</div>
        <p class="text-sm text-slate-500 mb-4">
          Responda automaticamente quando o contato voltar após um tempo sem interação.
        </p>

        <form id="secretaryForm" class="space-y-4" onsubmit="return false;">
          <div class="flex items-center gap-2">
            <input type="checkbox" id="secretaryEnabled" class="h-4 w-4 rounded"
                   <?= $secretaryEnabled ? 'checked' : '' ?>>
            <label for="secretaryEnabled" class="text-sm text-slate-600">
              Habilitar secretária virtual
            </label>
          </div>

          <div>
            <label class="text-xs text-slate-500">Tempo sem contato (horas)</label>
            <input id="secretaryIdleHours" type="number" min="1" step="1"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars((string)$secretaryIdleHours) ?>">
          </div>

          <div>
            <label class="text-xs text-slate-500">Resposta inicial</label>
            <textarea id="secretaryInitialResponse" rows="3"
                      class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                      placeholder="oi, já já lhe atendo"><?= htmlspecialchars($secretaryInitialResponse) ?></textarea>
          </div>

          <div class="space-y-3">
            <div class="text-xs text-slate-500 uppercase tracking-widest">Respostas rápidas</div>
            <div id="secretaryQuickReplies" class="grid grid-cols-1 gap-3"></div>
            <button type="button" id="addSecretaryReply"
                    class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
              Adicionar resposta rápida
            </button>
          </div>

          <div class="flex flex-wrap gap-2 items-center">
            <button type="button" id="saveSecretaryButton"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar
            </button>
            <p id="secretaryStatus" aria-live="polite" class="text-sm text-slate-500 mt-2 sm:mt-0">
              &nbsp;
            </p>
          </div>
        </form>
      </section>

      <section id="alarmSettingsSection" class="xl:col-span-2 bg-white border border-mid rounded-2xl p-6">
        <div class="flex items-start justify-between">
          <div>
            <div class="font-medium mb-1">Alarmes de instância</div>
            <p class="text-sm text-slate-500">
              Receba alertas por e-mail quando algo crítico acontecer na instância selecionada.
            </p>
          </div>
          <span class="text-xs text-slate-500">Configurado via serviço Node</span>
        </div>
        <form id="alarmConfigForm" class="space-y-5 mt-4" onsubmit="return false;">
          <?php
          $alarmEvents = [
            'whatsapp' => [
              'label' => 'WhatsApp desconectado',
              'help' => 'Dispara sempre que a conexão com o WhatsApp cair.'
            ],
            'server' => [
              'label' => 'Servidor desconectado',
              'help' => 'Detecta quando a porta da instância não responde (rodar pelo monitor).'
            ],
            'error' => [
              'label' => 'Erro encontrado',
              'help' => 'Quando o serviço registrar um erro crítico e parar de funcionar corretamente.'
            ]
          ];
          foreach ($alarmEvents as $eventKey => $eventMeta):
            $alarmEntry = $alarmConfig[$eventKey] ?? ['enabled' => false, 'recipients' => '', 'interval' => 120];
            $intervalValue = (int)($alarmEntry['interval'] ?? 120);
            $intervalValue = max(1, min(1440, $intervalValue));
          ?>
          <div class="rounded-2xl border border-mid/70 bg-light/60 p-4 space-y-3">
            <div class="flex items-center justify-between gap-3">
              <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input id="alarm_<?= $eventKey ?>_enabled" type="checkbox" class="h-4 w-4 rounded border-mid text-primary"
                       <?= (!empty($alarmEntry['enabled']) ? 'checked' : '') ?>>
                <?= htmlspecialchars($eventMeta['label']) ?>
              </label>
              <span class="text-[11px] text-slate-500"><?= htmlspecialchars($eventMeta['help']) ?></span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
              <div class="lg:col-span-3">
                <label class="text-xs text-slate-500">E-mails destino (separe por vírgula)</label>
                <input id="alarm_<?= $eventKey ?>_recipients" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm"
                       placeholder="ex: devops@empresa.com" value="<?= htmlspecialchars($alarmEntry['recipients'] ?? '') ?>">
              </div>
              <div>
                <label class="text-xs text-slate-500">Intervalo (minutos)</label>
                <input id="alarm_<?= $eventKey ?>_interval" type="range" min="1" max="1440" step="1"
                       value="<?= $intervalValue ?>" class="mt-2 w-full accent-primary">
                <div class="text-xs text-slate-500 mt-1">
                  <span id="alarm_<?= $eventKey ?>_interval_label"></span>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <button id="saveAlarmButton" type="button"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar alarmes
            </button>
            <p id="alarmStatus" aria-live="polite" class="text-xs text-slate-500 sm:text-sm">
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

    <section id="logSummarySection" class="bg-white border border-mid rounded-2xl p-6 mt-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-dark">Painel de logs</h2>
          <p class="text-xs text-slate-500">Resumo para análise via IA e exportação completa.</p>
        </div>
        <a href="<?= htmlspecialchars($exportLogUrl) ?>"
           class="px-3 py-2 rounded-xl bg-primary text-white text-xs font-semibold hover:opacity-90">
          Salvar log completo
        </a>
      </div>
      <form method="GET" class="mt-4 flex flex-wrap items-end gap-3">
        <input type="hidden" name="instance" value="<?= htmlspecialchars($selectedInstanceId ?? '') ?>">
        <div class="min-w-[180px]">
          <label class="text-[11px] text-slate-500 uppercase tracking-widest">Período</label>
          <select id="logRangeSelect" name="log_range" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
            <option value="today" <?= $logRange['preset'] === 'today' ? 'selected' : '' ?>>Hoje</option>
            <option value="yesterday" <?= $logRange['preset'] === 'yesterday' ? 'selected' : '' ?>>Ontem</option>
            <option value="all" <?= $logRange['preset'] === 'all' ? 'selected' : '' ?>>Período total</option>
            <option value="custom" <?= $logRange['preset'] === 'custom' ? 'selected' : '' ?>>Personalizado</option>
          </select>
        </div>
        <div id="logRangeCustomFields" class="<?= $logRange['preset'] === 'custom' ? '' : 'hidden' ?> flex flex-wrap gap-3">
          <div>
            <label class="text-[11px] text-slate-500 uppercase tracking-widest">Início</label>
            <input type="date" name="log_start" value="<?= htmlspecialchars($logRange['custom_start'] ?? '') ?>"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
          </div>
          <div>
            <label class="text-[11px] text-slate-500 uppercase tracking-widest">Fim</label>
            <input type="date" name="log_end" value="<?= htmlspecialchars($logRange['custom_end'] ?? '') ?>"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
          </div>
        </div>
        <button type="submit" class="px-4 py-2 rounded-xl border border-primary text-primary text-sm font-semibold hover:bg-primary/5">
          Atualizar
        </button>
      </form>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Mensagens</div>
          <div class="text-2xl font-semibold text-dark"><?= (int)$logSummary['total_messages'] ?></div>
          <div class="text-[11px] text-slate-500 mt-1">
            Recebidas: <?= (int)$logSummary['total_inbound'] ?> • Enviadas: <?= (int)$logSummary['total_outbound'] ?>
          </div>
        </div>
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Contatos</div>
          <div class="text-2xl font-semibold text-dark"><?= (int)$logSummary['total_contacts'] ?></div>
          <div class="text-[11px] text-slate-500 mt-1">Conversas únicas registradas</div>
        </div>
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Comandos</div>
          <div class="text-2xl font-semibold text-dark"><?= (int)$logSummary['total_commands'] ?></div>
          <div class="text-[11px] text-slate-500 mt-1">Funções e retornos identificados</div>
        </div>
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Agendamentos</div>
          <div class="text-2xl font-semibold text-dark">
            <?= (int)($logSummary['scheduled_pending'] + $logSummary['scheduled_sent'] + $logSummary['scheduled_failed']) ?>
          </div>
          <div class="text-[11px] text-slate-500 mt-1">
            Pendentes: <?= (int)$logSummary['scheduled_pending'] ?> • Enviados: <?= (int)$logSummary['scheduled_sent'] ?> • Falhas: <?= (int)$logSummary['scheduled_failed'] ?>
          </div>
        </div>
      </div>
      <div class="text-[11px] text-slate-500 mt-3">
        Período: <?= htmlspecialchars($logRange['label']) ?> • Última atividade: <?= htmlspecialchars(formatLogDateTime($logSummary['last_message_at']) ?: 'sem registros') ?>
      </div>
    </section>
  </main>
</div>
<footer class="w-full bg-slate-900 text-slate-200 text-xs text-center py-3 mt-6">
  Por <strong>Osvaldo J. Filho</strong> |
  <a href="https://linkedin.com/in/ojaneri" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">LinkedIn</a> |
  <a href="https://github.com/ojaneri/maestro" class="text-sky-400 hover:underline" target="_blank" rel="noreferrer">GitHub</a>
</footer>
<button id="helpTourButton" class="tour-help-button" type="button" aria-label="Abrir tour guiado" title="Ajuda">
  ?
</button>
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
    <div id="qrBox" class="text-center space-y-3">
      <img id="qrImage" src="" alt="Código QR" class="mx-auto" style="display:none;">
      <p id="qrStatus" class="text-sm text-slate-500 mx-auto"></p>
    </div>
    <div id="qrConnectedCard" class="qr-connected-card hidden">
      <div class="qr-confetti" aria-hidden="true">
        <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
      </div>
      <div class="flex items-center gap-4">
        <div class="qr-badge">
          <svg width="44" height="44" viewBox="0 0 64 64" fill="none" aria-hidden="true">
            <circle cx="32" cy="32" r="28" fill="#0f766e"/>
            <path d="M22 33.5l6.5 6.5L42 26" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div>
          <div class="qr-connected-title">Conectado com sucesso</div>
          <div class="qr-connected-subtitle">WhatsApp online. Pode fechar esta janela.</div>
        </div>
      </div>
      <div class="qr-sparkle" aria-hidden="true"></div>
    </div>
    <div class="qr-status-grid mt-4" id="qrStatusGrid" aria-live="polite">
      <div><span>Status</span><strong id="qrStatusConnection">-</strong></div>
      <div><span>Conectado</span><strong id="qrStatusConnected">-</strong></div>
      <div><span>QR ativo</span><strong id="qrStatusHasQr">-</strong></div>
      <div><span>Ultimo erro</span><strong id="qrStatusError">-</strong></div>
    </div>
    <p id="qrStatusNote" class="text-xs text-slate-500 mt-3">Se o QR nao aparecer, reinicie a sessao e aguarde alguns minutos.</p>
    <div id="qrActions" class="mt-4 space-y-2">
      <button onclick="refreshQR()" class="w-full px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">Atualizar QR</button>
      <button onclick="openQrResetConfirm()" class="w-full px-4 py-2 rounded-xl bg-primary text-white hover:opacity-90">Reiniciar sessao</button>
    </div>
  </div>
</div>
<div id="qrResetOverlay" class="qr-reset-overlay">
  <div class="qr-reset-card">
    <h3>Antes de reiniciar</h3>
    <p>Saia de todas as conexoes WhatsApp Web/desktop vinculadas a este numero. Isso evita conflito e permite gerar um novo QR.</p>
    <label class="qr-reset-check">
      <input id="qrResetConfirm" type="checkbox">
      Ja desconectei todas as sessoes
    </label>
    <div class="qr-reset-actions">
      <button id="qrResetConfirmBtn" class="px-4 py-2 rounded-xl bg-primary text-white hover:opacity-90">Confirmar e reiniciar</button>
      <button id="qrResetCancelBtn" class="px-4 py-2 rounded-xl border border-mid text-slate-600 hover:bg-light">Cancelar</button>
    </div>
  </div>
</div>
<style>
  .qr-status-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    padding: 12px;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    font-size: 12px;
    color: #475569;
  }
  .qr-status-grid span {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #94a3b8;
  }
  .qr-status-grid strong {
    display: block;
    font-weight: 600;
    color: #0f172a;
  }
  .qr-connected-card {
    border: 1px solid #ccfbf1;
    background: linear-gradient(135deg, #ecfeff, #f0fdf4);
    border-radius: 16px;
    padding: 16px;
    margin-top: 12px;
    position: relative;
    overflow: hidden;
  }
  .qr-badge {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: #ffffff;
    display: grid;
    place-items: center;
    box-shadow: 0 12px 22px rgba(15, 118, 110, 0.18);
  }
  .qr-connected-title {
    font-weight: 600;
    color: #0f172a;
  }
  .qr-connected-subtitle {
    font-size: 12px;
    color: #475569;
  }
  .qr-confetti span {
    position: absolute;
    width: 8px;
    height: 14px;
    border-radius: 3px;
    opacity: 0.8;
    animation: qr-confetti-fall 2.6s ease-in-out infinite;
  }
  .qr-confetti span:nth-child(1) { left: 8%; background: #f97316; animation-delay: 0s; }
  .qr-confetti span:nth-child(2) { left: 18%; background: #22c55e; animation-delay: 0.2s; }
  .qr-confetti span:nth-child(3) { left: 32%; background: #06b6d4; animation-delay: 0.4s; }
  .qr-confetti span:nth-child(4) { left: 46%; background: #facc15; animation-delay: 0.1s; }
  .qr-confetti span:nth-child(5) { left: 60%; background: #fb7185; animation-delay: 0.35s; }
  .qr-confetti span:nth-child(6) { left: 74%; background: #a855f7; animation-delay: 0.5s; }
  .qr-confetti span:nth-child(7) { left: 86%; background: #34d399; animation-delay: 0.25s; }
  .qr-sparkle {
    position: absolute;
    right: 16px;
    bottom: 10px;
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(15, 118, 110, 0.4), transparent 70%);
    animation: qr-pulse 2.4s ease-in-out infinite;
  }
  @keyframes qr-confetti-fall {
    0% { transform: translateY(-20px) rotate(0deg); opacity: 0; }
    30% { opacity: 0.9; }
    100% { transform: translateY(120px) rotate(220deg); opacity: 0; }
  }
  @keyframes qr-pulse {
    0%, 100% { transform: scale(1); opacity: 0.35; }
    50% { transform: scale(1.08); opacity: 0.6; }
  }
  .qr-reset-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    z-index: 60;
  }
  .qr-reset-overlay.active {
    display: flex;
  }
  .qr-reset-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 18px;
    max-width: 420px;
    width: 100%;
    box-shadow: 0 18px 40px rgba(15, 118, 110, 0.2);
    border: 1px solid #e2e8f0;
  }
  .qr-reset-card h3 {
    margin: 0 0 8px;
    font-size: 16px;
    font-weight: 600;
    color: #0f172a;
  }
  .qr-reset-card p {
    margin: 0 0 12px;
    font-size: 13px;
    color: #475569;
  }
  .qr-reset-check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #475569;
    margin-bottom: 12px;
  }
  .qr-reset-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .countdown {
    font-weight: 600;
    color: #0f766e;
  }
</style>

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

  const section = document.getElementById('chatHistorySection');
  const list = document.getElementById('historyList');
  const status = document.getElementById('historyStatus');
  const refreshBtn = document.getElementById('refreshHistoryBtn');
  const historyInstanceId = <?= json_encode($selectedInstanceId ?? '') ?>;
  const port = <?= isset($selectedInstance['port']) ? (int)$selectedInstance['port'] : 'null' ?>;

  if (!section || !list || !status || !refreshBtn) return;
  if (!historyInstanceId || !port) {
    section.classList.remove('hidden');
    status.textContent = 'Instância indisponível para carregar histórico';
    return;
  }

  const endpointUrl = new URL(window.location.href);
  endpointUrl.searchParams.set('ajax_history', '1');
  endpointUrl.searchParams.set('instance', historyInstanceId);
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
        <a href="conversas.php?instance=${historyInstanceId}&contact=${encodeURIComponent(chat.remote_jid)}"
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
<script src="https://cdn.jsdelivr.net/npm/intro.js/minified/intro.min.js"></script>
<script>
(() => {
  const helpButton = document.getElementById('helpTourButton');
  if (!helpButton || typeof introJs !== 'function') {
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

    pushStep('#aiSettingsSection', 'IA (OpenAI / Gemini)', 'Configura o comportamento do bot e a integração com IA.');
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

    pushStep('#chatHistorySection', 'Histórico de conversas', 'Lista os últimos contatos e mensagens salvas.');
    pushStep('#refreshHistoryBtn', 'Atualizar histórico', 'Recarrega o painel de histórico.');
    pushStep('#logSummarySection', 'Painel de logs', 'Resumo e exportação do período selecionado.');

    return steps;
  };

  helpButton.addEventListener('click', () => {
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
</body>
</html>
