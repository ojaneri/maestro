<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class CalendarController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listEvents($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM calendar_events";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY start_time";

        return $this->db->fetchAll($sql, $params);
    }

    public function getEvent($eventId) {
        $sql = "SELECT * FROM calendar_events WHERE event_id = :id";
        return $this->db->fetchOne($sql, [':id' => $eventId]);
    }

    public function createEvent($data) {
        $eventId = 'event_' . bin2hex(random_bytes(8));
        
        $eventData = [
            'event_id' => $eventId,
            'instance_id' => $data['instance_id'] ?? null,
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'start_time' => $data['start_time'] ?? date('Y-m-d H:i:s'),
            'end_time' => $data['end_time'] ?? date('Y-m-d H:i:s'),
            'location' => $data['location'] ?? '',
            'all_day' => $data['all_day'] ?? 0,
            'status' => $data['status'] ?? 'scheduled',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('calendar_events', $eventData);
        return $this->getEvent($eventId);
    }

    public function updateEvent($eventId, $data) {
        $updateData = [
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'start_time' => $data['start_time'] ?? '',
            'end_time' => $data['end_time'] ?? '',
            'location' => $data['location'] ?? '',
            'all_day' => $data['all_day'] ?? '',
            'status' => $data['status'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('calendar_events', $updateData, 'event_id = :id', [':id' => $eventId]);
        return $this->getEvent($eventId);
    }

    public function deleteEvent($eventId) {
        $this->db->delete('calendar_events', 'event_id = :id', [':id' => $eventId]);
        return true;
    }

    public function getEventsByDateRange($startDate, $endDate, $instanceId = null) {
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];
        
        $where = [
            "(start_time BETWEEN :start_date AND :end_date OR end_time BETWEEN :start_date AND :end_date)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM calendar_events WHERE " . implode(" AND ", $where) . " ORDER BY start_time";
        return $this->db->fetchAll($sql, $params);
    }

    public function getUpcomingEvents($limit = 10, $instanceId = null) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s'),
            ':limit' => $limit
        ];
        
        $where = [
            "start_time >= :current_time"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM calendar_events WHERE " . implode(" AND ", $where) . " ORDER BY start_time LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getEventsForToday($instanceId = null) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getEventsByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId);
    }

    public function getEventsForWeek($instanceId = null) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getEventsByDateRange($startOfWeek, $endOfWeek, $instanceId);
    }

    public function getEventsForMonth($instanceId = null) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getEventsByDateRange($firstDay, $lastDay, $instanceId);
    }

    public function getEventCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM calendar_events";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getEventsByStatus($status, $instanceId = null) {
        $params = [
            ':status' => $status
        ];
        
        $where = [
            "status = :status"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM calendar_events WHERE " . implode(" AND ", $where) . " ORDER BY start_time";
        return $this->db->fetchAll($sql, $params);
    }

    public function getOverdueEvents($instanceId = null) {
        $params = [
            ':current_time' => date('Y-m-d H:i:s')
        ];
        
        $where = [
            "end_time < :current_time",
            "status = 'scheduled'"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM calendar_events WHERE " . implode(" AND ", $where) . " ORDER BY end_time";
        return $this->db->fetchAll($sql, $params);
    }

    public function rescheduleEvent($eventId, $newStartTime, $newEndTime = null) {
        $updateData = [
            'start_time' => $newStartTime,
            'end_time' => $newEndTime ?? $newStartTime,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('calendar_events', $updateData, 'event_id = :id', [':id' => $eventId]);
        return $this->getEvent($eventId);
    }

    public function cancelEvent($eventId) {
        $updateData = [
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('calendar_events', $updateData, 'event_id = :id', [':id' => $eventId]);
        return $this->getEvent($eventId);
    }

    public function completeEvent($eventId) {
        $updateData = [
            'status' => 'completed',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('calendar_events', $updateData, 'event_id = :id', [':id' => $eventId]);
        return $this->getEvent($eventId);
    }

    public function getEventStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress
                FROM calendar_events";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function getEventsByLocation($location, $instanceId = null) {
        $params = [
            ':location' => '%' . $location . '%'
        ];
        
        $where = [
            "location LIKE :location"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM calendar_events WHERE " . implode(" AND ", $where) . " ORDER BY start_time";
        return $this->db->fetchAll($sql, $params);
    }

    public function searchEvents($query, $instanceId = null) {
        $params = [
            ':query' => '%' . $query . '%'
        ];
        
        $where = [
            "(title LIKE :query OR description LIKE :query OR location LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM calendar_events WHERE " . implode(" AND ", $where) . " ORDER BY start_time";
        return $this->db->fetchAll($sql, $params);
    }
}