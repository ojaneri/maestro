<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class DashboardController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function getDashboardOverview() {
        $overview = [
            'instances' => $this->getInstanceOverview(),
            'messages' => $this->getMessageOverview(),
            'contacts' => $this->getContactOverview(),
            'campaigns' => $this->getCampaignOverview(),
            'scheduled_messages' => $this->getScheduledMessageOverview(),
            'recent_activity' => $this->getRecentActivity(),
            'system_stats' => $this->getSystemStats()
        ];

        return $overview;
    }

    private function getInstanceOverview() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'stopped' THEN 1 ELSE 0 END) as stopped,
                SUM(CASE WHEN connection_status = 'connected' THEN 1 ELSE 0 END) as connected,
                SUM(CASE WHEN connection_status = 'disconnected' THEN 1 ELSE 0 END) as disconnected
                FROM instances";

        return $this->db->fetchOne($sql);
    }

    private function getMessageOverview() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
                SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages";

        return $this->db->fetchOne($sql);
    }

    private function getContactOverview() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN phone != '' THEN 1 ELSE 0 END) as with_phone,
                SUM(CASE WHEN email != '' THEN 1 ELSE 0 END) as with_email,
                SUM(CASE WHEN address != '' THEN 1 ELSE 0 END) as with_address,
                SUM(CASE WHEN tags != '' THEN 1 ELSE 0 END) as with_tags,
                SUM(CASE WHEN notes != '' THEN 1 ELSE 0 END) as with_notes
                FROM contacts";

        return $this->db->fetchOne($sql);
    }

    private function getCampaignOverview() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM campaigns";

        return $this->db->fetchOne($sql);
    }

    private function getScheduledMessageOverview() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM scheduled_messages";

        return $this->db->fetchOne($sql);
    }

    private function getRecentActivity() {
        $recentMessages = $this->getRecentMessages(10);
        $recentChats = $this->getRecentChats(5);
        $recentCampaigns = $this->getRecentCampaigns(5);

        return [
            'messages' => $recentMessages,
            'chats' => $recentChats,
            'campaigns' => $recentCampaigns
        ];
    }

    private function getRecentMessages($limit = 10) {
        $sql = "SELECT m.*, c.name as contact_name, i.name as instance_name 
                FROM messages m 
                LEFT JOIN contacts c ON m.contact_id = c.contact_id 
                LEFT JOIN instances i ON m.instance_id = i.instance_id 
                ORDER BY m.created_at DESC 
                LIMIT :limit";

        return $this->db->fetchAll($sql, [':limit' => $limit]);
    }

    private function getRecentChats($limit = 5) {
        $sql = "SELECT ch.*, c.name as contact_name, i.name as instance_name 
                FROM chat_history ch 
                LEFT JOIN contacts c ON ch.contact_id = c.contact_id 
                LEFT JOIN instances i ON ch.instance_id = i.instance_id 
                ORDER BY ch.last_message_at DESC 
                LIMIT :limit";

        return $this->db->fetchAll($sql, [':limit' => $limit]);
    }

    private function getRecentCampaigns($limit = 5) {
        $sql = "SELECT c.*, i.name as instance_name 
                FROM campaigns c 
                LEFT JOIN instances i ON c.instance_id = i.instance_id 
                ORDER BY c.created_at DESC 
                LIMIT :limit";

        return $this->db->fetchAll($sql, [':limit' => $limit]);
    }

    private function getSystemStats() {
        $uptime = $this->getSystemUptime();
        $memoryUsage = $this->getMemoryUsage();
        $diskUsage = $this->getDiskUsage();
        $cpuUsage = $this->getCpuUsage();

        return [
            'uptime' => $uptime,
            'memory' => $memoryUsage,
            'disk' => $diskUsage,
            'cpu' => $cpuUsage
        ];
    }

    private function getSystemUptime() {
        try {
            $uptime = exec('cat /proc/uptime | cut -d " " -f1');
            return $this->formatUptime($uptime);
        } catch (Exception $e) {
            return 'Unknown';
        }
    }

    private function getMemoryUsage() {
        try {
            $free = shell_exec('free');
            $free = (string)trim($free);
            $free_arr = explode("\n", $free);
            $mem = explode(" ", $free_arr[1]);
            $mem = array_filter($mem);
            $mem = array_merge($mem);
            $memory_usage = $mem[2]/$mem[1]*100;
            
            return [
                'total' => $this->formatBytes($mem[1] * 1024),
                'used' => $this->formatBytes($mem[2] * 1024),
                'free' => $this->formatBytes($mem[3] * 1024),
                'usage_percent' => round($memory_usage, 2)
            ];
        } catch (Exception $e) {
            return [
                'total' => '0 MB',
                'used' => '0 MB',
                'free' => '0 MB',
                'usage_percent' => 0
            ];
        }
    }

    private function getDiskUsage() {
        try {
            $df = shell_exec('df -h /');
            $df = (string)trim($df);
            $df_arr = explode("\n", $df);
            $disk = explode(" ", $df_arr[1]);
            $disk = array_filter($disk);
            $disk = array_merge($disk);
            
            return [
                'total' => $disk[1],
                'used' => $disk[2],
                'free' => $disk[3],
                'usage_percent' => $disk[4]
            ];
        } catch (Exception $e) {
            return [
                'total' => '0 MB',
                'used' => '0 MB',
                'free' => '0 MB',
                'usage_percent' => '0%'
            ];
        }
    }

    private function getCpuUsage() {
        try {
            $cpu = shell_exec('top -bn1 | grep "Cpu(s)"');
            preg_match("/\d+\.\d+/", $cpu, $usage);
            $cpu_usage = round($usage[0], 2);
            
            return [
                'usage_percent' => $cpu_usage,
                'status' => $cpu_usage < 80 ? 'normal' : ($cpu_usage < 90 ? 'warning' : 'critical')
            ];
        } catch (Exception $e) {
            return [
                'usage_percent' => 0,
                'status' => 'normal'
            ];
        }
    }

    private function formatUptime($seconds) {
        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $uptime = [];
        if ($days > 0) $uptime[] = "{$days}d";
        if ($hours > 0) $uptime[] = "{$hours}h";
        if ($minutes > 0) $uptime[] = "{$minutes}m";
        if ($secs > 0) $uptime[] = "{$secs}s";

        return implode(", ", $uptime);
    }

    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public function getPerformanceMetrics() {
        $metrics = [
            'instance_performance' => $this->getInstancePerformance(),
            'message_performance' => $this->getMessagePerformance(),
            'campaign_performance' => $this->getCampaignPerformance(),
            'system_performance' => $this->getSystemPerformance()
        ];

        return $metrics;
    }

    private function getInstancePerformance() {
        $sql = "SELECT 
                COUNT(*) as total,
                AVG(CASE WHEN status = 'running' THEN 1 ELSE 0 END) * 100 as running_percent,
                AVG(CASE WHEN connection_status = 'connected' THEN 1 ELSE 0 END) * 100 as connected_percent,
                AVG(CASE WHEN last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) * 100 as responsive_percent
                FROM instances";

        return $this->db->fetchOne($sql);
    }

    private function getMessagePerformance() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                AVG(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN TIMESTAMPDIFF(SECOND, created_at, updated_at) END) as avg_response_time
                FROM messages";

        return $this->db->fetchOne($sql);
    }

    private function getCampaignPerformance() {
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                AVG(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN TIMESTAMPDIFF(SECOND, created_at, updated_at) END) as avg_campaign_time
                FROM campaigns";

        return $this->db->fetchOne($sql);
    }

    private function getSystemPerformance() {
        $cpuUsage = $this->getCpuUsage();
        $memoryUsage = $this->getMemoryUsage();
        $diskUsage = $this->getDiskUsage();

        return [
            'cpu' => $cpuUsage['usage_percent'],
            'memory' => $memoryUsage['usage_percent'],
            'disk' => str_replace('%', '', $diskUsage['usage_percent']),
            'status' => $this->getSystemStatus($cpuUsage, $memoryUsage, $diskUsage)
        ];
    }

    private function getSystemStatus($cpu, $memory, $disk) {
        $status = 'healthy';
        
        if ($cpu['usage_percent'] > 85 || $memory['usage_percent'] > 85 || str_replace('%', '', $disk['usage_percent']) > 85) {
            $status = 'warning';
        }
        
        if ($cpu['usage_percent'] > 95 || $memory['usage_percent'] > 95 || str_replace('%', '', $disk['usage_percent']) > 95) {
            $status = 'critical';
        }
        
        return $status;
    }

    public function getNotifications() {
        $notifications = [
            'system_alerts' => $this->getSystemAlerts(),
            'instance_alerts' => $this->getInstanceAlerts(),
            'message_alerts' => $this->getMessageAlerts(),
            'campaign_alerts' => $this->getCampaignAlerts()
        ];

        return $notifications;
    }

    private function getSystemAlerts() {
        $alerts = [];
        
        $systemStatus = $this->getSystemPerformance();
        if ($systemStatus['status'] !== 'healthy') {
            $alerts[] = [
                'type' => 'system',
                'severity' => $systemStatus['status'],
                'message' => "System performance is {$systemStatus['status']}: CPU {$systemStatus['cpu']}%, Memory {$systemStatus['memory']}%, Disk {$systemStatus['disk']}%",
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }

        return $alerts;
    }

    private function getInstanceAlerts() {
        $alerts = [];
        
        $sql = "SELECT * FROM instances 
                WHERE status = 'stopped' OR connection_status = 'disconnected' 
                OR last_heartbeat < DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
        
        $problematicInstances = $this->db->fetchAll($sql);
        
        foreach ($problematicInstances as $instance) {
            $alerts[] = [
                'type' => 'instance',
                'severity' => $instance['status'] === 'stopped' ? 'critical' : 'warning',
                'message' => "Instance {$instance['name']} is {$instance['status']} and {$instance['connection_status']}",
                'timestamp' => $instance['updated_at'],
                'instance_id' => $instance['instance_id']
            ];
        }

        return $alerts;
    }

    private function getMessageAlerts() {
        $alerts = [];
        
        $sql = "SELECT * FROM messages 
                WHERE status = 'failed' 
                ORDER BY created_at DESC 
                LIMIT 10";
        
        $failedMessages = $this->db->fetchAll($sql);
        
        foreach ($failedMessages as $message) {
            $alerts[] = [
                'type' => 'message',
                'severity' => 'warning',
                'message' => "Message to {$message['to']} failed: {$message['error_message']}",
                'timestamp' => $message['created_at'],
                'message_id' => $message['message_id']
            ];
        }

        return $alerts;
    }

    private function getCampaignAlerts() {
        $alerts = [];
        
        $sql = "SELECT * FROM campaigns 
                WHERE status = 'active' 
                AND (end_date IS NOT NULL AND end_date < NOW())";
        
        $expiredCampaigns = $this->db->fetchAll($sql);
        
        foreach ($expiredCampaigns as $campaign) {
            $alerts[] = [
                'type' => 'campaign',
                'severity' => 'warning',
                'message' => "Campaign {$campaign['name']} has expired but is still active",
                'timestamp' => $campaign['updated_at'],
                'campaign_id' => $campaign['campaign_id']
            ];
        }

        return $alerts;
    }

    public function getTrends() {
        $trends = [
            'message_trends' => $this->getMessageTrends(),
            'contact_trends' => $this->getContactTrends(),
            'campaign_trends' => $this->getCampaignTrends(),
            'instance_trends' => $this->getInstanceTrends()
        ];

        return $trends;
    }

    private function getMessageTrends() {
        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as message_count,
                SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming,
                SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC";

        return $this->db->fetchAll($sql);
    }

    private function getContactTrends() {
        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as contact_count,
                SUM(CASE WHEN phone != '' THEN 1 ELSE 0 END) as with_phone,
                SUM(CASE WHEN email != '' THEN 1 ELSE 0 END) as with_email
                FROM contacts 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC";

        return $this->db->fetchAll($sql);
    }

    private function getCampaignTrends() {
        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as campaign_count,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                FROM campaigns 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC";

        return $this->db->fetchAll($sql);
    }

    private function getInstanceTrends() {
        $sql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as instance_count,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN connection_status = 'connected' THEN 1 ELSE 0 END) as connected
                FROM instances 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) 
                GROUP BY DATE(created_at) 
                ORDER BY date DESC";

        return $this->db->fetchAll($sql);
    }

    public function getReports() {
        $reports = [
            'daily_report' => $this->getDailyReport(),
            'weekly_report' => $this->getWeeklyReport(),
            'monthly_report' => $this->getMonthlyReport()
        ];

        return $reports;
    }

    private function getDailyReport() {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $todayStats = $this->getStatsForDate($today);
        $yesterdayStats = $this->getStatsForDate($yesterday);

        return [
            'date' => $today,
            'today_stats' => $todayStats,
            'yesterday_stats' => $yesterdayStats,
            'comparison' => $this->calculateComparison($todayStats, $yesterdayStats)
        ];
    }

    private function getWeeklyReport() {
        $startOfWeek = date('Y-m-d', strtotime('monday this week'));
        $endOfWeek = date('Y-m-d', strtotime('sunday this week'));

        $weeklyStats = $this->getStatsForDateRange($startOfWeek, $endOfWeek);
        
        return [
            'week' => "{$startOfWeek} to {$endOfWeek}",
            'stats' => $weeklyStats
        ];
    }

    private function getMonthlyReport() {
        $firstDay = date('Y-m-01');
        $lastDay = date('Y-m-t');

        $monthlyStats = $this->getStatsForDateRange($firstDay, $lastDay);
        
        return [
            'month' => date('F Y'),
            'stats' => $monthlyStats
        ];
    }

    private function getStatsForDate($date) {
        $startDate = $date . ' 00:00:00';
        $endDate = $date . ' 23:59:59';

        return $this->getStatsForDateRange($startDate, $endDate);
    }

    private function getStatsForDateRange($startDate, $endDate) {
        $sql = "SELECT 
                COUNT(DISTINCT i.instance_id) as instance_count,
                COUNT(DISTINCT c.contact_id) as contact_count,
                COUNT(DISTINCT cam.campaign_id) as campaign_count,
                COUNT(m.message_id) as message_count,
                SUM(CASE WHEN m.direction = 'incoming' THEN 1 ELSE 0 END) as incoming_messages,
                SUM(CASE WHEN m.direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing_messages,
                SUM(CASE WHEN m.status = 'read' THEN 1 ELSE 0 END) as read_messages,
                SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) as failed_messages
                FROM instances i
                LEFT JOIN contacts c ON 1=1
                LEFT JOIN campaigns cam ON 1=1
                LEFT JOIN messages m ON m.created_at BETWEEN :start_date AND :end_date
                WHERE i.created_at BETWEEN :start_date AND :end_date";

        return $this->db->fetchOne($sql, [
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
    }

    private function calculateComparison($today, $yesterday) {
        $comparison = [];
        
        foreach ($today as $key => $value) {
            if (isset($yesterday[$key]) && is_numeric($value) && is_numeric($yesterday[$key])) {
                $change = $value - $yesterday[$key];
                $percentage = $yesterday[$key] > 0 ? ($change / $yesterday[$key]) * 100 : 0;
                
                $comparison[$key] = [
                    'change' => $change,
                    'percentage' => round($percentage, 2),
                    'direction' => $change > 0 ? 'increase' : ($change < 0 ? 'decrease' : 'same')
                ];
            }
        }

        return $comparison;
    }
}