<?php

// retorno.php - Meta API Webhook Receiver for WhatsApp
// Handles incoming webhook events from Meta WhatsApp Business API

if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('retorno.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

header("Content-Type: application/json");

debug_log('Webhook request received');

$input = file_get_contents('php://input');
debug_log('Raw input: ' . substr($input, 0, 500) . '...');

$data = json_decode($input, true);

// Signature verification for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_array($data) && isset($data['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'])) {
        $phoneNumberId = $data['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'];
        $instance = findInstanceByTelephoneId($phoneNumberId);
        $appSecret = $instance ? ($instance['meta']['app_secret'] ?? null) : null;

        if ($appSecret) {
            $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
            if (strpos($signature, 'sha256=') === 0) {
                $expectedSignature = 'sha256=' . hash_hmac('sha256', $input, $appSecret);
                if (!hash_equals($signature, $expectedSignature)) {
                    debug_log('Signature verification failed');
                    http_response_code(401);
                    echo json_encode(['error' => 'Invalid signature']);
                    exit;
                }
            } else {
                debug_log('Missing or invalid signature header');
                http_response_code(401);
                echo json_encode(['error' => 'Missing signature']);
                exit;
            }
        } else {
            debug_log('App secret not found for instance');
            http_response_code(500);
            echo json_encode(['error' => 'Configuration error']);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    debug_log('GET request - verification mode');
    
    $mode = isset($_GET['hub_mode']) ? $_GET['hub_mode'] : '';
    $token = isset($_GET['hub_verify_token']) ? $_GET['hub_verify_token'] : '';
    $challenge = isset($_GET['hub_challenge']) ? $_GET['hub_challenge'] : '';
    
    $VERIFY_TOKEN = getenv('META_VERIFY_TOKEN') ?: 'janeri-whatsapp-2024';
    
    if ($mode && $token) {
        if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
            debug_log('Verification successful');
            http_response_code(200);
            echo $challenge;
            exit;
        } else {
            debug_log('Verification failed - invalid token');
            http_response_code(403);
            echo json_encode(['error' => 'Invalid verification token']);
            exit;
        }
    } else {
        debug_log('Verification failed - missing parameters');
        http_response_code(400);
        echo json_encode(['error' => 'Missing required parameters']);
        exit;
    }
}

if (!is_array($data) || !isset($data['object'])) {
    debug_log('Invalid request - missing object field');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request format']);
    exit;
}

if ($data['object'] !== 'whatsapp_business_account') {
    debug_log('Invalid object type: ' . $data['object']);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid object type']);
    exit;
}

try {
    foreach ($data['entry'] as $entry) {
        $metadata = $entry['changes'][0]['value']['metadata'] ?? [];
        $phoneNumberId = $metadata['phone_number_id'] ?? '';
        $displayPhoneNumber = $metadata['display_phone_number'] ?? '';
        
        debug_log("Processing entry for phone: " . $displayPhoneNumber);
        
        foreach ($entry['changes'] as $change) {
            $field = $change['field'] ?? '';
            $value = $change['value'] ?? [];
            
            debug_log("Field: " . $field);
            
            switch ($field) {
                case 'messages':
                    handleMessages($value['messages'] ?? [], $metadata);
                    break;
                    
                case 'message_deliveries':
                    handleDeliveries($value['deliveries'] ?? [], $metadata);
                    break;
                    
                case 'message_reads':
                    handleReads($value['reads'] ?? [], $metadata);
                    break;
                    
                case 'message_reactions':
                    handleReactions($value['reactions'] ?? [], $metadata);
                    break;

                case 'statuses':
                    handleStatuses($value['statuses'] ?? [], $metadata);
                    break;

                default:
                    debug_log("Unknown field: " . $field);
                    break;
            }
        }
    }
    
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'Webhook processed successfully']);
    
} catch (Exception $e) {
    debug_log('Error processing webhook: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function handleMessages($messages, $metadata) {
    $phoneNumberId = $metadata['phone_number_id'] ?? '';
    $displayPhoneNumber = $metadata['display_phone_number'] ?? '';
    $instance = findInstanceByTelephoneId($displayPhoneNumber);
    $accessToken = $instance ? ($instance['meta']['access_token'] ?? null) : null;
    $instanceId = $instance ? $instance['instance_id'] : null;
    
    // Add instance_id to metadata for use in saveMessage
    $metadata['instance_id'] = $instanceId;

    foreach ($messages as $message) {
        $from = $message['from'] ?? '';
        $id = $message['id'] ?? '';
        $type = $message['type'] ?? '';
        $timestamp = isset($message['timestamp'])
            ? date('Y-m-d H:i:s', $message['timestamp'])
            : date('Y-m-d H:i:s');

        debug_log("Message from: $from, type: $type, id: $id, time: $timestamp");

        switch ($type) {
            case 'text':
                $body = $message['text']['body'] ?? '';
                debug_log("Text message: " . $body);

                $lastTimestamp = getLastCustomerMessageTimestamp($from);
                $within24h = isWithin24Hours($lastTimestamp);

                if (!$within24h) {
                    debug_log("Message outside 24h window, rejecting free-text");
                    // Send rejection message with template options
                    if ($accessToken && $instanceId) {
                        $templateList = getApprovedTemplates($instanceId);
                        $rejectionMessage = "Mensagens depois de 24hs do envio do cliente não são aceitas. Por favor, só envie mensagens até 24hs do contato do cliente, ou então use a função para enviar as mensagens pré-aprovadas abaixo:\n\n" . implode("\n", $templateList);
                        sendTextMessage($phoneNumberId, $accessToken, $from, $rejectionMessage);
                        debug_log("Sent rejection message with templates");
                    }
                } else {
                    debug_log("Message within 24h window, processing normally");
                }

                saveMessage($from, $id, $type, $body, $timestamp, $metadata);
                
                // Trigger AI processing for text messages within 24h window
                if ($type === 'text' && !empty($body) && $instance && $instanceId && $within24h) {
                    processAIResponse($instance, $from, $body, $metadata);
                }
                break;
                
            case 'image':
                $imageId = $message['image']['id'] ?? '';
                $caption = $message['image']['caption'] ?? '';
                debug_log("Image message: ID=$imageId, caption=" . $caption);
                saveMessage($from, $id, $type, $imageId, $timestamp, $metadata, $caption);
                break;
                
            case 'audio':
                $audioId = $message['audio']['id'] ?? '';
                debug_log("Audio message: ID=$audioId");
                saveMessage($from, $id, $type, $audioId, $timestamp, $metadata);
                break;
                
            case 'video':
                $videoId = $message['video']['id'] ?? '';
                $caption = $message['video']['caption'] ?? '';
                debug_log("Video message: ID=$videoId, caption=" . $caption);
                saveMessage($from, $id, $type, $videoId, $timestamp, $metadata, $caption);
                break;
                
            case 'document':
                $documentId = $message['document']['id'] ?? '';
                $filename = $message['document']['filename'] ?? '';
                $caption = $message['document']['caption'] ?? '';
                debug_log("Document message: ID=$documentId, filename=$filename, caption=" . $caption);
                saveMessage($from, $id, $type, $documentId, $timestamp, $metadata, $caption, $filename);
                break;
                
            default:
                debug_log("Unknown message type: $type");
                break;
        }
    }
}

function handleDeliveries($deliveries, $metadata) {
    foreach ($deliveries as $delivery) {
        $messageId = $delivery['id'] ?? '';
        $timestamp = isset($delivery['timestamp']) 
            ? date('Y-m-d H:i:s', $delivery['timestamp']) 
            : date('Y-m-d H:i:s');
        $recipientId = $delivery['recipient_id'] ?? '';
        
        debug_log("Delivery: Message ID=$messageId, Recipient=$recipientId, Time=$timestamp");
        
        saveDeliveryStatus($messageId, $recipientId, $timestamp, $metadata);
    }
}

function handleReads($reads, $metadata) {
    foreach ($reads as $read) {
        $messageId = $read['id'] ?? '';
        $timestamp = isset($read['timestamp']) 
            ? date('Y-m-d H:i:s', $read['timestamp']) 
            : date('Y-m-d H:i:s');
        $watermark = isset($read['watermark']) 
            ? date('Y-m-d H:i:s', $read['watermark']) 
            : date('Y-m-d H:i:s');
            
        debug_log("Read: Message ID=$messageId, Watermark=$watermark, Time=$timestamp");
        
        saveReadStatus($messageId, $timestamp, $watermark, $metadata);
    }
}

function handleReactions($reactions, $metadata) {
    foreach ($reactions as $reaction) {
        $messageId = $reaction['message_id'] ?? '';
        $emoji = $reaction['emoji'] ?? '';
        $timestamp = isset($reaction['timestamp'])
            ? date('Y-m-d H:i:s', $reaction['timestamp'])
            : date('Y-m-d H:i:s');
        $from = $reaction['from'] ?? '';

        debug_log("Reaction: Message ID=$messageId, Emoji=$emoji, From=$from, Time=$timestamp");

        saveReaction($messageId, $emoji, $from, $timestamp, $metadata);
    }
}

function handleStatuses($statuses, $metadata) {
    foreach ($statuses as $status) {
        $messageId = $status['id'] ?? '';
        $statusType = $status['status'] ?? '';
        $timestamp = isset($status['timestamp'])
            ? date('Y-m-d H:i:s', $status['timestamp'])
            : date('Y-m-d H:i:s');
        $recipientId = $status['recipient_id'] ?? '';

        debug_log("Status: Message ID=$messageId, Status=$statusType, Recipient=$recipientId, Time=$timestamp");

        saveStatus($messageId, $statusType, $timestamp, $recipientId, $metadata);
    }
}

function findInstanceByTelephoneId($telephoneId) {
    require_once __DIR__ . '/instance_data.php';
    $instances = loadInstancesFromDatabase();
    foreach ($instances as $instance) {
        if (($instance['meta']['telephone_id'] ?? null) === $telephoneId) {
            return $instance;
        }
    }
    return null;
}

function getLastCustomerMessageTimestamp($fromNumber) {
    $dbPath = __DIR__ . '/chat_data.db';
    if (!file_exists($dbPath)) {
        return null;
    }
    try {
        $db = new SQLite3($dbPath);
        $stmt = $db->prepare('SELECT timestamp FROM messages WHERE remote_jid = :from ORDER BY timestamp DESC LIMIT 1');
        $stmt->bindValue(':from', $fromNumber, SQLITE3_TEXT);
        $result = $stmt->execute();
        $timestamp = null;
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $timestamp = $row['timestamp'];
        }
        $result->finalize();
        $stmt->close();
        $db->close();
        return $timestamp;
    } catch (Exception $e) {
        debug_log('Error getting last customer message timestamp: ' . $e->getMessage());
        return null;
    }
}

function isWithin24Hours($timestamp) {
    if (!$timestamp) {
        return false;
    }
    $lastTime = strtotime($timestamp);
    $currentTime = time();
    $diff = $currentTime - $lastTime;
    return $diff <= 24 * 3600;
}

function sendTextMessage($phoneNumberId, $accessToken, $to, $message) {
    $url = "https://graph.facebook.com/v22.0/{$phoneNumberId}/messages";
    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $message]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

function getApprovedTemplates($instanceId) {
    require_once __DIR__ . '/instance_data.php';
    $templates = listMetaTemplates($instanceId, 'APPROVED');
    $templateList = [];
    foreach ($templates as $template) {
        $name = $template['template_name'];
        // Try to get a sample body text
        $body = '';
        if (isset($template['components'])) {
            foreach ($template['components'] as $component) {
                if (isset($component['type']) && $component['type'] === 'BODY' && isset($component['text'])) {
                    $body = $component['text'];
                    break;
                }
            }
        }
        $templateList[] = "Template|{$name}|{$body}";
    }
    return $templateList;
}

function saveMessage($from, $id, $type, $content, $timestamp, $metadata, $caption = '', $filename = '') {
    // Connect to main chat database (chat_data.db) instead of separate webhook_messages.db
    $dbPath = __DIR__ . '/chat_data.db';
    
    // Get instance_id from metadata
    $instanceId = $metadata['instance_id'] ?? '';
    $phoneNumberId = $metadata['phone_number_id'] ?? '';
    $displayPhoneNumber = $metadata['display_phone_number'] ?? '';
    
    // Format remote_jid as WhatsApp JID (from@s.whatsapp.net)
    // Meta API sends phone numbers, convert to WhatsApp format
    $remoteJid = $from;
    if (strpos($from, '@') === false) {
        $remoteJid = $from . '@s.whatsapp.net';
    }
    
    // Build metadata JSON with message details
    $messageMetadata = [
        'message_type' => $type,
        'caption' => $caption,
        'filename' => $filename,
        'phone_number_id' => $phoneNumberId,
        'display_phone_number' => $displayPhoneNumber,
        'meta_message_id' => $id
    ];
    $metadataJson = json_encode($messageMetadata);
    
    try {
        $db = new SQLite3($dbPath);
        
        // Use INSERT OR REPLACE to handle potential duplicates
        $stmt = $db->prepare('INSERT INTO messages 
            (instance_id, remote_jid, session_id, role, content, direction, metadata, wa_message_id, timestamp, created_at)
            VALUES (:instance_id, :remote_jid, :session_id, :role, :content, :direction, :metadata, :wa_message_id, :timestamp, :created_at)');

        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote_jid', $remoteJid, SQLITE3_TEXT);
        $stmt->bindValue(':session_id', '', SQLITE3_TEXT);
        $stmt->bindValue(':role', 'user', SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':direction', 'inbound', SQLITE3_TEXT);
        $stmt->bindValue(':metadata', $metadataJson, SQLITE3_TEXT);
        $stmt->bindValue(':wa_message_id', $id, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

        $result = $stmt->execute();
        if (!$result) {
            debug_log('Error saving message: ' . $db->lastErrorMsg());
        } else {
            debug_log('Message saved successfully to chat_data.db');
        }
        
        $result->finalize();
        $stmt->close();
        $db->close();
        
    } catch (Exception $e) {
        debug_log('Exception saving message: ' . $e->getMessage());
    }
}

function saveDeliveryStatus($messageId, $recipientId, $timestamp, $metadata) {
    // Log delivery status to main database for tracking
    $dbPath = __DIR__ . '/chat_data.db';
    $instanceId = $metadata['instance_id'] ?? '';
    
    try {
        $db = new SQLite3($dbPath);
        
        // Create meta_webhook_events table if not exists
        $db->exec('CREATE TABLE IF NOT EXISTS meta_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id TEXT NOT NULL,
            phone_number_id TEXT,
            event_type TEXT NOT NULL,
            event_data TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed INTEGER NOT NULL DEFAULT 0
        )');
        
        $stmt = $db->prepare('INSERT INTO meta_webhook_events 
            (instance_id, phone_number_id, event_type, event_data, timestamp, processed)
            VALUES (:instance_id, :phone_number_id, :event_type, :event_data, :timestamp, 1)');
        
        $eventData = json_encode([
            'message_id' => $messageId,
            'recipient_id' => $recipientId,
            'status' => 'delivered'
        ]);
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':event_type', 'message_delivery', SQLITE3_TEXT);
        $stmt->bindValue(':event_data', $eventData, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        
        $stmt->execute();
        debug_log('Delivery status logged to chat_data.db');
        
        $stmt->close();
        $db->close();
        
    } catch (Exception $e) {
        debug_log('Error saving delivery status: ' . $e->getMessage());
    }
}

function saveReadStatus($messageId, $timestamp, $watermark, $metadata) {
    // Log read status to main database for tracking
    $dbPath = __DIR__ . '/chat_data.db';
    $instanceId = $metadata['instance_id'] ?? '';
    
    try {
        $db = new SQLite3($dbPath);
        
        $db->exec('CREATE TABLE IF NOT EXISTS meta_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id TEXT NOT NULL,
            phone_number_id TEXT,
            event_type TEXT NOT NULL,
            event_data TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed INTEGER NOT NULL DEFAULT 0
        )');
        
        $stmt = $db->prepare('INSERT INTO meta_webhook_events 
            (instance_id, phone_number_id, event_type, event_data, timestamp, processed)
            VALUES (:instance_id, :phone_number_id, :event_type, :event_data, :timestamp, 1)');
        
        $eventData = json_encode([
            'message_id' => $messageId,
            'watermark' => $watermark
        ]);
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':event_type', 'message_read', SQLITE3_TEXT);
        $stmt->bindValue(':event_data', $eventData, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        
        $stmt->execute();
        debug_log('Read status logged to chat_data.db');
        
        $stmt->close();
        $db->close();
        
    } catch (Exception $e) {
        debug_log('Error saving read status: ' . $e->getMessage());
    }
}

function saveReaction($messageId, $emoji, $from, $timestamp, $metadata) {
    // Log reaction to main database for tracking
    $dbPath = __DIR__ . '/chat_data.db';
    $instanceId = $metadata['instance_id'] ?? '';
    
    try {
        $db = new SQLite3($dbPath);
        
        $db->exec('CREATE TABLE IF NOT EXISTS meta_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id TEXT NOT NULL,
            phone_number_id TEXT,
            event_type TEXT NOT NULL,
            event_data TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed INTEGER NOT NULL DEFAULT 0
        )');
        
        $stmt = $db->prepare('INSERT INTO meta_webhook_events 
            (instance_id, phone_number_id, event_type, event_data, timestamp, processed)
            VALUES (:instance_id, :phone_number_id, :event_type, :event_data, :timestamp, 1)');
        
        $eventData = json_encode([
            'message_id' => $messageId,
            'emoji' => $emoji,
            'from' => $from
        ]);
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':event_type', 'message_reaction', SQLITE3_TEXT);
        $stmt->bindValue(':event_data', $eventData, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        
        $stmt->execute();
        debug_log('Reaction logged to chat_data.db');
        
        $stmt->close();
        $db->close();
        
    } catch (Exception $e) {
        debug_log('Error saving reaction: ' . $e->getMessage());
    }
}

function saveStatus($messageId, $status, $timestamp, $recipientId, $metadata) {
    // Log status to main database for tracking
    $dbPath = __DIR__ . '/chat_data.db';
    $instanceId = $metadata['instance_id'] ?? '';
    
    try {
        $db = new SQLite3($dbPath);
        
        $db->exec('CREATE TABLE IF NOT EXISTS meta_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id TEXT NOT NULL,
            phone_number_id TEXT,
            event_type TEXT NOT NULL,
            event_data TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed INTEGER NOT NULL DEFAULT 0
        )');
        
        $stmt = $db->prepare('INSERT INTO meta_webhook_events 
            (instance_id, phone_number_id, event_type, event_data, timestamp, processed)
            VALUES (:instance_id, :phone_number_id, :event_type, :event_data, :timestamp, 1)');
        
        $eventData = json_encode([
            'message_id' => $messageId,
            'status' => $status,
            'recipient_id' => $recipientId
        ]);
        
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':event_type', 'message_status', SQLITE3_TEXT);
        $stmt->bindValue(':event_data', $eventData, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
        
        $stmt->execute();
        debug_log('Status logged to chat_data.db');
        
        $stmt->close();
        $db->close();
        
    } catch (Exception $e) {
        debug_log('Error saving status: ' . $e->getMessage());
    }
}

/**
 * Get AI configuration from database for an instance
 * @param string $instanceId - Instance ID
 * @return array|null - AI config or null if not found/disabled
 */
function getAIConfig($instanceId) {
    require_once __DIR__ . '/config/database.php';
    
    try {
        $dbPath = __DIR__ . '/chat_data.db';
        $db = new SQLite3($dbPath);
        
        // Query AI settings from the settings table
        $keys = ['ai_enabled', 'ai_provider', 'ai_model', 'ai_system_prompt', 'ai_assistant_prompt', 
                 'gemini_api_key', 'gemini_instruction', 'openai_api_key', 'openai_mode',
                 'ai_history_limit', 'ai_temperature', 'ai_max_tokens'];
        
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT key, value FROM settings WHERE instance_id = ? AND key IN ($placeholders)");
        $params = array_merge([$instanceId], $keys);
        
        foreach ($params as $i => $param) {
            $stmt->bindValue($i + 1, $param);
        }
        
        $result = $stmt->execute();
        $config = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $config[$row['key']] = $row['value'];
        }
        $stmt->close();
        $db->close();
        
        if (empty($config)) {
            debug_log('No AI config found for instance: ' . $instanceId);
            return null;
        }
        
        // Check if AI is enabled
        $enabled = $config['ai_enabled'] ?? '0';
        if ($enabled !== 'true' && $enabled !== '1') {
            debug_log('AI is disabled for instance: ' . $instanceId);
            return null;
        }
        
        // Map to expected format for the rest of the code
        return [
            'enabled' => $enabled,
            'provider' => $config['ai_provider'] ?? 'gemini',
            'model' => $config['ai_model'] ?? 'gemini-2.0-flash',
            'system_prompt' => $config['ai_system_prompt'] ?? '',
            'assistant_prompt' => $config['ai_assistant_prompt'] ?? '',
            'gemini_api_key' => $config['gemini_api_key'] ?? '',
            'gemini_instruction' => $config['gemini_instruction'] ?? '',
            'openai_api_key' => $config['openai_api_key'] ?? '',
            'openai_mode' => $config['openai_mode'] ?? 'responses',
            'history_limit' => intval($config['ai_history_limit'] ?? 20),
            'temperature' => floatval($config['ai_temperature'] ?? 0.6),
            'max_tokens' => intval($config['ai_max_tokens'] ?? 2000)
        ];
    } catch (Exception $e) {
        debug_log('Error getting AI config: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get conversation history from database for context
 * @param string $instanceId - Instance ID
 * @param string $remoteJid - Remote JID (phone number)
 * @param int $limit - Number of messages to fetch
 * @return array - Array of message objects with role and content
 */
function getConversationHistory($instanceId, $remoteJid, $limit = 10) {
    $dbPath = __DIR__ . '/chat_data.db';
    $history = [];
    
    try {
        $db = new SQLite3($dbPath);
        $stmt = $db->prepare('
            SELECT role, content, direction 
            FROM messages 
            WHERE instance_id = :instance_id 
            AND remote_jid LIKE :remote_jid
            ORDER BY timestamp DESC 
            LIMIT :limit'
        );
        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote_jid', '%' . $remoteJid . '%', SQLITE3_TEXT);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Convert direction to role
            $role = ($row['direction'] === 'inbound' || $row['role'] === 'user') ? 'user' : 'model';
            $history[] = [
                'role' => $role,
                'content' => $row['content']
            ];
        }
        
        $result->finalize();
        $stmt->close();
        $db->close();
        
        // Reverse to get chronological order
        return array_reverse($history);
    } catch (Exception $e) {
        debug_log('Error getting conversation history: ' . $e->getMessage());
        return [];
    }
}

/**
 * Process incoming message with AI and send response
 * @param array $instance - Instance data
 * @param string $from - Sender phone number
 * @param string $messageBody - Message text
 * @param array $metadata - Message metadata
 */
function processAIResponse($instance, $from, $messageBody, $metadata) {
    $instanceId = $instance['instance_id'] ?? null;
    if (!$instanceId) {
        debug_log('No instance_id for AI processing');
        return;
    }
    
    debug_log('Starting AI processing for instance: ' . $instanceId);
    
    // Get AI configuration
    $aiConfig = getAIConfig($instanceId);
    if (!$aiConfig) {
        debug_log('AI not configured or disabled for this instance');
        return;
    }
    
    $provider = $aiConfig['provider'] ?? 'gemini';
    debug_log('AI Provider: ' . $provider);
    
    // Route to appropriate provider
    if ($provider === 'gemini') {
        processGeminiResponse($instance, $from, $messageBody, $aiConfig);
    } else {
        debug_log('Unsupported AI provider: ' . $provider);
    }
}

/**
 * Process message with Gemini API
 * @param array $instance - Instance data
 * @param string $from - Sender phone number
 * @param string $messageBody - Message text
 * @param array $aiConfig - AI configuration
 */
function processGeminiResponse($instance, $from, $messageBody, $aiConfig) {
    $instanceId = $instance['instance_id'];
    $phoneNumberId = $instance['meta']['telephone_id'] ?? '';
    $accessToken = $instance['meta']['access_token'] ?? '';
    
    $geminiApiKey = $aiConfig['gemini_api_key'] ?? '';
    if (empty($geminiApiKey)) {
        debug_log('Gemini API key not configured');
        return;
    }
    
    // Format remote JID for history query
    $remoteJid = $from;
    if (strpos($from, '@') === false) {
        $remoteJid = $from . '@s.whatsapp.net';
    }
    
    // Get conversation history
    $historyLimit = isset($aiConfig['history_limit']) ? intval($aiConfig['history_limit']) : 10;
    $history = getConversationHistory($instanceId, $remoteJid, $historyLimit);
    debug_log('Retrieved ' . count($history) . ' history messages');
    
    // Build system instruction
    $systemPrompt = $aiConfig['system_prompt'] ?? '';
    $geminiInstruction = $aiConfig['gemini_instruction'] ?? '';
    $systemInstruction = $systemPrompt;
    if (!empty($geminiInstruction)) {
        $systemInstruction .= "\n\n" . $geminiInstruction;
    }
    
    if (empty($systemInstruction)) {
        $systemInstruction = "Você é um assistente virtual do sistema Maestro.\nSua função é ajudar os usuários com suas dúvidas e solicitações.\nResponda de forma clara, objetiva e profissional em português brasileiro.";
    }
    
    // Build messages for Gemini API
    $messages = [];
    
    // Add system instruction
    $messages[] = [
        'role' => 'user',
        'parts' => [['text' => $systemInstruction]]
    ];
    
    // Add history
    foreach ($history as $msg) {
        $messages[] = [
            'role' => ($msg['role'] === 'user') ? 'user' : 'model',
            'parts' => [['text' => $msg['content']]]
        ];
    }
    
    // Add current message
    $messages[] = [
        'role' => 'user',
        'parts' => [['text' => $messageBody]]
    ];
    
    // Prepare API request
    $model = $aiConfig['model'] ?? 'gemini-1.5-flash';
    $temperature = floatval($aiConfig['temperature'] ?? 0.7);
    $maxTokens = intval($aiConfig['max_tokens'] ?? 600);
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$geminiApiKey}";
    
    $payload = [
        'contents' => $messages,
        'generationConfig' => [
            'temperature' => $temperature,
            'maxOutputTokens' => $maxTokens
        ]
    ];
    
    debug_log('Calling Gemini API with model: ' . $model);
    
    // Make API call
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        debug_log('Gemini API cURL error: ' . $curlError);
        return;
    }
    
    if ($httpCode !== 200) {
        debug_log('Gemini API error. HTTP: ' . $httpCode . ', Response: ' . substr($response, 0, 500));
        return;
    }
    
    $responseData = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        debug_log('Failed to parse Gemini response: ' . json_last_error_msg());
        return;
    }
    
    // Extract response text
    $candidates = $responseData['candidates'] ?? [];
    if (empty($candidates)) {
        debug_log('Gemini response has no candidates');
        return;
    }
    
    $aiText = $candidates[0]['content']['parts'][0]['text'] ?? '';
    
    if (empty($aiText)) {
        debug_log('Gemini returned empty response');
        return;
    }
    
    debug_log('AI Response: ' . substr($aiText, 0, 100) . '...');
    
    // Send response via Meta API
    if (!empty($accessToken) && !empty($phoneNumberId)) {
        $sent = sendTextMessage($phoneNumberId, $accessToken, $from, $aiText);
        if ($sent) {
            debug_log('AI response sent successfully');
            
            // Save AI response to database
            $responseId = 'ai_' . time() . '_' . rand(1000, 9999);
            $responseMetadata = [
                'message_type' => 'text',
                'ai_provider' => 'gemini',
                'ai_model' => $model,
                'ai_status' => 'success'
            ];
            saveAIResponseMessage($instanceId, $remoteJid, $responseId, $aiText, $responseMetadata);
        } else {
            debug_log('Failed to send AI response');
        }
    } else {
        debug_log('Missing access_token or phone_number_id for sending AI response');
    }
}

/**
 * Save AI response message to database
 * @param string $instanceId - Instance ID
 * @param string $remoteJid - Remote JID
 * @param string $messageId - Message ID
 * @param string $content - Message content
 * @param array $metadata - Additional metadata
 */
function saveAIResponseMessage($instanceId, $remoteJid, $messageId, $content, $metadata = []) {
    $dbPath = __DIR__ . '/chat_data.db';
    
    try {
        $db = new SQLite3($dbPath);
        
        $stmt = $db->prepare('INSERT INTO messages 
            (instance_id, remote_jid, session_id, role, content, direction, metadata, wa_message_id, timestamp, created_at)
            VALUES (:instance_id, :remote_jid, :session_id, :role, :content, :direction, :metadata, :wa_message_id, :timestamp, :created_at)');

        $stmt->bindValue(':instance_id', $instanceId, SQLITE3_TEXT);
        $stmt->bindValue(':remote_jid', $remoteJid, SQLITE3_TEXT);
        $stmt->bindValue(':session_id', '', SQLITE3_TEXT);
        $stmt->bindValue(':role', 'assistant', SQLITE3_TEXT);
        $stmt->bindValue(':content', $content, SQLITE3_TEXT);
        $stmt->bindValue(':direction', 'outbound', SQLITE3_TEXT);
        $stmt->bindValue(':metadata', json_encode($metadata), SQLITE3_TEXT);
        $stmt->bindValue(':wa_message_id', $messageId, SQLITE3_TEXT);
        $stmt->bindValue(':timestamp', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

        $result = $stmt->execute();
        
        $result->finalize();
        $stmt->close();
        $db->close();
        
        debug_log('AI response saved to database');
        
    } catch (Exception $e) {
        debug_log('Error saving AI response: ' . $e->getMessage());
    }
}
