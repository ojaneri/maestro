<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class ChatController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listChats($instanceId = null, $limit = 100, $offset = 0) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY last_message_at DESC LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getChat($chatId) {
        $sql = "SELECT * FROM chat_history WHERE chat_id = :id";
        return $this->db->fetchOne($sql, [':id' => $chatId]);
    }

    public function createChat($data) {
        $chatId = 'chat_' . bin2hex(random_bytes(8));
        
        $chatData = [
            'chat_id' => $chatId,
            'instance_id' => $data['instance_id'] ?? null,
            'contact_id' => $data['contact_id'] ?? null,
            'phone' => $data['phone'] ?? '',
            'name' => $data['name'] ?? '',
            'last_message' => $data['last_message'] ?? '',
            'last_message_at' => $data['last_message_at'] ?? date('Y-m-d H:i:s'),
            'unread_count' => $data['unread_count'] ?? 0,
            'status' => $data['status'] ?? 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('chat_history', $chatData);
        return $this->getChat($chatId);
    }

    public function updateChat($chatId, $data) {
        $updateData = [
            'contact_id' => $data['contact_id'] ?? '',
            'phone' => $data['phone'] ?? '',
            'name' => $data['name'] ?? '',
            'last_message' => $data['last_message'] ?? '',
            'last_message_at' => $data['last_message_at'] ?? '',
            'unread_count' => $data['unread_count'] ?? '',
            'status' => $data['status'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('chat_history', $updateData, 'chat_id = :id', [':id' => $chatId]);
        return $this->getChat($chatId);
    }

    public function deleteChat($chatId) {
        $this->db->delete('chat_history', 'chat_id = :id', [':id' => $chatId]);
        return true;
    }

    public function getChatsByPhone($phone, $instanceId = null, $limit = 50) {
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

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsByName($name, $instanceId = null, $limit = 50) {
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

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getUnreadChats($instanceId = null, $limit = 50) {
        $params = [
            ':limit' => $limit
        ];
        
        $where = [
            "unread_count > 0"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getRecentChats($instanceId = null, $limit = 20) {
        $params = [
            ':limit' => $limit
        ];
        
        $where = [
            "status = 'active'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getInactiveChats($instanceId = null, $days = 30, $limit = 50) {
        $params = [
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            ':limit' => $limit
        ];
        
        $where = [
            "last_message_at < :days_ago",
            "status = 'active'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM chat_history";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getUnreadChatCount($instanceId = null) {
        $params = [];
        $where = [
            "unread_count > 0"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM chat_history WHERE " . implode(" AND ", $where);
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getChatStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN unread_count > 0 THEN 1 ELSE 0 END) as with_unread,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
                FROM chat_history";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function searchChats($query, $instanceId = null, $limit = 50) {
        $params = [
            ':query' => '%' . $query . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "(name LIKE :query OR phone LIKE :query OR last_message LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsByDateRange($startDate, $endDate, $instanceId = null, $limit = 100) {
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':limit' => $limit
        ];
        
        $where = [
            "(last_message_at BETWEEN :start_date AND :end_date)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsForToday($instanceId = null, $limit = 50) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getChatsByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId, $limit);
    }

    public function getChatsForWeek($instanceId = null, $limit = 100) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getChatsByDateRange($startOfWeek, $endOfWeek, $instanceId, $limit);
    }

    public function getChatsForMonth($instanceId = null, $limit = 200) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getChatsByDateRange($firstDay, $lastDay, $instanceId, $limit);
    }

    public function getChatsWithRecentActivity($instanceId = null, $days = 7, $limit = 50) {
        $params = [
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            ':limit' => $limit
        ];
        
        $where = [
            "last_message_at >= :days_ago"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithoutRecentActivity($instanceId = null, $days = 30, $limit = 50) {
        $params = [
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            ':limit' => $limit
        ];
        
        $where = [
            "last_message_at < :days_ago",
            "status = 'active'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsByStatus($status, $instanceId = null, $limit = 50) {
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

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function markChatAsRead($chatId) {
        $updateData = [
            'unread_count' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('chat_history', $updateData, 'chat_id = :id', [':id' => $chatId]);
        return $this->getChat($chatId);
    }

    public function markChatAsUnread($chatId) {
        $chat = $this->getChat($chatId);
        if (!$chat) {
            return false;
        }

        $updateData = [
            'unread_count' => $chat['unread_count'] + 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('chat_history', $updateData, 'chat_id = :id', [':id' => $chatId]);
        return $this->getChat($chatId);
    }

    public function archiveChat($chatId) {
        $updateData = [
            'status' => 'archived',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('chat_history', $updateData, 'chat_id = :id', [':id' => $chatId]);
        return $this->getChat($chatId);
    }

    public function unarchiveChat($chatId) {
        $updateData = [
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('chat_history', $updateData, 'chat_id = :id', [':id' => $chatId]);
        return $this->getChat($chatId);
    }

    public function getChatConversation($chatId, $limit = 50) {
        $params = [
            ':chat_id' => $chatId,
            ':limit' => $limit
        ];

        $sql = "SELECT * FROM messages WHERE chat_id = :chat_id ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatMessages($chatId, $limit = 100) {
        $params = [
            ':chat_id' => $chatId,
            ':limit' => $limit
        ];

        $sql = "SELECT * FROM messages WHERE chat_id = :chat_id ORDER BY created_at ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatWithMessages($chatId, $limit = 50) {
        $chat = $this->getChat($chatId);
        if (!$chat) {
            return null;
        }

        $chat['messages'] = $this->getChatMessages($chatId, $limit);
        return $chat;
    }

    public function getChatContact($chatId) {
        $chat = $this->getChat($chatId);
        if (!$chat || !$chat['contact_id']) {
            return null;
        }

        $sql = "SELECT * FROM contacts WHERE contact_id = :id";
        return $this->db->fetchOne($sql, [':id' => $chat['contact_id']]);
    }

    public function getChatInstance($chatId) {
        $chat = $this->getChat($chatId);
        if (!$chat || !$chat['instance_id']) {
            return null;
        }

        $sql = "SELECT * FROM instances WHERE instance_id = :id";
        return $this->db->fetchOne($sql, [':id' => $chat['instance_id']]);
    }

    public function getChatWithContactAndInstance($chatId) {
        $chat = $this->getChat($chatId);
        if (!$chat) {
            return null;
        }

        $chat['contact'] = $this->getChatContact($chatId);
        $chat['instance'] = $this->getChatInstance($chatId);
        
        return $chat;
    }

    public function getChatMessagesWithDetails($chatId, $limit = 50) {
        $params = [
            ':chat_id' => $chatId,
            ':limit' => $limit
        ];

        $sql = "SELECT m.*, c.name as contact_name, c.phone as contact_phone 
                FROM messages m 
                LEFT JOIN contacts c ON m.contact_id = c.contact_id 
                WHERE m.chat_id = :chat_id 
                ORDER BY m.created_at ASC 
                LIMIT :limit";
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatLastMessage($chatId) {
        $params = [
            ':chat_id' => $chatId
        ];

        $sql = "SELECT * FROM messages WHERE chat_id = :chat_id ORDER BY created_at DESC LIMIT 1";
        return $this->db->fetchOne($sql, $params);
    }

    public function updateChatLastMessage($chatId, $message, $timestamp = null) {
        $updateData = [
            'last_message' => $message,
            'last_message_at' => $timestamp ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('chat_history', $updateData, 'chat_id = :id', [':id' => $chatId]);
        return $this->getChat($chatId);
    }

    public function incrementUnreadCount($chatId) {
        $sql = "UPDATE chat_history SET unread_count = unread_count + 1, updated_at = :updated_at WHERE chat_id = :id";
        $this->db->execute($sql, [
            ':id' => $chatId,
            ':updated_at' => date('Y-m-d H:i:s')
        ]);
        return $this->getChat($chatId);
    }

    public function resetUnreadCount($chatId) {
        $updateData = [
            'unread_count' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('chat_history', $updateData, 'chat_id = :id', [':id' => $chatId]);
        return $this->getChat($chatId);
    }

    public function getChatsWithNoMessages($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "last_message_at IS NULL OR last_message_at = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY created_at ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithMessages($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "last_message_at IS NOT NULL AND last_message_at != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithContact($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "contact_id IS NOT NULL AND contact_id != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithNoContact($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "contact_id IS NULL OR contact_id = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY created_at ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithPhone($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "phone != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithNoPhone($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "phone = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY created_at ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithName($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "name != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithNoName($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "name = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY created_at ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithStatus($status, $instanceId = null, $limit = 50) {
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

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getChatsWithMultipleCriteria($criteria, $instanceId = null, $limit = 50) {
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
        
        if (isset($criteria['status'])) {
            $where[] = "status = :status";
            $params[':status'] = $criteria['status'];
        }
        
        if (isset($criteria['contact_id'])) {
            $where[] = "contact_id = :contact_id";
            $params[':contact_id'] = $criteria['contact_id'];
        }

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM chat_history WHERE " . implode(" AND ", $where) . " ORDER BY last_message_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }
}