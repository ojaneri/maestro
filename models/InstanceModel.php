<?php

require_once __DIR__ . '/../config/database.php';

class InstanceModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllInstances(): array {
        $stmt = $this->db->prepare("SELECT * FROM instances ORDER BY created_at DESC");
        $result = $stmt->execute();
        $instances = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $instances[] = $row;
        }
        $stmt->close();
        return $instances;
    }

    public function getInstanceById(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instances WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $instance = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $instance ?: null;
    }

    public function getInstanceByApiKey(string $apiKey): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instances WHERE api_key = :api_key");
        $stmt->bindValue(':api_key', $apiKey, SQLITE3_TEXT);
        $result = $stmt->execute();
        $instance = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $instance ?: null;
    }

    public function createInstance(array $data): string {
        $instanceId = $this->generateInstanceId();
        $apiKey = $this->generateApiKey();
        
        $stmt = $this->db->prepare("
            INSERT INTO instances (instance_id, api_key, name, description, port, status, created_at, updated_at)
            VALUES (:id, :api_key, :name, :description, :port, :status, datetime('now'), datetime('now'))
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':api_key', $apiKey, SQLITE3_TEXT);
        $stmt->bindValue(':name', $data['name'] ?? 'New Instance', SQLITE3_TEXT);
        $stmt->bindValue(':description', $data['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':port', $data['port'] ?? 3000, SQLITE3_INTEGER);
        $stmt->bindValue(':status', $data['status'] ?? 'active', SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $instanceId;
    }

    public function updateInstance(string $instanceId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'description', 'port', 'status', 'updated_at'];
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
        $sql = "UPDATE instances SET " . implode(', ', $fields) . " WHERE instance_id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteInstance(string $instanceId): bool {
        $this->db->beginTransaction();
        try {
            // Delete from instances table
            $stmt = $this->db->prepare("DELETE FROM instances WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_settings table
            $stmt = $this->db->prepare("DELETE FROM instance_settings WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_ai_config table
            $stmt = $this->db->prepare("DELETE FROM instance_ai_config WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_calendar_config table
            $stmt = $this->db->prepare("DELETE FROM instance_calendar_config WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_secretary_config table
            $stmt = $this->db->prepare("DELETE FROM instance_secretary_config WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_audio_config table
            $stmt = $this->db->prepare("DELETE FROM instance_audio_config WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_messages table
            $stmt = $this->db->prepare("DELETE FROM instance_messages WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_scheduled_messages table
            $stmt = $this->db->prepare("DELETE FROM instance_scheduled_messages WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_contacts table
            $stmt = $this->db->prepare("DELETE FROM instance_contacts WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_chats table
            $stmt = $this->db->prepare("DELETE FROM instance_chats WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_groups table
            $stmt = $this->db->prepare("DELETE FROM instance_groups WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_campaigns table
            $stmt = $this->db->prepare("DELETE FROM instance_campaigns WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            // Delete from instance_templates table
            $stmt = $this->db->prepare("DELETE FROM instance_templates WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->close();

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            debug_log("Error deleting instance {$instanceId}: " . $e->getMessage());
            return false;
        }
    }

    public function getInstanceSettings(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT * FROM instance_settings WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $settings = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
        $stmt->close();
        return $settings;
    }

    public function getInstanceAIConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_ai_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function getInstanceCalendarConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_calendar_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function getInstanceSecretaryConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_secretary_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function getInstanceAudioConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_audio_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function getInstanceMessages(string $instanceId, int $limit = 100, int $offset = 0): array {
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

    public function getInstanceScheduledMessages(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT * FROM instance_scheduled_messages WHERE instance_id = :id ORDER BY scheduled_at ASC");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $messages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $messages[] = $row;
        }
        $stmt->close();
        return $messages;
    }

    public function getInstanceContacts(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT * FROM instance_contacts WHERE instance_id = :id ORDER BY name ASC");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $contacts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contacts[] = $row;
        }
        $stmt->close();
        return $contacts;
    }

    public function getInstanceChats(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT * FROM instance_chats WHERE instance_id = :id ORDER BY last_message_at DESC");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $chats = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $chats[] = $row;
        }
        $stmt->close();
        return $chats;
    }

    public function getInstanceGroups(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT * FROM instance_groups WHERE instance_id = :id ORDER BY name ASC");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $groups = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groups[] = $row;
        }
        $stmt->close();
        return $groups;
    }

    public function getInstanceCampaigns(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT * FROM instance_campaigns WHERE instance_id = :id ORDER BY created_at DESC");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = $row;
        }
        $stmt->close();
        return $campaigns;
    }

    public function getInstanceTemplates(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT * FROM instance_templates WHERE instance_id = :id ORDER BY name ASC");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $templates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $templates[] = $row;
        }
        $stmt->close();
        return $templates;
    }

    private function generateInstanceId(): string {
        do {
            $id = 'inst_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("SELECT 1 FROM instances WHERE instance_id = :id");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }

    private function generateApiKey(): string {
        do {
            $key = 'sk_' . bin2hex(random_bytes(32));
            $stmt = $this->db->prepare("SELECT 1 FROM instances WHERE api_key = :key");
            $stmt->bindValue(':key', $key, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $key;
    }
}