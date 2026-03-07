#!/usr/bin/env php
<?php
/**
 * Test Helper for AI Chat with &&& Separator
 * 
 * Usage: php test-ai-chat.php [port] [message]
 * 
 * Examples:
 *   php test-ai-chat.php 3011 "Hello, test message"
 *   php test-ai-chat.php 3011 "Test &&& dados('test@email.com')"
 *   php test-ai-chat.php 3013 "Agendamento test &&& agendar2('+30m', 'Test message', 'sdr', 'followup')"
 */

$port = $argv[1] ?? 3011;
$message = $argv[2] ?? 'Hello, this is a test message';
$instanceId = $argv[3] ?? null;
$toNumber = $argv[4] ?? '558586030781'; // Default to a valid contact

// Auto-detect instance based on port
$instanceMap = [
    3011 => 'inst_6992ec9e78d1c',
    3013 => 'inst_6992ed0c735f0'
];

if (!$instanceId && isset($instanceMap[$port])) {
    $instanceId = $instanceMap[$port];
}

echo "=== AI Chat Test Helper ===" . PHP_EOL;
echo "Port: $port" . PHP_EOL;
echo "Instance: " . ($instanceId ?? 'auto-detect') . PHP_EOL;
echo "Message: $message" . PHP_EOL;
echo "To: $toNumber" . PHP_EOL;
echo PHP_EOL;

// Send message to instance
$url = "http://127.0.0.1:$port/send-message";

$postData = json_encode([
    'to' => $toNumber,
    'message' => $message
]);

echo "Sending request to: $url" . PHP_EOL;
echo "Payload: $postData" . PHP_EOL;
echo PHP_EOL;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "=== Response ===" . PHP_EOL;
echo "HTTP Code: $httpCode" . PHP_EOL;
if ($error) {
    echo "Error: $error" . PHP_EOL;
}
echo "Response: $response" . PHP_EOL;
echo PHP_EOL;

// Decode response
$data = json_decode($response, true);
if ($data) {
    echo "=== Parsed Response ===" . PHP_EOL;
    print_r($data);
} else {
    echo "Could not parse JSON response" . PHP_EOL;
}

echo PHP_EOL;
echo "=== Test Complete ===" . PHP_EOL;
echo "Now check conversas.php to see the message and any AI responses." . PHP_EOL;
