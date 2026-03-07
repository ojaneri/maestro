<?php
/**
 * Log formatting and generation functions extracted from index.php
 * Includes centralized logging system with debug.log and critical.log
 */

// ============================================================
// CENTRALIZED LOGGING SYSTEM
// ============================================================

define('LOG_LEVEL_DEBUG', 0);
define('LOG_LEVEL_INFO', 1);
define('LOG_LEVEL_WARN', 2);
define('LOG_LEVEL_ERROR', 3);
define('LOG_LEVEL_CRITICAL', 4);

define('LOG_DEBUG_FILE', __DIR__ . '/../debug.log');
define('LOG_CRITICAL_FILE', __DIR__ . '/../critical.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10MB

/**
 * Thread-safe logging for PHP
 * Writes to debug.log (all logs) and critical.log (ERROR/CRITICAL only)
 */
function centralizedLog(int $level, string $message, array $context = []): bool {
    $levelNames = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'CRITICAL'];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    
    // Build context string
    $contextStr = '';
    if (!empty($context)) {
        $contextPairs = [];
        foreach ($context as $key => $value) {
            $contextPairs[] = "{$key}=" . (is_string($value) ? $value : json_encode($value));
        }
        $contextStr = ' [' . implode(' | ', $contextPairs) . ']';
    }
    
    // Format: [ISO8601] [LEVEL] [context] message
    $timestamp = gmdate('Y-m-d\TH:i:s.v\Z');
    $logLine = "[{$timestamp}] [{$levelName}]{$contextStr} {$message}\n";
    
    $success = true;
    
    // Always write to debug.log
    if (!writeLogWithRotation(LOG_DEBUG_FILE, $logLine)) {
        error_log("Failed to write to debug.log: " . LOG_DEBUG_FILE);
        $success = false;
    }
    
    // Write to critical.log only for ERROR and CRITICAL
    if ($level >= LOG_LEVEL_ERROR) {
        if (!writeLogWithRotation(LOG_CRITICAL_FILE, $logLine)) {
            error_log("Failed to write to critical.log: " . LOG_CRITICAL_FILE);
            $success = false;
        }
    }
    
    return $success;
}

/**
 * Write log with rotation (max 10MB per file)
 */
function writeLogWithRotation(string $filePath, string $content): bool {
    try {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }
        
        // Check file size and rotate if needed
        if (file_exists($filePath) && filesize($filePath) >= LOG_MAX_SIZE) {
            $timestamp = gmdate('Y-m-d-H-i-s');
            $rotatePath = $filePath . '.' . $timestamp . '.old';
            rename($filePath, $rotatePath);
            
            // Clean up old rotated files (keep last 5)
            cleanupOldLogs($filePath, 5);
        }
        
        // Thread-safe write using file locking
        $fp = fopen($filePath, 'a');
        if ($fp === false) {
            return false;
        }
        
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        
        $result = fwrite($fp, $content);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        return $result !== false;
    } catch (Exception $e) {
        error_log("Log write exception: " . $e->getMessage());
        return false;
    }
}

/**
 * Clean up old rotated log files
 */
function cleanupOldLogs(string $logFilePath, int $keepCount): void {
    $dir = dirname($logFilePath);
    $basename = basename($logFilePath);
    $pattern = $dir . '/' . $basename . '.*.old';
    
    $files = glob($pattern);
    if (count($files) <= $keepCount) {
        return;
    }
    
    // Sort by modification time (oldest first)
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    // Delete oldest files beyond keepCount
    $toDelete = array_slice($files, 0, count($files) - $keepCount);
    foreach ($toDelete as $file) {
        @unlink($file);
    }
}

// Convenience functions
function logDebug(string $message, array $context = []): bool {
    return centralizedLog(LOG_LEVEL_DEBUG, $message, $context);
}

function logInfo(string $message, array $context = []): bool {
    return centralizedLog(LOG_LEVEL_INFO, $message, $context);
}

function logWarn(string $message, array $context = []): bool {
    return centralizedLog(LOG_LEVEL_WARN, $message, $context);
}

function logError(string $message, array $context = []): bool {
    return centralizedLog(LOG_LEVEL_ERROR, $message, $context);
}

function logCritical(string $message, array $context = []): bool {
    return centralizedLog(LOG_LEVEL_CRITICAL, $message, $context);
}

// ============================================================
// EXISTING LOG FUNCTIONS (unchanged)
// ============================================================

function normalizeLogDateRange(?string $startDate, ?string $endDate, ?string $timezone = null): array {
    if (!$startDate && !$endDate) {
        return [null, null];
    }
    $tz = new DateTimeZone($timezone ?? getApplicationTimezone());
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
    $tz = new DateTimeZone(getApplicationTimezone());
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
        $date->setTimezone(new DateTimeZone(getApplicationTimezone()));
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
    if (in_array($type, ['agendar', 'agendar2', 'agendar3', 'cancelar_e_agendar2', 'cancelar_e_agendar3'], true) && is_array($result)) {
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
    $dbPath = __DIR__ . '/../chat_data.db';
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
    $dbPath = __DIR__ . '/../chat_data.db';
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
