<?php
/**
 * Database helper functions extracted from index.php
 * Handles SQLite (chat_data) and MySQL (kitpericia) access
 */

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
    $dbPath = __DIR__ . '/../chat_data.db';
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
