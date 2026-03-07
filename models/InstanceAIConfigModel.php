<?php

require_once __DIR__ . '/../config/database.php';

class InstanceAIConfigModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAIConfig(string $instanceId): ?array {
        // First try ai_settings table (newer schema)
        $tableExists = $this->db->tableExists('ai_settings');
        
        if ($tableExists) {
            $stmt = $this->db->prepare("SELECT * FROM ai_settings WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $config = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
            
            if ($config) {
                // Map ai_settings fields to standard format
                return [
                    'instance_id' => $config['instance_id'],
                    'enabled' => (bool)$config['openai_enabled'],
                    'model' => $config['openai_model'],
                    'api_key' => $config['openai_api_key'],
                    'system_prompt' => $config['system_prompt'],
                    'assistant_prompt' => $config['assistant_prompt'],
                    'ai_tools' => $config['ai_tools'] ?? null,
                    'created_at' => $config['created_at'],
                    'updated_at' => $config['updated_at']
                ];
            }
        }
        
        // Fallback to instance_ai_config table
        $stmt = $this->db->prepare("SELECT * FROM instance_ai_config WHERE instance_id = :id");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $config = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $config ?: null;
    }

    public function saveAIConfig(string $instanceId, array $data): bool {
        // First check if ai_settings table exists (newer schema)
        $tableExists = $this->db->tableExists('ai_settings');
        
        if ($tableExists) {
            // Use ai_settings table
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO ai_settings (
                    instance_id, openai_enabled, openai_api_key, openai_model,
                    system_prompt, assistant_prompt, ai_tools, updated_at
                ) VALUES (
                    :instance_id, :openai_enabled, :openai_api_key, :openai_model,
                    :system_prompt, :assistant_prompt, :ai_tools, datetime('now')
                )
            ");
            
            $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
            $stmt->bindValue(':openai_enabled', $data['enabled'] ?? false ? '1' : '0', SQLITE3_INTEGER);
            $stmt->bindValue(':openai_api_key', $data['api_key'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':openai_model', $data['model'] ?? 'gpt-3.5-turbo', SQLITE3_TEXT);
            $stmt->bindValue(':system_prompt', $data['system_prompt'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':assistant_prompt', $data['assistant_prompt'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':ai_tools', $data['ai_tools'] ?? null, SQLITE3_TEXT);
        } else {
            // Fallback to instance_ai_config table (legacy)
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO instance_ai_config (
                    instance_id, enabled, model, temperature, max_tokens, 
                    api_key, base_url, ai_tools, created_at, updated_at
                ) VALUES (
                    :instance_id, :enabled, :model, :temperature, :max_tokens,
                    :api_key, :base_url, :ai_tools, datetime('now'), datetime('now')
                )
            ");
            
            $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
            $stmt->bindValue(':enabled', $data['enabled'] ?? false ? 'true' : 'false', SQLITE3_TEXT);
            $stmt->bindValue(':model', $data['model'] ?? 'gpt-3.5-turbo', SQLITE3_TEXT);
            $stmt->bindValue(':temperature', $data['temperature'] ?? 0.7, SQLITE3_FLOAT);
            $stmt->bindValue(':max_tokens', $data['max_tokens'] ?? 2000, SQLITE3_INTEGER);
            $stmt->bindValue(':api_key', $data['api_key'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':base_url', $data['base_url'] ?? 'https://api.openai.com/v1', SQLITE3_TEXT);
            $stmt->bindValue(':ai_tools', $data['ai_tools'] ?? null, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function saveAITools(string $instanceId, string $toolsJson): bool {
        // Try ai_settings table first
        $tableExists = $this->db->tableExists('ai_settings');
        
        if ($tableExists) {
            $stmt = $this->db->prepare("
                UPDATE ai_settings 
                SET ai_tools = :ai_tools, updated_at = datetime('now')
                WHERE instance_id = :instance_id
            ");
            $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
            $stmt->bindValue(':ai_tools', $toolsJson, SQLITE3_TEXT);
            $result = $stmt->execute();
            $stmt->close();
            
            // Check if any row was updated
            return $this->db->changes() > 0;
        }
        
        return false;
    }

    public function getAITools(string $instanceId): ?string {
        // Try ai_settings table first
        $tableExists = $this->db->tableExists('ai_settings');
        
        if ($tableExists) {
            $stmt = $this->db->prepare("SELECT ai_tools FROM ai_settings WHERE instance_id = :id");
            $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
            
            if ($row && !empty($row['ai_tools'])) {
                return $row['ai_tools'];
            }
        }
        
        return null;
    }
}
