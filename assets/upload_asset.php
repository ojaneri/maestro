<?php
if (file_exists(__DIR__ . '/../debug')) {
    function debug_log($message) {
        file_put_contents(__DIR__ . '/../debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        debug_log('upload_asset fatal: ' . ($error['message'] ?? 'unknown') . ' at ' . ($error['file'] ?? '') . ':' . ($error['line'] ?? ''));
    }
});

require_once __DIR__ . '/../vendor/autoload.php';
session_start();

$instanceId = $_GET['instance'] ?? '';
debug_log('upload_asset start: method=' . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . ' instance=' . $instanceId);

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    $_SESSION['asset_upload_result'] = [
        'error' => 'Token CSRF inválido.',
        'message' => '',
        'code' => '',
        'url' => ''
    ];
    header('Location: ../index.php');
    exit;
}
$file = $_FILES['asset_file'] ?? null;

if (!$file || !is_array($file)) {
    $_SESSION['asset_upload_result'] = [
        'error' => 'Arquivo não encontrado.',
        'message' => '',
        'code' => '',
        'url' => ''
    ];
    header('Location: ../index.php?instance=' . urlencode($instanceId));
    exit;
}

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['asset_upload_result'] = [
        'error' => 'Falha no upload do arquivo.',
        'message' => '',
        'code' => '',
        'url' => ''
    ];
    header('Location: ../index.php?instance=' . urlencode($instanceId));
    exit;
}

$allowedPrefixes = [
    'image/' => 'IMG',
    'video/' => 'VIDEO',
    'audio/' => 'AUDIO'
];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($file['tmp_name']) ?: '';
$codePrefix = '';
foreach ($allowedPrefixes as $prefix => $label) {
    if (strpos($mimeType, $prefix) === 0) {
        $codePrefix = $label;
        break;
    }
}

if ($codePrefix === '') {
    $_SESSION['asset_upload_result'] = [
        'error' => 'Tipo de arquivo não suportado. Use imagem, vídeo ou áudio.',
        'message' => '',
        'code' => '',
        'url' => ''
    ];
    header('Location: ../index.php?instance=' . urlencode($instanceId));
    exit;
}

$assetsDir = __DIR__ . '/uploads';
if (!is_dir($assetsDir)) {
    mkdir($assetsDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$ext = $ext ? preg_replace('/[^a-zA-Z0-9]/', '', $ext) : '';
if ($ext === '') {
    $ext = match ($codePrefix) {
        'IMG' => 'jpg',
        'VIDEO' => 'mp4',
        'AUDIO' => 'mp3',
        default => 'bin'
    };
}

$safeInstance = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$instanceId);
$timestamp = date('Ymd-His');
$random = bin2hex(random_bytes(4));
$filename = "asset-{$safeInstance}-{$timestamp}-{$random}.{$ext}";
$targetPath = $assetsDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    $_SESSION['asset_upload_result'] = [
        'error' => 'Não foi possível salvar o arquivo.',
        'message' => '',
        'code' => '',
        'url' => ''
    ];
    header('Location: ../index.php?instance=' . urlencode($instanceId));
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetUrl = "{$scheme}://{$host}{$basePath}/assets/uploads/{$filename}";
$assetLocalPath = 'uploads/' . $filename;
$assetCode = "{$codePrefix}:{$assetLocalPath}";

$_SESSION['asset_upload_result'] = [
    'message' => 'Arquivo enviado com sucesso.',
    'error' => '',
    'code' => $assetCode,
    'url' => $assetUrl,
    'path' => $assetLocalPath
];
header('Location: ../index.php?instance=' . urlencode($instanceId));
exit;
