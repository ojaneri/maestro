<?php
require_once __DIR__ . '/../vendor/autoload.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$instanceId = $_GET['instance'] ?? '';
$uploadId = trim((string)($_POST['upload_id'] ?? ''));
$chunkIndex = (int)($_POST['chunk_index'] ?? -1);
$totalChunks = (int)($_POST['total_chunks'] ?? 0);
$fileName = trim((string)($_POST['file_name'] ?? ''));
$fileType = trim((string)($_POST['file_type'] ?? ''));

if ($uploadId === '' || $chunkIndex < 0 || $totalChunks < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parâmetros de upload inválidos.']);
    exit;
}

if (!isset($_FILES['chunk']) || !is_array($_FILES['chunk'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Chunk ausente.']);
    exit;
}

$chunk = $_FILES['chunk'];
if (($chunk['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Erro no envio do chunk.']);
    exit;
}

$tmpDir = __DIR__ . '/uploads/tmp/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId);
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0755, true);
}

$chunkPath = $tmpDir . '/chunk_' . $chunkIndex . '.part';
if (!move_uploaded_file($chunk['tmp_name'], $chunkPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falha ao salvar chunk.']);
    exit;
}

if ($chunkIndex < $totalChunks - 1) {
    echo json_encode(['ok' => true, 'received' => $chunkIndex]);
    exit;
}

// montar arquivo final
$allowedPrefixes = [
    'image/' => 'IMG',
    'video/' => 'VIDEO',
    'audio/' => 'AUDIO'
];
$codePrefix = '';
foreach ($allowedPrefixes as $prefix => $label) {
    if ($fileType && strpos($fileType, $prefix) === 0) {
        $codePrefix = $label;
        break;
    }
}
if ($codePrefix === '') {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($chunkPath) ?: '';
    foreach ($allowedPrefixes as $prefix => $label) {
        if (strpos($mimeType, $prefix) === 0) {
            $codePrefix = $label;
            $fileType = $mimeType;
            break;
        }
    }
}
if ($codePrefix === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de arquivo não suportado.']);
    exit;
}

$ext = pathinfo($fileName, PATHINFO_EXTENSION);
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
$finalPath = __DIR__ . '/uploads/' . $filename;

$out = fopen($finalPath, 'wb');
if (!$out) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Falha ao criar arquivo final.']);
    exit;
}

for ($i = 0; $i < $totalChunks; $i++) {
    $partPath = $tmpDir . '/chunk_' . $i . '.part';
    if (!file_exists($partPath)) {
        fclose($out);
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Chunks incompletos.']);
        exit;
    }
    $data = file_get_contents($partPath);
    if ($data === false) {
        fclose($out);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Falha ao ler chunk.']);
        exit;
    }
    fwrite($out, $data);
}
fclose($out);

// limpeza
for ($i = 0; $i < $totalChunks; $i++) {
    $partPath = $tmpDir . '/chunk_' . $i . '.part';
    if (file_exists($partPath)) {
        @unlink($partPath);
    }
}
@rmdir($tmpDir);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$assetUrl = "{$scheme}://{$host}{$basePath}/assets/uploads/{$filename}";
$assetLocalPath = 'uploads/' . $filename;
$assetCode = "{$codePrefix}:{$assetLocalPath}";

echo json_encode([
    'ok' => true,
    'code' => $assetCode,
    'url' => $assetUrl,
    'path' => $assetLocalPath
]);
