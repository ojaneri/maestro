<?php
// Inclui o autoloader do Composer.
require 'vendor/autoload.php';

use Dotenv\Dotenv;

// --- ⚙️ CONFIGURAÇÃO DE AMBIENTE E API ---
// Carrega as variáveis de ambiente do arquivo .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$apiKey = $_ENV['GEMINI_API_KEY'] ?? null; 

$model = "gemini-2.5-flash"; 
$endpointBase = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

// Variáveis para feedback
$analysisText = null;
$error = null;

// --- 🛑 VALIDAÇÃO DE CHAVE ---
if (empty($apiKey)) {
    $error = "Erro: A chave de API Gemini (GEMINI_API_KEY) não foi encontrada no arquivo .env.";
}

// --- 🚀 LÓGICA DE PROCESSAMENTO DO FORMULÁRIO ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $promptText = $_POST['prompt'] ?? '';
    
    // 1. Processa o Upload do Arquivo
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imagePath = $_FILES['image']['tmp_name']; // Caminho temporário do arquivo
        
        // --- 2. PREPARAÇÃO DA IMAGEM ---
        try {
            $imageData = file_get_contents($imagePath);
            $base64Image = base64_encode($imageData);
            
            // Determina o tipo MIME
            $mimeType = mime_content_type($imagePath);
            if (!$mimeType) {
                // Fallback (apenas por segurança)
                $mimeType = 'image/jpeg'; 
            }

            // --- 3. CRIAÇÃO DO PAYLOAD JSON ---
            $payload = json_encode([
                'contents' => [
                    [
                        'parts' => [
                            // Parte 1: Imagem codificada
                            ['inlineData' => ['mimeType' => $mimeType, 'data' => $base64Image]],
                            // Parte 2: Prompt de texto
                            ['text' => $promptText]
                        ]
                    ]
                ]
            ]);

            // --- 4. CHAMADA À API USANDO cURL ---
            $endpoint = $endpointBase . "?key=" . $apiKey;
            $ch = curl_init($endpoint);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                $error = "Erro cURL: " . curl_error($ch);
            } else {
                $responseDecoded = json_decode($response, true);
                
                // Trata a resposta da API
                if (isset($responseDecoded['error'])) {
                    $error = "Erro da API Gemini: " . ($responseDecoded['error']['message'] ?? 'Erro desconhecido.');
                } elseif (isset($responseDecoded['candidates'][0]['content']['parts'][0]['text'])) {
                    $analysisText = $responseDecoded['candidates'][0]['content']['parts'][0]['text'];
                } else {
                    $error = "Resposta da API inesperada. Verifique o JSON retornado.";
                }
            }
            curl_close($ch);

        } catch (Exception $e) {
            $error = "Erro durante o processamento da imagem: " . $e->getMessage();
        }

    } else {
        $error = "Erro no upload do arquivo. Verifique se a imagem foi selecionada e se é menor que o limite do servidor.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Gemini Vision AI - PHP</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        h2 { border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .form-container { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .result-container { margin-top: 30px; border: 1px solid #ddd; padding: 20px; border-radius: 8px; background: #fff; }
        .error { color: #d9534f; background-color: #f2dede; border: 1px solid #ebccd1; padding: 10px; border-radius: 4px; }
        .success { color: #5cb85c; background-color: #dff0d8; border: 1px solid #d6e9c6; padding: 10px; border-radius: 4px; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input[type="text"], input[type="file"], textarea { width: 100%; padding: 10px; margin-top: 5px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
    </style>
</head>
<body>

    <h1>🧠 Análise de Imagem com Gemini</h1>

    <?php if ($error): ?>
        <div class="error">
            <p><strong>🚨 Erro:</strong> <?php echo htmlspecialchars($error); ?></p>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <h2>Passo 1: Envie Imagem e Prompt</h2>
        <form method="POST" enctype="multipart/form-data">
            
            <label for="image">Selecione a Imagem (JPG, PNG, etc.):</label>
            <input type="file" id="image" name="image" accept="image/*" required>

            <label for="prompt">Prompt de Análise:</label>
            <textarea id="prompt" name="prompt" rows="4" placeholder="Ex: Descreva detalhadamente o que você vê nesta foto e identifique as emoções das pessoas." required><?php echo htmlspecialchars($_POST['prompt'] ?? ''); ?></textarea>

            <button type="submit">Enviar para Análise Gemini</button>
        </form>
    </div>

    <?php if ($analysisText): ?>
        <div class="result-container">
            <h2>✨ Resultado da Análise Gemini</h2>
            <div class="success">
                <p><strong>Prompt Enviado:</strong> <?php echo htmlspecialchars($promptText); ?></p>
            </div>
            <pre style="white-space: pre-wrap; background: #f0f0f0; padding: 15px; border-radius: 4px;"><?php echo htmlspecialchars($analysisText); ?></pre>
        </div>
    <?php endif; ?>

</body>
</html>
