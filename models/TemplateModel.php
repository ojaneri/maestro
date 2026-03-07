<?php

require_once __DIR__ . '/../config/database.php';

class TemplateModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllTemplates(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_templates 
            WHERE instance_id = :id 
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $templates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $templates[] = $row;
        }
        $stmt->close();
        return $templates;
    }

    public function getTemplateById(string $instanceId, string $templateId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_templates 
            WHERE instance_id = :id AND template_id = :template_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':template_id', $templateId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $template = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $template ?: null;
    }

    public function getTemplateByName(string $instanceId, string $name): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_templates 
            WHERE instance_id = :id AND name = :name
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $result = $stmt->execute();
        $template = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $template ?: null;
    }

    public function createTemplate(string $instanceId, array $data): string {
        $templateId = $this->generateTemplateId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_templates (
                template_id, instance_id, name, content, category, variables, metadata, created_at
            ) VALUES (
                :template_id, :instance_id, :name, :content, :category, :variables, :metadata, datetime('now')
            )
        ");
        $stmt->bindValue(':template_id', $templateId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $data['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':content', $data['content'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':category', $data['category'] ?? 'general', SQLITE3_TEXT);
        $stmt->bindValue(':variables', json_encode($data['variables'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $templateId;
    }

    public function updateTemplate(string $instanceId, string $templateId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'content', 'category', 'variables', 'metadata', 'updated_at'];
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
        $sql = "UPDATE instance_templates SET " . implode(', ', $fields) . " WHERE instance_id = :id AND template_id = :template_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':template_id', $templateId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteTemplate(string $instanceId, string $templateId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_templates 
            WHERE instance_id = :id AND template_id = :template_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':template_id', $templateId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getTemplatesByCategory(string $instanceId, string $category): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_templates 
            WHERE instance_id = :id AND category = :category 
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':category', $category, SQLITE3_TEXT);
        $result = $stmt->execute();
        $templates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $templates[] = $row;
        }
        $stmt->close();
        return $templates;
    }

    public function searchTemplates(string $instanceId, string $query): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_templates 
            WHERE instance_id = :id 
            AND (name LIKE :query OR content LIKE :query OR category LIKE :query)
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':query', "%{$query}%", SQLITE3_TEXT);
        $result = $stmt->execute();
        $templates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $templates[] = $row;
        }
        $stmt->close();
        return $templates;
    }

    public function getTemplatesWithVariables(string $instanceId, array $variables): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_templates 
            WHERE instance_id = :id 
            AND variables LIKE '%' || :variables || '%'
            ORDER BY name ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':variables', json_encode($variables), SQLITE3_TEXT);
        $result = $stmt->execute();
        $templates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $templates[] = $row;
        }
        $stmt->close();
        return $templates;
    }

    public function getTemplateUsageCount(string $instanceId, string $templateId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM instance_campaigns 
            WHERE instance_id = :id AND message_template = :template_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':template_id', $templateId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $row['count'] ?? 0;
    }

    public function getMostUsedTemplates(string $instanceId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT t.*, COUNT(c.campaign_id) as usage_count 
            FROM instance_templates t 
            LEFT JOIN instance_campaigns c ON t.template_id = c.message_template 
            WHERE t.instance_id = :id 
            GROUP BY t.template_id 
            ORDER BY usage_count DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $templates = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $templates[] = $row;
        }
        $stmt->close();
        return $templates;
    }

    private function generateTemplateId(): string {
        do {
            $id = 'tmpl_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_templates 
                WHERE template_id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }
}