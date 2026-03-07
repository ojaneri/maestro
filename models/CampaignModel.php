<?php

require_once __DIR__ . '/../config/database.php';

class CampaignModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function getAllCampaigns(string $instanceId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id 
            ORDER BY created_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = $row;
        }
        $stmt->close();
        return $campaigns;
    }

    public function getCampaignById(string $instanceId, string $campaignId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id AND campaign_id = :campaign_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $campaign = $result->fetchArray(SQLITE3_ASSOC);
        $stmt->close();
        return $campaign ?: null;
    }

    public function createCampaign(string $instanceId, array $data): string {
        $campaignId = $this->generateCampaignId();
        
        $stmt = $this->db->prepare("
            INSERT INTO instance_campaigns (
                campaign_id, instance_id, name, description, target_audience, message_template, 
                status, scheduled_at, sent_count, total_count, metadata, created_at
            ) VALUES (
                :campaign_id, :instance_id, :name, :description, :target_audience, :message_template,
                :status, :scheduled_at, :sent_count, :total_count, :metadata, datetime('now')
            )
        ");
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':name', $data['name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':description', $data['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':target_audience', json_encode($data['target_audience'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':message_template', $data['message_template'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':status', $data['status'] ?? 'draft', SQLITE3_TEXT);
        $stmt->bindValue(':scheduled_at', $data['scheduled_at'] ?? date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':sent_count', $data['sent_count'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':total_count', $data['total_count'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':metadata', json_encode($data['metadata'] ?? []), SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $campaignId;
    }

    public function updateCampaign(string $instanceId, string $campaignId, array $data): bool {
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'description', 'target_audience', 'message_template', 'status', 
                         'scheduled_at', 'sent_count', 'total_count', 'metadata', 'updated_at'];
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
        $sql = "UPDATE instance_campaigns SET " . implode(', ', $fields) . " WHERE instance_id = :id AND campaign_id = :campaign_id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
        foreach ($values as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function deleteCampaign(string $instanceId, string $campaignId): bool {
        $stmt = $this->db->prepare("
            DELETE FROM instance_campaigns 
            WHERE instance_id = :id AND campaign_id = :campaign_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function getCampaignsByStatus(string $instanceId, string $status): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id AND status = :status 
            ORDER BY created_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = $row;
        }
        $stmt->close();
        return $campaigns;
    }

    public function getCampaignsByTargetAudience(string $instanceId, array $targetAudience): array {
        $placeholders = str_repeat('?,', count($targetAudience) - 1) . '?';
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id 
            AND target_audience LIKE '%' || :audience || '%'
            ORDER BY created_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':audience', json_encode($targetAudience), SQLITE3_TEXT);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = $row;
        }
        $stmt->close();
        return $campaigns;
    }

    public function getCampaignsByTemplate(string $instanceId, string $templateId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id AND message_template = :template_id 
            ORDER BY created_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':template_id', $templateId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = $row;
        }
        $stmt->close();
        return $campaigns;
    }

    public function getCampaignsBySchedule(string $instanceId, string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id 
            AND scheduled_at BETWEEN :start_date AND :end_date 
            ORDER BY scheduled_at ASC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':start_date', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':end_date', $endDate, SQLITE3_TEXT);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = $row;
        }
        $stmt->close();
        return $campaigns;
    }

    public function getCampaignsByProgress(string $instanceId, int $minProgress = 0, int $maxProgress = 100): array {
        $stmt = $this->db->prepare("
            SELECT * FROM instance_campaigns 
            WHERE instance_id = :id 
            AND (sent_count * 100.0 / total_count) BETWEEN :min_progress AND :max_progress 
            ORDER BY created_at DESC
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':min_progress', $minProgress, SQLITE3_INTEGER);
        $stmt->bindValue(':max_progress', $maxProgress, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $campaigns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $campaigns[] = $row;
        }
        $stmt->close();
        return $campaigns;
    }

    public function updateCampaignProgress(string $instanceId, string $campaignId, int $sentCount, int $totalCount): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_campaigns 
            SET sent_count = :sent_count, total_count = :total_count, updated_at = datetime('now')
            WHERE instance_id = :id AND campaign_id = :campaign_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
        $stmt->bindValue(':sent_count', $sentCount, SQLITE3_INTEGER);
        $stmt->bindValue(':total_count', $totalCount, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function markCampaignAsCompleted(string $instanceId, string $campaignId): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_campaigns 
            SET status = 'completed', updated_at = datetime('now')
            WHERE instance_id = :id AND campaign_id = :campaign_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    public function markCampaignAsFailed(string $instanceId, string $campaignId): bool {
        $stmt = $this->db->prepare("
            UPDATE instance_campaigns 
            SET status = 'failed', updated_at = datetime('now')
            WHERE instance_id = :id AND campaign_id = :campaign_id
        ");
        $stmt->bindValue(':id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result !== false;
    }

    private function generateCampaignId(): string {
        do {
            $id = 'camp_' . bin2hex(random_bytes(8));
            $stmt = $this->db->prepare("
                SELECT 1 FROM instance_campaigns 
                WHERE campaign_id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_TEXT);
            $result = $stmt->execute();
            $exists = $result->fetchArray(SQLITE3_ASSOC);
            $stmt->close();
        } while ($exists);
        return $id;
    }
}