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
    $dbPath = __DIR__ . '/webhook_messages.db';
    if (!file_exists($dbPath)) {
        return null;
    }
    $db = new SQLite3($dbPath);
    $stmt = $db->prepare('SELECT timestamp FROM messages WHERE from_number = :from ORDER BY timestamp DESC LIMIT 1');
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
    $dbPath = __DIR__ . '/webhook_messages.db';
    $db = new SQLite3($dbPath);

    $db->exec('CREATE TABLE IF NOT EXISTS messages (
        id TEXT PRIMARY KEY,
        from_number TEXT,
        type TEXT,
        content TEXT,
        caption TEXT,
        filename TEXT,
        timestamp TEXT,
        phone_number_id TEXT,
        display_phone_number TEXT,
        created_at TEXT
    )');

    $stmt = $db->prepare('INSERT OR REPLACE INTO messages
        (id, from_number, type, content, caption, filename, timestamp, phone_number_id, display_phone_number, created_at)
        VALUES (:id, :from_number, :type, :content, :caption, :filename, :timestamp, :phone_number_id, :display_phone_number, :created_at)');

    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $stmt->bindValue(':from_number', $from, SQLITE3_TEXT);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->bindValue(':caption', $caption, SQLITE3_TEXT);
    $stmt->bindValue(':filename', $filename, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':display_phone_number', $metadata['display_phone_number'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

    $stmt->execute();
    $db->close();
}

function saveDeliveryStatus($messageId, $recipientId, $timestamp, $metadata) {
    $dbPath = __DIR__ . '/webhook_messages.db';
    $db = new SQLite3($dbPath);
    
    $db->exec('CREATE TABLE IF NOT EXISTS delivery_status (
        message_id TEXT PRIMARY KEY,
        recipient_id TEXT,
        timestamp TEXT,
        phone_number_id TEXT,
        display_phone_number TEXT,
        created_at TEXT
    )');
    
    $stmt = $db->prepare('INSERT OR REPLACE INTO delivery_status 
        (message_id, recipient_id, timestamp, phone_number_id, display_phone_number, created_at) 
        VALUES (:message_id, :recipient_id, :timestamp, :phone_number_id, :display_phone_number, :created_at)');
    
    $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
    $stmt->bindValue(':recipient_id', $recipientId, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':display_phone_number', $metadata['display_phone_number'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
    
    $stmt->execute();
    $db->close();
}

function saveReadStatus($messageId, $timestamp, $watermark, $metadata) {
    $dbPath = __DIR__ . '/webhook_messages.db';
    $db = new SQLite3($dbPath);
    
    $db->exec('CREATE TABLE IF NOT EXISTS read_status (
        message_id TEXT PRIMARY KEY,
        timestamp TEXT,
        watermark TEXT,
        phone_number_id TEXT,
        display_phone_number TEXT,
        created_at TEXT
    )');
    
    $stmt = $db->prepare('INSERT OR REPLACE INTO read_status 
        (message_id, timestamp, watermark, phone_number_id, display_phone_number, created_at) 
        VALUES (:message_id, :timestamp, :watermark, :phone_number_id, :display_phone_number, :created_at)');
    
    $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    $stmt->bindValue(':watermark', $watermark, SQLITE3_TEXT);
    $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':display_phone_number', $metadata['display_phone_number'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
    
    $stmt->execute();
    $db->close();
}

function saveReaction($messageId, $emoji, $from, $timestamp, $metadata) {
    $dbPath = __DIR__ . '/webhook_messages.db';
    $db = new SQLite3($dbPath);

    $db->exec('CREATE TABLE IF NOT EXISTS reactions (
        id TEXT PRIMARY KEY,
        message_id TEXT,
        emoji TEXT,
        from_number TEXT,
        timestamp TEXT,
        phone_number_id TEXT,
        display_phone_number TEXT,
        created_at TEXT
    )');

    $id = uniqid('reaction_', true);

    $stmt = $db->prepare('INSERT INTO reactions
        (id, message_id, emoji, from_number, timestamp, phone_number_id, display_phone_number, created_at)
        VALUES (:id, :message_id, :emoji, :from_number, :timestamp, :phone_number_id, :display_phone_number, :created_at)');

    $stmt->bindValue(':id', $id, SQLITE3_TEXT);
    $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
    $stmt->bindValue(':emoji', $emoji, SQLITE3_TEXT);
    $stmt->bindValue(':from_number', $from, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':display_phone_number', $metadata['display_phone_number'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

    $stmt->execute();
    $db->close();
}

function saveStatus($messageId, $status, $timestamp, $recipientId, $metadata) {
    $dbPath = __DIR__ . '/webhook_messages.db';
    $db = new SQLite3($dbPath);

    $db->exec('CREATE TABLE IF NOT EXISTS message_statuses (
        message_id TEXT,
        status TEXT,
        timestamp TEXT,
        recipient_id TEXT,
        phone_number_id TEXT,
        display_phone_number TEXT,
        created_at TEXT,
        PRIMARY KEY (message_id, status)
    )');

    $stmt = $db->prepare('INSERT OR REPLACE INTO message_statuses
        (message_id, status, timestamp, recipient_id, phone_number_id, display_phone_number, created_at)
        VALUES (:message_id, :status, :timestamp, :recipient_id, :phone_number_id, :display_phone_number, :created_at)');

    $stmt->bindValue(':message_id', $messageId, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status, SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    $stmt->bindValue(':recipient_id', $recipientId, SQLITE3_TEXT);
    $stmt->bindValue(':phone_number_id', $metadata['phone_number_id'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':display_phone_number', $metadata['display_phone_number'] ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);

    $stmt->execute();
    $db->close();
}
