<?php
/**
 * WebSocket Proxy for WhatsApp QR Code Connections
 * Handles bidirectional WebSocket communication between external clients and internal Node.js instances
 */

if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - WS-PROXY: ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

debug_log('WebSocket proxy request: ' . $_SERVER['REQUEST_URI']);

require_once __DIR__ . '/instance_data.php';

// Get instance ID from query parameters or path
$instanceId = $_GET['instance'] ?? null;
if (!$instanceId) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (preg_match('/\/ws\/([^\/]+)/', $path, $matches)) {
        $instanceId = $matches[1];
    }
}

if (!$instanceId) {
    http_response_code(404);
    die(json_encode(["error" => "Instance not found"]));
}

$instance = loadInstanceRecordFromDatabase($instanceId);
if (!$instance) {
    http_response_code(404);
    die(json_encode(["error" => "Instance not found"]));
}

$port = $instance['port'] ?? null;
if (!$port) {
    http_response_code(500);
    die(json_encode(["error" => "Instance port not configured"]));
}

$internalWsUrl = "ws://127.0.0.1:$port/ws";

debug_log("Setting up WebSocket proxy for instance $instanceId on port $port");

// Handle WebSocket upgrade
if (!isset($_SERVER['HTTP_UPGRADE']) || strtolower($_SERVER['HTTP_UPGRADE']) !== 'websocket') {
    http_response_code(400);
    die(json_encode(["error" => "WebSocket upgrade required"]));
}

// WebSocket implementation using ReactPHP would be ideal, but for PHP 7.4 compatibility,
// we'll create a polling-based proxy that bridges HTTP and WebSocket connections
handleWebSocketProxy($internalWsUrl, $instanceId, $instance);

/**
 * Handle WebSocket proxy connections
 * This is a simplified implementation that bridges HTTP long-polling to simulate WebSocket behavior
 */
function handleWebSocketProxy($internalWsUrl, $instanceId, $instance) {
    
    debug_log("Establishing WebSocket proxy for $instanceId");
    
    // Set headers for WebSocket
    header("HTTP/1.1 101 Switching Protocols");
    header("Upgrade: websocket");
    header("Connection: Upgrade");
    header("Sec-WebSocket-Accept: " . base64_encode(pack('H*', sha1($_SERVER['HTTP_SEC_WEBSOCKET_KEY'] . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"))));
    
    // Create a bridge between client and internal WhatsApp WebSocket
    $clientConnected = true;
    $internalConnected = false;
    
    // Test connection to internal WebSocket
    $ch = curl_init($internalWsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: WhatsApp-WebProxy/1.0"
    ]);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if (!$error) {
        $internalConnected = true;
        debug_log("Internal WebSocket connection established for $instanceId");
    } else {
        debug_log("Internal WebSocket connection failed for $instanceId: $error");
    }
    
    // Send initial connection message
    if ($clientConnected) {
        $initialMessage = json_encode([
            "type" => "connection",
            "status" => $internalConnected ? "connected" : "connecting",
            "instance" => $instanceId,
            "timestamp" => time(),
            "message" => $internalConnected ? 
                "Successfully connected to WhatsApp instance" : 
                "Connecting to WhatsApp instance, please wait..."
        ]);
        
        sendWebSocketMessage($initialMessage);
    }
    
    // Maintain connection and handle messages
    $startTime = time();
    $maxDuration = 300; // 5 minutes max connection
    
    while (time() - $startTime < $maxDuration && $clientConnected) {
        // Check for incoming messages from client (simulated)
        // In a real implementation, this would handle actual WebSocket frames
        
        // Poll internal instance for status updates
        $statusUpdate = checkInternalStatus($internalWsUrl, $instanceId);
        if ($statusUpdate) {
            sendWebSocketMessage(json_encode($statusUpdate));
        }
        
        // Send heartbeat
        $heartbeat = json_encode([
            "type" => "heartbeat",
            "timestamp" => time(),
            "instance" => $instanceId
        ]);
        sendWebSocketMessage($heartbeat);
        
        // Small delay to prevent excessive polling
        usleep(500000); // 0.5 seconds
    }
    
    debug_log("WebSocket proxy session ended for $instanceId");
}

/**
 * Send WebSocket message to client
 */
function sendWebSocketMessage($message) {
    // In a real WebSocket implementation, this would send proper WebSocket frames
    // For this PHP implementation, we'll use Server-Sent Events as a fallback
    header("Content-Type: text/event-stream");
    header("Cache-Control: no-cache");
    header("Connection: keep-alive");
    header("Access-Control-Allow-Origin: *");
    
    echo "data: " . $message . "\n\n";
    flush();
}

/**
 * Check internal instance status
 */
function checkInternalStatus($internalWsUrl, $instanceId) {
    $ch = curl_init($internalWsUrl . '/status');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if (!$error && $response) {
        $data = json_decode($response, true);
        if ($data) {
            return [
                "type" => "status",
                "instance" => $instanceId,
                "data" => $data,
                "timestamp" => time()
            ];
        }
    }
    
    return null;
}

/**
 * Handle HTTP fallback for clients that don't support WebSocket
 */
function handleHttpFallback($instance) {
    header("Content-Type: application/json");
    
    $instanceId = $instance['instance_id'] ?? '';
    $port = $instance['port'] ?? null;
    $baseUrl = $port ? "http://127.0.0.1:$port" : '';
    
    debug_log("HTTP fallback for instance: $instanceId");
    
    if (!$port) {
        echo json_encode([
            "success" => false,
            "error" => "Instance port not configured",
            "instance" => $instanceId
        ]);
        return;
    }

    // Check if instance is running
    $ch = curl_init($baseUrl . '/status');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if (!$response) {
        echo json_encode([
            "success" => false,
            "error" => "Instance not responding",
            "instance" => $instanceId
        ]);
        return;
    }
    
    // Return connection information
    echo json_encode([
        "success" => true,
        "instance" => $instanceId,
        "port" => $port,
        "connection_url" => $baseUrl,
        "proxy_url" => "/qr-proxy.php?id=$instanceId",
        "websocket_proxy" => "/ws-proxy.php?instance=$instanceId",
        "status" => "ready",
        "timestamp" => time()
    ]);
}

// If this is an HTTP request (not WebSocket), provide fallback
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_SERVER['HTTP_UPGRADE'])) {
    handleHttpFallback($instance);
    exit;
}
?>
