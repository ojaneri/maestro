<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class InstanceController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listInstances() {
        $sql = "SELECT * FROM instances ORDER BY name";
        return $this->db->fetchAll($sql);
    }

    public function getInstance($instanceId) {
        $sql = "SELECT * FROM instances WHERE instance_id = :id";
        return $this->db->fetchOne($sql, [':id' => $instanceId]);
    }

    public function createInstance($name) {
        $nextPort = DEFAULT_WHATSAPP_PORT + count($this->listInstances()) + 1;
        $instanceId = 'inst_' . bin2hex(random_bytes(8));
        $apiKey = bin2hex(random_bytes(16));

        $data = [
            'instance_id' => $instanceId,
            'name' => $name,
            'port' => $nextPort,
            'api_key' => $apiKey,
            'status' => 'stopped',
            'connection_status' => 'disconnected',
            'base_url' => "http://127.0.0.1:{$nextPort}",
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('instances', $data);
        
        // Execute create instance script
        exec("bash create_instance.sh {$instanceId} {$nextPort} >/dev/null 2>&1 &");
        
        return $this->getInstance($instanceId);
    }

    public function updateInstance($instanceId, $data) {
        $updateData = [
            'name' => $data['name'] ?? '',
            'base_url' => $data['base_url'] ?? '',
            'port' => $data['port'] ?? '',
            'integration_type' => $data['integration_type'] ?? 'baileys',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('instances', $updateData, 'instance_id = :id', [':id' => $instanceId]);
        return $this->getInstance($instanceId);
    }

    public function deleteInstance($instanceId) {
        // Start transaction
        $this->db->beginTransaction();
        
        try {
            // Delete instance record
            $this->db->delete('instances', 'instance_id = :id', [':id' => $instanceId]);
            
            // Delete related settings
            $this->db->delete('instance_settings', 'instance_id = :id', [':id' => $instanceId]);
            
            // Delete chat history
            $this->db->delete('messages', 'instance_id = :id', [':id' => $instanceId]);
            $this->db->delete('chat_history', 'instance_id = :id', [':id' => $instanceId]);
            
            // Delete scheduled messages
            $this->db->delete('scheduled_messages', 'instance_id = :id', [':id' => $instanceId]);
            
            // Delete contact metadata
            $this->db->delete('contact_metadata', 'instance_id = :id', [':id' => $instanceId]);
            
            // Delete authentication directory
            $authDir = __DIR__ . '/../auth_' . $instanceId;
            if (is_dir($authDir)) {
                $this->deleteDirectory($authDir);
            }
            
            // Stop Node.js instance
            $instance = $this->getInstance($instanceId);
            if ($instance && $instance['port']) {
                $this->stopInstance($instance['port']);
            }
            
            // Commit transaction
            $this->db->commit();
            return true;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->db->rollback();
            throw $e;
        }
    }

    public function getInstanceStatus($instanceId) {
        $instance = $this->getInstance($instanceId);
        if (!$instance) {
            return null;
        }

        $status = [
            'server' => 'Stopped',
            'connection' => 'disconnected'
        ];

        // Check if server is running
        if ($instance['port'] && $this->isPortOpen('localhost', $instance['port'])) {
            $status['server'] = 'Running';
            
            // Check WhatsApp connection
            $healthStatus = $this->checkWhatsAppConnection($instance['port']);
            if ($healthStatus === 'connected') {
                $status['connection'] = 'connected';
            }
        }

        return $status;
    }

    public function getInstancesWithStatus() {
        $instances = $this->listInstances();
        $result = [];

        foreach ($instances as $instance) {
            $status = $this->getInstanceStatus($instance['instance_id']);
            $instance['status'] = $status['server'] ?? 'Stopped';
            $instance['connection_status'] = $status['connection'] ?? 'disconnected';
            $result[$instance['instance_id']] = $instance;
        }

        return $result;
    }

    public function sendMessage($instanceId, $phone, $message) {
        $instance = $this->getInstance($instanceId);
        if (!$instance || !$instance['port']) {
            throw new RuntimeException('Instance not found or port not configured');
        }

        $url = "http://127.0.0.1:{$instance['port']}/send-message";
        $data = [
            'to' => $phone,
            'message' => $message
        ];

        return $this->makeApiCall($url, $data);
    }

    public function getQRCode($instanceId) {
        $instance = $this->getInstance($instanceId);
        if (!$instance || !$instance['port']) {
            throw new RuntimeException('Instance not found or port not configured');
        }

        $url = "http://127.0.0.1:{$instance['port']}/qr";
        return $this->makeApiCall($url);
    }

    public function disconnectWhatsApp($instanceId) {
        $instance = $this->getInstance($instanceId);
        if (!$instance || !$instance['port']) {
            throw new RuntimeException('Instance not found or port not configured');
        }

        $url = "http://127.0.0.1:{$instance['port']}/disconnect";
        return $this->makeApiCall($url, [], 'POST');
    }

    private function isPortOpen($host, $port) {
        $fp = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private function checkWhatsAppConnection($port) {
        $url = "http://127.0.0.1:{$port}/health";
        try {
            $response = $this->makeApiCall($url);
            return $response['whatsappConnected'] ?? 'disconnected';
        } catch (Exception $e) {
            return 'disconnected';
        }
    }

    private function stopInstance($port) {
        // Implement instance stopping logic
        // This could involve sending a stop signal to the Node.js process
        // or using PM2 to stop the instance
        return true;
    }

    private function makeApiCall($url, $data = [], $method = 'GET') {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("API call failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("API returned HTTP {$httpCode}: {$response}");
        }

        $result = json_decode($response, true);
        if (!$result) {
            throw new RuntimeException("Invalid JSON response: {$response}");
        }

        return $result;
    }

    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}