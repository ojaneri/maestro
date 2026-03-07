<?php

require_once __DIR__ . '/../config/database.php';

class AIModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAIConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_ai_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function saveAIConfig(string $instanceId, array $data): bool {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO instance_ai_config (
                instance_id, enabled, provider, model, system_prompt, assistant_prompt, 
                assistant_id, history_limit, temperature, max_tokens, multi_input_delay,
                openai_api_key, openai_mode, gemini_api_key, gemini_instruction,
                ai_model_fallback_1, ai_model_fallback_2, openrouter_api_key, openrouter_base_url,
                created_at, updated_at
            ) VALUES (
                :instance_id, :enabled, :provider, :model, :system_prompt, :assistant_prompt,
                :assistant_id, :history_limit, :temperature, :max_tokens, :multi_input_delay,
                :openai_api_key, :openai_mode, :gemini_api_key, :gemini_instruction,
                :ai_model_fallback_1, :ai_model_fallback_2, :openrouter_api_key, :openrouter_base_url,
                datetime('now'), datetime('now')
            )
        ");
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':enabled', $data['enabled'] ?? false ? 'true' : 'false', SQLITE3_TEXT);
        $stmt->bindValue(':provider', $data['provider'] ?? 'openai', SQLITE3_TEXT);
        $stmt->bindValue(':model', $data['model'] ?? 'gpt-4.1-mini', SQLITE3_TEXT);
        $stmt->bindValue(':system_prompt', $data['system_prompt'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':assistant_prompt', $data['assistant_prompt'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':assistant_id', $data['assistant_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':history_limit', $data['history_limit'] ?? 20, SQLITE3_INTEGER);
        $stmt->bindValue(':temperature', $data['temperature'] ?? 0.3, SQLITE3_FLOAT);
        $stmt->bindValue(':max_tokens', $data['max_tokens'] ?? 600, SQLITE3_INTEGER);
        $stmt->bindValue(':multi_input_delay', $data['multi_input_delay'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':openai_api_key', $data['openai_api_key'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':openai_mode', $data['openai_mode'] ?? 'responses', SQLITE3_TEXT);
        $stmt->bindValue(':gemini_api_key', $data['gemini_api_key'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':gemini_instruction', $data['gemini_instruction'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':ai_model_fallback_1', $data['ai_model_fallback_1'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':ai_model_fallback_2', $data['ai_model_fallback_2'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':openrouter_api_key', $data['openrouter_api_key'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':openrouter_base_url', $data['openrouter_base_url'] ?? '', SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getAIUsageStats(string $instanceId, int $days = 30): array {
        $stats = [
            'total_requests' => 0,
            'successful_requests' => 0,
            'failed_requests' => 0,
            'avg_response_time' => 0,
            'total_tokens' => 0,
            'avg_tokens_per_request' => 0,
            'requests_by_provider' => [],
            'requests_by_model' => []
        ];

        // Get AI usage from message metadata
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN metadata->>'ai_status' = 'success' THEN 1 ELSE 0 END) as successful_requests,
                SUM(CASE WHEN metadata->>'ai_status' = 'error' THEN 1 ELSE 0 END) as failed_requests,
                AVG(CAST(metadata->>'response_time' AS REAL)) as avg_response_time,
                SUM(CAST(metadata->>'tokens_used' AS INTEGER)) as total_tokens
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'ai_provider' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_requests'] = $row['total_requests'] ?? 0;
        $stats['successful_requests'] = $row['successful_requests'] ?? 0;
        $stats['failed_requests'] = $row['failed_requests'] ?? 0;
        $stats['avg_response_time'] = $row['avg_response_time'] ?? 0;
        $stats['total_tokens'] = $row['total_tokens'] ?? 0;
        $stats['avg_tokens_per_request'] = $stats['total_requests'] > 0 ? ($stats['total_tokens'] / $stats['total_requests']) : 0;
        $stmt->close();

        // Get requests by provider
        $stmt = $this->db->prepare("
            SELECT 
                metadata->>'ai_provider' as provider,
                COUNT(*) as request_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'ai_provider' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY metadata->>'ai_provider'
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['requests_by_provider'][$row['provider']] = $row['request_count'];
        }
        $stmt->close();

        // Get requests by model
        $stmt = $this->db->prepare("
            SELECT 
                metadata->>'ai_model' as model,
                COUNT(*) as request_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'ai_model' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY metadata->>'ai_model'
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['requests_by_model'][$row['model']] = $row['request_count'];
        }
        $stmt->close();

        return $stats;
    }

    public function getAIResponseQuality(string $instanceId, int $days = 30): array {
        $quality = [
            'avg_rating' => 0,
            'total_ratings' => 0,
            'positive_ratings' => 0,
            'negative_ratings' => 0,
            'neutral_ratings' => 0,
            'ratings_by_category' => []
        ];

        // Get ratings from message metadata
        $stmt = $this->db->prepare("
            SELECT 
                AVG(CAST(metadata->>'rating' AS REAL)) as avg_rating,
                COUNT(*) as total_ratings,
                SUM(CASE WHEN metadata->>'rating' = '5' THEN 1 ELSE 0 END) as positive_ratings,
                SUM(CASE WHEN metadata->>'rating' = '1' THEN 1 ELSE 0 END) as negative_ratings,
                SUM(CASE WHEN metadata->>'rating' = '3' THEN 1 ELSE 0 END) as neutral_ratings
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'rating' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $quality['avg_rating'] = $row['avg_rating'] ?? 0;
        $quality['total_ratings'] = $row['total_ratings'] ?? 0;
        $quality['positive_ratings'] = $row['positive_ratings'] ?? 0;
        $quality['negative_ratings'] = $row['negative_ratings'] ?? 0;
        $quality['neutral_ratings'] = $row['neutral_ratings'] ?? 0;
        $stmt->close();

        // Get ratings by category
        $stmt = $this->db->prepare("
            SELECT 
                metadata->>'category' as category,
                COUNT(*) as rating_count,
                AVG(CAST(metadata->>'rating' AS REAL)) as avg_rating
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'rating' IS NOT NULL
            AND metadata->>'category' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY metadata->>'category'
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $quality['ratings_by_category'][$row['category']] = [
                'count' => $row['rating_count'],
                'avg_rating' => $row['avg_rating']
            ];
        }
        $stmt->close();

        return $quality;
    }

    public function getAIConversationStats(string $instanceId, int $days = 30): array {
        $conversations = [
            'total_conversations' => 0,
            'avg_messages_per_conversation' => 0,
            'avg_conversation_duration' => 0,
            'conversations_by_length' => [],
            'conversations_by_duration' => []
        ];

        // Get conversation statistics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT conversation_id) as total_conversations,
                AVG(message_count) as avg_messages_per_conversation,
                AVG(duration_seconds) as avg_conversation_duration
            FROM (
                SELECT 
                    metadata->>'conversation_id' as conversation_id,
                    COUNT(*) as message_count,
                    CAST(strftime('%s', MAX(created_at)) - strftime('%s', MIN(created_at)) AS REAL) as duration_seconds
                FROM instance_messages 
                WHERE instance_id = :id 
                AND metadata->>'conversation_id' IS NOT NULL
                AND created_at >= datetime('now', :days || ' days')
                GROUP BY metadata->>'conversation_id'
            )
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $conversations['total_conversations'] = $row['total_conversations'] ?? 0;
        $conversations['avg_messages_per_conversation'] = $row['avg_messages_per_conversation'] ?? 0;
        $conversations['avg_conversation_duration'] = $row['avg_conversation_duration'] ?? 0;
        $stmt->close();

        // Get conversations by length
        $stmt = $this->db->prepare("
            SELECT 
                message_count,
                COUNT(*) as conversation_count
            FROM (
                SELECT 
                    metadata->>'conversation_id' as conversation_id,
                    COUNT(*) as message_count
                FROM instance_messages 
                WHERE instance_id = :id 
                AND metadata->>'conversation_id' IS NOT NULL
                AND created_at >= datetime('now', :days || ' days')
                GROUP BY metadata->>'conversation_id'
            )
            GROUP BY message_count
            ORDER BY message_count ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $conversations['conversations_by_length'][$row['message_count']] = $row['conversation_count'];
        }
        $stmt->close();

        // Get conversations by duration
        $stmt = $this->db->prepare("
            SELECT 
                duration_seconds,
                COUNT(*) as conversation_count
            FROM (
                SELECT 
                    metadata->>'conversation_id' as conversation_id,
                    CAST(strftime('%s', MAX(created_at)) - strftime('%s', MIN(created_at)) AS REAL) as duration_seconds
                FROM instance_messages 
                WHERE instance_id = :id 
                AND metadata->>'conversation_id' IS NOT NULL
                AND created_at >= datetime('now', :days || ' days')
                GROUP BY metadata->>'conversation_id'
            )
            GROUP BY duration_seconds
            ORDER BY duration_seconds ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $conversations['conversations_by_duration'][$row['duration_seconds']] = $row['conversation_count'];
        }
        $stmt->close();

        return $conversations;
    }

    public function getAIErrorStats(string $instanceId, int $days = 30): array {
        $errors = [
            'total_errors' => 0,
            'errors_by_type' => [],
            'errors_by_model' => [],
            'errors_by_provider' => []
        ];

        // Get error statistics
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_errors,
                metadata->>'error_type' as error_type,
                metadata->>'ai_model' as ai_model,
                metadata->>'ai_provider' as ai_provider
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'ai_status' = 'error'
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $errors['total_errors']++;
            
            if ($row['error_type']) {
                $errors['errors_by_type'][$row['error_type']] = ($errors['errors_by_type'][$row['error_type']] ?? 0) + 1;
            }
            if ($row['ai_model']) {
                $errors['errors_by_model'][$row['ai_model']] = ($errors['errors_by_model'][$row['ai_model']] ?? 0) + 1;
            }
            if ($row['ai_provider']) {
                $errors['errors_by_provider'][$row['ai_provider']] = ($errors['errors_by_provider'][$row['ai_provider']] ?? 0) + 1;
            }
        }
        $stmt->close();

        return $errors;
    }

    public function getAIUsageByHour(string $instanceId, int $days = 30): array {
        $usage = [];
        
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', created_at) as hour,
                COUNT(*) as request_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'ai_provider' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%H', created_at)
            ORDER BY hour ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage[$row['hour']] = $row['request_count'];
        }
        $stmt->close();

        return $usage;
    }
}