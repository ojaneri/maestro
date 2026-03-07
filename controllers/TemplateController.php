<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

class TemplateController {
    private $db;
    private $auth;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->auth = Auth::getInstance();
    }

    public function listTemplates($instanceId = null, $limit = 100, $offset = 0) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY name ASC LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplate($templateId) {
        $sql = "SELECT * FROM templates WHERE template_id = :id";
        return $this->db->fetchOne($sql, [':id' => $templateId]);
    }

    public function createTemplate($data) {
        $templateId = 'template_' . bin2hex(random_bytes(8));
        
        $templateData = [
            'template_id' => $templateId,
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'content' => $data['content'] ?? '',
            'type' => $data['type'] ?? 'text',
            'category' => $data['category'] ?? 'general',
            'variables' => $data['variables'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->insert('templates', $templateData);
        return $this->getTemplate($templateId);
    }

    public function updateTemplate($templateId, $data) {
        $updateData = [
            'name' => $data['name'] ?? '',
            'description' => $data['description'] ?? '',
            'content' => $data['content'] ?? '',
            'type' => $data['type'] ?? '',
            'category' => $data['category'] ?? '',
            'variables' => $data['variables'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->update('templates', $updateData, 'template_id = :id', [':id' => $templateId]);
        return $this->getTemplate($templateId);
    }

    public function deleteTemplate($templateId) {
        $this->db->delete('templates', 'template_id = :id', [':id' => $templateId]);
        return true;
    }

    public function getTemplatesByInstance($instanceId, $limit = 50) {
        $params = [
            ':instance_id' => $instanceId,
            ':limit' => $limit
        ];

        $sql = "SELECT * FROM templates WHERE instance_id = :instance_id ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesByType($type, $instanceId = null, $limit = 50) {
        $params = [
            ':type' => $type,
            ':limit' => $limit
        ];
        
        $where = [
            "type = :type"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesByCategory($category, $instanceId = null, $limit = 50) {
        $params = [
            ':category' => $category,
            ':limit' => $limit
        ];
        
        $where = [
            "category = :category"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesWithVariables($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "variables != ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesWithoutVariables($instanceId = null, $limit = 50) {
        $params = [];
        $where = [
            "variables = ''"
        ];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplateCount($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(*) as count FROM templates";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $result = $this->db->fetchOne($sql, $params);
        return $result['count'] ?? 0;
    }

    public function getTemplateStatistics($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN type = 'text' THEN 1 ELSE 0 END) as text_templates,
                SUM(CASE WHEN type = 'image' THEN 1 ELSE 0 END) as image_templates,
                SUM(CASE WHEN type = 'document' THEN 1 ELSE 0 END) as document_templates,
                SUM(CASE WHEN variables != '' THEN 1 ELSE 0 END) as with_variables,
                COUNT(DISTINCT category) as category_count
                FROM templates";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        return $this->db->fetchOne($sql, $params);
    }

    public function searchTemplates($query, $instanceId = null, $limit = 50) {
        $params = [
            ':query' => '%' . $query . '%',
            ':limit' => $limit
        ];
        
        $where = [
            "(name LIKE :query OR description LIKE :query OR content LIKE :query OR 
              variables LIKE :query)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesByDateRange($startDate, $endDate, $instanceId = null, $limit = 100) {
        $params = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':limit' => $limit
        ];
        
        $where = [
            "(created_at BETWEEN :start_date AND :end_date)"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY created_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesForToday($instanceId = null, $limit = 50) {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        return $this->getTemplatesByDateRange($today . ' 00:00:00', $tomorrow . ' 00:00:00', $instanceId, $limit);
    }

    public function getTemplatesForWeek($instanceId = null, $limit = 100) {
        $startOfWeek = date('Y-m-d', strtotime('monday this week')) . ' 00:00:00';
        $endOfWeek = date('Y-m-d', strtotime('sunday this week')) . ' 23:59:59';
        
        return $this->getTemplatesByDateRange($startOfWeek, $endOfWeek, $instanceId, $limit);
    }

    public function getTemplatesForMonth($instanceId = null, $limit = 200) {
        $firstDay = date('Y-m-01') . ' 00:00:00';
        $lastDay = date('Y-m-t') . ' 23:59:59';
        
        return $this->getTemplatesByDateRange($firstDay, $lastDay, $instanceId, $limit);
    }

    public function getTemplatesWithRecentActivity($instanceId = null, $days = 7, $limit = 50) {
        $params = [
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            ':limit' => $limit
        ];
        
        $where = [
            "updated_at >= :days_ago"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY updated_at DESC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesWithoutRecentActivity($instanceId = null, $days = 30, $limit = 50) {
        $params = [
            ':days_ago' => date('Y-m-d H:i:s', strtotime('-' . $days . ' days')),
            ':limit' => $limit
        ];
        
        $where = [
            "updated_at < :days_ago"
        ];

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY updated_at ASC LIMIT :limit";
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesByMultipleCriteria($criteria, $instanceId = null, $limit = 50) {
        $params = [];
        $where = [];
        
        if (isset($criteria['name'])) {
            $where[] = "name LIKE :name";
            $params[':name'] = '%' . $criteria['name'] . '%';
        }
        
        if (isset($criteria['description'])) {
            $where[] = "description LIKE :description";
            $params[':description'] = '%' . $criteria['description'] . '%';
        }
        
        if (isset($criteria['type'])) {
            $where[] = "type = :type";
            $params[':type'] = $criteria['type'];
        }
        
        if (isset($criteria['category'])) {
            $where[] = "category = :category";
            $params[':category'] = $criteria['category'];
        }
        
        if (isset($criteria['variables'])) {
            if ($criteria['variables']) {
                $where[] = "variables != ''";
            } else {
                $where[] = "variables = ''";
            }
        }

        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT * FROM templates WHERE " . implode(" AND ", $where) . " ORDER BY name ASC LIMIT :limit";
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function renderTemplate($templateId, $variables = []) {
        $template = $this->getTemplate($templateId);
        if (!$template) {
            throw new RuntimeException('Template not found');
        }

        $content = $template['content'];
        
        // Replace variables in template
        if ($template['variables'] && !empty($variables)) {
            $templateVariables = json_decode($template['variables'], true) ?? [];
            
            foreach ($templateVariables as $var) {
                $varName = $var['name'] ?? $var;
                $varValue = $variables[$varName] ?? '';
                $content = str_replace('{{' . $varName . '}}', $varValue, $content);
            }
        }

        return $content;
    }

    public function getTemplateVariables($templateId) {
        $template = $this->getTemplate($templateId);
        if (!$template || !$template['variables']) {
            return [];
        }

        return json_decode($template['variables'], true) ?? [];
    }

    public function validateTemplateVariables($templateId, $variables) {
        $templateVariables = $this->getTemplateVariables($templateId);
        
        $errors = [];
        
        foreach ($templateVariables as $var) {
            $varName = $var['name'] ?? $var;
            $required = $var['required'] ?? true;
            $type = $var['type'] ?? 'string';
            
            if ($required && !isset($variables[$varName])) {
                $errors[] = "Variable '{$varName}' is required";
            }
            
            if (isset($variables[$varName])) {
                $value = $variables[$varName];
                
                switch ($type) {
                    case 'number':
                        if (!is_numeric($value)) {
                            $errors[] = "Variable '{$varName}' must be a number";
                        }
                        break;
                    case 'boolean':
                        if (!is_bool($value) && !in_array(strtolower($value), ['true', 'false'])) {
                            $errors[] = "Variable '{$varName}' must be a boolean";
                        }
                        break;
                    case 'date':
                        if (!strtotime($value)) {
                            $errors[] = "Variable '{$varName}' must be a valid date";
                        }
                        break;
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function previewTemplate($templateId, $variables = []) {
        $validation = $this->validateTemplateVariables($templateId, $variables);
        
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
                'preview' => null
            ];
        }

        try {
            $preview = $this->renderTemplate($templateId, $variables);
            return [
                'success' => true,
                'preview' => $preview,
                'variables' => $variables
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()],
                'preview' => null
            ];
        }
    }

    public function getTemplateUsageStatistics($templateId) {
        $params = [
            ':template_id' => $templateId
        ];

        $sql = "SELECT 
                COUNT(*) as usage_count,
                MIN(created_at) as first_used,
                MAX(created_at) as last_used,
                SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing_count,
                SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming_count
                FROM messages 
                WHERE template_id = :template_id";
        
        return $this->db->fetchOne($sql, $params);
    }

    public function getTemplatePerformance($templateId) {
        $params = [
            ':template_id' => $templateId
        ];

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM messages 
                WHERE template_id = :template_id";
        
        return $this->db->fetchOne($sql, $params);
    }

    public function getTemplateWithUsage($templateId) {
        $template = $this->getTemplate($templateId);
        if (!$template) {
            return null;
        }

        $template['usage_statistics'] = $this->getTemplateUsageStatistics($templateId);
        $template['performance'] = $this->getTemplatePerformance($templateId);
        
        return $template;
    }

    public function getPopularTemplates($instanceId = null, $limit = 10) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT t.*, COUNT(m.message_id) as usage_count 
                FROM templates t 
                LEFT JOIN messages m ON t.template_id = m.template_id 
                WHERE " . implode(" AND ", $where) . " 
                GROUP BY t.template_id 
                ORDER BY usage_count DESC 
                LIMIT :limit";
        
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getTrendingTemplates($instanceId = null, $days = 7, $limit = 10) {
        $params = [];
        $where = [
            "m.created_at >= :days_ago"
        ];
        
        if ($instanceId) {
            $where[] = "t.instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT t.*, COUNT(m.message_id) as usage_count 
                FROM templates t 
                JOIN messages m ON t.template_id = m.template_id 
                WHERE " . implode(" AND ", $where) . " 
                GROUP BY t.template_id 
                ORDER BY usage_count DESC 
                LIMIT :limit";
        
        $params[':days_ago'] = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $params[':limit'] = $limit;
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplateCategories($instanceId = null) {
        $params = [];
        $where = [];
        
        if ($instanceId) {
            $where[] = "instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT category, COUNT(*) as template_count 
                FROM templates 
                WHERE " . implode(" AND ", $where) . " 
                GROUP BY category 
                ORDER BY template_count DESC";
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getTemplatesByCategoryStats($instanceId = null) {
        $categories = $this->getTemplateCategories($instanceId);
        
        $stats = [];
        foreach ($categories as $category) {
            $stats[$category['category']] = [
                'category' => $category['category'],
                'template_count' => $category['template_count'],
                'usage_count' => 0,
                'performance' => [
                    'sent' => 0,
                    'delivered' => 0,
                    'read' => 0,
                    'failed' => 0
                ]
            ];
        }

        // Get usage statistics for each category
        foreach ($stats as $categoryName => &$categoryStats) {
            $categoryStats['usage_count'] = $this->getCategoryUsageCount($categoryName, $instanceId);
            $categoryStats['performance'] = $this->getCategoryPerformance($categoryName, $instanceId);
        }

        return array_values($stats);
    }

    private function getCategoryUsageCount($category, $instanceId = null) {
        $params = [
            ':category' => $category
        ];
        
        $where = [
            "t.category = :category"
        ];

        if ($instanceId) {
            $where[] = "t.instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT COUNT(m.message_id) as usage_count 
                FROM templates t 
                JOIN messages m ON t.template_id = m.template_id 
                WHERE " . implode(" AND ", $where);
        
        $result = $this->db->fetchOne($sql, $params);
        return $result['usage_count'] ?? 0;
    }

    private function getCategoryPerformance($category, $instanceId = null) {
        $params = [
            ':category' => $category
        ];
        
        $where = [
            "t.category = :category"
        ];

        if ($instanceId) {
            $where[] = "t.instance_id = :instance_id";
            $params[':instance_id'] = $instanceId;
        }

        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN m.status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN m.status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN m.status = 'read' THEN 1 ELSE 0 END) as read,
                SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM templates t 
                JOIN messages m ON t.template_id = m.template_id 
                WHERE " . implode(" AND ", $where);
        
        return $this->db->fetchOne($sql, $params);
    }
}