<?php
if (defined('EXTERNAL_AUTH_LOADED')) {
    return;
}
define('EXTERNAL_AUTH_LOADED', true);

function getExternalAuthDbPath(): string
{
    return __DIR__ . '/chat_data.db';
}

function openExternalAuthDb(): ?SQLite3
{
    $dbPath = getExternalAuthDbPath();
    if (!file_exists($dbPath)) {
        return null;
    }
    try {
        return new SQLite3($dbPath);
    } catch (Exception $err) {
        error_log("external_auth: falha ao abrir {$dbPath}: {$err->getMessage()}");
        return null;
    }
}

function ensureExternalUsersSchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $db = openExternalAuthDb();
    if (!$db) {
        return;
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS external_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('user','manager')) DEFAULT 'user',
            password_hash TEXT NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('active','inactive')) DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS external_user_instance_access (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            instance_id TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES external_users(id),
            UNIQUE(user_id, instance_id)
        )
    ");
    $ensured = true;
    $db->close();
}

function getExternalUserByEmail(string $email): ?array
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM external_users WHERE email = :email LIMIT 1");
    if (!$stmt) {
        $db->close();
        return null;
    }
    $stmt->bindValue(':email', strtolower(trim($email)), SQLITE3_TEXT);
    $result = $stmt->execute();
    $user = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;
    $result?->finalize();
    $stmt->close();
    $db->close();
    return $user ?: null;
}

function getExternalUserById(int $userId): ?array
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM external_users WHERE id = :id LIMIT 1");
    if (!$stmt) {
        $db->close();
        return null;
    }
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $user = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;
    $result?->finalize();
    $stmt->close();
    $db->close();
    return $user ?: null;
}

function getExternalUserInstances(int $userId): array
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        return [];
    }
    $stmt = $db->prepare("
        SELECT instance_id
        FROM external_user_instance_access
        WHERE user_id = :user_id
    ");
    if (!$stmt) {
        $db->close();
        return [];
    }
    $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $instances = [];
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $instances[] = $row['instance_id'] ?? '';
        }
        $result->finalize();
    }
    $stmt->close();
    $db->close();
    return array_values(array_filter($instances, 'strlen'));
}

function setExternalUserInstances(int $userId, array $instanceIds): void
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        return;
    }
    $db->exec("BEGIN");
    try {
        $stmt = $db->prepare("DELETE FROM external_user_instance_access WHERE user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("
            INSERT OR IGNORE INTO external_user_instance_access (user_id, instance_id)
            VALUES (:user_id, :instance_id)
        ");
        foreach ($instanceIds as $instanceId) {
            $instanceId = trim((string)$instanceId);
            if ($instanceId === '') {
                continue;
            }
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
        }
        $stmt->close();
        $db->exec("COMMIT");
    } catch (Exception $err) {
        $db->exec("ROLLBACK");
    }
    $db->close();
}

function listExternalUsers(): array
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        return [];
    }
    $result = $db->query("SELECT id, email, name, role, status, created_at FROM external_users ORDER BY created_at DESC");
    if (!$result) {
        $db->close();
        return [];
    }
    $users = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $users[] = $row;
    }
    $result->finalize();
    $db->close();
    return $users;
}

function createExternalUser(string $name, string $email, string $password, string $role, array $instanceIds): array
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        throw new RuntimeException("Banco de acessos indisponível");
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("
        INSERT INTO external_users (name, email, password_hash, role)
        VALUES (:name, :email, :hash, :role)
    ");
    if (!$stmt) {
        $db->close();
        throw new RuntimeException("Falha ao preparar inserção");
    }
    $stmt->bindValue(':name', trim($name), SQLITE3_TEXT);
    $stmt->bindValue(':email', strtolower(trim($email)), SQLITE3_TEXT);
    $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
    $stmt->bindValue(':role', $role === 'manager' ? 'manager' : 'user', SQLITE3_TEXT);
    $result = $stmt->execute();
    if ($result === false) {
        $stmt->close();
        $db->close();
        throw new RuntimeException("Falha ao criar usuário externo: " . $db->lastErrorMsg());
    }
    $lastId = $db->lastInsertRowID();
    $stmt->close();
    setExternalUserInstances((int)$lastId, $instanceIds);
    $db->close();
    return getExternalUserById((int)$lastId) ?: [];
}

function sendExternalAccessNotice(string $email, string $name, ?string $password, array $instanceNames): void
{
    $baseUrl = rtrim($_ENV['PANEL_BASE_URL'] ?? ($_SERVER['HTTP_HOST'] ?? '/api/envio/wpp'), '/');
    if (strpos($baseUrl, 'http') !== 0) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}/api/envio/wpp";
    }
    $instanceList = $instanceNames ? implode(", ", $instanceNames) : 'Sem instâncias associadas';
    $subject = "Acesso ao Conversas • Maestro";
    $passwordLine = $password ? "Senha: {$password}" : "Solicite sua senha ao administrador do sistema.";
    $message = <<<EOT
Olá {$name},

Seu acesso ao painel de conversas foi criado.

Login: {$email}
Instâncias autorizadas: {$instanceList}
Link de login: {$baseUrl}/login.php
{$passwordLine}

Atenciosamente,
Equipe Maestro
EOT;
    $headers = "From: atendeai@janeri.com.br\r\n";
    if (!mail($email, $subject, $message, $headers)) {
        error_log("external_auth: falha ao enviar e-mail para {$email}");
    } else {
        error_log("external_auth: e-mail enviado para {$email}");
    }
}

function updateExternalUserProfile(int $userId, ?string $role = null, ?string $status = null): bool
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        return false;
    }
    $fields = [];
    $params = [];
    if ($role !== null) {
        $fields[] = 'role = :role';
        $params[':role'] = $role === 'manager' ? 'manager' : 'user';
    }
    if ($status !== null) {
        $fields[] = 'status = :status';
        $params[':status'] = $status === 'inactive' ? 'inactive' : 'active';
    }
    if (empty($fields)) {
        $db->close();
        return false;
    }

    $sql = 'UPDATE external_users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        $db->close();
        return false;
    }
    $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, SQLITE3_TEXT);
    }
    $result = $stmt->execute();
    $stmt->close();
    $changes = ($result !== false) ? $db->changes() : 0;
    $db->close();
    return $changes > 0;
}

function deleteExternalUser(int $userId): bool
{
    ensureExternalUsersSchema();
    $db = openExternalAuthDb();
    if (!$db) {
        return false;
    }
    $stmtAccess = $db->prepare("DELETE FROM external_user_instance_access WHERE user_id = :id");
    if ($stmtAccess) {
        $stmtAccess->bindValue(':id', $userId, SQLITE3_INTEGER);
        $stmtAccess->execute();
        $stmtAccess->close();
    }

    $stmtUser = $db->prepare("DELETE FROM external_users WHERE id = :id");
    if (!$stmtUser) {
        $db->close();
        return false;
    }
    $stmtUser->bindValue(':id', $userId, SQLITE3_INTEGER);
    $result = $stmtUser->execute();
    $stmtUser->close();
    $deleted = ($result !== false) ? $db->changes() : 0;
    $db->close();
    return $deleted > 0;
}
