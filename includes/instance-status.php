<?php
/**
 * Instance status functions extracted from index.php
 */

function fetchInstanceHealthStatus(int $port): string {
    if (!$port) {
        return 'disconnected';
    }

    $ch = curl_init("http://127.0.0.1:{$port}/health");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode >= 400 || !$resp) {
        return '';
    }

    $data = json_decode($resp, true);
    if ($data && isset($data['whatsappConnected'])) {
        return $data['whatsappConnected'] ? 'connected' : 'disconnected';
    }

    return '';
}

if (!function_exists('buildInstanceStatuses')) {
    function buildInstanceStatuses(array $instances): array
    {
        $statuses = [];
        $connectionStatuses = [];
        foreach ($instances as $id => $inst) {
            $port = $inst['port'] ?? null;
            $serverRunning = $port && isPortOpen('localhost', $port);
            $status = $serverRunning ? 'Running' : 'Stopped';
            $statuses[$id] = $status;

            $healthStatus = $port ? fetchInstanceHealthStatus($port) : '';

            if ($healthStatus) {
                $connectionStatuses[$id] = $healthStatus;
            } else {
                $connectionStatuses[$id] = ($serverRunning && $healthStatus === 'connected') ? 'connected' : 'disconnected';
            }

            debug_log("Status check for {$id} on port {$port}: server={$status}, connection={$connectionStatuses[$id]}");
        }
        return [$statuses, $connectionStatuses];
    }
}
