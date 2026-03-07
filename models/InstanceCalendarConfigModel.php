<?php

require_once __DIR__ . '/../config/database.php';

class InstanceCalendarConfigModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getCalendarConfig(string $instanceId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM instance_calendar_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function saveCalendarConfig(string $instanceId, array $data): bool {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO instance_calendar_config (
                instance_id, enabled, calendar_id, access_token, refresh_token, 
                token_expiry, timezone, event_prefix, created_at, updated_at
            ) VALUES (
                :instance_id, :enabled, :calendar_id, :access_token, :refresh_token,
                :token_expiry, :timezone, :event_prefix, datetime('now'), datetime('now')
            )
        ");
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':enabled', $data['enabled'] ?? false ? 'true' : 'false', SQLITE3_TEXT);
        $stmt->bindValue(':calendar_id', $data['calendar_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':access_token', $data['access_token'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':refresh_token', $data['refresh_token'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':token_expiry', $data['token_expiry'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':timezone', $data['timezone'] ?? 'America/Sao_Paulo', SQLITE3_TEXT);
        $stmt->bindValue(':event_prefix', $data['event_prefix'] ?? 'WhatsApp:', SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getCalendarEvents(string $instanceId, string $startDate, string $endDate): array {
        $events = [];
        
        // Get events from database
        $stmt = $this->db->prepare("
            SELECT * FROM instance_calendar_events 
            WHERE instance_id = :id 
            AND start_time BETWEEN :start_date AND :end_date 
            ORDER BY start_time ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end_date', $endDate, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $events[] = $row;
        }
        $stmt->close();

        return $events;
    }

    public function createCalendarEvent(string $instanceId, array $data): string {
        $eventId = $this->generateEventId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_calendar_events (
                event_id, instance_id, summary, description, start_time, end_time, 
                timezone, attendees, reminders, metadata, created_at
            ) VALUES (
                :event_id, :instance_id, :summary, :description, :start_time, :end_time,
                :timezone, :attendees, :reminders, :metadata, datetime('now')
            )
        ");
        
        $stmt->bindValue(':event_id', $eventId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':summary', $data['summary'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':description', $data['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':start_time', $data['start_time'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':end_time', $data['end_time'] ?? date('Y-m-d H:i:s', strtotime('+1 hour')), SQLITE3_TEXT);
        $stmt->bindValue(':timezone', $data['timezone'] ?? 'America/Sao_Paulo', SQLITE3_TEXT);
        $stmt->bindValue(':attendees', json_encode($data['attendees'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':reminders', json_encode($data['reminders'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $eventId;
    }

    public function updateCalendarEvent(string $instanceId, string $eventId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['summary', 'description', 'start_time', 'end_time', 'timezone', 
                         'attendees', 'reminders', 'metadata', 'updated_at'];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $values[":{$field}"] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = 'updated_at = datetime("now")';
        $sql = "UPDATE instance_calendar_events SET " . implode(', ', $fields) . " WHERE instance_id = :id AND event_id = :event_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':event_id', $eventId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteCalendarEvent(string $instanceId, string $eventId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_calendar_events 
            WHERE instance_id = :id AND event_id = :event_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':event_id', $eventId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getCalendarEventById(string $instanceId, string $eventId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_calendar_events 
            WHERE instance_id = :id AND event_id = :event_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':event_id', $eventId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $event = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $event ?: null;
    }

    public function getCalendarEventsByStatus(string $instanceId, string $status): array {
        $events = [];
        
        $stmt = $this->db->prepare("
            SELECT * FROM instance_calendar_events 
            WHERE instance_id = :id AND status = :status 
            ORDER BY start_time ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $events[] = $row;
        }
        $stmt->close();

        return $events;
    }

    public function getCalendarEventsForNotification(string $instanceId, int $minutesAhead = 15): array {
        $events = [];
        
        $stmt = $this->db->prepare("
            SELECT * FROM instance_calendar_events 
            WHERE instance_id = :id 
            AND status = 'confirmed'
            AND start_time BETWEEN datetime('now') AND datetime('now', :minutes || ' minutes')
            ORDER BY start_time ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':minutes', '+' . $minutesAhead, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $events[] = $row;
        }
        $stmt->close();

        return $events;
    }

    public function getCalendarStats(string $instanceId, int $days = 30): array {
        $stats = [
            'total_events' => 0,
            'upcoming_events' => 0,
            'past_events' => 0,
            'events_by_status' => [],
            'events_by_type' => []
        ];

        // Get total events
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM instance_calendar_events WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['total_events'] = $row['count'] ?? 0;
        $stmt->close();

        // Get upcoming events
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM instance_calendar_events 
            WHERE instance_id = :id AND start_time >= datetime('now')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['upcoming_events'] = $row['count'] ?? 0;
        $stmt->close();

        // Get past events
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM instance_calendar_events 
            WHERE instance_id = :id AND start_time < datetime('now')
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stats['past_events'] = $row['count'] ?? 0;
        $stmt->close();

        // Get events by status
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as count 
            FROM instance_calendar_events 
            WHERE instance_id = :id 
            GROUP BY status
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['events_by_status'][$row['status']] = $row['count'];
        }
        $stmt->close();

        // Get events by type
        $stmt = $this->db->prepare("
            SELECT 
                CASE 
                    WHEN summary LIKE '%WhatsApp:%' THEN 'WhatsApp'
                    WHEN summary LIKE '%Meeting%' THEN 'Meeting'
                    WHEN summary LIKE '%Call%' THEN 'Call'
                    ELSE 'Other'
                END as event_type,
                COUNT(*) as count
            FROM instance_calendar_events 
            WHERE instance_id = :id 
            GROUP BY event_type
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats['events_by_type'][$row['event_type']] = $row['count'];
        }
        $stmt->close();

        return $stats;
    }

    public function getCalendarUsage(string $instanceId, int $days = 30): array {
        $usage = [];
        
        // Get events by day of week
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%w', start_time) as day_of_week,
                COUNT(*) as event_count
            FROM instance_calendar_events 
            WHERE instance_id = :id 
            AND start_time >= datetime('now', :days || ' days')
            GROUP BY strftime('%w', start_time)
            ORDER BY day_of_week ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['events_by_day'][$row['day_of_week']] = $row['event_count'];
        }
        $stmt->close();

        // Get events by hour of day
        $stmt = $this->db->prepare("
            SELECT 
                strftime('%H', start_time) as hour_of_day,
                COUNT(*) as event_count
            FROM instance_calendar_events 
            WHERE instance_id = :id 
            AND start_time >= datetime('now', :days || ' days')
            GROUP BY strftime('%H', start_time)
            ORDER BY hour_of_day ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':days', '-' . $days, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $usage['events_by_hour'][$row['hour_of_day']] = $row['event_count'];
        }
        $stmt->close();

        return $usage;
    }

    private function generateEventId(): string {
        do {
            $id = 'event_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_calendar_events 
                WHERE event_id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }
}