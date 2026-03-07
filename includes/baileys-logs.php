<?php
/**
 * Baileys/PM2 log reading functions extracted from index.php
 */

function getBaileysLogDirectory(): string
{
    static $dir = null;
    if ($dir === null) {
        $raw = getenv('PM2_LOG_DIR');
        $default = '/var/www/.pm2/logs';
        $dir = $raw ? trim($raw) : $default;
        $dir = rtrim($dir, '/\\');
    }
    return $dir;
}

function normalizeInstanceIdForLogs(string $instanceId): string
{
    return str_replace('_', '-', $instanceId);
}

function buildBaileysLogPaths(?string $instanceId): array
{
    if (!$instanceId) {
        return ['out' => null, 'error' => null];
    }
    $dir = getBaileysLogDirectory();
    if ($dir === '') {
        return ['out' => null, 'error' => null];
    }
    $normalized = normalizeInstanceIdForLogs($instanceId);
    $base = "{$dir}/wpp-{$normalized}";
    return [
        'out' => "{$base}-out.log",
        'error' => "{$base}-error.log"
    ];
}

function tailBaileysLogFile(string $path, int $lines = BAILEYS_LOG_LINE_LIMIT, int $bytes = BAILEYS_LOG_TAIL_BYTES): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $size = filesize($path);
    if ($size === false) {
        return '';
    }
    $offset = max(0, $size - $bytes);
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return '';
    }
    if ($offset > 0) {
        fseek($handle, $offset);
        fgets($handle);
    }
    $contents = stream_get_contents($handle);
    fclose($handle);
    if ($contents === false) {
        return '';
    }
    $trimmed = rtrim($contents, "\r\n");
    if ($trimmed === '') {
        return '';
    }
    $linesArray = preg_split('/\r\n|\n|\r/', $trimmed);
    if (!is_array($linesArray)) {
        return '';
    }
    if (count($linesArray) > $lines) {
        $linesArray = array_slice($linesArray, -$lines);
    }
    return implode("\n", $linesArray);
}

function getBaileysDebugLogs(?string $instanceId): array
{
    $paths = buildBaileysLogPaths($instanceId);
    $logs = [];
    foreach (['out', 'error'] as $type) {
        $path = $paths[$type] ?? null;
        $logs[$type] = $path ? tailBaileysLogFile($path) : '';
    }
    return $logs;
}
