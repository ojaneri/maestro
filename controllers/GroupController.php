<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class GroupController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listGroups($instanceId = null, $limit = 100, $offset = 0) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getGroup($groupId) {
        $sql = "SELECT * FROM groups WHERE group_id = :id";
        return $this->db->fetchOne($sql, [':id' => $groupId]);
    }

    public function createGroup($data) {
        $groupId = 'group_' . bin2hex(random_bytes(8));
        
        $groupData = [
            'group_id' => $groupId,
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'group_jid' => $data['group_jid'] ?? '',
            'participants' => $data['participants'] ?? '',
            'admin' => $data['admin'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('groups', $groupData);
        return $this->getGroup($groupId);
    }

    public function updateGroup($groupId, $data) {
        $updateData = [
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'group_jid' => $data['group_jid'] ?? '',
            'participants' => $data['participants'] ?? '',
            'admin' => $data['admin'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('groups', $updateData, 'group_id = :id', [':id' => $groupId]);
        return $this->getGroup($groupId);
    }

    public function deleteGroup($groupId) {
        $this->db->delete('groups', 'group_id = :id', [':id' => $groupId]);
        return true;
    }

    public function getGroupsByInstance($instanceId, $limit = 50) {
        $params = [
            ':instance_id' => $instanceId,
            ':limit' => $limit
        ];

        $sql = "SELECT * FROM groups WHERE instance_id = :instance_id ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsByName($name, $instanceId = null, $limit = 50) {
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

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsByDescription($description, $instanceId = null, $limit = 50) {
        $params = [
            ':description' => '%' . $description . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "description LIKE :description"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsByJID($groupJid, $instanceId = null, $limit = 50) {
        $params = [
            ':group_jid' => '%' . $groupJid . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "group_jid LIKE :group_jid"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsByAdmin($admin, $instanceId = null, $limit = 50) {
        $params = [
            ':admin' => '%' . $admin . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "admin LIKE :admin"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsByParticipant($participant, $instanceId = null, $limit = 50) {
        $params = [
            ':participant' => '%' . $participant . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "participants LIKE :participant"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM groups";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getGroupStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN participants != '' THEN 1 ELSE 0 END) as with_participants,
                SUM(CASE WHEN admin != '' THEN 1 ELSE 0 END) as with_admin,
                SUM(CASE WHEN description != '' THEN 1 ELSE 0 END) as with_description
                FROM groups";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function searchGroups($query, $instanceId = null, $limit = 50) {
        $params = [
            ':query' => '%' . $query . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "(name LIKE :query OR description LIKE :query OR group_jid LIKE :query OR 
              admin LIKE :query OR participants LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsByDateRange($startDate, $endDate, $instanceId = null, $limit = 100) {
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

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsForToday($instanceId = null, $limit = 50) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getGroupsByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId, $limit);
    }

    public function getGroupsForWeek($instanceId = null, $limit = 100) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getGroupsByDateRange($startOfWeek, $endOfWeek, $instanceId, $limit);
    }

    public function getGroupsForMonth($instanceId = null, $limit = 200) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getGroupsByDateRange($firstDay, $lastDay, $instanceId, $limit);
    }

    public function getGroupsWithRecentActivity($instanceId = null, $days = 7, $limit = 50) {
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

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY updated_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsWithoutRecentActivity($instanceId = null, $days = 30, $limit = 50) {
        $params = [
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            ':limit' => $limit
        ];
        
        $where = [
            "updated_at < :days_ago"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY updated_at ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsWithParticipants($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "participants != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsWithoutParticipants($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "participants = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsWithAdmin($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "admin != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsWithoutAdmin($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "admin = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsWithDescription($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "description != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsWithoutDescription($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "description = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getGroupsByMultipleCriteria($criteria, $instanceId = null, $limit = 50) {
        $params = [];
        $where = [];
        
        if (isset($criteria['name'])) {
            $where[] = "name LIKE :name";
            $params[':name'] = '%' . $criteria['name'] . '%';
        }
        
        if (isset($criteria['description'])) {
            $where[] = "description LIKE :description";
            $params[':description'] = '%' . $criteria['description'] . '%';
        }
        
        if (isset($criteria['group_jid'])) {
            $where[] = "group_jid LIKE :group_jid";
            $params[':group_jid'] = '%' . $criteria['group_jid'] . '%';
        }
        
        if (isset($criteria['admin'])) {
            $where[] = "admin LIKE :admin";
            $params[':admin'] = '%' . $criteria['admin'] . '%';
        }
        
        if (isset($criteria['participants'])) {
            $where[] = "participants LIKE :participants";
            $params[':participants'] = '%' . $criteria['participants'] . '%';
        }

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM groups WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function addParticipantToGroup($groupId, $participant) {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return false;
        }

        $participants = $group['participants'] ? explode(',', $group['participants']) : [];
        
        // Add participant if not already in list
        if (!in_array($participant, $participants)) {
            $participants[] = $participant;
            $updateData = [
                'participants' => implode(',', $participants),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('groups', $updateData, 'group_id = :id', [':id' => $groupId]);
            return $this->getGroup($groupId);
        }

        return $group;
    }

    public function removeParticipantFromGroup($groupId, $participant) {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return false;
        }

        $participants = $group['participants'] ? explode(',', $group['participants']) : [];
        
        // Remove participant if exists
        if (($key = array_search($participant, $participants)) !== false) {
            unset($participants[$key]);
            $updateData = [
                'participants' => implode(',', $participants),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $this->db->update('groups', $updateData, 'group_id = :id', [':id' => $groupId]);
            return $this->getGroup($groupId);
        }

        return $group;
    }

    public function getGroupParticipants($groupId) {
        $group = $this->getGroup($groupId);
        if (!$group || !$group['participants']) {
            return [];
        }

        return explode(',', $group['participants']);
    }

    public function getGroupParticipantCount($groupId) {
        $participants = $this->getGroupParticipants($groupId);
        return count($participants);
    }

    public function getGroupParticipantStatistics($groupId) {
        $participants = $this->getGroupParticipants($groupId);
        
        return [
            'total' => count($participants),
            'unique' => count(array_unique($participants)),
            'active' => 0, // Could be enhanced with activity tracking
            'inactive' => 0 // Could be enhanced with activity tracking
        ];
    }

    public function getGroupWithParticipants($groupId) {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return null;
        }

        $group['participants_list'] = $this->getGroupParticipants($groupId);
        return $group;
    }

    public function getGroupWithAdmin($groupId) {
        $group = $this->getGroup($groupId);
        if (!$group) {
            return null;
        }

        $group['admin_info'] = $this->getAdminInfo($group['admin']);
        return $group;
    }

    private function getAdminInfo($admin) {
        // This could be enhanced to fetch admin details from contacts or users table
        return [
            'admin' => $admin,
            'name' => $admin, // Default to admin phone number
            'role' => 'admin'
        ];
    }
}