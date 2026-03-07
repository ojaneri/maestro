<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class AIController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listAIConfigurations() {
        $sql = "SELECT * FROM ai_configurations ORDER BY name";
        return $this->db->fetchAll($sql);
    }

    public function getAIConfiguration($configId) {
        $sql = "SELECT * FROM ai_configurations WHERE config_id = :id";
        return $this->db->fetchOne($sql, [':id' => $configId]);
    }

    public function createAIConfiguration($data) {
        $configId = 'ai_' . bin2hex(random_bytes(8));
        
        $configData = [
            'config_id' => $configId,
            'name' => $data['name'] ?? '',
            'model' => $data['model'] ?? 'gpt-3.5-turbo',
            'api_key' => $data['api_key'] ?? '',
            'temperature' => $data['temperature'] ?? 0.7,
            'max_tokens' => $data['max_tokens'] ?? 4096,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('ai_configurations', $configData);
        return $this->getAIConfiguration($configId);
    }

    public function updateAIConfiguration($configId, $data) {
        $updateData = [
            'name' => $data['name'] ?? '',
            'model' => $data['model'] ?? '',
            'api_key' => $data['api_key'] ?? '',
            'temperature' => $data['temperature'] ?? '',
            'max_tokens' => $data['max_tokens'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('ai_configurations', $updateData, 'config_id = :id', [':id' => $configId]);
        return $this->getAIConfiguration($configId);
    }

    public function deleteAIConfiguration($configId) {
        $this->db->delete('ai_configurations', 'config_id = :id', [':id' => $configId]);
        return true;
    }

    public function getAIConfigurationStatus($configId) {
        $config = $this->getAIConfiguration($configId);
        if (!$config) {
            return null;
        }

        $status = [
            'connected' => false,
            'last_test' => null,
            'error_message' => null
        ];

        // Test AI connection
        try {
            $testResponse = $this->testAIConnection($config);
            $status['connected'] = $testResponse['success'];
            $status['last_test'] = date('Y-m-d H:i:s');
            $status['error_message'] = $testResponse['error'] ?? null;
        } catch (Exception $e) {
            $status['error_message'] = $e->getMessage();
        }

        return $status;
    }

    public function testAIConnection($config) {
        $model = $config['model'] ?? 'gpt-3.5-turbo';
        $apiKey = $config['api_key'] ?? '';

        if (empty($apiKey)) {
            throw new RuntimeException('API key is required');
        }

        // Test connection with a simple prompt
        $prompt = 'Hello, this is a test message to verify the AI connection.';
        
        try {
            $response = $this->makeAICall($model, $apiKey, $prompt);
            return [
                'success' => true,
                'response' => $response
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function generateResponse($instanceId, $message, $configId = null) {
        $instance = $this->getInstance($instanceId);
        if (!$instance) {
            throw new RuntimeException('Instance not found');
        }

        // Get AI configuration
        $config = $configId ? $this->getAIConfiguration($configId) : $this->getDefaultAIConfiguration();
        if (!$config) {
            throw new RuntimeException('AI configuration not found');
        }

        // Prepare prompt
        $prompt = $this->preparePrompt($message, $instance, $config);
        
        // Generate response
        $response = $this->makeAICall($config['model'], $config['api_key'], $prompt, [
            'temperature' => $config['temperature'],
            'max_tokens' => $config['max_tokens']
        ]);

        return $response;
    }

    private function getInstance($instanceId) {
        $sql = "SELECT * FROM instances WHERE instance_id = :id";
        return $this->db->fetchOne($sql, [':id' => $instanceId]);
    }

    private function getDefaultAIConfiguration() {
        $sql = "SELECT * FROM ai_configurations ORDER BY created_at LIMIT 1";
        return $this->db->fetchOne($sql);
    }

    private function preparePrompt($message, $instance, $config) {
        $prompt = "You are an AI assistant for WhatsApp instance '{$instance['name']}'.\n";
        $prompt .= "Respond to the following message in a helpful and professional manner:\n";
        $prompt .= "\n" . $message . "\n\n";
        $prompt .= "Keep responses concise and relevant to the conversation context.";
        
        return $prompt;
    }

    private function makeAICall($model, $apiKey, $prompt, $options = []) {
        $url = "https://api.openai.com/v1/chat/completions";
        
        $data = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a helpful AI assistant.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        // Add optional parameters
        if (isset($options['temperature'])) {
            $data['temperature'] = $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $data['max_tokens'] = $options['max_tokens'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, API_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("AI API call failed: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("AI API returned HTTP {$httpCode}: {$response}");
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['choices'][0]['message']['content'])) {
            throw new RuntimeException("Invalid AI response: {$response}");
        }

        return $result['choices'][0]['message']['content'];
    }
}