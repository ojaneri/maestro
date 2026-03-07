<?php

require_once __DIR__ . '/../config/database.php';

class ChatModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllChats(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_chats 
            WHERE instance_id = :id 
            ORDER BY last_message_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $chats = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $chats[] = $row;
        }
        $stmt->close();
        return $chats;
    }

    public function getChatById(string $instanceId, string $chatId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_chats 
            WHERE instance_id = :id AND chat_id = :chat_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $chat = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $chat ?: null;
    }

    public function getChatByJid(string $instanceId, string $jid): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_chats 
            WHERE instance_id = :id AND jid = :jid
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':jid', $jid, SQLITE3_TEXT);
        $result = $stmt->execute();
        $chat = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $chat ?: null;
    }

    public function createChat(string $instanceId, array $data): string {
        $chatId = $this->generateChatId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_chats (
                chat_id, instance_id, jid, name, unread_count, last_message_at, metadata, created_at
            ) VALUES (
                :chat_id, :instance_id, :jid, :name, :unread_count, :last_message_at, :metadata, datetime('now')
            )
        ");
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':jid', $data['jid'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':name', $data['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':unread_count', $data['unread_count'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':last_message_at', $data['last_message_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $chatId;
    }

    public function updateChat(string $instanceId, string $chatId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['jid', 'name', 'unread_count', 'last_message_at', 'metadata', 'updated_at'];
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
        $sql = "UPDATE instance_chats SET " . implode(', ', $fields) . " WHERE instance_id = :id AND chat_id = :chat_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteChat(string $instanceId, string $chatId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_chats 
            WHERE instance_id = :id AND chat_id = :chat_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getChatsByStatus(string $instanceId, string $status): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_chats 
            WHERE instance_id = :id AND status = :status 
            ORDER BY last_message_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $result = $stmt->execute();
        $chats = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $chats[] = $row;
        }
        $stmt->close();
        return $chats;
    }

    public function getUnreadChats(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_chats 
            WHERE instance_id = :id AND unread_count > 0 
            ORDER BY last_message_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $chats = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $chats[] = $row;
        }
        $stmt->close();
        return $chats;
    }

    public function markChatAsRead(string $instanceId, string $chatId): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_chats 
            SET unread_count = 0, updated_at = datetime('now')
            WHERE instance_id = :id AND chat_id = :chat_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function incrementUnreadCount(string $instanceId, string $chatId): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_chats 
            SET unread_count = unread_count + 1, updated_at = datetime('now')
            WHERE instance_id = :id AND chat_id = :chat_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function updateLastMessage(string $instanceId, string $chatId, string $lastMessageAt): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_chats 
            SET last_message_at = :last_message_at, updated_at = datetime('now')
            WHERE instance_id = :id AND chat_id = :chat_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':chat_id', $chatId, SQLITE3_TEXT);
        $stmt->bindValue(':last_message_at', $lastMessageAt, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    private function generateChatId(): string {
        do {
            $id = 'chat_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_chats 
                WHERE chat_id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }
}