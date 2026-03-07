<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class ContactController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listContacts($instanceId = null, $limit = 100, $offset = 0) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getContact($contactId) {
        $sql = "SELECT * FROM contacts WHERE contact_id = :id";
        return $this->db->fetchOne($sql, [':id' => $contactId]);
    }

    public function createContact($data) {
        $contactId = 'contact_' . bin2hex(random_bytes(8));
        
        $contactData = [
            'contact_id' => $contactId,
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
            'address' => $data['address'] ?? '',
            'city' => $data['city'] ?? '',
            'state' => $data['state'] ?? '',
            'country' => $data['country'] ?? '',
            'notes' => $data['notes'] ?? '',
            'tags' => $data['tags'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('contacts', $contactData);
        return $this->getContact($contactId);
    }

    public function updateContact($contactId, $data) {
        $updateData = [
            'name' => $data['name'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
            'address' => $data['address'] ?? '',
            'city' => $data['city'] ?? '',
            'state' => $data['state'] ?? '',
            'country' => $data['country'] ?? '',
            'notes' => $data['notes'] ?? '',
            'tags' => $data['tags'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('contacts', $updateData, 'contact_id = :id', [':id' => $contactId]);
        return $this->getContact($contactId);
    }

    public function deleteContact($contactId) {
        $this->db->delete('contacts', 'contact_id = :id', [':id' => $contactId]);
        return true;
    }

    public function getContactsByPhone($phone, $instanceId = null, $limit = 50) {
        $params = [
            ':phone' => '%' . $phone . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "phone LIKE :phone"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByName($name, $instanceId = null, $limit = 50) {
        $params = [
            ':name' => '%' . $name . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "name LIKE :name"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByEmail($email, $instanceId = null, $limit = 50) {
        $params = [
            ':email' => '%' . $email . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "email LIKE :email"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByCity($city, $instanceId = null, $limit = 50) {
        $params = [
            ':city' => '%' . $city . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "city LIKE :city"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByState($state, $instanceId = null, $limit = 50) {
        $params = [
            ':state' => '%' . $state . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "state LIKE :state"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByCountry($country, $instanceId = null, $limit = 50) {
        $params = [
            ':country' => '%' . $country . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "country LIKE :country"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByTag($tag, $instanceId = null, $limit = 50) {
        $params = [
            ':tag' => '%' . $tag . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "tags LIKE :tag"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByNotes($notes, $instanceId = null, $limit = 50) {
        $params = [
            ':notes' => '%' . $notes . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "notes LIKE :notes"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM contacts";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getContactsWithPhone($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "phone != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithEmail($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "email != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithAddress($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "address != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithTags($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "tags != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithNotes($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "notes != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN phone != '' THEN 1 ELSE 0 END) as with_phone,
                SUM(CASE WHEN email != '' THEN 1 ELSE 0 END) as with_email,
                SUM(CASE WHEN address != '' THEN 1 ELSE 0 END) as with_address,
                SUM(CASE WHEN tags != '' THEN 1 ELSE 0 END) as with_tags,
                SUM(CASE WHEN notes != '' THEN 1 ELSE 0 END) as with_notes
                FROM contacts";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function searchContacts($query, $instanceId = null, $limit = 50) {
        $params = [
            ':query' => '%' . $query . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "(name LIKE :query OR phone LIKE :query OR email LIKE :query OR 
              address LIKE :query OR city LIKE :query OR state LIKE :query OR 
              country LIKE :query OR tags LIKE :query OR notes LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByDateRange($startDate, $endDate, $instanceId = null, $limit = 100) {
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':limit' => $limit
        ];
        
        $where = [
            "(created_at BETWEEN :start_date AND :end_date)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsForToday($instanceId = null, $limit = 50) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getContactsByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId, $limit);
    }

    public function getContactsForWeek($instanceId = null, $limit = 100) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getContactsByDateRange($startOfWeek, $endOfWeek, $instanceId, $limit);
    }

    public function getContactsForMonth($instanceId = null, $limit = 200) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getContactsByDateRange($firstDay, $lastDay, $instanceId, $limit);
    }

    public function getContactsWithRecentActivity($instanceId = null, $days = 30, $limit = 50) {
        $params = [
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            ':limit' => $limit
        ];
        
        $where = [
            "updated_at >= :days_ago"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY updated_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithoutPhone($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "phone = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithoutEmail($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "email = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithoutAddress($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "address = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithoutTags($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "tags = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsWithoutNotes($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "notes = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getContactsByMultipleCriteria($criteria, $instanceId = null, $limit = 50) {
        $params = [];
        $where = [];
        
        if (isset($criteria['name'])) {
            $where[] = "name LIKE :name";
            $params[':name'] = '%' . $criteria['name'] . '%';
        }
        
        if (isset($criteria['phone'])) {
            $where[] = "phone LIKE :phone";
            $params[':phone'] = '%' . $criteria['phone'] . '%';
        }
        
        if (isset($criteria['email'])) {
            $where[] = "email LIKE :email";
            $params[':email'] = '%' . $criteria['email'] . '%';
        }
        
        if (isset($criteria['city'])) {
            $where[] = "city LIKE :city";
            $params[':city'] = '%' . $criteria['city'] . '%';
        }
        
        if (isset($criteria['state'])) {
            $where[] = "state LIKE :state";
            $params[':state'] = '%' . $criteria['state'] . '%';
        }
        
        if (isset($criteria['country'])) {
            $where[] = "country LIKE :country";
            $params[':country'] = '%' . $criteria['country'] . '%';
        }
        
        if (isset($criteria['tags'])) {
            $where[] = "tags LIKE :tags";
            $params[':tags'] = '%' . $criteria['tags'] . '%';
        }
        
        if (isset($criteria['notes'])) {
            $where[] = "notes LIKE :notes";
            $params[':notes'] = '%' . $criteria['notes'] . '%';
        }

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM contacts WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }
}