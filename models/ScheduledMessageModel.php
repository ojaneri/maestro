<?php

require_once __DIR__ . '/../config/database.php';

class ScheduledMessageModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllScheduledMessages(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_scheduled_messages 
            WHERE instance_id = :id 
            ORDER BY scheduled_at ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getScheduledMessageById(string $instanceId, string $messageId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_scheduled_messages 
            WHERE instance_id = :id AND message_id = :message_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $message = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $message ?: null;
    }

    public function createScheduledMessage(string $instanceId, array $data): string {
        $messageId = $this->generateMessageId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_scheduled_messages (
                message_id, instance_id, remote_jid, content, scheduled_at, status, 
                metadata, created_at
            ) VALUES (
                :message_id, :instance_id, :remote_jid, :content, :scheduled_at, :status,
                :metadata, datetime('now')
            )
        ");
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote_jid', $data['remote_jid'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':content', $data['content'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':scheduled_at', $data['scheduled_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':status', $data['status'] ?? 'pending', SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $messageId;
    }

    public function updateScheduledMessage(string $instanceId, string $messageId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['remote_jid', 'content', 'scheduled_at', 'status', 'metadata', 'updated_at'];
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
        $sql = "UPDATE instance_scheduled_messages SET " . implode(', ', $fields) . " WHERE instance_id = :id AND message_id = :message_id";
        
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

    public function deleteScheduledMessage(string $instanceId, string $messageId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_scheduled_messages 
            WHERE instance_id = :id AND message_id = :message_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getScheduledMessagesByStatus(string $instanceId, string $status): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_scheduled_messages 
            WHERE instance_id = :id AND status = :status 
            ORDER BY scheduled_at ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getScheduledMessagesByContact(string $instanceId, string $remoteJid): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_scheduled_messages 
            WHERE instance_id = :id AND remote_jid = :remote_jid 
            ORDER BY scheduled_at ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote_jid', $remoteJid, SQLITE3_TEXT);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getScheduledMessagesForDispatch(string $instanceId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_scheduled_messages 
            WHERE instance_id = :id AND status = 'pending' 
            AND scheduled_at <= datetime('now') 
            ORDER BY scheduled_at ASC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function markScheduledMessageAsSent(string $instanceId, string $messageId): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_scheduled_messages 
            SET status = 'sent', updated_at = datetime('now')
            WHERE instance_id = :id AND message_id = :message_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function markScheduledMessageAsFailed(string $instanceId, string $messageId): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_scheduled_messages 
            SET status = 'failed', updated_at = datetime('now')
            WHERE instance_id = :id AND message_id = :message_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getScheduledMessagesForDateRange(string $instanceId, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_scheduled_messages 
            WHERE instance_id = :id 
            AND scheduled_at BETWEEN :start_date AND :end_date 
            ORDER BY scheduled_at ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end_date', $endDate, SQLITE3_TEXT);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getScheduledMessagesByProgress(string $instanceId, int $minProgress = 0, int $maxProgress = 100): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_scheduled_messages 
            WHERE instance_id = :id 
            AND (CASE 
                WHEN status = 'sent' THEN 100
                WHEN status = 'failed' THEN 0
                ELSE (
                    CAST(strftime('%s', scheduled_at) AS INTEGER) - CAST(strftime('%s', datetime('now')) AS INTEGER)
                ) * 100 / (
                    CAST(strftime('%s', scheduled_at) AS INTEGER) - CAST(strftime('%s', datetime('now', '-1 day')) AS INTEGER)
                )
            END) BETWEEN :min_progress AND :max_progress 
            ORDER BY scheduled_at ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':min_progress', $minProgress, SQLITE3_INTEGER);
        $stmt->bindValue(':max_progress', $maxProgress, SQLITE3_INTEGER);
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
            $id = 'sched_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_scheduled_messages 
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