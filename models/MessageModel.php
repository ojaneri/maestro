<?php

require_once __DIR__ . '/../config/database.php';

class MessageModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllMessages(string $instanceId, int $limit = 100, int $offset = 0): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_messages 
            WHERE instance_id = :id 
            ORDER BY created_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getMessageById(string $instanceId, string $messageId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_messages 
            WHERE instance_id = :id AND message_id = :message_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $message = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $message ?: null;
    }

    public function createMessage(string $instanceId, array $data): string {
        $messageId = $this->generateMessageId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_messages (
                message_id, instance_id, remote_jid, role, content, direction, metadata, created_at
            ) VALUES (
                :message_id, :instance_id, :remote_jid, :role, :content, :direction, :metadata, datetime('now')
            )
        ");
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote_jid', $data['remote_jid'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':role', $data['role'] ?? 'user', SQLITE3_TEXT);
        $stmt->bindValue(':content', $data['content'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':direction', $data['direction'] ?? 'inbound', SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $messageId;
    }

    public function updateMessage(string $instanceId, string $messageId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['remote_jid', 'role', 'content', 'direction', 'metadata', 'updated_at'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $values[":{$field}"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = 'updated_at = datetime("now")';
        $sql = "UPDATE instance_messages SET " . implode(', ', $fields) . " WHERE instance_id = :id AND message_id = :message_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteMessage(string $instanceId, string $messageId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_messages 
            WHERE instance_id = :id AND message_id = :message_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getMessagesByContact(string $instanceId, string $remoteJid, int $limit = 100): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_messages 
            WHERE instance_id = :id AND remote_jid = :remote_jid 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote_jid', $remoteJid, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getMessagesByRole(string $instanceId, string $role, int $limit = 100): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_messages 
            WHERE instance_id = :id AND role = :role 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':role', $role, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getMessagesByDirection(string $instanceId, string $direction, int $limit = 100): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_messages 
            WHERE instance_id = :id AND direction = :direction 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':direction', $direction, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    private function generateMessageId(): string {
        do {
            $id = 'msg_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_messages 
                WHERE message_id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }
}