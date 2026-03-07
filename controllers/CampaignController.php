<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class CampaignController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listCampaigns($instanceId = null, $limit = 100, $offset = 0) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaign($campaignId) {
        $sql = "SELECT * FROM campaigns WHERE campaign_id = :id";
        return $this->db->fetchOne($sql, [':id' => $campaignId]);
    }

    public function createCampaign($data) {
        $campaignId = 'camp_' . bin2hex(random_bytes(8));
        
        $campaignData = [
            'campaign_id' => $campaignId,
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'target_audience' => $data['target_audience'] ?? '',
            'message_template' => $data['message_template'] ?? '',
            'status' => $data['status'] ?? 'draft',
            'start_date' => $data['start_date'] ?? date('Y-m-d H:i:s'),
            'end_date' => $data['end_date'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('campaigns', $campaignData);
        return $this->getCampaign($campaignId);
    }

    public function updateCampaign($campaignId, $data) {
        $updateData = [
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'target_audience' => $data['target_audience'] ?? '',
            'message_template' => $data['message_template'] ?? '',
            'status' => $data['status'] ?? '',
            'start_date' => $data['start_date'] ?? '',
            'end_date' => $data['end_date'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('campaigns', $updateData, 'campaign_id = :id', [':id' => $campaignId]);
        return $this->getCampaign($campaignId);
    }

    public function deleteCampaign($campaignId) {
        $this->db->delete('campaigns', 'campaign_id = :id', [':id' => $campaignId]);
        return true;
    }

    public function getCampaignsByInstance($instanceId, $limit = 50) {
        $params = [
            ':instance_id' => $instanceId,
            ':limit' => $limit
        ];

        $sql = "SELECT * FROM campaigns WHERE instance_id = :instance_id ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsByStatus($status, $instanceId = null, $limit = 50) {
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

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getActiveCampaigns($instanceId = null, $limit = 50) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s'),
            ':limit' => $limit
        ];
        
        $where = [
            "status = 'active'",
            "start_date <= :current_time",
            "(end_date IS NULL OR end_date >= :current_time)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getUpcomingCampaigns($instanceId = null, $limit = 50) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s'),
            ':limit' => $limit
        ];
        
        $where = [
            "status = 'scheduled'",
            "start_date > :current_time"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY start_date ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getCompletedCampaigns($instanceId = null, $limit = 50) {
        $params = [
            ':limit' => $limit
        ];
        
        $where = [
            "status = 'completed'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY end_date DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsByDateRange($startDate, $endDate, $instanceId = null, $limit = 100) {
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

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsForToday($instanceId = null, $limit = 50) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getCampaignsByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId, $limit);
    }

    public function getCampaignsForWeek($instanceId = null, $limit = 100) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getCampaignsByDateRange($startOfWeek, $endOfWeek, $instanceId, $limit);
    }

    public function getCampaignsForMonth($instanceId = null, $limit = 200) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getCampaignsByDateRange($firstDay, $lastDay, $instanceId, $limit);
    }

    public function getCampaignCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM campaigns";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getCampaignStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM campaigns";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function searchCampaigns($query, $instanceId = null, $limit = 50) {
        $params = [
            ':query' => '%' . $query . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "(name LIKE :query OR description LIKE :query OR target_audience LIKE :query OR 
              message_template LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsWithTargetAudience($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "target_audience != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsWithoutTargetAudience($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "target_audience = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsWithMessageTemplate($instanceId = null, $limit = 100) {
        $params = [];
        $where = [
            "message_template != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsWithoutMessageTemplate($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "message_template = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignsByMultipleCriteria($criteria, $instanceId = null, $limit = 50) {
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
        
        if (isset($criteria['target_audience'])) {
            $where[] = "target_audience LIKE :target_audience";
            $params[':target_audience'] = '%' . $criteria['target_audience'] . '%';
        }
        
        if (isset($criteria['status'])) {
            $where[] = "status = :status";
            $params[':status'] = $criteria['status'];
        }
        
        if (isset($criteria['start_date'])) {
            $where[] = "start_date >= :start_date";
            $params[':start_date'] = $criteria['start_date'];
        }
        
        if (isset($criteria['end_date'])) {
            $where[] = "end_date <= :end_date";
            $params[':end_date'] = $criteria['end_date'];
        }

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM campaigns WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function startCampaign($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign || $campaign['status'] !== 'scheduled') {
            return false;
        }

        // Update campaign status to active
        $updateData = [
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('campaigns', $updateData, 'campaign_id = :id', [':id' => $campaignId]);
        return $this->getCampaign($campaignId);
    }

    public function pauseCampaign($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign || $campaign['status'] !== 'active') {
            return false;
        }

        // Update campaign status to paused
        $updateData = [
            'status' => 'paused',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('campaigns', $updateData, 'campaign_id = :id', [':id' => $campaignId]);
        return $this->getCampaign($campaignId);
    }

    public function completeCampaign($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign || $campaign['status'] === 'completed') {
            return false;
        }

        // Update campaign status to completed
        $updateData = [
            'status' => 'completed',
            'end_date' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('campaigns', $updateData, 'campaign_id = :id', [':id' => $campaignId]);
        return $this->getCampaign($campaignId);
    }

    public function cancelCampaign($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign || $campaign['status'] === 'cancelled') {
            return false;
        }

        // Update campaign status to cancelled
        $updateData = [
            'status' => 'cancelled',
            'end_date' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('campaigns', $updateData, 'campaign_id = :id', [':id' => $campaignId]);
        return $this->getCampaign($campaignId);
    }

    public function getCampaignMessages($campaignId, $limit = 100) {
        $params = [
            ':campaign_id' => $campaignId,
            ':limit' => $limit
        ];

        $sql = "SELECT m.* FROM messages m 
                JOIN campaign_messages cm ON m.message_id = cm.message_id 
                WHERE cm.campaign_id = :campaign_id 
                ORDER BY m.created_at DESC 
                LIMIT :limit";
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignMessageCount($campaignId) {
        $params = [
            ':campaign_id' => $campaignId
        ];

        $sql = "SELECT COUNT(*) as count FROM campaign_messages WHERE campaign_id = :campaign_id";
        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getCampaignMessageStatistics($campaignId) {
        $params = [
            ':campaign_id' => $campaignId
        ];

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN m.direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
                SUM(CASE WHEN m.status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN m.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN m.status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages m 
                JOIN campaign_messages cm ON m.message_id = cm.message_id 
                WHERE cm.campaign_id = :campaign_id";
        
        return $this->db->fetchOne($sql, $params);
    }

    public function getCampaignWithMessages($campaignId, $limit = 50) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign) {
            return null;
        }

        $campaign['messages'] = $this->getCampaignMessages($campaignId, $limit);
        $campaign['message_count'] = $this->getCampaignMessageCount($campaignId);
        $campaign['message_statistics'] = $this->getCampaignMessageStatistics($campaignId);
        
        return $campaign;
    }

    public function getCampaignTargetAudience($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign || !$campaign['target_audience']) {
            return [];
        }

        // Parse target audience criteria
        $criteria = json_decode($campaign['target_audience'], true);
        if (!$criteria) {
            return [];
        }

        // Get contacts matching criteria
        $contactController = new ContactController();
        return $contactController->getContactsByMultipleCriteria($criteria);
    }

    public function getCampaignTargetAudienceCount($campaignId) {
        $contacts = $this->getCampaignTargetAudience($campaignId);
        return count($contacts);
    }

    public function getCampaignTargetAudienceStatistics($campaignId) {
        $contacts = $this->getCampaignTargetAudience($campaignId);
        
        $stats = [
            'total' => count($contacts),
            'with_phone' => 0,
            'with_email' => 0,
            'with_address' => 0
        ];

        foreach ($contacts as $contact) {
            if ($contact['phone']) $stats['with_phone']++;
            if ($contact['email']) $stats['with_email']++;
            if ($contact['address']) $stats['with_address']++;
        }

        return $stats;
    }

    public function getCampaignTargetAudienceWithDetails($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign) {
            return null;
        }

        $campaign['target_audience_contacts'] = $this->getCampaignTargetAudience($campaignId);
        $campaign['target_audience_count'] = $this->getCampaignTargetAudienceCount($campaignId);
        $campaign['target_audience_statistics'] = $this->getCampaignTargetAudienceStatistics($campaignId);
        
        return $campaign;
    }

    public function getCampaignPerformance($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign) {
            return null;
        }

        $performance = [
            'campaign' => $campaign,
            'message_count' => $this->getCampaignMessageCount($campaignId),
            'message_statistics' => $this->getCampaignMessageStatistics($campaignId),
            'target_audience_count' => $this->getCampaignTargetAudienceCount($campaignId),
            'target_audience_statistics' => $this->getCampaignTargetAudienceStatistics($campaignId),
            'delivery_rate' => 0,
            'read_rate' => 0,
            'conversion_rate' => 0
        ];

        // Calculate rates
        $stats = $performance['message_statistics'];
        if ($stats['total'] > 0) {
            $performance['delivery_rate'] = ($stats['sent'] + $stats['delivered']) / $stats['total'] * 100;
            $performance['read_rate'] = $stats['read'] / $stats['total'] * 100;
            $performance['conversion_rate'] = $stats['read'] / $performance['target_audience_count'] * 100;
        }

        return $performance;
    }

    public function getCampaignPerformanceHistory($campaignId) {
        $params = [
            ':campaign_id' => $campaignId
        ];

        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as message_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages m 
                JOIN campaign_messages cm ON m.message_id = cm.message_id 
                WHERE cm.campaign_id = :campaign_id 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC";
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignDailyPerformance($campaignId) {
        $history = $this->getCampaignPerformanceHistory($campaignId);
        
        $dailyPerformance = [];
        foreach ($history as $day) {
            $dailyPerformance[$day['date']] = [
                'date' => $day['date'],
                'message_count' => $day['message_count'],
                'sent' => $day['sent'],
                'delivered' => $day['delivered'],
                'read' => $day['read'],
                'failed' => $day['failed'],
                'delivery_rate' => $day['message_count'] > 0 ? (($day['sent'] + $day['delivered']) / $day['message_count']) * 100 : 0,
                'read_rate' => $day['message_count'] > 0 ? ($day['read'] / $day['message_count']) * 100 : 0
            ];
        }

        return $dailyPerformance;
    }

    public function getCampaignWeeklyPerformance($campaignId) {
        $history = $this->getCampaignPerformanceHistory($campaignId);
        
        $weeklyPerformance = [];
        foreach ($history as $day) {
            $week = date('oW', strtotime($day['date']));
            if (!isset($weeklyPerformance[$week])) {
                $weeklyPerformance[$week] = [
                    'week' => $week,
                    'message_count' => 0,
                    'sent' => 0,
                    'delivered' => 0,
                    'read' => 0,
                    'failed' => 0
                ];
            }

            $weeklyPerformance[$week]['message_count'] += $day['message_count'];
            $weeklyPerformance[$week]['sent'] += $day['sent'];
            $weeklyPerformance[$week]['delivered'] += $day['delivered'];
            $weeklyPerformance[$week]['read'] += $day['read'];
            $weeklyPerformance[$week]['failed'] += $day['failed'];
        }

        // Calculate rates for each week
        foreach ($weeklyPerformance as &$weekData) {
            $weekData['delivery_rate'] = $weekData['message_count'] > 0 ? (($weekData['sent'] + $weekData['delivered']) / $weekData['message_count']) * 100 : 0;
            $weekData['read_rate'] = $weekData['message_count'] > 0 ? ($weekData['read'] / $weekData['message_count']) * 100 : 0;
        }

        return array_values($weeklyPerformance);
    }

    public function getCampaignMonthlyPerformance($campaignId) {
        $history = $this->getCampaignPerformanceHistory($campaignId);
        
        $monthlyPerformance = [];
        foreach ($history as $day) {
            $month = date('Ym', strtotime($day['date']));
            if (!isset($monthlyPerformance[$month])) {
                $monthlyPerformance[$month] = [
                    'month' => $month,
                    'message_count' => 0,
                    'sent' => 0,
                    'delivered' => 0,
                    'read' => 0,
                    'failed' => 0
                ];
            }

            $monthlyPerformance[$month]['message_count'] += $day['message_count'];
            $monthlyPerformance[$month]['sent'] += $day['sent'];
            $monthlyPerformance[$month]['delivered'] += $day['delivered'];
            $monthlyPerformance[$month]['read'] += $day['read'];
            $monthlyPerformance[$month]['failed'] += $day['failed'];
        }

        // Calculate rates for each month
        foreach ($monthlyPerformance as &$monthData) {
            $monthData['delivery_rate'] = $monthData['message_count'] > 0 ? (($monthData['sent'] + $monthData['delivered']) / $monthData['message_count']) * 100 : 0;
            $monthData['read_rate'] = $monthData['message_count'] > 0 ? ($monthData['read'] / $monthData['message_count']) * 100 : 0;
        }

        return array_values($monthlyPerformance);
    }

    public function getCampaignPerformanceByDay($campaignId, $days = 7) {
        $params = [
            ':campaign_id' => $campaignId,
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days'))
        ];

        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as message_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages m 
                JOIN campaign_messages cm ON m.message_id = cm.message_id 
                WHERE cm.campaign_id = :campaign_id 
                AND created_at >= :days_ago 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC";
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignPerformanceByWeek($campaignId, $weeks = 4) {
        $params = [
            ':campaign_id' => $campaignId,
            ':weeks_ago' => date('Y-m-d H:i:s', strtotime('-' . ($weeks * 7) . ' days'))
        ];

        $sql = "SELECT 
                STRFTIME('%Y-' || (STRFTIME('%W', created_at) + 1) AS week,
                COUNT(*) as message_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages m 
                JOIN campaign_messages cm ON m.message_id = cm.message_id 
                WHERE cm.campaign_id = :campaign_id 
                AND created_at >= :weeks_ago 
                GROUP BY week 
                ORDER BY week DESC";
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignPerformanceByMonth($campaignId, $months = 6) {
        $params = [
            ':campaign_id' => $campaignId,
            ':months_ago' => date('Y-m-d H:i:s', strtotime('-' . ($months * 30) . ' days'))
        ];

        $sql = "SELECT 
                STRFTIME('%Y-%m', created_at) as month,
                COUNT(*) as message_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages m 
                JOIN campaign_messages cm ON m.message_id = cm.message_id 
                WHERE cm.campaign_id = :campaign_id 
                AND created_at >= :months_ago 
                GROUP BY month 
                ORDER BY month DESC";
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getCampaignPerformanceComparison($campaignId, $period = 'week') {
        switch ($period) {
            case 'day':
                return $this->getCampaignPerformanceByDay($campaignId);
            case 'week':
                return $this->getCampaignPerformanceByWeek($campaignId);
            case 'month':
                return $this->getCampaignPerformanceByMonth($campaignId);
            default:
                return $this->getCampaignPerformanceHistory($campaignId);
        }
    }

    public function getCampaignPerformanceTrends($campaignId, $period = 'week') {
        $performance = $this->getCampaignPerformanceComparison($campaignId, $period);
        
        $trends = [
            'message_count' => [],
            'sent' => [],
            'delivered' => [],
            'read' => [],
            'failed' => [],
            'delivery_rate' => [],
            'read_rate' => []
        ];

        foreach ($performance as $periodData) {
            $trends['message_count'][] = $periodData['message_count'];
            $trends['sent'][] = $periodData['sent'];
            $trends['delivered'][] = $periodData['delivered'];
            $trends['read'][] = $periodData['read'];
            $trends['failed'][] = $periodData['failed'];
            
            $total = $periodData['message_count'];
            $trends['delivery_rate'][] = $total > 0 ? (($periodData['sent'] + $periodData['delivered']) / $total) * 100 : 0;
            $trends['read_rate'][] = $total > 0 ? ($periodData['read'] / $total) * 100 : 0;
        }

        return $trends;
    }

    public function getCampaignPerformanceSummary($campaignId) {
        $campaign = $this->getCampaign($campaignId);
        if (!$campaign) {
            return null;
        }

        $summary = [
            'campaign' => $campaign,
            'overall_performance' => $this->getCampaignPerformance($campaignId),
            'daily_performance' => $this->getCampaignPerformanceByDay($campaignId, 7),
            'weekly_performance' => $this->getCampaignPerformanceByWeek($campaignId, 4),
            'monthly_performance' => $this->getCampaignPerformanceByMonth($campaignId, 6),
            'target_audience' => $this->getCampaignTargetAudienceWithDetails($campaignId),
            'performance_trends' => $this->getCampaignPerformanceTrends($campaignId, 'week')
        ];

        return $summary;
    }
}