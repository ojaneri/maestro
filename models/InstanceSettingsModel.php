<?php

require_once __DIR__ . '/../config/database.php';

class InstanceSettingsModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getInstanceSettings(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_settings WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $settings = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $settings ?: null;
    }

    public function saveInstanceSettings(string $instanceId, array $data): bool {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO instance_settings (
                instance_id, instance_name, instance_description, instance_status, 
                max_connections, timeout, retry_attempts, retry_delay, 
                webhook_url, webhook_secret, created_at, updated_at
            ) VALUES (
                :instance_id, :instance_name, :instance_description, :instance_status,
                :max_connections, :timeout, :retry_attempts, :retry_delay,
                :webhook_url, :webhook_secret, datetime('now'), datetime('now')
            )
        ");
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_name', $data['instance_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':instance_description', $data['instance_description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':instance_status', $data['instance_status'] ?? 'active', SQLITE3_TEXT);
        $stmt->bindValue(':max_connections', $data['max_connections'] ?? 10, SQLITE3_INTEGER);
        $stmt->bindValue(':timeout', $data['timeout'] ?? 30, SQLITE3_INTEGER);
        $stmt->bindValue(':retry_attempts', $data['retry_attempts'] ?? 3, SQLITE3_INTEGER);
        $stmt->bindValue(':retry_delay', $data['retry_delay'] ?? 1000, SQLITE3_INTEGER);
        $stmt->bindValue(':webhook_url', $data['webhook_url'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':webhook_secret', $data['webhook_secret'] ?? '', SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function updateInstanceStatus(string $instanceId, string $status): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_settings 
            SET instance_status = :status, updated_at = datetime('now')
            WHERE instance_id = :id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getInstanceStatus(string $instanceId): ?string {
        $stmt = $this->db->prepare("SELECT instance_status FROM instance_settings WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        
        return $row['instance_status'] ?? null;
    }

    public function getInstanceLimits(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT max_connections, timeout, retry_attempts, retry_delay FROM instance_settings WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        
        return [
            'max_connections' => $row['max_connections'] ?? 10,
            'timeout' => $row['timeout'] ?? 30,
            'retry_attempts' => $row['retry_attempts'] ?? 3,
            'retry_delay' => $row['retry_delay'] ?? 1000
        ];
    }

    public function updateInstanceLimits(string $instanceId, array $limits): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_settings 
            SET max_connections = :max_connections, timeout = :timeout, 
                retry_attempts = :retry_attempts, retry_delay = :retry_delay,
                updated_at = datetime('now')
            WHERE instance_id = :id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':max_connections', $limits['max_connections'] ?? 10, SQLITE3_INTEGER);
        $stmt->bindValue(':timeout', $limits['timeout'] ?? 30, SQLITE3_INTEGER);
        $stmt->bindValue(':retry_attempts', $limits['retry_attempts'] ?? 3, SQLITE3_INTEGER);
        $stmt->bindValue(':retry_delay', $limits['retry_delay'] ?? 1000, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getInstanceWebhooks(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT webhook_url, webhook_secret FROM instance_settings WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        
        return [
            'webhook_url' => $row['webhook_url'] ?? '',
            'webhook_secret' => $row['webhook_secret'] ?? ''
        ];
    }

    public function updateInstanceWebhooks(string $instanceId, array $webhooks): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_settings 
            SET webhook_url = :webhook_url, webhook_secret = :webhook_secret,
                updated_at = datetime('now')
            WHERE instance_id = :id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':webhook_url', $webhooks['webhook_url'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':webhook_secret', $webhooks['webhook_secret'] ?? '', SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getInstanceMetadata(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT metadata FROM instance_settings WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        
        return json_decode($row['metadata'] ?? '{}', true) ?: [];
    }

    public function updateInstanceMetadata(string $instanceId, array $metadata): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_settings 
            SET metadata = :metadata, updated_at = datetime('now')
            WHERE instance_id = :id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($metadata), SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getInstancePerformance(string $instanceId, int $hours = 24): array {
        $performance = [
            'message_throughput' => 0,
            'response_time' => 0,
            'error_rate' => 0,
            'uptime' => 0
        ];

        // Get message throughput
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as message_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['message_throughput'] = $row['message_count'] ?? 0;
        $stmt->close();

        // Get average response time
        $stmt = $this->db->prepare("
            SELECT 
                AVG(strftime('%s', m.created_at) - strftime('%s', c.last_message_at)) as avg_response_time
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
        $performance['response_time'] = $row['avg_response_time'] ?? 0;
        $stmt->close();

        // Get error rate
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_messages,
                SUM(CASE WHEN metadata->>'error' IS NOT NULL THEN 1 ELSE 0 END) as error_messages
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $totalMessages = $row['total_messages'] ?? 0;
        $errorMessages = $row['error_messages'] ?? 0;
        $performance['error_rate'] = $totalMessages > 0 ? ($errorMessages / $totalMessages) * 100 : 0;
        $stmt->close();

        // Get uptime (simplified - would need actual process monitoring)
        $performance['uptime'] = 100; // Placeholder - implement actual uptime monitoring

        return $performance;
    }

    public function getInstanceUsage(string $instanceId, int $days = 30): array {
        $usage = [];
        
        // Get messages by day of week
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%w', created_at) as day_of_week,
                COUNT(*) as message_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%w', created_at)
            ORDER BY day_of_week ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['messages_by_day'][$row['day_of_week']] = $row['message_count'];
        }
        $stmt->close();

        // Get messages by hour of day
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', created_at) as hour_of_day,
                COUNT(*) as message_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%H', created_at)
            ORDER BY hour_of_day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['messages_by_hour'][$row['hour_of_day']] = $row['message_count'];
        }
        $stmt->close();

        return $usage;
    }

    public function getInstanceActivity(string $instanceId, int $hours = 24): array {
        $activity = [
            'active_chats' => 0,
            'pending_messages' => 0,
            'recent_messages' => []
        ];

        // Get active chats
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as active_chats
            FROM instance_chats 
            WHERE instance_id = :id 
            AND last_message_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $activity['active_chats'] = $row['active_chats'] ?? 0;
        $stmt->close();

        // Get pending messages
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as pending_messages
            FROM instance_messages 
            WHERE instance_id = :id 
            AND direction = 'outbound'
            AND status = 'pending'
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $activity['pending_messages'] = $row['pending_messages'] ?? 0;
        $stmt->close();

        // Get recent messages
        $stmt = $this->db->prepare("
            SELECT 
                remote_jid, message, direction, status, created_at
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :hours || ' hours')
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $activity['recent_messages'][] = $row;
        }
        $stmt->close();

        return $activity;
    }

    public function getInstanceHealth(string $instanceId): array {
        $health = [
            'database_connection' => true,
            'process_status' => 'unknown',
            'memory_usage' => 0,
            'cpu_usage' => 0,
            'disk_space' => 0
        ];

        // Check database connection
        try {
            $this->db->exec("SELECT 1");
        } catch (Exception $e) {
            $health['database_connection'] = false;
        }

        // Get process status (simplified - would need actual process monitoring)
        $processStatus = $this->getInstanceStatus($instanceId);
        $health['process_status'] = $processStatus ?: 'unknown';

        // Get system metrics (simplified - would need actual system monitoring)
        $health['memory_usage'] = memory_get_usage();
        $health['cpu_usage'] = 0; // Placeholder
        $health['disk_space'] = disk_free_space('/var/www/html/maestro.janeri.com.br');

        return $health;
    }

    public function getInstanceLogs(string $instanceId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT 
                log_level, message, created_at
            FROM instance_logs 
            WHERE instance_id = :id 
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $logs = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $logs[] = $row;
        }
        $stmt->close();
        
        return $logs;
    }

    public function getInstanceNotifications(string $instanceId, int $limit = 20): array {
        $stmt = $this->db->prepare("
            SELECT 
                notification_type, message, is_read, created_at
            FROM instance_notifications 
            WHERE instance_id = :id 
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $notifications = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $notifications[] = $row;
        }
        $stmt->close();
        
        return $notifications;
    }

    public function markNotificationAsRead(string $instanceId, int $notificationId): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_notifications 
            SET is_read = 'true', updated_at = datetime('now')
            WHERE instance_id = :id AND id = :notification_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':notification_id', $notificationId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getInstanceAlerts(string $instanceId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT 
                alert_type, severity, message, created_at
            FROM instance_alerts 
            WHERE instance_id = :id 
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $alerts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $alerts[] = $row;
        }
        $stmt->close();
        
        return $alerts;
    }

    public function getInstanceMetrics(string $instanceId, int $hours = 24): array {
        $metrics = [
            'messages_sent' => 0,
            'messages_received' => 0,
            'messages_failed' => 0,
            'messages_delivered' => 0,
            'messages_read' => 0
        ];

        // Get message counts
        $stmt = $this->db->prepare("
            SELECT 
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as messages_sent,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as messages_received,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as messages_failed,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as messages_delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as messages_read
            FROM instance_messages 
            WHERE instance_id = :id 
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $metrics['messages_sent'] = $row['messages_sent'] ?? 0;
        $metrics['messages_received'] = $row['messages_received'] ?? 0;
        $metrics['messages_failed'] = $row['messages_failed'] ?? 0;
        $metrics['messages_delivered'] = $row['messages_delivered'] ?? 0;
        $metrics['messages_read'] = $row['messages_read'] ?? 0;
        $stmt->close();

        return $metrics;
    }
}