<?php
/**
 * General utility functions extracted from index.php
 */

if (file_exists(__DIR__ . '/../debug')) {
    function debug_log($message) {
        file_put_contents(__DIR__ . '/../debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

define('DEFAULT_GEMINI_INSTRUCTION', 'Você é um assistente atencioso e prestativo. Mantenha o tom profissional e informal. Sempre separe claramente o texto visível ao usuário do bloco de instruções/funções usando o marcador lógico &&& antes de iniciar os comandos.');
define('DEFAULT_MULTI_INPUT_DELAY', 0);
define('DEFAULT_OPENROUTER_BASE_URL', 'https://openrouter.ai');
const BAILEYS_LOG_TAIL_BYTES = 128 * 1024;
const BAILEYS_LOG_LINE_LIMIT = 200;

if (!function_exists('perf_mark')) {
    $perfEnabled = (getenv('PERF_LOG') === '1') || (isset($_GET['perf']) && $_GET['perf'] === '1');
    $perfStart = microtime(true);
    $perfMarks = [];

    function perf_mark(string $label, array $extra = []): void
    {
        global $perfEnabled, $perfMarks;
        if (!$perfEnabled) {
            return;
        }
        $perfMarks[] = [
            'label' => $label,
            'time' => microtime(true),
            'extra' => $extra
        ];
    }

    function perf_log(string $context, array $extra = []): void
    {
        global $perfEnabled, $perfMarks, $perfStart;
        if (!$perfEnabled) {
            return;
        }
        $now = microtime(true);
        $parts = [];
        $prev = $perfStart;
        foreach ($perfMarks as $mark) {
            $delta = ($mark['time'] - $prev) * 1000;
            $parts[] = $mark['label'] . ':' . round($delta) . 'ms';
            $prev = $mark['time'];
        }
        $total = round(($now - $perfStart) * 1000);
        $payload = array_merge(['total_ms' => $total, 'marks' => $parts], $extra);
        debug_log('PERF ' . $context . ' ' . json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}

function isPortOpen($host, $port, $timeout = 1) {
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($fp) {
        fclose($fp);
        return true;
    } else {
        return false;
    }
}

if (!function_exists('buildPublicBaseUrl')) {
    function buildPublicBaseUrl(string $basePath): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $normalized = rtrim($basePath, '/');
        return "{$scheme}://{$host}{$normalized}";
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function formatInstancePhoneLabel($jid) {
    if (!$jid) {
        return '';
    }
    $parts = explode('@', $jid, 2);
    $local = $parts[0];
    $domain = $parts[1] ?? 's.whatsapp.net';
    $digits = preg_replace('/\\D/', '', $local);
    $formatted = '';
    if (preg_match('/^55(\\d{2})(\\d{4,5})(\\d{4})$/', $digits, $matches)) {
        $formatted = "55 {$matches[1]} {$matches[2]}-{$matches[3]}";
    } elseif (preg_match('/^(\\d{2})(\\d{4,5})(\\d{4})$/', $digits, $matches)) {
        $formatted = "{$matches[1]} {$matches[2]}-{$matches[3]}";
    } elseif ($digits) {
        $formatted = $digits;
    }
    $label = $formatted ?: $local;
    return "{$label} @{$domain}";
}
