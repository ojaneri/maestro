<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/instance_data.php';

date_default_timezone_set('America/Fortaleza');

define('CAMPAIGN_DB_PATH', __DIR__ . '/campaigns.db');

if (!isset($_SESSION)) {
    session_start();
}

if (!isset($_SESSION['auth'])) {
    header('Location: /api/envio/wpp/');
    exit;
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
try {
    $dotenv->load();
} catch (Exception $e) {
    // Continue even if env is missing
}

function openCampaignDatabase(bool $readonly = false): ?SQLite3
{
    $flags = $readonly ? SQLITE3_OPEN_READONLY : (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    try {
        return new SQLite3(CAMPAIGN_DB_PATH, $flags);
    } catch (Exception $e) {
        return null;
    }
}

function ensureCampaignStorage(): void
{
    $db = openCampaignDatabase(false);
    if (!$db) {
        return;
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS campaigns (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            scheduled_at INTEGER NOT NULL,
            estimated_end_at INTEGER NOT NULL,
            message_template TEXT NOT NULL,
            delay_seconds INTEGER NOT NULL,
            contact_count INTEGER NOT NULL,
            instance_id TEXT,
            columns_mapping TEXT,
            placeholders TEXT,
            contacts_json TEXT,
            status TEXT DEFAULT 'scheduled'
        )
    ");
    ensureCampaignColumn($db, 'campaigns', 'message_parts', 'TEXT');
    $db->close();
}

function ensureCampaignColumn(SQLite3 $db, string $table, string $column, string $definition): void
{
    $result = $db->query("PRAGMA table_info('$table')");
    $existingColumns = [];
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $existingColumns[] = $row['name'];
        }
        $result->finalize();
    }
    if (!in_array($column, $existingColumns, true)) {
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

function splitMessageParts(string $message): array
{
    $segments = array_map('trim', explode('#', $message));
    $segments = array_filter($segments, static fn($part) => $part !== '');
    if (empty($segments)) {
        return [$message];
    }
    return array_values($segments);
}

function determineSegmentsForContact(array $contact, array $variationSegments, array $defaultSegments, int $contactIndex): array
{
    if ($variationSegments) {
        $count = count($variationSegments);
        if ($count > 0) {
            $variationIndex = isset($contact['variation_index']) ? (int)$contact['variation_index'] : $contactIndex;
            if ($variationIndex < 0) {
                $variationIndex = 0;
            }
            $selected = $variationSegments[$variationIndex % $count] ?? $variationSegments[0];
            if (!empty($selected)) {
                return $selected;
            }
        }
    }
    return $defaultSegments;
}

function buildVariationSegmentsFromTexts(array $variationTexts): array
{
    $variationSegments = [];
    foreach ($variationTexts as $text) {
        $trimmed = trim((string)$text);
        if ($trimmed === '') {
            continue;
        }
        $parts = splitMessageParts($trimmed);
        if (empty($parts)) {
            continue;
        }
        $variationSegments[] = $parts;
    }
    return $variationSegments;
}

define('CAMPAIGN_SEGMENT_DELAY_SECONDS', 2);

function openChatDataDatabase(bool $readonly = false): ?SQLite3
{
    $dbPath = __DIR__ . '/chat_data.db';
    if (!file_exists($dbPath)) {
        return null;
    }
    $flags = $readonly ? SQLITE3_OPEN_READONLY : SQLITE3_OPEN_READWRITE;
    try {
        return new SQLite3($dbPath, $flags);
    } catch (Exception $err) {
        return null;
    }
}

function tableHasColumn(SQLite3 $db, string $table, string $column): bool
{
    if (!$db) {
        return false;
    }
    $result = $db->query("PRAGMA table_info('{$table}')");
    if (!$result) {
        return false;
    }
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (($row['name'] ?? '') === $column) {
            $result->finalize();
            return true;
        }
    }
    $result->finalize();
    return false;
}

function ensureCampaignIdColumn(SQLite3 $db): void
{
    if (!$db || tableHasColumn($db, 'scheduled_messages', 'campaign_id')) {
        return;
    }
    $db->exec("ALTER TABLE scheduled_messages ADD COLUMN campaign_id TEXT");
}

function applyContactPlaceholders(string $message, array $contactData): string
{
    if (empty($contactData)) {
        return $message;
    }

    $search = [];
    $replace = [];
    foreach ($contactData as $column => $value) {
        $trimmed = trim((string)$column);
        if ($trimmed === '') {
            continue;
        }
        $search[] = "%{$trimmed}%";
        $replace[] = is_scalar($value) ? (string)$value : '';
    }
    if (empty($search)) {
        return $message;
    }
    return str_ireplace($search, $replace, $message);
}

function normalizeRemoteJid(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }
    if (strpos($trimmed, '@') !== false) {
        return $trimmed;
    }
    $digits = preg_replace('/\D/', '', $trimmed);
    if (!$digits) {
        return null;
    }
    if (!str_starts_with($digits, '55')) {
        $digits = '55' . $digits;
    }
    return "{$digits}@s.whatsapp.net";
}

function deleteCampaignById(string $campaignId): void
{
    $db = openCampaignDatabase(false);
    if (!$db) {
        return;
    }
    $stmt = $db->prepare("DELETE FROM campaigns WHERE id = :id");
    if ($stmt) {
        $stmt->bindValue(':id', $campaignId, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }
    $db->close();
}

function fetchCampaignById(string $campaignId): ?array
{
    $db = openCampaignDatabase(true);
    if (!$db) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM campaigns WHERE id = :id LIMIT 1");
    if (!$stmt) {
        $db->close();
        return null;
    }
    $stmt->bindValue(':id', $campaignId, SQLITE3_TEXT);
    $result = $stmt->execute();
    $campaign = null;
    if ($result) {
        $campaign = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
    }
    $stmt->close();
    $db->close();
    return $campaign ?: null;
}

function updateCampaignStatus(string $campaignId, string $status): void
{
    $allowed = ['scheduled', 'paused'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Status inválido para campanha');
    }
    $db = openCampaignDatabase(false);
    if (!$db) {
        throw new RuntimeException('Não foi possível abrir o banco de campanhas');
    }
    $stmt = $db->prepare("UPDATE campaigns SET status = :status WHERE id = :id");
    if (!$stmt) {
        $db->close();
        throw new RuntimeException('Não foi possível atualizar o status da campanha');
    }
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':id', $campaignId, SQLITE3_TEXT);
    $stmt->execute();
    $stmt->close();
    $db->close();
}

function setCampaignPauseState(string $campaignId, bool $paused): void
{
    updateCampaignStatus($campaignId, $paused ? 'paused' : 'scheduled');

    $db = openChatDataDatabase(false);
    if (!$db) {
        return;
    }
    if (!tableHasColumn($db, 'scheduled_messages', 'is_paused')) {
        $db->close();
        return;
    }
    $stmt = $db->prepare("
        UPDATE scheduled_messages
        SET is_paused = :flag
        WHERE campaign_id = :id
          AND status = 'pending'
    ");
    if ($stmt) {
        $stmt->bindValue(':flag', $paused ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $campaignId, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }
    $db->close();
}

function removeScheduledMessagesForCampaign(string $campaignId): int
{
    $db = openChatDataDatabase(false);
    if (!$db) {
        return 0;
    }
    if (!tableHasColumn($db, 'scheduled_messages', 'campaign_id')) {
        $db->close();
        return 0;
    }
    $stmt = $db->prepare("DELETE FROM scheduled_messages WHERE campaign_id = :id");
    if (!$stmt) {
        $db->close();
        return 0;
    }
    $stmt->bindValue(':id', $campaignId, SQLITE3_TEXT);
    $stmt->execute();
    $deleted = $db->changes();
    $stmt->close();
    $db->close();
    return $deleted;
}

function deleteCampaignWithMessages(string $campaignId): int
{
    $deleted = removeScheduledMessagesForCampaign($campaignId);
    deleteCampaignById($campaignId);
    return $deleted;
}

function scheduleCampaignMessages(string $campaignId, ?string $instanceId, array $contacts, array $segments, int $startAt, int $delaySeconds, array $variationSegments = []): int
{
    $db = openChatDataDatabase(false);
    if (!$db) {
        throw new RuntimeException('Banco de agendamentos não disponível');
    }
    ensureCampaignIdColumn($db);
    if (!tableHasColumn($db, 'scheduled_messages', 'campaign_id')) {
        $db->close();
        throw new RuntimeException('Coluna campaign_id ausente no banco de agendamentos');
    }

    $segmentInterval = max(1, CAMPAIGN_SEGMENT_DELAY_SECONDS);
    $delaySeconds = max(1, $delaySeconds);
    $scheduledCount = 0;
    $processedRemoteJids = [];
    $scheduledContactIndex = 0;

    $stmt = $db->prepare("
        INSERT INTO scheduled_messages (
            instance_id,
            remote_jid,
            message,
            scheduled_at,
            status,
            tag,
            tipo,
            campaign_id
        ) VALUES (
            :instance_id,
            :remote_jid,
            :message,
            :scheduled_at,
            :status,
            :tag,
            :tipo,
            :campaign_id
        )
    ");
    if (!$stmt) {
        $db->close();
        throw new RuntimeException('Não foi possível preparar o insert de agendamentos');
    }

    $db->exec('BEGIN');
    try {
        foreach ($contacts as $contactIndex => $contact) {
            $remoteJid = normalizeRemoteJid($contact['whatsapp'] ?? '');
            if (!$remoteJid || isset($processedRemoteJids[$remoteJid])) {
                continue;
            }
            $processedRemoteJids[$remoteJid] = true;
            $segmentsForContact = determineSegmentsForContact($contact, $variationSegments, $segments, $contactIndex);
            $contactScheduled = $scheduledContactIndex;
            $hasScheduledSegment = false;
            foreach ($segmentsForContact as $segmentIndex => $segmentText) {
                $messageText = trim($segmentText);
                if ($messageText === '') {
                    continue;
                }
                $contactData = $contact['data'] ?? [];
                $messageText = applyContactPlaceholders($messageText, $contactData);
                $scheduledTimestamp = $startAt + ($contactScheduled * $delaySeconds) + ($segmentIndex * $segmentInterval);
                $scheduledIso = gmdate('Y-m-d\TH:i:s\Z', $scheduledTimestamp);
                $stmt->bindValue(':instance_id', $instanceId ?? '', SQLITE3_TEXT);
                $stmt->bindValue(':remote_jid', $remoteJid, SQLITE3_TEXT);
                $stmt->bindValue(':message', $messageText, SQLITE3_TEXT);
                $stmt->bindValue(':scheduled_at', $scheduledIso, SQLITE3_TEXT);
                $stmt->bindValue(':status', 'pending', SQLITE3_TEXT);
                $stmt->bindValue(':tag', 'campaign', SQLITE3_TEXT);
                $stmt->bindValue(':tipo', 'campaign', SQLITE3_TEXT);
                $stmt->bindValue(':campaign_id', $campaignId, SQLITE3_TEXT);
                $result = $stmt->execute();
                if ($result === false) {
                    throw new RuntimeException($db->lastErrorMsg());
                }
                $stmt->reset();
                $scheduledCount++;
                $hasScheduledSegment = true;
            }
            if ($hasScheduledSegment) {
                $scheduledContactIndex++;
            }
        }
        if ($scheduledCount === 0) {
            throw new RuntimeException('Nenhuma mensagem válida encontrada para agendamento');
        }
        $db->exec('COMMIT');
    } catch (Exception $err) {
        $db->exec('ROLLBACK');
        $stmt->close();
        $db->close();
        throw $err;
    }

    $stmt->close();
    $db->close();
    return $scheduledCount;
}

function fetchCampaignDeliveryStats(array $campaignIds): array
{
    $campaignIds = array_values(array_unique(array_filter($campaignIds)));
    if (!$campaignIds) {
        return [];
    }
    $db = openChatDataDatabase(false);
    if (!$db) {
        return [];
    }
    if (!tableHasColumn($db, 'scheduled_messages', 'campaign_id')) {
        ensureCampaignIdColumn($db);
        if (!tableHasColumn($db, 'scheduled_messages', 'campaign_id')) {
            $db->close();
            return [];
        }
    }

    $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
    $stmt = $db->prepare("
        SELECT campaign_id, status, COUNT(*) AS total
        FROM scheduled_messages
        WHERE campaign_id IN ({$placeholders})
        GROUP BY campaign_id, status
    ");
    if (!$stmt) {
        $db->close();
        return [];
    }

    foreach ($campaignIds as $index => $campaignId) {
        $stmt->bindValue($index + 1, $campaignId, SQLITE3_TEXT);
    }

    $result = $stmt->execute();
    if (!$result) {
        $stmt->close();
        $db->close();
        return [];
    }

    $stats = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $campaignId = $row['campaign_id'] ?? '';
        $status = $row['status'] ?? 'pending';
        $total = (int)($row['total'] ?? 0);
        if ($campaignId === '') {
            continue;
        }
        $stats[$campaignId][$status] = $total;
    }
    $result->finalize();
    $stmt->close();
    $db->close();
    return $stats;
}

function fetchCampaigns(): array
{
    ensureCampaignStorage();
    $db = openCampaignDatabase(true);
    if (!$db) {
        return [];
    }
    $result = $db->query("SELECT * FROM campaigns ORDER BY created_at DESC");
    $campaigns = [];
    if ($result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['columns_mapping'] = json_decode($row['columns_mapping'] ?? '[]', true) ?? [];
            $row['placeholders'] = json_decode($row['placeholders'] ?? '[]', true) ?? [];
            $row['contacts_json'] = json_decode($row['contacts_json'] ?? '[]', true) ?? [];
            $row['message_parts'] = json_decode($row['message_parts'] ?? '[]', true) ?? [];
            $campaigns[] = $row;
        }
        $result->finalize();
    }
    $db->close();
    return $campaigns;
}

function renderCampaignCards(array $campaigns, array $instances, string $emptyMessage, bool $running, array $deliveryStats = []): string
{
    ob_start();
    if (empty($campaigns)) {
        ?>
        <p class="text-sm text-slate-500"><?= htmlspecialchars($emptyMessage) ?></p>
        <?php
        return ob_get_clean();
    }
    foreach ($campaigns as $campaign):
        $campaignStatus = $campaign['status'] ?? 'scheduled';
        $isPaused = $campaignStatus === 'paused';
        if ($isPaused) {
            $statusLabel = 'Pausada';
            $statusBadgeClass = 'bg-warning/10 text-warning';
        } elseif ($running) {
            $statusLabel = 'Rodando';
            $statusBadgeClass = 'bg-primary/10 text-primary';
        } else {
            $statusLabel = 'Finalizada';
            $statusBadgeClass = 'bg-success/10 text-success';
        }
        $instanceLabel = $instances[$campaign['instance_id']]['name'] ?? 'Padrão';
        $campaignId = $campaign['id'] ?? '';
        ?>
        <div
          class="rounded-2xl border border-slate-200 p-4 <?= $running ? 'bg-slate-50' : 'bg-white' ?> space-y-2 text-sm"
          data-campaign-id="<?= htmlspecialchars($campaignId) ?>"
          data-campaign-status="<?= htmlspecialchars($campaignStatus) ?>"
        >
          <div class="flex <?= $running ? 'items-start' : 'items-center' ?> justify-between">
            <strong><?= htmlspecialchars($campaign['name']) ?></strong>
            <span class="text-[11px] px-2 py-0.5 rounded-full <?= $statusBadgeClass ?>"><?= $statusLabel ?></span>
          </div>
          <p class="text-xs text-slate-500">
            <?= htmlspecialchars(formatContactLabel($campaign)) ?>
            <?= $running ? '• agendada para' : '• terminou' ?>
            <?= htmlspecialchars(formatDatetime((int)$campaign[$running ? 'scheduled_at' : 'estimated_end_at'])) ?>
          </p>
          <?php if ($running): ?>
            <p class="text-xs text-slate-500">Fim previsto <?= htmlspecialchars(formatDatetime((int)$campaign['estimated_end_at'])) ?></p>
          <?php else: ?>
            <p class="text-xs text-slate-500 text-ellipsis overflow-hidden" style="max-height: 3rem;">
              <?= htmlspecialchars(mb_substr($campaign['message_template'], 0, 120)) ?><?= strlen($campaign['message_template']) > 120 ? '…' : '' ?>
            </p>
          <?php endif; ?>
          <?php
            $stats = $deliveryStats[$campaign['id']] ?? [];
            $sentCount = (int)($stats['sent'] ?? 0);
            $failedCount = (int)($stats['failed'] ?? 0);
            $pendingCount = (int)($stats['pending'] ?? 0);
            $contactCount = max(0, (int)($campaign['contact_count'] ?? 0));
            $scheduledTotal = $sentCount + $failedCount + $pendingCount;
            $segmentsPerContact = $contactCount > 0 ? max(1, (int)round($scheduledTotal / $contactCount)) : ($scheduledTotal > 0 ? 1 : 0);
            $expectedTotal = max(0, $scheduledTotal);
            $hasStats = ($sentCount + $failedCount + $pendingCount) > 0;
          ?>
          <p class="text-[11px] text-slate-500">
            Contatos previstos: <?= $contactCount ?> • Segmentos por contato: <?= $segmentsPerContact ?> • Total esperado: <?= $expectedTotal ?>
          </p>
          <?php if ($hasStats): ?>
            <p class="text-[11px] text-slate-500">
              <span class="text-success">Sucesso: <?= $sentCount ?></span> •
              <span class="text-error">Falhas: <?= $failedCount ?></span> •
              <span class="text-slate-500">Pendentes: <?= $pendingCount ?></span>
            </p>
          <?php else: ?>
            <p class="text-[11px] text-slate-400">Nenhum envio registrado ainda para esta campanha.</p>
          <?php endif; ?>
          <p class="text-xs text-slate-500">Instância: <?= htmlspecialchars($instanceLabel) ?></p>
          <?php if ($running): ?>
            <?php
              $scheduledTotal = $sentCount + $failedCount + $pendingCount;
              $scheduledTotal = max(0, $scheduledTotal);
              $completedCount = $sentCount + $failedCount;
              $progressPercent = $scheduledTotal > 0 ? (int)floor(($completedCount / $scheduledTotal) * 100) : ($completedCount > 0 ? 100 : 0);
              $progressPercent = max(0, min(100, $progressPercent));
              $progressLabel = $scheduledTotal > 0 ? "{$completedCount}/{$scheduledTotal}" : ($completedCount > 0 ? 'Envios registrados' : 'Nenhum envio registrado ainda');
            ?>
            <div class="space-y-1 text-[11px] text-slate-500">
              <div class="flex items-center justify-between">
                <span>Progresso de envios</span>
                <span class="text-slate-700 font-semibold"><?= $progressPercent ?>%</span>
              </div>
              <div class="h-2 rounded-full bg-slate-200 overflow-hidden">
                <div class="h-full bg-primary transition-all" style="width: <?= $progressPercent ?>%"></div>
              </div>
              <div class="text-[10px] text-slate-400"><?= htmlspecialchars($progressLabel) ?></div>
            </div>
          <?php endif; ?>
          <div class="flex flex-wrap gap-2">
            <?php if ($running && $isPaused): ?>
              <button
                type="button"
                class="text-[11px] px-3 py-1 rounded-2xl border border-warning/30 bg-warning/10 text-warning font-semibold hover:bg-warning/20 focus:outline-none"
                data-campaign-id="<?= htmlspecialchars($campaignId) ?>"
                data-campaign-action="resume"
              >
                Retomar campanha
              </button>
            <?php elseif ($running): ?>
              <button
                type="button"
                class="text-[11px] px-3 py-1 rounded-2xl border border-slate-200 bg-white text-slate-600 font-semibold hover:border-primary/50 hover:text-primary focus:outline-none"
                data-campaign-id="<?= htmlspecialchars($campaignId) ?>"
                data-campaign-action="pause"
              >
                Pausar campanha
              </button>
            <?php endif; ?>
            <button
              type="button"
              class="text-[11px] px-3 py-1 rounded-2xl border border-error/60 bg-white text-error font-semibold hover:bg-error/10 focus:outline-none"
              data-campaign-id="<?= htmlspecialchars($campaignId) ?>"
              data-campaign-action="delete"
            >
              Excluir campanha
            </button>
          </div>
        </div>
        <?php
    endforeach;
    return ob_get_clean();
}

function saveCampaign(array $payload): array
{
    ensureCampaignStorage();
    $db = openCampaignDatabase(false);
    if (!$db) {
        throw new RuntimeException('Não foi possível acessar o banco de campanhas');
    }

    $stmt = $db->prepare("
        INSERT INTO campaigns (
            id,
            name,
            created_at,
            scheduled_at,
            estimated_end_at,
            message_template,
            delay_seconds,
            contact_count,
            instance_id,
            columns_mapping,
            placeholders,
            message_parts,
            contacts_json,
            status
        ) VALUES (
            :id,
            :name,
            :created_at,
            :scheduled_at,
            :estimated_end_at,
            :message_template,
            :delay_seconds,
            :contact_count,
            :instance_id,
            :columns_mapping,
            :placeholders,
            :message_parts,
            :contacts_json,
            :status
        )
    ");

    $stmt->bindValue(':id', $payload['id'], SQLITE3_TEXT);
    $stmt->bindValue(':name', $payload['name'], SQLITE3_TEXT);
    $stmt->bindValue(':created_at', $payload['created_at'], SQLITE3_INTEGER);
    $stmt->bindValue(':scheduled_at', $payload['scheduled_at'], SQLITE3_INTEGER);
    $stmt->bindValue(':estimated_end_at', $payload['estimated_end_at'], SQLITE3_INTEGER);
    $stmt->bindValue(':message_template', $payload['message_template'], SQLITE3_TEXT);
    $stmt->bindValue(':delay_seconds', $payload['delay_seconds'], SQLITE3_INTEGER);
    $stmt->bindValue(':contact_count', $payload['contact_count'], SQLITE3_INTEGER);
    $stmt->bindValue(':instance_id', $payload['instance_id'], SQLITE3_TEXT);
    $stmt->bindValue(':columns_mapping', $payload['columns_mapping'], SQLITE3_TEXT);
    $stmt->bindValue(':placeholders', $payload['placeholders'], SQLITE3_TEXT);
    $stmt->bindValue(':message_parts', $payload['message_parts'], SQLITE3_TEXT);
    $stmt->bindValue(':contacts_json', $payload['contacts_json'], SQLITE3_TEXT);
    $stmt->bindValue(':status', $payload['status'], SQLITE3_TEXT);

    $executed = $stmt->execute();
    if (!$executed) {
        $error = $db->lastErrorMsg();
        $stmt->close();
        $db->close();
        throw new RuntimeException('Falha ao salvar campanha: ' . $error);
    }

    $stmt->close();
    $db->close();
    return $payload;
}

function respondJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$instances = loadInstancesFromDatabase();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        respondJson(['ok' => false, 'error' => 'Payload inválido'], 400);
    }
    $action = $input['action'] ?? '';
    if ($action === 'create_campaign') {
        $campaignName = trim($input['campaign_name'] ?? '');
        if ($campaignName === '') {
            respondJson(['ok' => false, 'error' => 'Nome da campanha é obrigatório'], 400);
        }

        $messageTemplate = trim($input['message'] ?? '');
        if ($messageTemplate === '') {
            respondJson(['ok' => false, 'error' => 'Mensagem da campanha é obrigatória'], 400);
        }

        $messageParts = splitMessageParts($messageTemplate);
        $messageVariations = is_array($input['message_variations'] ?? []) ? array_values($input['message_variations']) : [];
        $variationSegments = buildVariationSegmentsFromTexts($messageVariations);

        $mapping = is_array($input['mapping'] ?? []) ? $input['mapping'] : [];
        $placeholders = is_array($input['placeholders'] ?? []) ? $input['placeholders'] : [];

        $instanceId = $input['instance_id'] ?? '';
        if ($instanceId && !isset($instances[$instanceId])) {
            respondJson(['ok' => false, 'error' => 'Instância selecionada não existe'], 400);
        }

        $contacts = [];
        $seenRemoteJids = [];
        $metadataDb = openChatDataDatabase(true);
        $metadataStatusStmt = null;
        if ($metadataDb && tableHasColumn($metadataDb, 'contact_metadata', 'status_name')) {
            $metadataStatusStmt = $metadataDb->prepare("
                SELECT status_name
                FROM contact_metadata
                WHERE instance_id = :instance
                  AND remote_jid = :remote
                LIMIT 1
            ");
        }
        if (!empty($input['contacts']) && is_array($input['contacts'])) {
            foreach ($input['contacts'] as $contact) {
                $rawWhatsapp = trim($contact['whatsapp'] ?? '');
                if ($rawWhatsapp === '') {
                    continue;
                }
                $remoteJid = normalizeRemoteJid($rawWhatsapp);
                if (!$remoteJid || isset($seenRemoteJids[$remoteJid])) {
                    continue;
                }
                $seenRemoteJids[$remoteJid] = true;
                $statusName = '';
                if ($metadataStatusStmt) {
                    $metadataStatusStmt->bindValue(':instance', $instanceId ?? '', SQLITE3_TEXT);
                    $metadataStatusStmt->bindValue(':remote', $remoteJid, SQLITE3_TEXT);
                    $metadataResult = $metadataStatusStmt->execute();
                    if ($metadataResult) {
                        $row = $metadataResult->fetchArray(SQLITE3_ASSOC);
                        if ($row) {
                            $statusName = trim($row['status_name'] ?? '');
                        }
                        $metadataResult->finalize();
                    }
                    $metadataStatusStmt->reset();
                }
                $contactData = is_array($contact['data'] ?? null) ? $contact['data'] : [];
                $contactData['statusname'] = $statusName;
                $contacts[] = [
                    'whatsapp' => $remoteJid,
                    'nome' => trim($contact['nome'] ?? ''),
                    'endereco' => trim($contact['endereco'] ?? ''),
                    'data' => $contactData,
                    'status_name' => $statusName,
                    'variation_index' => isset($contact['variation_index']) ? (int)$contact['variation_index'] : null,
                ];
            }
        }
        if ($metadataStatusStmt) {
            $metadataStatusStmt->close();
        }
        if ($metadataDb) {
            $metadataDb->close();
        }
        if (count($contacts) === 0) {
            respondJson(['ok' => false, 'error' => 'Não foi possível identificar contatos válidos'], 400);
        }

        $delaySeconds = max(1, (int)($input['delay_seconds'] ?? 5));

        $startAt = $input['start_at'] ?? time();
        if (!is_numeric($startAt)) {
            $startAt = time();
        }
        $startAt = (int)$startAt;
        if ($startAt > 1_000_000_000_000) {
            $startAt = (int)round($startAt / 1000);
        }

        $estimatedEndAt = $startAt + ($delaySeconds * count($contacts));

        $payload = [
            'id' => uniqid('cmp_'),
            'name' => $campaignName,
            'created_at' => time(),
            'scheduled_at' => $startAt,
            'estimated_end_at' => $estimatedEndAt,
            'message_template' => $messageTemplate,
            'delay_seconds' => $delaySeconds,
            'contact_count' => count($contacts),
            'instance_id' => $instanceId,
            'columns_mapping' => json_encode($mapping, JSON_UNESCAPED_UNICODE),
            'placeholders' => json_encode($placeholders, JSON_UNESCAPED_UNICODE),
            'message_parts' => json_encode($messageParts, JSON_UNESCAPED_UNICODE),
            'contacts_json' => json_encode($contacts, JSON_UNESCAPED_UNICODE),
            'status' => 'scheduled',
        ];

        try {
            $saved = saveCampaign($payload);
        } catch (Exception $e) {
            respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        try {
            $scheduledCount = scheduleCampaignMessages(
                $saved['id'],
                $instanceId,
                $contacts,
                $messageParts,
                $startAt,
                $delaySeconds,
                $variationSegments
            );
        } catch (Exception $e) {
            deleteCampaignById($saved['id']);
            respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        respondJson([
            'ok' => true,
            'campaign' => $saved,
            'scheduled_messages' => $scheduledCount
        ]);
    }

    $campaignId = trim((string)($input['campaign_id'] ?? ''));
    if ($campaignId === '') {
        respondJson(['ok' => false, 'error' => 'ID da campanha é obrigatório'], 400);
    }
    $campaign = fetchCampaignById($campaignId);
    if (!$campaign) {
        respondJson(['ok' => false, 'error' => 'Campanha não encontrada'], 404);
    }

    try {
        if ($action === 'pause_campaign') {
            setCampaignPauseState($campaignId, true);
            $status = 'paused';
            $message = 'Campanha pausada';
        } elseif ($action === 'resume_campaign') {
            setCampaignPauseState($campaignId, false);
            $status = 'scheduled';
            $message = 'Campanha retomada';
        } elseif ($action === 'delete_campaign') {
            $deleted = deleteCampaignWithMessages($campaignId);
            respondJson([
                'ok' => true,
                'action' => 'delete_campaign',
                'campaign_id' => $campaignId,
                'deleted_messages' => $deleted
            ]);
        } else {
            respondJson(['ok' => false, 'error' => 'Ação desconhecida'], 400);
        }
    } catch (Exception $e) {
        respondJson(['ok' => false, 'error' => $e->getMessage()], 500);
    }

    respondJson([
        'ok' => true,
        'action' => $action,
        'campaign_id' => $campaignId,
        'status' => $status,
        'message' => $message
    ]);
}

$campaigns = fetchCampaigns();
$campaignIds = array_values(array_filter(array_map(fn($campaign) => $campaign['id'] ?? '', $campaigns)));
$deliveryStats = fetchCampaignDeliveryStats($campaignIds);
$runningCampaigns = [];
$finishedCampaigns = [];
$now = time();

foreach ($campaigns as $campaign) {
    $campaignStatus = $campaign['status'] ?? 'scheduled';
    $isFinished = $campaignStatus !== 'paused' && $campaign['estimated_end_at'] <= $now;
    if ($isFinished) {
        $finishedCampaigns[] = $campaign;
    } else {
        $runningCampaigns[] = $campaign;
    }
}

if (isset($_GET['ajax_campaign_overview'])) {
    respondJson([
        'runningCount' => count($runningCampaigns),
        'finishedCount' => count($finishedCampaigns),
        'runningHtml' => renderCampaignCards($runningCampaigns, $instances, 'Sem campanhas em andamento.', true, $deliveryStats),
        'finishedHtml' => renderCampaignCards($finishedCampaigns, $instances, 'Nenhuma campanha finalizada ainda.', false, $deliveryStats),
    ]);
}

function formatDatetime(int $timestamp): string
{
    if ($timestamp <= 0) {
        return 'Indefinido';
    }
    return date('d/m/Y H:i', $timestamp);
}

function formatContactLabel(array $campaign): string
{
    $count = (int)($campaign['contact_count'] ?? 0);
    return "$count contato" . ($count === 1 ? '' : 's');
}

$instanceOptions = [];
foreach ($instances as $id => $row) {
    $instanceOptions[] = [
        'id' => $id,
        'label' => $row['name'] ?? $id,
        'port' => $row['port'] ?? null,
    ];
}

$runningCount = count($runningCampaigns);
$finishedCount = count($finishedCampaigns);

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Campanhas • Maestro</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary: '#2563EB',
            dark: '#1E293B',
            light: '#F1F5F9',
            mid: '#CBD5E1',
            success: '#22C55E',
            alert: '#F59E0B',
            error: '#EF4444',
            warning: '#F59E0B'
          }
        }
      }
    }
  </script>
  <style>
    html, body { font-family: Inter, system-ui, sans-serif; }
    body { background: #f7f7fb; }
    .wizard-panel { border: 1px solid #e2e8f0; background: #fff; border-radius: 1rem; padding: 1.5rem; }
    .hidden { display: none; }
  </style>
</head>
<body>
  <div class="min-h-screen bg-light text-dark">
    <div class="max-w-6xl mx-auto py-10 px-4 space-y-6">
      <header class="flex flex-col gap-2">
        <div class="flex items-center gap-3">
          <a href="index.php" class="text-sm text-slate-500 hover:text-dark">← Painel principal</a>
          <h1 class="text-2xl font-semibold">Campanhas</h1>
        </div>
        <p class="text-sm text-slate-500">
          Monitore e programe envios em lote com persistência completa.
        </p>
      </header>

      <div class="grid md:grid-cols-3 gap-6">
        <section class="wizard-panel md:col-span-2 space-y-6">
          <div class="space-y-3">
            <h2 class="text-xl font-semibold">1. Importar contatos</h2>
            <p class="text-sm text-slate-500">Faça upload de um CSV com cabeçalho ou cole os dados diretamente.</p>
            <p class="text-[11px] text-slate-500">Somente os contatos desta lista (clientes) serão salvos nesta campanha.</p>
          </div>
          <div class="space-y-3">
            <label class="text-xs text-slate-500">Nome da campanha</label>
            <input id="campaignName" class="w-full rounded-2xl border border-mid px-4 py-2 bg-light" placeholder="Campanha de Vendas — Abril">
          </div>
          <div class="space-y-2">
            <label class="text-xs text-slate-500">CSV</label>
            <input id="csvFile" type="file" accept=".csv,text/csv" class="w-full">
            <p class="text-[11px] text-slate-400">Com cabeçalho. Os campos obrigatórios são WhatsApp, nome e endereço.</p>
          </div>
          <div class="space-y-2">
            <label class="text-xs text-slate-500">Ou cole o conteúdo</label>
            <textarea id="csvPaste" rows="4" class="w-full rounded-2xl border border-mid px-4 py-2 bg-light" placeholder="WhatsApp,nome,endereco\n5585999999999,João,Fortaleza"></textarea>
            <div class="flex flex-col gap-1 text-[11px] text-slate-500">
              <span>Cole sua lista e clique em processar para validar apenas esses clientes.</span>
              <button id="parsePaste" class="px-4 py-2 rounded-2xl bg-primary text-white text-sm font-medium border border-primary hover:bg-primary/90 focus:outline-none">Processar dados colados</button>
            </div>
          </div>
          <div id="previewSection" class="space-y-2 hidden">
            <div class="flex items-center justify-between">
              <div class="text-xs font-semibold text-slate-600">Amostra de dados</div>
              <span id="parsedRowsBadge" class="text-[11px] px-2 py-1 rounded-full bg-slate-100 text-slate-600"></span>
            </div>
            <div id="previewTable" class="overflow-auto rounded-2xl border border-mid bg-white shadow-sm text-xs"></div>
          </div>

          <div id="mappingSection" class="space-y-3 hidden">
            <div class="flex items-center justify-between">
              <div class="text-xs font-semibold text-slate-600">2. Mapear colunas</div>
              <span id="mappingBadge" class="text-[11px] px-2 py-1 rounded-full bg-success/10 text-success">Pronto</span>
            </div>
            <div class="grid md:grid-cols-3 gap-3">
              <label class="text-[11px] text-slate-500">
                WhatsApp
                <select id="mapWhatsApp" class="mt-1 w-full rounded-2xl border border-mid bg-light text-sm"></select>
              </label>
              <label class="text-[11px] text-slate-500">
                Nome
                <select id="mapNome" class="mt-1 w-full rounded-2xl border border-mid bg-light text-sm"></select>
              </label>
              <label class="text-[11px] text-slate-500">
                Endereço
                <select id="mapEndereco" class="mt-1 w-full rounded-2xl border border-mid bg-light text-sm"></select>
              </label>
            </div>
          </div>

          <div id="finalStep" class="space-y-4 hidden">
            <div class="flex items-center justify-between">
              <div class="text-xs font-semibold text-slate-600">3. Mensagem e agendamento</div>
              <span id="summaryBadge" class="text-[11px] px-2 py-1 rounded-full bg-slate-100 text-slate-600"></span>
            </div>
            <div class="space-y-3 text-sm">
              <p class="text-[11px] text-slate-500">Placeholders disponíveis com base nas colunas importadas:</p>
              <div id="placeholdersList" class="flex flex-wrap gap-2 text-xs"></div>
            </div>
            <div class="space-y-3 text-sm">
              <div class="flex items-center justify-between">
                <p class="text-[11px] text-slate-500">Variações simultâneas</p>
                <span id="variationCountLabel" class="text-[11px] font-semibold text-slate-700">1 variação</span>
              </div>
              <input id="variationSlider" type="range" min="1" max="50" value="1" class="w-full accent-primary">
              <p class="text-[11px] text-slate-500">Cada contato receberá uma das variações em sequência, para distribuir as mensagens de forma ordenada.</p>
            </div>
            <div id="variationFields" class="space-y-3"></div>
            <div id="messageSegmentHint" class="text-[11px] text-slate-500">Uma única mensagem será enviada e repetida.</div>
            <div class="grid md:grid-cols-2 gap-3 text-sm">
              <label class="text-[11px] text-slate-500">
                Instância de envio
                <select id="instanceSelector" class="mt-1 w-full rounded-2xl border border-mid bg-light text-sm">
                  <option value="">Selecione a instância</option>
                  <?php foreach ($instanceOptions as $option): ?>
                    <option value="<?= htmlspecialchars($option['id']) ?>">
                      <?= htmlspecialchars($option['label']) ?><?= $option['port'] ? " (porta {$option['port']})" : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="text-[11px] text-slate-500">
                Delay entre envios (segundos)
                <input id="delaySeconds" type="number" min="1" value="5" class="mt-1 w-full rounded-2xl border border-mid bg-light text-sm px-3 py-2">
              </label>
            </div>
            <div class="grid md:grid-cols-2 gap-3 text-sm">
              <label class="text-[11px] text-slate-500 flex flex-col gap-2">
                <span>Tempo de início</span>
                <input id="scheduledAt" type="datetime-local" class="rounded-2xl border border-mid bg-light text-sm px-3 py-2">
              </label>
              <label class="text-[11px] text-slate-500 flex items-center gap-2">
                <input id="immediateStart" type="checkbox">
                Iniciar imediatamente
              </label>
            </div>
            <div class="text-sm text-slate-500 space-y-1">
              <p>Início aproximado: <span id="startDisplay">---</span></p>
              <p>Término aproximado: <span id="endDisplay">---</span></p>
              <p>Contatos válidos: <strong id="contactCount">0</strong></p>
            </div>
            <div class="flex flex-col gap-2">
              <div class="flex flex-wrap gap-2">
                <button id="submitCampaignSchedule" class="px-4 py-2 rounded-2xl bg-primary text-white font-medium hover:opacity-90">Agendar campanha</button>
                <button id="submitCampaignNow" class="px-4 py-2 rounded-2xl border border-primary text-primary font-medium hover:bg-primary/5">Iniciar campanha agora</button>
              </div>
              <span id="feedbackMessage" class="text-sm text-slate-500"></span>
            </div>
          </div>
        </section>

        <aside class="wizard-panel space-y-4">
          <div>
            <div class="text-xs uppercase tracking-[0.3em] text-slate-400">Campanhas ativas</div>
            <div class="text-2xl font-semibold" id="runningCountTotal"><?= $runningCount ?></div>
            <p class="text-sm text-slate-500" id="runningCountCopy"><?= $runningCount === 0 ? 'Nenhuma campanha em execução' : 'Campanhas em andamento' ?></p>
          </div>
          <div>
            <div class="text-xs uppercase tracking-[0.3em] text-slate-400">Campanhas finalizadas</div>
            <div class="text-2xl font-semibold" id="finishedCountTotal"><?= $finishedCount ?></div>
            <p class="text-sm text-slate-500" id="finishedCountCopy"><?= $finishedCount === 0 ? 'Ainda não há histórico' : 'Campanhas concluídas recentemente' ?></p>
          </div>
          <div class="space-y-3 text-sm">
            <div class="rounded-2xl border border-mid p-3 bg-slate-50">
              <p class="text-xs text-slate-500">Seu histórico fica gravado no arquivo de campanhas. Mesmo saindo ou atualizando a página, o status é preservado.</p>
            </div>
            <div class="rounded-2xl border border-mid p-3 bg-slate-50">
              <p class="text-xs text-slate-500">A mensagem armazenada alimenta o contexto da instância, permitindo que a IA entenda o que está sendo enviado.</p>
            </div>
          </div>
        </aside>
      </div>

      <div class="grid lg:grid-cols-2 gap-6">
        <section class="wizard-panel space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-lg font-semibold">Campanhas rodando</h3>
              <p class="text-xs text-slate-500">Ordenadas pela data de criação</p>
            </div>
            <span id="runningCountLabel" class="text-[11px] px-2 py-1 rounded-full bg-success/10 text-success"><?= $runningCount ?> em execução</span>
          </div>
          <div class="space-y-3" id="runningCampaignsList">
            <?= renderCampaignCards($runningCampaigns, $instances, 'Sem campanhas em andamento.', true, $deliveryStats) ?>
          </div>
        </section>

        <section class="wizard-panel space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-lg font-semibold">Campanhas concluídas</h3>
              <p class="text-xs text-slate-500">Mantidas para consulta rápida</p>
            </div>
            <span id="finishedCountLabel" class="text-[11px] px-2 py-0.5 rounded-full bg-slate-100 text-slate-600"><?= $finishedCount ?> finalizadas</span>
          </div>
          <div class="space-y-3" id="finishedCampaignsList">
            <?= renderCampaignCards($finishedCampaigns, $instances, 'Nenhuma campanha finalizada ainda.', false, $deliveryStats) ?>
          </div>
        </section>
      </div>
    </div>
  </div>

  <script>
    const Instances = <?= json_encode($instanceOptions, JSON_UNESCAPED_UNICODE) ?>;
    const csvFileInput = document.getElementById('csvFile');
    const csvPasteArea = document.getElementById('csvPaste');
    const parsePasteBtn = document.getElementById('parsePaste');
    const previewSection = document.getElementById('previewSection');
    const previewTable = document.getElementById('previewTable');
    const parsedRowsBadge = document.getElementById('parsedRowsBadge');
    const mappingSection = document.getElementById('mappingSection');
    const finalStep = document.getElementById('finalStep');
    const mapSelects = {
      whatsapp: document.getElementById('mapWhatsApp'),
      nome: document.getElementById('mapNome'),
      endereco: document.getElementById('mapEndereco')
    };
    const placeholdersList = document.getElementById('placeholdersList');
    const contactCountEl = document.getElementById('contactCount');
    const labelSummary = document.getElementById('summaryBadge');
    const startDisplay = document.getElementById('startDisplay');
    const endDisplay = document.getElementById('endDisplay');
    const variationSlider = document.getElementById('variationSlider');
    const variationFieldsContainer = document.getElementById('variationFields');
    const variationCountLabel = document.getElementById('variationCountLabel');
    const messageSegmentHint = document.getElementById('messageSegmentHint');
    const runningCountTotal = document.getElementById('runningCountTotal');
    const finishedCountTotal = document.getElementById('finishedCountTotal');
    const runningCountCopy = document.getElementById('runningCountCopy');
    const finishedCountCopy = document.getElementById('finishedCountCopy');
    const runningCountLabel = document.getElementById('runningCountLabel');
    const finishedCountLabel = document.getElementById('finishedCountLabel');
    const runningCampaignsList = document.getElementById('runningCampaignsList');
    const finishedCampaignsList = document.getElementById('finishedCampaignsList');

    let parsedData = {
      headers: [],
      rows: []
    };

    const delimiterCandidates = [',', ';', '\t', '|'];

    function parseCsvLine(line, delimiter = ',') {
      const values = [];
      let current = '';
      let inQuotes = false;
      for (let i = 0; i < line.length; i++) {
        const char = line[i];
        if (char === '"' && line[i + 1] === '"') {
          current += '"';
          i++;
          continue;
        }
        if (char === '"') {
          inQuotes = !inQuotes;
          continue;
        }
        if (char === delimiter && !inQuotes) {
          values.push(current);
          current = '';
          continue;
        }
        current += char;
      }
      values.push(current);
      return values;
    }

    function detectDelimiter(lines) {
      if (!lines.length) {
        return ',';
      }
      const sample = lines.slice(0, 5);
      let best = { delimiter: ',', score: -Infinity };
      delimiterCandidates.forEach((delimiter) => {
        const columnCounts = sample.map(line => parseCsvLine(line, delimiter).length);
        const averageCount = columnCounts.reduce((sum, count) => sum + count, 0) / columnCounts.length;
        const consistent = new Set(columnCounts).size === 1 ? 0.1 : 0;
        const score = averageCount + consistent;
        if (score > best.score) {
          best = { delimiter, score };
        }
      });
      return best.delimiter;
    }

    function parseCsvText(text) {
      const lines = text.split(/\r?\n/).map(line => line.trim()).filter(line => line.length > 0);
      if (!lines.length) {
        return [];
      }
      const delimiter = detectDelimiter(lines);
      return lines.map(line => parseCsvLine(line, delimiter));
    }

    function buildPreview(headers, rows) {
      const chunk = rows.slice(0, 5);
      let table = '<table class="min-w-full text-xs border-separate" cellspacing="0">';
      table += '<tr>';
      headers.forEach(column => {
        table += `<th class="px-2 py-1 text-left text-slate-500 font-semibold">${column}</th>`;
      });
      table += '</tr>';
      chunk.forEach(row => {
        table += '<tr>';
        headers.forEach((column, index) => {
          table += `<td class="px-2 py-1 border-t border-slate-100">${row[index] ?? ''}</td>`;
        });
        table += '</tr>';
      });
      table += '</table>';
      previewTable.innerHTML = table;
      previewSection.classList.remove('hidden');
    }

    function finalizeMapping() {
      const uniqueHeaders = parsedData.headers;
      Object.values(mapSelects).forEach(select => {
        select.innerHTML = '<option value="">Selecione</option>';
        uniqueHeaders.forEach(header => {
          const option = document.createElement('option');
          option.value = header;
          option.textContent = header;
          select.appendChild(option);
        });
      });
      mappingSection.classList.remove('hidden');
    }

    function updatePlaceholders() {
      placeholdersList.innerHTML = '';
      const columns = Array.from(new Set(parsedData.headers.map(header => header.trim()).filter(Boolean)));
      if (!columns.length) {
        const helper = document.createElement('span');
        helper.className = 'text-[11px] text-slate-400';
        helper.textContent = 'Importe uma lista para ver os placeholders disponíveis.';
        placeholdersList.appendChild(helper);
        return;
      }
      columns.forEach(column => {
        const badge = document.createElement('span');
        badge.textContent = `%${column}%`;
        badge.className = 'px-2 py-1 rounded-full bg-slate-100 text-slate-600 text-[11px]';
        placeholdersList.appendChild(badge);
      });
    }

    function getMapping() {
      return {
        whatsapp: mapSelects.whatsapp.value,
        nome: mapSelects.nome.value,
        endereco: mapSelects.endereco.value
      };
    }

    function buildVariationField(index, initialValue = '') {
      const label = document.createElement('label');
      label.className = 'text-[11px] text-slate-500 flex flex-col gap-2';
      const title = document.createElement('span');
      title.className = 'text-[11px] font-semibold text-slate-600';
      title.textContent = `Mensagem ${index}`;
      const textarea = document.createElement('textarea');
      textarea.className = 'min-h-[120px] w-full rounded-2xl border border-mid bg-light text-sm px-4 py-2';
      textarea.placeholder = 'Escreva a variação que será enviada neste momento';
      textarea.dataset.variationField = 'true';
      textarea.dataset.variationIndex = String(index);
      textarea.addEventListener('input', updateMessageSegmentHint);
      textarea.value = initialValue;
      label.appendChild(title);
      label.appendChild(textarea);
      return label;
    }

    function renderVariationFields(count) {
      if (!variationFieldsContainer) {
        return;
      }
      const preserved = {};
      variationFieldsContainer.querySelectorAll('[data-variation-field]').forEach(field => {
        preserved[field.dataset.variationIndex] = field.value;
      });
      variationFieldsContainer.innerHTML = '';
      for (let i = 1; i <= count; i++) {
        const field = buildVariationField(i, preserved[i] ?? '');
        variationFieldsContainer.appendChild(field);
      }
    }

    function getMessageSegments() {
      if (!variationFieldsContainer) {
        return [];
      }
      return Array.from(variationFieldsContainer.querySelectorAll('[data-variation-field]'))
        .map(field => field.value.trim())
        .filter(value => value.length > 0);
    }

    function detectHashSegmentCount(text) {
      const normalized = (text || '').trim();
      if (!normalized || normalized.indexOf('#') === -1) {
        return 0;
      }
      const chunks = normalized
        .split('#')
        .map(part => part.trim())
        .filter(Boolean);
      return chunks.length > 0 ? chunks.length : 0;
    }

    function updateMessageSegmentHint() {
      if (!messageSegmentHint) {
        return;
      }
      const segments = getMessageSegments();
      const sliderValue = variationSlider ? parseInt(variationSlider.value, 10) || 1 : 1;
      if (variationCountLabel) {
        variationCountLabel.textContent = `${sliderValue} variação${sliderValue === 1 ? '' : 'es'}`;
      }
      const baseHint = segments.length <= 1
        ? 'Uma única mensagem será enviada e repetida.'
        : `${segments.length} variações definidas; serão enviadas em sequência para cada contato.`;

      const multiSegmentDetails = [];
      segments.forEach((segmentText, index) => {
        const count = detectHashSegmentCount(segmentText);
        if (count > 1) {
          multiSegmentDetails.push({ index: index + 1, count });
        }
      });

      if (multiSegmentDetails.length) {
        const maxCount = Math.max(...multiSegmentDetails.map(detail => detail.count));
        const detailDescriptions = multiSegmentDetails
          .map(detail => `Mensagem ${detail.index}: ${detail.count} envio${detail.count === 1 ? '' : 's'}`)
          .join(' • ');
        messageSegmentHint.textContent = `${baseHint} Foram detectadas ${maxCount} mensagens por contato (${detailDescriptions}).`;
      } else {
        messageSegmentHint.textContent = baseHint;
      }
    }

    function normalizePhone(raw) {
      if (!raw) return '';
      const trimmed = raw.trim();
      if (!trimmed) return '';
      if (trimmed.includes('@')) {
        return trimmed;
      }
      const digits = trimmed.replace(/\\D/g, '');
      if (!digits) return '';
      const needsCountryCode = !digits.startsWith('55') && digits.length >= 10 && digits.length <= 11;
      const normalizedDigits = needsCountryCode ? `55${digits}` : digits;
      return `${normalizedDigits}@s.whatsapp.net`;
    }

    function getContacts() {
      const mapping = getMapping();
      const whatsappIndex = mapping.whatsapp ? parsedData.headers.indexOf(mapping.whatsapp) : -1;
      const nomeIndex = mapping.nome ? parsedData.headers.indexOf(mapping.nome) : -1;
      const enderecoIndex = mapping.endereco ? parsedData.headers.indexOf(mapping.endereco) : -1;

      return parsedData.rows
        .map(row => {
          const data = {};
          parsedData.headers.forEach((header, idx) => {
            const key = header.trim();
            if (!key) {
              return;
            }
            data[key] = row[idx] ?? '';
          });
          return {
            whatsapp: normalizePhone(whatsappIndex >= 0 ? row[whatsappIndex] : ''),
            nome: nomeIndex >= 0 ? row[nomeIndex] : '',
            endereco: enderecoIndex >= 0 ? row[enderecoIndex] : '',
            data
          };
        })
        .filter(contact => contact.whatsapp);
    }

    function updateTimingInfo() {
      const contacts = getContacts();
      const delay = parseInt(document.getElementById('delaySeconds').value, 10) || 5;
      let start = new Date();
      const scheduledInput = document.getElementById('scheduledAt').value;
      if (!document.getElementById('immediateStart').checked && scheduledInput) {
        start = new Date(scheduledInput);
      }
      const startIso = start.toLocaleString();
      const durationMs = delay * 1000 * Math.max(contacts.length, 1);
      const estimatedEnd = new Date(start.getTime() + durationMs);
      startDisplay.textContent = startIso;
      endDisplay.textContent = estimatedEnd.toLocaleString();
      contactCountEl.textContent = contacts.length;
      labelSummary.textContent = `${contacts.length} contato${contacts.length === 1 ? '' : 's'} • Delay ${delay}s`;
    }

    function triggerFinalStepIfReady() {
      const mapping = getMapping();
      if (parsedData.headers.length && mapping.whatsapp && mapping.nome) {
        updatePlaceholders();
        updateTimingInfo();
        finalStep.classList.remove('hidden');
      }
    }

    function handleParsedData(headers, rows) {
      parsedData = { headers, rows };
      parsedRowsBadge.textContent = `${rows.length} linhas`;
      buildPreview(headers, rows);
      updatePlaceholders();
      finalizeMapping();
      triggerFinalStepIfReady();
    }

    function readFile(file) {
      const reader = new FileReader();
      reader.onload = () => {
        const chunks = parseCsvText(reader.result);
        if (chunks.length <= 1) {
          alert('Arquivo inválido ou sem dados.');
          return;
        }
        const headers = chunks[0];
        const rows = chunks.slice(1);
        handleParsedData(headers, rows);
      };
      reader.readAsText(file);
    }

    csvFileInput.addEventListener('change', (event) => {
      const file = event.target.files?.[0];
      if (file) {
        readFile(file);
      }
    });

    parsePasteBtn.addEventListener('click', () => {
      const text = csvPasteArea.value.trim();
      if (!text) {
        alert('Cole os dados antes de processar.');
        return;
      }
      const chunks = parseCsvText(text);
      if (chunks.length <= 1) {
        alert('Não foi possível encontrar cabeçalho e linhas.');
        return;
      }
      const headers = chunks[0];
      const rows = chunks.slice(1);
      handleParsedData(headers, rows);
    });

    Object.values(mapSelects).forEach(select => {
      select.addEventListener('change', () => {
        mappingSection.classList.remove('hidden');
        updatePlaceholders();
        triggerFinalStepIfReady();
      });
    });

    document.getElementById('delaySeconds').addEventListener('change', updateTimingInfo);
    document.getElementById('scheduledAt').addEventListener('change', updateTimingInfo);
    document.getElementById('immediateStart').addEventListener('change', updateTimingInfo);
    const initialVariationCount = variationSlider ? (parseInt(variationSlider.value, 10) || 1) : 1;
    renderVariationFields(initialVariationCount);
    if (variationSlider) {
      variationSlider.addEventListener('input', () => {
        const nextCount = parseInt(variationSlider.value, 10) || 1;
        renderVariationFields(nextCount);
        updateMessageSegmentHint();
      });
    }
    updateMessageSegmentHint();

    const scheduleButton = document.getElementById('submitCampaignSchedule');
    const startButton = document.getElementById('submitCampaignNow');
    const submissionButtons = [scheduleButton, startButton].filter(Boolean);
    const feedbackMessage = document.getElementById('feedbackMessage');

    async function submitCampaign(immediate) {
      const segments = getMessageSegments();
      if (!segments.length) {
        alert('Informe pelo menos uma variação de mensagem.');
        return;
      }
      const instanceId = document.getElementById('instanceSelector').value;
      const delay = parseInt(document.getElementById('delaySeconds').value, 10) || 5;
      const mapping = getMapping();

      if (!instanceId) {
        alert('Selecione a instância responsável pelo envio.');
        return;
      }
      if (!mapping.whatsapp || !mapping.nome) {
        alert('Associe pelo menos WhatsApp e Nome antes de prosseguir.');
        return;
      }
      const contacts = getContacts();
      if (!contacts.length) {
        alert('Nenhum contato válido encontrado.');
        return;
      }
      const contactsWithVariation = contacts.map((contact, index) => ({
        ...contact,
        variation_index: index % segments.length
      }));

      let startTimestamp = Date.now();
      if (!immediate) {
        const scheduledInput = document.getElementById('scheduledAt').value;
        if (!document.getElementById('immediateStart').checked && scheduledInput) {
          startTimestamp = new Date(scheduledInput).getTime();
        }
      }

      const payload = {
        action: 'create_campaign',
        campaign_name: document.getElementById('campaignName').value.trim() || 'Campanha rápida',
        message: segments.join('#'),
        message_variations: segments,
        message_variation_count: segments.length,
        instance_id: instanceId,
        delay_seconds: delay,
        mapping: mapping,
        placeholders: mapping,
        contacts: contactsWithVariation,
        start_at: Math.round(startTimestamp / 1000)
      };

      submissionButtons.forEach(btn => btn.disabled = true);
      if (feedbackMessage) {
        feedbackMessage.textContent = immediate ? 'Iniciando campanha...' : 'Agendando campanha...';
      }

      try {
        const response = await fetch('campanhas.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (!result.ok) {
          throw new Error(result.error || 'Erro interno');
        }
        if (feedbackMessage) {
          feedbackMessage.textContent = 'Campanha programada com sucesso! Atualizando lista...';
        }
        setTimeout(() => {
          window.location.reload();
        }, 1200);
      } catch (err) {
        if (feedbackMessage) {
          feedbackMessage.textContent = `Erro: ${err.message}`;
        }
      } finally {
        submissionButtons.forEach(btn => btn.disabled = false);
      }
    }

    if (scheduleButton) {
      scheduleButton.addEventListener('click', () => submitCampaign(false));
    }
    if (startButton) {
      startButton.addEventListener('click', () => submitCampaign(true));
    }
    async function refreshCampaignOverview() {
      if (!runningCampaignsList || !finishedCampaignsList) {
        return;
      }
      try {
        const response = await fetch(`campanhas.php?ajax_campaign_overview=1&_=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) {
          throw new Error('Falha ao buscar atualizações');
        }
        const data = await response.json();
        const runningCount = Number(data.runningCount ?? 0);
        const finishedCount = Number(data.finishedCount ?? 0);
        if (runningCountTotal) {
          runningCountTotal.textContent = runningCount;
        }
        if (runningCountCopy) {
          runningCountCopy.textContent = runningCount === 0 ? 'Nenhuma campanha em execução' : 'Campanhas em andamento';
        }
        if (runningCountLabel) {
          runningCountLabel.textContent = `${runningCount} em execução`;
        }
        if (finishedCountTotal) {
          finishedCountTotal.textContent = finishedCount;
        }
        if (finishedCountCopy) {
          finishedCountCopy.textContent = finishedCount === 0 ? 'Ainda não há histórico' : 'Campanhas concluídas recentemente';
        }
        if (finishedCountLabel) {
          finishedCountLabel.textContent = `${finishedCount} finalizadas`;
        }
        if (data.runningHtml) {
          runningCampaignsList.innerHTML = data.runningHtml;
        }
        if (data.finishedHtml) {
          finishedCampaignsList.innerHTML = data.finishedHtml;
        }
      } catch (err) {
        console.warn('Falha ao atualizar campanhas', err);
      }
    }

    if (runningCampaignsList && finishedCampaignsList) {
      refreshCampaignOverview();
      setInterval(refreshCampaignOverview, 5000);
    }

    const campaignActionMap = {
      pause: 'pause_campaign',
      resume: 'resume_campaign',
      delete: 'delete_campaign'
    };

    async function handleCampaignAction(event) {
      const button = event.target.closest('button[data-campaign-action]');
      if (!button) {
        return;
      }
      event.preventDefault();
      const actionKey = button.dataset.campaignAction;
      const campaignId = button.dataset.campaignId;
      const mappedAction = campaignActionMap[actionKey];
      if (!actionKey || !campaignId || !mappedAction) {
        return;
      }
      if (actionKey === 'delete' && !confirm('Deseja mesmo excluir esta campanha? Esta ação não pode ser desfeita.')) {
        return;
      }
      const feedbackCopy = actionKey === 'pause'
        ? 'Pausando campanha...'
        : actionKey === 'resume'
          ? 'Retomando campanha...'
          : 'Excluindo campanha...';
      button.disabled = true;
      if (feedbackMessage) {
        feedbackMessage.textContent = feedbackCopy;
      }
      try {
        const response = await fetch('campanhas.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: mappedAction, campaign_id: campaignId })
        });
        const result = await response.json();
        if (!result.ok) {
          throw new Error(result.error || 'Erro interno');
        }
        if (feedbackMessage) {
          feedbackMessage.textContent = result.message || 'Operação realizada com sucesso.';
        }
        await refreshCampaignOverview();
      } catch (err) {
        if (feedbackMessage) {
          feedbackMessage.textContent = `Erro: ${err.message}`;
        }
      } finally {
        button.disabled = false;
      }
    }

    document.addEventListener('click', handleCampaignAction);
  </script>
</body>
</html>
