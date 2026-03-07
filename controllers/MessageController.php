<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class MessageController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listMessages($instanceId = null, $limit = 100, $offset = 0) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getMessage($messageId) {
        $sql = "SELECT * FROM messages WHERE message_id = :id";
        return $this->db->fetchOne($sql, [':id' => $messageId]);
    }

    public function createMessage($data) {
        $messageId = 'msg_' . bin2hex(random_bytes(8));
        
        $messageData = [
            'message_id' => $messageId,
            'instance_id' => $data['instance_id'] ?? null,
            'from' => $data['from'] ?? '',
            'to' => $data['to'] ?? '',
            'message' => $data['message'] ?? '',
            'type' => $data['type'] ?? 'text',
            'status' => $data['status'] ?? 'pending',
            'direction' => $data['direction'] ?? 'incoming',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('messages', $messageData);
        return $this->getMessage($messageId);
    }

    public function updateMessage($messageId, $data) {
        $updateData = [
            'from' => $data['from'] ?? '',
            'to' => $data['to'] ?? '',
            'message' => $data['message'] ?? '',
            'type' => $data['type'] ?? '',
            'status' => $data['status'] ?? '',
            'direction' => $data['direction'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('messages', $updateData, 'message_id = :id', [':id' => $messageId]);
        return $this->getMessage($messageId);
    }

    public function deleteMessage($messageId) {
        $this->db->delete('messages', 'message_id = :id', [':id' => $messageId]);
        return true;
    }

    public function getMessagesByPhone($phone, $instanceId = null, $limit = 50) {
        $params = [
            ':phone' => $phone,
            ':limit' => $limit
        ];
        
        $where = [
            "(from = :phone OR to = :phone)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getMessagesByType($type, $instanceId = null, $limit = 50) {
        $params = [
            ':type' => $type,
            ':limit' => $limit
        ];
        
        $where = [
            "type = :type"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getMessagesByStatus($status, $instanceId = null, $limit = 50) {
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

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getMessagesByDirection($direction, $instanceId = null, $limit = 50) {
        $params = [
            ':direction' => $direction,
            ':limit' => $limit
        ];
        
        $where = [
            "direction = :direction"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getUnreadMessages($instanceId = null, $limit = 50) {
        $params = [
            ':limit' => $limit
        ];
        
        $where = [
            "status = 'unread'",
            "direction = 'incoming'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function markMessageAsRead($messageId) {
        $updateData = [
            'status' => 'read',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('messages', $updateData, 'message_id = :id', [':id' => $messageId]);
        return $this->getMessage($messageId);
    }

    public function markMessageAsSent($messageId) {
        $updateData = [
            'status' => 'sent',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('messages', $updateData, 'message_id = :id', [':id' => $messageId]);
        return $this->getMessage($messageId);
    }

    public function markMessageAsFailed($messageId, $errorMessage = null) {
        $updateData = [
            'status' => 'failed',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($errorMessage) {
            $updateData['error_message'] = $errorMessage;
        }

        $this->db->update('messages', $updateData, 'message_id = :id', [':id' => $messageId]);
        return $this->getMessage($messageId);
    }

    public function getMessageCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM messages";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getIncomingMessageCount($instanceId = null) {
        $params = [];
        $where = [
            "direction = 'incoming'"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM messages WHERE " . implode(" AND ", $where);
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getOutgoingMessageCount($instanceId = null) {
        $params = [];
        $where = [
            "direction = 'outgoing'"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM messages WHERE " . implode(" AND ", $where);
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getUnreadMessageCount($instanceId = null) {
        $params = [];
        $where = [
            "status = 'unread'",
            "direction = 'incoming'"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM messages WHERE " . implode(" AND ", $where);
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getFailedMessageCount($instanceId = null) {
        $params = [];
        $where = [
            "status = 'failed'"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM messages WHERE " . implode(" AND ", $where);
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getMessageStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
                SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function getMessagesByDateRange($startDate, $endDate, $instanceId = null, $limit = 100) {
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

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getMessagesForToday($instanceId = null, $limit = 50) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getMessagesByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId, $limit);
    }

    public function getMessagesForWeek($instanceId = null, $limit = 100) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getMessagesByDateRange($startOfWeek, $endOfWeek, $instanceId, $limit);
    }

    public function getMessagesForMonth($instanceId = null, $limit = 200) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getMessagesByDateRange($firstDay, $lastDay, $instanceId, $limit);
    }

    public function searchMessages($query, $instanceId = null, $limit = 50) {
        $params = [
            ':query' => '%' . $query . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "(message LIKE :query OR from LIKE :query OR to LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getMessagesWithAttachments($instanceId = null, $limit = 50) {
        $params = [
            ':limit' => $limit
        ];
        
        $where = [
            "type IN ('image', 'video', 'document', 'audio')"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getMessageConversation($phone, $instanceId = null, $limit = 20) {
        $params = [
            ':phone' => $phone,
            ':limit' => $limit
        ];
        
        $where = [
            "(from = :phone OR to = :phone)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getMessageThread($phone, $instanceId = null, $limit = 50) {
        $params = [
            ':phone' => $phone,
            ':limit' => $limit
        ];
        
        $where = [
            "(from = :phone OR to = :phone)",
            "direction = 'incoming'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM messages WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }
}