<?php
/**
 * QR Proxy for WhatsApp Instances
 * Secure proxy for retrieving QR codes from instances.json
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to client

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Configuration
define('INSTANCES_FILE', __DIR__ . '/instances.json');
define('LOG_FILE', __DIR__ . '/logs/qr-proxy.log');
define('DEV_MODE', file_exists(__DIR__ . '/debug'));
define('MAX_QR_LIFETIME', 300); // 5 minutes default TTL

// Ensure log directory exists
$logDir = dirname(LOG_FILE);
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}

/**
 * Log message to file
 */
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logEntry = "[{$timestamp}] [{$clientIp}] {$message}" . PHP_EOL;
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Validate and sanitize instance ID
 */
function validate_instance_id($id) {
    // Only allow alphanumeric characters, hyphens, and underscores (1-64 chars)
    if (!preg_match('/^[\w-]{1,64}$/', $id)) {
        return false;
    }
    return trim($id);
}

/**
 * Calculate QR freshness based on last_seen and ttl
 */
function is_qr_fresh($lastSeen, $ttl) {
    if (!$lastSeen || !$ttl) return false;
    
    $lastSeenTime = strtotime($lastSeen);
    if ($lastSeenTime === false) return false;
    
    $currentTime = time();
    $elapsed = $currentTime - $lastSeenTime;
    
    return $elapsed <= $ttl;
}

/**
 * Fetch QR code data from whatsapp-server.js
 */
function fetch_real_qr_from_server($port) {
    if (!$port) return null;
    
    $url = "http://localhost:{$port}/qr";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: QR-Proxy/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false || $httpCode !== 200) {
        log_message("Failed to fetch QR from server on port {$port}: " . ($error ?: "HTTP {$httpCode}"));
        return null;
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['qr'])) {
        log_message("Invalid QR response from server on port {$port}: " . json_last_error_msg());
        return null;
    }
    
    return $data['qr'];
}

/**
 * Fetch instance status from whatsapp-server.js
 */
function fetch_instance_status($port) {
    if (!$port) return null;
    
    $url = "http://localhost:{$port}/status";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'User-Agent: QR-Proxy/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_setopt($ch, CURLOPT_URL, "http://localhost:{$port}/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $healthResponse = curl_exec($ch);
    $healthHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Get detailed status if server is responsive
    if ($response !== false && $httpCode === 200) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
    }
    
    // Fall back to health check data
    if ($healthResponse !== false && $healthHttpCode === 200) {
        $healthData = json_decode($healthResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $healthData;
        }
    }
    
    return null;
}

/**
 * Generate QR PNG from text using GD library
 */
function generate_qr_png($qrText) {
    if (empty($qrText) || !function_exists('imagecreatefromstring')) {
        return false;
    }
    
    try {
        // Create a simple QR-like pattern using GD
        // This is a basic implementation - for production, use a proper QR library
        
        $width = 300;
        $height = 300;
        $image = imagecreate($width, $height);
        
        // Colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 128, 128, 128);
        
        // Fill background
        imagefill($image, 0, 0, $white);
        
        // Create a simple pattern based on QR text hash
        $hash = md5($qrText);
        $cellSize = 8;
        $gridSize = min(37, floor(min($width, $height) / $cellSize));
        
        // Draw border
        imagerectangle($image, 0, 0, $width-1, $height-1, $black);
        
        // Draw finder patterns (corners)
        $cornerSize = 3;
        // Top-left
        draw_finder_pattern($image, 1, 1, $cornerSize, $black, $white);
        // Top-right  
        draw_finder_pattern($image, $gridSize - $cornerSize - 1, 1, $cornerSize, $black, $white);
        // Bottom-left
        draw_finder_pattern($image, 1, $gridSize - $cornerSize - 1, $cornerSize, $black, $white);
        
        // Draw data pattern based on hash
        for ($i = 0; $i < strlen($hash); $i++) {
            $char = $hash[$i];
            for ($j = 0; $j < 4; $j++) {
                $bit = (hexdec($char) >> $j) & 1;
                $x = ($i * 4 + $j) % $gridSize;
                $y = floor(($i * 4 + $j) / $gridSize);
                
                if ($x >= $cornerSize + 1 && $x < $gridSize - $cornerSize - 1 && 
                    $y >= $cornerSize + 1 && $y < $gridSize - $cornerSize - 1) {
                    $color = $bit ? $black : $white;
                    $pixelX = $x * $cellSize;
                    $pixelY = $y * $cellSize;
                    imagefilledrectangle($image, $pixelX, $pixelY, 
                                       $pixelX + $cellSize - 1, $pixelY + $cellSize - 1, $color);
                }
            }
        }
        
        // Add QR text as title
        imagestring($image, 3, 10, $height - 25, 'QR for: ' . substr($qrText, 0, 20), $black);
        
        // Capture output
        ob_start();
        imagepng($image);
        $imageData = ob_get_contents();
        ob_end_clean();
        
        // Clean up
        imagedestroy($image);
        
        return base64_encode($imageData);
        
    } catch (Exception $e) {
        log_message("QR PNG generation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Draw finder pattern for QR code
 */
function draw_finder_pattern($image, $x, $y, $size, $black, $white) {
    // Outer black square
    imagerectangle($image, $x * 8, $y * 8, 
                  ($x + $size) * 8 - 1, ($y + $size) * 8 - 1, $black);
    
    // Inner white square
    imagerectangle($image, ($x + 1) * 8, ($y + 1) * 8,
                  ($x + $size - 1) * 8 - 1, ($y + $size - 1) * 8 - 1, $white);
    
    // Inner black square
    imagerectangle($image, ($x + 2) * 8, ($y + 2) * 8,
                  ($x + $size - 2) * 8 - 1, ($y + $size - 2) * 8 - 1, $black);
}

/**
 * Send JSON response with appropriate status code
 */
function send_response($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Main execution
 */
try {
    // Log request
    log_message("QR proxy request: " . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . " " . ($_SERVER['REQUEST_URI'] ?? ''));
    
    // Only allow GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        log_message("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
        send_response([
            'success' => false,
            'error' => 'Method not allowed'
        ], 405);
    }
    
    // Get and validate instance ID
    $instanceId = $_GET['id'] ?? '';
    $instanceId = validate_instance_id($instanceId);
    
    if (!$instanceId) {
        log_message("Invalid instance ID provided: " . ($_GET['id'] ?? 'empty'));
        send_response([
            'success' => false,
            'error' => 'Invalid instance ID format'
        ], 400);
    }
    
    log_message("Processing QR request for instance: {$instanceId}");
    
    // Check if instances file exists
    if (!file_exists(INSTANCES_FILE)) {
        log_message("Instances file not found: " . INSTANCES_FILE);
        send_response([
            'success' => false,
            'error' => 'Instances database not found'
        ], 500);
    }
    
    // Read and parse instances.json
    $instancesData = file_get_contents(INSTANCES_FILE);
    if ($instancesData === false) {
        log_message("Failed to read instances file");
        send_response([
            'success' => false,
            'error' => 'Failed to read instances database'
        ], 500);
    }
    
    $instances = json_decode($instancesData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_message("Invalid JSON in instances file: " . json_last_error_msg());
        send_response([
            'success' => false,
            'error' => 'Invalid instances database format'
        ], 500);
    }
    
    // Find the instance
    if (!isset($instances[$instanceId])) {
        log_message("Instance not found: {$instanceId}");
        send_response([
            'success' => false,
            'error' => 'Instance not found',
            'instance_id' => $instanceId
        ], 404);
    }
    
    $instance = $instances[$instanceId];
    
    // Check if QR data exists
    $qrText = $instance['qr_text'] ?? null;
    $qrPng = $instance['qr_png'] ?? null;
    $lastSeen = $instance['last_seen'] ?? null;
    $ttl = $instance['ttl'] ?? MAX_QR_LIFETIME;
    $status = $instance['status'] ?? 'disconnected';
    $port = $instance['port'] ?? null;
    
    log_message("Instance {$instanceId} - QR text: " . ($qrText ? 'available' : 'missing') . 
               ", PNG: " . ($qrPng ? 'available' : 'missing') . 
               ", Status: {$status}, Port: {$port}");
    
    // Try to fetch real QR code from whatsapp-server.js first
    $realQrCode = null;
    $serverStatus = null;
    
    if ($port) {
        $realQrCode = fetch_real_qr_from_server($port);
        $serverStatus = fetch_instance_status($port);
        
        if ($realQrCode) {
            log_message("Successfully fetched real QR from whatsapp-server.js for instance: {$instanceId}");
            
            // Generate PNG from real QR code
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($realQrCode);
            $ch = curl_init($qrUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $qrImageData = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($qrImageData && !$curlError) {
                $qrPngBase64 = base64_encode($qrImageData);
                $currentTime = time();
                
                send_response([
                    'success' => true,
                    'instance_id' => $instanceId,
                    'qr_png' => $qrPngBase64,
                    'qr_text' => $realQrCode,
                    'last_seen' => date('Y-m-d\TH:i:s.v\Z', $currentTime),
                    'ttl' => $ttl,
                    'fresh' => true,
                    'generated_at' => $currentTime,
                    'source' => 'baileys_real',
                    'server_status' => $serverStatus
                ]);
            } else {
                // Return real QR text if PNG generation fails
                send_response([
                    'success' => true,
                    'instance_id' => $instanceId,
                    'qr_text' => $realQrCode,
                    'last_seen' => date('Y-m-d\TH:i:s.v\Z', time()),
                    'ttl' => $ttl,
                    'fresh' => true,
                    'generated_at' => time(),
                    'source' => 'baileys_real_text_only',
                    'server_status' => $serverStatus
                ]);
            }
        }
    }
    
    // If real QR fetch failed, fall back to instances.json data
    log_message("No real QR available from server, falling back to instances.json for instance: {$instanceId}");
    
    // Handle ETag for caching
    $etag = md5($instanceId . ($qrPng ?? '') . ($qrText ?? '') . $lastSeen);
    
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) {
        log_message("ETag match for instance: {$instanceId}");
        http_response_code(304);
        exit;
    }
    
    // Set cache headers based on environment
    if (DEV_MODE) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    } else {
        $cacheTime = min($ttl, 60); // Cache for at most 1 minute in production
        header("Cache-Control: public, max-age={$cacheTime}");
        header("ETag: {$etag}");
    }
    
    // Check QR freshness
    $isFresh = is_qr_fresh($lastSeen, $ttl);
    
    // If QR is not fresh and no fallback available
    if (!$isFresh && !$qrText && !$qrPng) {
        log_message("QR expired and no fallback available for instance: {$instanceId}");
        send_response([
            'success' => false,
            'error' => 'QR code has expired',
            'instance_id' => $instanceId,
            'last_seen' => $lastSeen,
            'ttl' => $ttl,
            'expired' => true,
            'server_status' => $serverStatus
        ], 410);
    }
    
    // If we have PNG data, return it
    if ($qrPng) {
        log_message("Returning QR PNG for instance: {$instanceId}");
        send_response([
            'success' => true,
            'instance_id' => $instanceId,
            'qr_png' => $qrPng,
            'last_seen' => $lastSeen,
            'ttl' => $ttl,
            'fresh' => $isFresh,
            'generated_at' => time(),
            'source' => 'fallback_png'
        ]);
    }
    
    // If we have QR text, use external QR service to generate proper QR code
    if ($qrText) {
        log_message("Generating QR PNG from text using external service for instance: {$instanceId}");
        
        // Use external QR service to generate a proper, scannable QR code
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qrText);
        
        // Fetch the QR image from external service
        $ch = curl_init($qrUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $qrImageData = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($qrImageData && !$curlError) {
            $qrPngBase64 = base64_encode($qrImageData);
            log_message("Successfully generated QR PNG using external service for instance: {$instanceId}");
            
            send_response([
                'success' => true,
                'instance_id' => $instanceId,
                'qr_png' => $qrPngBase64,
                'qr_text' => $qrText,
                'last_seen' => $lastSeen,
                'ttl' => $ttl,
                'fresh' => $isFresh,
                'generated_at' => time(),
                'fallback' => true,
                'source' => 'fallback_external_service'
            ]);
        } else {
            log_message("External QR service failed for instance {$instanceId}: " . $curlError);
            
            // Return QR text if external service fails - let frontend handle it
            send_response([
                'success' => true,
                'instance_id' => $instanceId,
                'qr_text' => $qrText,
                'last_seen' => $lastSeen,
                'ttl' => $ttl,
                'fresh' => $isFresh,
                'generated_at' => time(),
                'fallback' => true,
                'source' => 'fallback_text_only'
            ]);
        }
    }
    
    // No QR data available
    log_message("No QR data available for instance: {$instanceId}");
    send_response([
        'success' => false,
        'error' => 'No QR code available',
        'instance_id' => $instanceId,
        'status' => $status,
        'message' => 'QR code not yet generated or instance is connected',
        'server_status' => $serverStatus
    ], 404);
    
} catch (Exception $e) {
    log_message("Unexpected error: " . $e->getMessage());
    send_response([
        'success' => false,
        'error' => 'Internal server error',
        'message' => 'An unexpected error occurred'
    ], 500);
}
?>