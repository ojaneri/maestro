<?php

require_once __DIR__ . '/../config/database.php';

class DashboardModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getInstanceStats(string $instanceId): array {
        $stats = [
            'total_instances' => 0,
            'active_instances' => 0,
            'inactive_instances' => 0,
            'total_messages' => 0,
            'unread_messages' => 0,
            'total_contacts' => 0,
            'active_contacts' => 0,
            'total_chats' => 0,
            'unread_chats' => 0,
            'total_groups' => 0,
            'total_campaigns' => 0,
            'active_campaigns' => 0,
            'total_templates' => 0,
            'pending_scheduled_messages' => 0,
            'sent_scheduled_messages' => 0,
            'failed_scheduled_messages' => 0
        ];

        // Instance statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instances");
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_instances'] = $row['count'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instances WHERE status = 'active'");
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['active_instances'] = $row['count'] ?? 0;
        $stmt->close();

        $stats['inactive_instances'] = $stats['total_instances'] - $stats['active_instances'];

        // Message statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_messages WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_messages'] = $row['count'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_messages WHERE instance_id = :id AND direction = 'inbound'");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['unread_messages'] = $row['count'] ?? 0;
        $stmt->close();

        // Contact statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_contacts WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_contacts'] = $row['count'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_contacts WHERE instance_id = :id AND status = 'active'");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['active_contacts'] = $row['count'] ?? 0;
        $stmt->close();

        // Chat statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_chats WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_chats'] = $row['count'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_chats WHERE instance_id = :id AND unread_count > 0");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['unread_chats'] = $row['count'] ?? 0;
        $stmt->close();

        // Group statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_groups WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_groups'] = $row['count'] ?? 0;
        $stmt->close();

        // Campaign statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_campaigns WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_campaigns'] = $row['count'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_campaigns WHERE instance_id = :id AND status = 'active'");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['active_campaigns'] = $row['count'] ?? 0;
        $stmt->close();

        // Template statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_templates WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_templates'] = $row['count'] ?? 0;
        $stmt->close();

        // Scheduled message statistics
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_scheduled_messages WHERE instance_id = :id AND status = 'pending'");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['pending_scheduled_messages'] = $row['count'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_scheduled_messages WHERE instance_id = :id AND status = 'sent'");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['sent_scheduled_messages'] = $row['count'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_scheduled_messages WHERE instance_id = :id AND status = 'failed'");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['failed_scheduled_messages'] = $row['count'] ?? 0;
        $stmt->close();

        return $stats;
    }

    public function getInstanceActivity(string $instanceId, int $days = 30): array {
        $activity = [];
        
        // Get message activity by day
        $stmt = $this->db->prepare("
            SELECT 
                date(created_at) as day, 
                COUNT(*) as message_count,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound_count,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY date(created_at)
            ORDER BY day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $activity['messages'][] = [
                'day' => $row['day'],
                'total' => $row['message_count'],
                'inbound' => $row['inbound_count'],
                'outbound' => $row['outbound_count']
            ];
        }
        $stmt->close();

        // Get contact activity by day
        $stmt = $this->db->prepare("
            SELECT 
                date(created_at) as day, 
                COUNT(*) as contact_count
            FROM instance_contacts 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY date(created_at)
            ORDER BY day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $activity['contacts'][] = [
                'day' => $row['day'],
                'count' => $row['contact_count']
            ];
        }
        $stmt->close();

        // Get campaign activity by day
        $stmt = $this->db->prepare("
            SELECT 
                date(created_at) as day, 
                COUNT(*) as campaign_count
            FROM instance_campaigns 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY date(created_at)
            ORDER BY day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $activity['campaigns'][] = [
                'day' => $row['day'],
                'count' => $row['campaign_count']
            ];
        }
        $stmt->close();

        return $activity;
    }

    public function getInstancePerformance(string $instanceId, int $hours = 24): array {
        $performance = [];
        
        // Get message response times
        $stmt = $this->db->prepare("
            SELECT 
                AVG(strftime('%s', m.created_at) - strftime('%s', c.last_message_at)) as avg_response_time,
                MIN(strftime('%s', m.created_at) - strftime('%s', c.last_message_at)) as min_response_time,
                MAX(strftime('%s', m.created_at) - strftime('%s', c.last_message_at)) as max_response_time
            FROM instance_messages m
            JOIN instance_chats c ON m.instance_id = c.instance_id AND m.remote_jid = c.jid
            WHERE m.instance_id = :id 
            AND m.direction = 'inbound'
            AND m.created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['response_time'] = [
            'avg' => $row['avg_response_time'] ?? 0,
            'min' => $row['min_response_time'] ?? 0,
            'max' => $row['max_response_time'] ?? 0
        ];
        $stmt->close();

        // Get message delivery rates
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_messages,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound_messages,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound_messages
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['message_delivery'] = [
            'total' => $row['total_messages'] ?? 0,
            'outbound' => $row['outbound_messages'] ?? 0,
            'inbound' => $row['inbound_messages'] ?? 0,
            'outbound_rate' => $row['total_messages'] > 0 ? ($row['outbound_messages'] / $row['total_messages']) * 100 : 0,
            'inbound_rate' => $row['total_messages'] > 0 ? ($row['inbound_messages'] / $row['total_messages']) * 100 : 0
        ];
        $stmt->close();

        // Get scheduled message performance
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_scheduled,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM instance_scheduled_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['scheduled_messages'] = [
            'total' => $row['total_scheduled'] ?? 0,
            'sent' => $row['sent_count'] ?? 0,
            'failed' => $row['failed_count'] ?? 0,
            'success_rate' => $row['total_scheduled'] > 0 ? ($row['sent_count'] / $row['total_scheduled']) * 100 : 0
        ];
        $stmt->close();

        return $performance;
    }

    public function getInstanceUsage(string $instanceId, int $days = 30): array {
        $usage = [];
        
        // Get message usage by hour
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', created_at) as hour,
                COUNT(*) as message_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%H', created_at)
            ORDER BY hour ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['hourly_messages'][$row['hour']] = $row['message_count'];
        }
        $stmt->close();

        // Get contact creation by day of week
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%w', created_at) as day_of_week,
                COUNT(*) as contact_count
            FROM instance_contacts 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%w', created_at)
            ORDER BY day_of_week ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['contacts_by_day'][$row['day_of_week']] = $row['contact_count'];
        }
        $stmt->close();

        // Get campaign creation by day of week
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%w', created_at) as day_of_week,
                COUNT(*) as campaign_count
            FROM instance_campaigns 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%w', created_at)
            ORDER BY day_of_week ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['campaigns_by_day'][$row['day_of_week']] = $row['campaign_count'];
        }
        $stmt->close();

        return $usage;
    }

    public function getInstanceTopContacts(string $instanceId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT 
                remote_jid, 
                COUNT(*) as message_count,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound_count,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound_count
            FROM instance_messages 
            WHERE instance_id = :id 
            GROUP BY remote_jid 
            ORDER BY message_count DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $contacts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $contacts[] = [
                'jid' => $row['remote_jid'],
                'total_messages' => $row['message_count'],
                'inbound' => $row['inbound_count'],
                'outbound' => $row['outbound_count'],
                'ratio' => $row['message_count'] > 0 ? ($row['outbound_count'] / $row['message_count']) * 100 : 0
            ];
        }
        $stmt->close();
        return $contacts;
    }

    public function getInstanceTopCampaigns(string $instanceId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT 
                c.campaign_id,
                c.name,
                c.status,
                c.sent_count,
                c.total_count,
                (c.sent_count * 100.0 / c.total_count) as progress,
                COUNT(m.message_id) as message_count
            FROM instance_campaigns c
            LEFT JOIN instance_messages m ON c.instance_id = m.instance_id AND c.message_template = m.metadata->>'template_id'
            WHERE c.instance_id = :id 
            GROUP BY c.campaign_id 
            ORDER BY c.sent_count DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = [
                'id' => $row['campaign_id'],
                'name' => $row['name'],
                'status' => $row['status'],
                'sent' => $row['sent_count'],
                'total' => $row['total_count'],
                'progress' => $row['progress'],
                'message_count' => $row['message_count']
            ];
        }
        $stmt->close();
        return $campaigns;
    }

    public function getInstanceRecentActivity(string $instanceId, int $limit = 10): array {
        $recent = [];
        
        // Get recent messages
        $stmt = $this->db->prepare("
            SELECT * FROM instance_messages 
            WHERE instance_id = :id 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $recent['messages'][] = $row;
        }
        $stmt->close();

        // Get recent contacts
        $stmt = $this->db->prepare("
            SELECT * FROM instance_contacts 
            WHERE instance_id = :id 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $recent['contacts'][] = $row;
        }
        $stmt->close();

        // Get recent campaigns
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id 
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $recent['campaigns'][] = $row;
        }
        $stmt->close();

        return $recent;
    }
}