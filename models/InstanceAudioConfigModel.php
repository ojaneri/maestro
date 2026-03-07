<?php

require_once __DIR__ . '/../config/database.php';

class InstanceAudioConfigModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAudioConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_audio_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function saveAudioConfig(string $instanceId, array $data): bool {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO instance_audio_config (
                instance_id, enabled, gemini_api_key, prefix, language, model, 
                created_at, updated_at
            ) VALUES (
                :instance_id, :enabled, :gemini_api_key, :prefix, :language, :model,
                datetime('now'), datetime('now')
            )
        ");
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':enabled', $data['enabled'] ?? false ? 'true' : 'false', SQLITE3_TEXT);
        $stmt->bindValue(':gemini_api_key', $data['gemini_api_key'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':prefix', $data['prefix'] ?? '🔊', SQLITE3_TEXT);
        $stmt->bindValue(':language', $data['language'] ?? 'pt-BR', SQLITE3_TEXT);
        $stmt->bindValue(':model', $data['model'] ?? 'gemini-1.5-flash', SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getAudioStats(string $instanceId, int $days = 30): array {
        $stats = [
            'total_transcriptions' => 0,
            'successful_transcriptions' => 0,
            'failed_transcriptions' => 0,
            'avg_transcription_time' => 0,
            'avg_confidence' => 0,
            'transcriptions_by_language' => [],
            'transcriptions_by_duration' => []
        ];

        // Get total transcriptions
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total_transcriptions
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_transcriptions'] = $row['total_transcriptions'] ?? 0;
        $stmt->close();

        // Get successful vs failed transcriptions
        $stmt = $this->db->prepare("
            SELECT 
                SUM(CASE WHEN metadata->>'audio_status' = 'success' THEN 1 ELSE 0 END) as successful_transcriptions,
                SUM(CASE WHEN metadata->>'audio_status' = 'error' THEN 1 ELSE 0 END) as failed_transcriptions
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['successful_transcriptions'] = $row['successful_transcriptions'] ?? 0;
        $stats['failed_transcriptions'] = $row['failed_transcriptions'] ?? 0;
        $stmt->close();

        // Get average transcription time
        $stmt = $this->db->prepare("
            SELECT 
                AVG(CAST(metadata->>'transcription_time' AS REAL)) as avg_transcription_time
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'transcription_time' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['avg_transcription_time'] = $row['avg_transcription_time'] ?? 0;
        $stmt->close();

        // Get average confidence
        $stmt = $this->db->prepare("
            SELECT 
                AVG(CAST(metadata->>'confidence' AS REAL)) as avg_confidence
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'confidence' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['avg_confidence'] = $row['avg_confidence'] ?? 0;
        $stmt->close();

        // Get transcriptions by language
        $stmt = $this->db->prepare("
            SELECT 
                metadata->>'audio_language' as language,
                COUNT(*) as transcription_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'audio_language' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY metadata->>'audio_language'
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['transcriptions_by_language'][$row['language']] = $row['transcription_count'];
        }
        $stmt->close();

        // Get transcriptions by duration
        $stmt = $this->db->prepare("
            SELECT 
                CAST(metadata->>'audio_duration' AS REAL) as duration,
                COUNT(*) as transcription_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'audio_duration' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY CAST(metadata->>'audio_duration' AS REAL)
            ORDER BY duration ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['transcriptions_by_duration'][$row['duration']] = $row['transcription_count'];
        }
        $stmt->close();

        return $stats;
    }

    public function getAudioPerformance(string $instanceId, int $hours = 24): array {
        $performance = [
            'transcription_success_rate' => 0,
            'avg_transcription_time' => 0,
            'avg_confidence' => 0,
            'common_errors' => []
        ];

        // Get transcription success rate
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_transcriptions,
                SUM(CASE WHEN metadata->>'audio_status' = 'success' THEN 1 ELSE 0 END) as successful_transcriptions
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $totalTranscriptions = $row['total_transcriptions'] ?? 0;
        $successfulTranscriptions = $row['successful_transcriptions'] ?? 0;
        $performance['transcription_success_rate'] = $totalTranscriptions > 0 ? ($successfulTranscriptions / $totalTranscriptions) * 100 : 0;
        $stmt->close();

        // Get average transcription time
        $stmt = $this->db->prepare("
            SELECT 
                AVG(CAST(metadata->>'transcription_time' AS REAL)) as avg_transcription_time
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'transcription_time' IS NOT NULL
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['avg_transcription_time'] = $row['avg_transcription_time'] ?? 0;
        $stmt->close();

        // Get average confidence
        $stmt = $this->db->prepare("
            SELECT 
                AVG(CAST(metadata->>'confidence' AS REAL)) as avg_confidence
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'confidence' IS NOT NULL
            AND created_at >= datetime('now', :hours || ' hours')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $performance['avg_confidence'] = $row['avg_confidence'] ?? 0;
        $stmt->close();

        // Get common errors
        $stmt = $this->db->prepare("
            SELECT 
                metadata->>'error_message' as error_message,
                COUNT(*) as error_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_status' = 'error'
            AND metadata->>'error_message' IS NOT NULL
            AND created_at >= datetime('now', :hours || ' hours')
            GROUP BY metadata->>'error_message'
            ORDER BY error_count DESC
            LIMIT 10
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':hours', '-' . $hours, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $performance['common_errors'][] = [
                'message' => $row['error_message'],
                'count' => $row['error_count']
            ];
        }
        $stmt->close();

        return $performance;
    }

    public function getAudioUsage(string $instanceId, int $days = 30): array {
        $usage = [];
        
        // Get transcriptions by day of week
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%w', created_at) as day_of_week,
                COUNT(*) as transcription_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%w', created_at)
            ORDER BY day_of_week ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['transcriptions_by_day'][$row['day_of_week']] = $row['transcription_count'];
        }
        $stmt->close();

        // Get transcriptions by hour of day
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', created_at) as hour_of_day,
                COUNT(*) as transcription_count
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND created_at >= datetime('now', :days || ' days')
            GROUP BY strftime('%H', created_at)
            ORDER BY hour_of_day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['transcriptions_by_hour'][$row['hour_of_day']] = $row['transcription_count'];
        }
        $stmt->close();

        return $usage;
    }

    public function getAudioLanguages(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT DISTINCT metadata->>'audio_language' as language
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'audio_language' IS NOT NULL
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $languages = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $languages[] = $row['language'];
        }
        $stmt->close();
        
        return $languages;
    }

    public function getAudioModels(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT DISTINCT metadata->>'audio_model' as model
            FROM instance_messages 
            WHERE instance_id = :id 
            AND metadata->>'audio_transcription' IS NOT NULL
            AND metadata->>'audio_model' IS NOT NULL
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $models = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $models[] = $row['model'];
        }
        $stmt->close();
        
        return $models;
    }
}