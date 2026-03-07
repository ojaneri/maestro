<?php

require_once __DIR__ . '/../config/database.php';

class ContactModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllContacts(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_contacts 
            WHERE instance_id = :id 
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $contacts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contacts[] = $row;
        }
        $stmt->close();
        return $contacts;
    }

    public function getContactById(string $instanceId, string $contactId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_contacts 
            WHERE instance_id = :id AND contact_id = :contact_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':contact_id', $contactId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $contact = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $contact ?: null;
    }

    public function getContactByJid(string $instanceId, string $jid): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_contacts 
            WHERE instance_id = :id AND jid = :jid
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':jid', $jid, SQLITE3_TEXT);
        $result = $stmt->execute();
        $contact = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $contact ?: null;
    }

    public function createContact(string $instanceId, array $data): string {
        $contactId = $this->generateContactId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_contacts (
                contact_id, instance_id, jid, name, phone, email, status, metadata, created_at
            ) VALUES (
                :contact_id, :instance_id, :jid, :name, :phone, :email, :status, :metadata, datetime('now')
            )
        ");
        $stmt->bindValue(':contact_id', $contactId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':jid', $data['jid'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':name', $data['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':phone', $data['phone'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':email', $data['email'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':status', $data['status'] ?? 'active', SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $contactId;
    }

    public function updateContact(string $instanceId, string $contactId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['jid', 'name', 'phone', 'email', 'status', 'metadata', 'updated_at'];
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
        $sql = "UPDATE instance_contacts SET " . implode(', ', $fields) . " WHERE instance_id = :id AND contact_id = :contact_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':contact_id', $contactId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteContact(string $instanceId, string $contactId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_contacts 
            WHERE instance_id = :id AND contact_id = :contact_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':contact_id', $contactId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getContactsByStatus(string $instanceId, string $status): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_contacts 
            WHERE instance_id = :id AND status = :status 
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $result = $stmt->execute();
        $contacts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contacts[] = $row;
        }
        $stmt->close();
        return $contacts;
    }

    public function searchContacts(string $instanceId, string $query): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_contacts 
            WHERE instance_id = :id 
            AND (name LIKE :query OR phone LIKE :query OR email LIKE :query)
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':query', "%{$query}%", SQLITE3_TEXT);
        $result = $stmt->execute();
        $contacts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contacts[] = $row;
        }
        $stmt->close();
        return $contacts;
    }

    private function generateContactId(): string {
        do {
            $id = 'cont_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_contacts 
                WHERE contact_id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }
}