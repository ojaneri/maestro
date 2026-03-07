<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class ScheduledMessageController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listScheduledMessages($instanceId = null, $limit = 100, $offset = 0) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY scheduled_time ASC LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getScheduledMessage($scheduledId) {
        $sql = "SELECT * FROM scheduled_messages WHERE scheduled_id = :id";
        return $this->db->fetchOne($sql, [':id' => $scheduledId]);
    }

    public function createScheduledMessage($data) {
        $scheduledId = 'sched_' . bin2hex(random_bytes(8));
        
        $scheduledData = [
            'scheduled_id' => $scheduledId,
            'instance_id' => $data['instance_id'] ?? null,
            'to' => $data['to'] ?? '',
            'message' => $data['message'] ?? '',
            'scheduled_time' => $data['scheduled_time'] ?? date('Y-m-d H:i:s'),
            'status' => $data['status'] ?? 'pending',
            'repeat_interval' => $data['repeat_interval'] ?? null,
            'repeat_count' => $data['repeat_count'] ?? 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('scheduled_messages', $scheduledData);
        return $this->getScheduledMessage($scheduledId);
    }

    public function updateScheduledMessage($scheduledId, $data) {
        $updateData = [
            'to' => $data['to'] ?? '',
            'message' => $data['message'] ?? '',
            'scheduled_time' => $data['scheduled_time'] ?? '',
            'status' => $data['status'] ?? '',
            'repeat_interval' => $data['repeat_interval'] ?? '',
            'repeat_count' => $data['repeat_count'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('scheduled_messages', $updateData, 'scheduled_id = :id', [':id' => $scheduledId]);
        return $this->getScheduledMessage($scheduledId);
    }

    public function deleteScheduledMessage($scheduledId) {
        $this->db->delete('scheduled_messages', 'scheduled_id = :id', [':id' => $scheduledId]);
        return true;
    }

    public function getScheduledMessagesByPhone($phone, $instanceId = null, $limit = 50) {
        $params = [
            ':phone' => $phone,
            ':limit' => $limit
        ];
        
        $where = [
            "to = :phone"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getScheduledMessagesByStatus($status, $instanceId = null, $limit = 50) {
        $params = [
            ':status' => $status,
            ':limit' => $limit
        ];
        
        $where = [
            "status = :status"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getPendingScheduledMessages($instanceId = null, $limit = 50) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s'),
            ':limit' => $limit
        ];
        
        $where = [
            "scheduled_time <= :current_time",
            "status = 'pending'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getUpcomingScheduledMessages($instanceId = null, $limit = 50) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s'),
            ':limit' => $limit
        ];
        
        $where = [
            "scheduled_time > :current_time",
            "status = 'pending'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getScheduledMessagesByDateRange($startDate, $endDate, $instanceId = null, $limit = 100) {
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':limit' => $limit
        ];
        
        $where = [
            "(scheduled_time BETWEEN :start_date AND :end_date)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getScheduledMessagesForToday($instanceId = null, $limit = 50) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getScheduledMessagesByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId, $limit);
    }

    public function getScheduledMessagesForWeek($instanceId = null, $limit = 100) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getScheduledMessagesByDateRange($startOfWeek, $endOfWeek, $instanceId, $limit);
    }

    public function getScheduledMessagesForMonth($instanceId = null, $limit = 200) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getScheduledMessagesByDateRange($firstDay, $lastDay, $instanceId, $limit);
    }

    public function getScheduledMessageCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM scheduled_messages";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getPendingScheduledMessageCount($instanceId = null) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s')
        ];
        
        $where = [
            "scheduled_time <= :current_time",
            "status = 'pending'"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM scheduled_messages WHERE " . implode(" AND ", $where);
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getScheduledMessageStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM scheduled_messages";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function searchScheduledMessages($query, $instanceId = null, $limit = 50) {
        $params = [
            ':query' => '%' . $query . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "(message LIKE :query OR to LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getScheduledMessagesWithRepeat($instanceId = null, $limit = 50) {
        $params = [
            ':limit' => $limit
        ];
        
        $where = [
            "repeat_interval IS NOT NULL",
            "repeat_count > 0"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function cancelScheduledMessage($scheduledId) {
        $updateData = [
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('scheduled_messages', $updateData, 'scheduled_id = :id', [':id' => $scheduledId]);
        return $this->getScheduledMessage($scheduledId);
    }

    public function executeScheduledMessage($scheduledId) {
        $scheduledMessage = $this->getScheduledMessage($scheduledId);
        if (!$scheduledMessage || $scheduledMessage['status'] !== 'pending') {
            return false;
        }

        // Mark as sent
        $updateData = [
            'status' => 'sent',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('scheduled_messages', $updateData, 'scheduled_id = :id', [':id' => $scheduledId]);

        // If it has repeat settings, create a new scheduled message
        if ($scheduledMessage['repeat_interval'] && $scheduledMessage['repeat_count'] > 0) {
            $newRepeatCount = $scheduledMessage['repeat_count'] - 1;
            
            $newScheduledTime = date('Y-m-d H:i:s', strtotime($scheduledMessage['scheduled_time'] . ' +' . $scheduledMessage['repeat_interval']));
            
            $this->createScheduledMessage([
                'instance_id' => $scheduledMessage['instance_id'],
                'to' => $scheduledMessage['to'],
                'message' => $scheduledMessage['message'],
                'scheduled_time' => $newScheduledTime,
                'status' => 'pending',
                'repeat_interval' => $scheduledMessage['repeat_interval'],
                'repeat_count' => $newRepeatCount
            ]);
        }

        return true;
    }

    public function getOverdueScheduledMessages($instanceId = null, $limit = 50) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s'),
            ':limit' => $limit
        ];
        
        $where = [
            "scheduled_time < :current_time",
            "status = 'pending'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getFailedScheduledMessages($instanceId = null, $limit = 50) {
        $params = [
            ':limit' => $limit
        ];
        
        $where = [
            "status = 'failed'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getScheduledMessagesByInstance($instanceId, $limit = 100) {
        $params = [
            ':instance_id' => $instanceId,
            ':limit' => $limit
        ];

        $sql = "SELECT * FROM scheduled_messages WHERE instance_id = :instance_id ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getScheduledMessagesForContact($phone, $instanceId = null, $limit = 50) {
        $params = [
            ':phone' => $phone,
            ':limit' => $limit
        ];
        
        $where = [
            "to = :phone"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM scheduled_messages WHERE " . implode(" AND ", $where) . " ORDER BY scheduled_time ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }
}