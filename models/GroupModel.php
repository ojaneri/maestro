<?php

require_once __DIR__ . '/../config/database.php';

class GroupModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllGroups(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_groups 
            WHERE instance_id = :id 
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $groups = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groups[] = $row;
        }
        $stmt->close();
        return $groups;
    }

    public function getGroupById(string $instanceId, string $groupId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_groups 
            WHERE instance_id = :id AND group_id = :group_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $group = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $group ?: null;
    }

    public function getGroupByName(string $instanceId, string $name): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_groups 
            WHERE instance_id = :id AND name = :name
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $result = $stmt->execute();
        $group = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $group ?: null;
    }

    public function createGroup(string $instanceId, array $data): string {
        $groupId = $this->generateGroupId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_groups (
                group_id, instance_id, name, description, participants, metadata, created_at
            ) VALUES (
                :group_id, :instance_id, :name, :description, :participants, :metadata, datetime('now')
            )
        ");
        $stmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $data['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':description', $data['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':participants', json_encode($data['participants'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $groupId;
    }

    public function updateGroup(string $instanceId, string $groupId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'description', 'participants', 'metadata', 'updated_at'];
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
        $sql = "UPDATE instance_groups SET " . implode(', ', $fields) . " WHERE instance_id = :id AND group_id = :group_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteGroup(string $instanceId, string $groupId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_groups 
            WHERE instance_id = :id AND group_id = :group_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getGroupsByParticipant(string $instanceId, string $participantJid): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_groups 
            WHERE instance_id = :id 
            AND participants LIKE :participant
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':participant', "%{$participantJid}%", SQLITE3_TEXT);
        $result = $stmt->execute();
        $groups = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $groups[] = $row;
        }
        $stmt->close();
        return $groups;
    }

    public function addParticipantToGroup(string $instanceId, string $groupId, string $participantJid): bool {
        $stmt = $this->db->prepare("
            SELECT participants FROM instance_groups 
            WHERE instance_id = :id AND group_id = :group_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $group = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();

        if (!$group) {
            return false;
        }

        $participants = json_decode($group['participants'], true) ?? [];
        if (!in_array($participantJid, $participants)) {
            $participants[] = $participantJid;
            
            $updateStmt = $this->db->prepare("
                UPDATE instance_groups 
                SET participants = :participants, updated_at = datetime('now')
                WHERE instance_id = :id AND group_id = :group_id
            ");
            $updateStmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $updateStmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
            $updateStmt->bindValue(':participants', json_encode($participants), SQLITE3_TEXT);
            $result = $updateStmt->execute();
            $updateStmt->close();
            
            return $result !== false;
        }
        
        return true;
    }

    public function removeParticipantFromGroup(string $instanceId, string $groupId, string $participantJid): bool {
        $stmt = $this->db->prepare("
            SELECT participants FROM instance_groups 
            WHERE instance_id = :id AND group_id = :group_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $group = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();

        if (!$group) {
            return false;
        }

        $participants = json_decode($group['participants'], true) ?? [];
        $key = array_search($participantJid, $participants);
        if ($key !== false) {
            unset($participants[$key]);
            $participants = array_values($participants);
            
            $updateStmt = $this->db->prepare("
                UPDATE instance_groups 
                SET participants = :participants, updated_at = datetime('now')
                WHERE instance_id = :id AND group_id = :group_id
            ");
            $updateStmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $updateStmt->bindValue(':group_id', $groupId, SQLITE3_TEXT);
            $updateStmt->bindValue(':participants', json_encode($participants), SQLITE3_TEXT);
            $result = $updateStmt->execute();
            $updateStmt->close();
            
            return $result !== false;
        }
        
        return true;
    }

    private function generateGroupId(): string {
        do {
            $id = 'group_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_groups 
                WHERE group_id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }
}