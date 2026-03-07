<?php

require_once __DIR__ . '/../config/database.php';

class InstanceSecretaryConfigModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getSecretaryConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_secretary_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function saveSecretaryConfig(string $instanceId, array $data): bool {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO instance_secretary_config (
                instance_id, enabled, idle_hours, initial_response, term_1, response_1,
                term_2, response_2, quick_replies, created_at, updated_at
            ) VALUES (
                :instance_id, :enabled, :idle_hours, :initial_response, :term_1, :response_1,
                :term_2, :response_2, :quick_replies, datetime('now'), datetime('now')
            )
        ");
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':enabled', $data['enabled'] ?? false ? 'true' : 'false', SQLITE3_TEXT);
        $stmt->bindValue(':idle_hours', $data['idle_hours'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':initial_response', $data['initial_response'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':term_1', $data['term_1'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':response_1', $data['response_1'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':term_2', $data['term_2'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':response_2', $data['response_2'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':quick_replies', json_encode($data['quick_replies'] ?? []), SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getSecretaryStats(string $instanceId, int $days = 30): array {
        $stats = [
            'total_responses' => 0,
            'automated_responses' => 0,
            'manual_responses' => 0,
            'response_rate' => 0,
            'avg_response_time' => 0,
            'responses_by_type' => [],
            'responses_by_hour' => []
        ];

        // Get total responses
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total_responses
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_responses'] = $row['total_responses'] ?? 0;
        $stmt->close();

        // Get automated vs manual responses
        $stmt = $this->db->prepare("
            SELECT 
                SUM(CASE WHEN metadata->>'secretary_response' = 'automated' THEN 1 ELSE 0 END) as automated_responses,
                SUM(CASE WHEN metadata->>'secretary_response' = 'manual' THEN 1 ELSE 0 END) as manual_responses
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['automated_responses'] = $row['automated_responses'] ?? 0;
        $stats['manual_responses'] = $row['manual_responses'] ?? 0;
        $stmt->close();

        // Calculate response rate
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_messages,
                SUM(CASE WHEN metadata->>'secretary_response' IS NOT NULL THEN 1 ELSE 0 END) as responded_messages
            FROM instance_messages 
            WHERE instance_id = :id 
            AND direction = 'inbound'
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $totalMessages = $row['total_messages'] ?? 0;
        $respondedMessages = $row['responded_messages'] ?? 0;
        $stats['response_rate'] = $totalMessages > 0 ? ($respondedMessages / $totalMessages) * 100 : 0;
        $stmt->close();

        // Get average response time
        $stmt = $this->db->prepare("
            SELECT 
                AVG(strftime('%s', m.created_at) - strftime('%s', c.last_message_at)) as avg_response_time
            FROM instance_messages m
            JOIN instance_chats c ON m.instance_id = c.instance_id AND m.remote_jid = c.jid
            WHERE m.instance_id = :id 
            AND m.direction = 'inbound'
            AND m.metadata->>'secretary_response' IS NOT NULL
            AND m.created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['avg_response_time'] = $row['avg_response_time'] ?? 0;
        $stmt->close();

        // Get responses by type
        $stmt = $this->db->prepare("
            SELECT 
                metadata->>'secretary_type' as response_type,
                COUNT(*) as response_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY metadata->>'secretary_type'
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['responses_by_type'][$row['response_type']] = $row['response_count'];
        }
        $stmt->close();

        // Get responses by hour
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', created_at) as hour_of_day,
                COUNT(*) as response_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%H', created_at)
            ORDER BY hour_of_day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['responses_by_hour'][$row['hour_of_day']] = $row['response_count'];
        }
        $stmt->close();

        return $stats;
    }

    public function getSecretaryPerformance(string $instanceId, int $hours = 24): array {
        $performance = [
            'response_time' => 0,
            'response_rate' => 0,
            'customer_satisfaction' => 0,
            'common_responses' => []
        ];

        // Get average response time
        $stmt = $this->db->prepare("
            SELECT 
                AVG(strftime('%s', m.created_at) - strftime('%s', c.last_message_at)) as avg_response_time
            FROM instance_messages m
            JOIN instance_chats c ON m.instance_id = c.instance_id AND m.remote_jid = c.jid
            WHERE m.instance_id = :id 
            AND m.direction = 'inbound'
            AND m.metadata->>'secretary_response' IS NOT NULL
            AND m.created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['response_time'] = $row['avg_response_time'] ?? 0;
        $stmt->close();

        // Get response rate
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_messages,
                SUM(CASE WHEN metadata->>'secretary_response' IS NOT NULL THEN 1 ELSE 0 END) as responded_messages
            FROM instance_messages 
            WHERE instance_id = :id 
            AND direction = 'inbound'
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $totalMessages = $row['total_messages'] ?? 0;
        $respondedMessages = $row['responded_messages'] ?? 0;
        $performance['response_rate'] = $totalMessages > 0 ? ($respondedMessages / $totalMessages) * 100 : 0;
        $stmt->close();

        // Get customer satisfaction
        $stmt = $this->db->prepare("
            SELECT 
                AVG(CAST(metadata->>'rating' AS REAL)) as avg_rating
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND metadata->>'rating' IS NOT NULL
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['customer_satisfaction'] = $row['avg_rating'] ?? 0;
        $stmt->close();

        // Get common responses
        $stmt = $this->db->prepare("
            SELECT 
                metadata->>'secretary_response_content' as response_content,
                COUNT(*) as response_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND created_at >= datetime('now', :hours || ' hours')
            GROUP BY metadata->>'secretary_response_content'
            ORDER BY response_count DESC
            LIMIT 10
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $performance['common_responses'][] = [
                'content' => $row['response_content'],
                'count' => $row['response_count']
            ];
        }
        $stmt->close();

        return $performance;
    }

    public function getSecretaryUsage(string $instanceId, int $days = 30): array {
        $usage = [];
        
        // Get responses by day of week
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%w', created_at) as day_of_week,
                COUNT(*) as response_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%w', created_at)
            ORDER BY day_of_week ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['responses_by_day'][$row['day_of_week']] = $row['response_count'];
        }
        $stmt->close();

        // Get responses by hour of day
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', created_at) as hour_of_day,
                COUNT(*) as response_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'secretary_response' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%H', created_at)
            ORDER BY hour_of_day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['responses_by_hour'][$row['hour_of_day']] = $row['response_count'];
        }
        $stmt->close();

        return $usage;
    }

    public function getSecretaryQuickReplies(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT quick_replies FROM instance_secretary_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        
        return json_decode($config['quick_replies'] ?? '[]', true) ?: [];
    }

    public function updateQuickReplies(string $instanceId, array $quickReplies): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_secretary_config 
            SET quick_replies = :quick_replies, updated_at = datetime('now')
            WHERE instance_id = :id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':quick_replies', json_encode($quickReplies), SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getSecretaryTerms(string $instanceId): array {
        $stmt = $this->db->prepare("SELECT term_1, response_1, term_2, response_2 FROM instance_secretary_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        
        return [
            'term_1' => $config['term_1'] ?? '',
            'response_1' => $config['response_1'] ?? '',
            'term_2' => $config['term_2'] ?? '',
            'response_2' => $config['response_2'] ?? ''
        ];
    }

    public function updateSecretaryTerms(string $instanceId, array $terms): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_secretary_config 
            SET term_1 = :term_1, response_1 = :response_1, term_2 = :term_2, response_2 = :response_2, updated_at = datetime('now')
            WHERE instance_id = :id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':term_1', $terms['term_1'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':response_1', $terms['response_1'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':term_2', $terms['term_2'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':response_2', $terms['response_2'] ?? '', SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }
}