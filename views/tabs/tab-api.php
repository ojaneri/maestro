<?php
/**
 * Tab de Exemplos de API (cURL)
 */

if (!function_exists('renderTabAPI')) {
    function renderTabAPI() {
        global $selectedInstanceId, $selectedInstance, $curlEndpoint, $curlEndpointPort,
               $sampleCurlCommand, $sampleCurlImageUrlCommand, $sampleCurlVideoUrlCommand,
               $sampleCurlVideoBase64Command, $sampleCurlAudioUrlCommand,
               $sampleCurlAudioBase64Command;
        
        if (!$selectedInstanceId) {
            return;
        }
        ?>
        <div id="tab-api" data-tab-pane class="tab-pane">
            <!-- API Documentation - Exemplos cURL -->
            <section id="curlExampleSection" class="bg-white border border-mid rounded-2xl p-6 card-soft">
                <div class="flex items-start justify-between">
                    <div>
                        <div class="font-medium mb-1">API Documentation - Exemplos cURL</div>
                        <p class="text-sm text-slate-500">
                            Exemplos de uso da API para envio de mensagens. Todos os comandos usam a instância selecionada
                            (porta <?= htmlspecialchars($curlEndpointPort) ?>).
                        </p>
                    </div>
                    <?php if (!$selectedInstance): ?>
                        <span class="text-xs px-2 py-1 rounded-full bg-alert/10 text-alert">Instância padrão</span>
                    <?php endif; ?>
                </div>

                <div class="mt-6 space-y-6">
                    <div>
                        <h3 class="text-lg font-semibold text-dark mb-2">Envio de Mensagem de Texto</h3>
                        <p class="text-sm text-slate-500 mb-3">Envie uma mensagem de texto simples para um número WhatsApp.</p>
                        <pre class="overflow-auto text-xs rounded-xl bg-black/90 text-white p-4"><code>curl -X POST "<?= htmlspecialchars($curlEndpoint) ?>/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "5585999999999",
    "message": "Olá, esta é uma mensagem de teste!"
  }'</code></pre>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-dark mb-2">Envio de Imagem via URL</h3>
                        <p class="text-sm text-slate-500 mb-3">Envie uma imagem hospedada em uma URL pública, com legenda opcional.</p>
                        <pre class="overflow-auto text-xs rounded-xl bg-black/90 text-white p-4"><code>curl -X POST "<?= htmlspecialchars($curlEndpoint) ?>/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "5585999999999",
    "image_url": "https://example.com/image.jpg",
    "caption": "Veja esta imagem incrível!"
  }'</code></pre>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-dark mb-2">Envio de Imagem via Base64</h3>
                        <p class="text-sm text-slate-500 mb-3">Envie uma imagem codificada em base64, com legenda opcional.</p>
                        <pre class="overflow-auto text-xs rounded-xl bg-black/90 text-white p-4"><code>curl -X POST "<?= htmlspecialchars($curlEndpoint) ?>/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "5585999999999",
    "image_base64": "data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD...",
    "caption": "Imagem enviada via base64"
  }'</code></pre>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-dark mb-2">Envio de Mensagem com Mencão a Todos (Everyone)</h3>
                        <p class="text-sm text-slate-500 mb-3">Mencione todos os participantes de um grupo usando o parâmetro "everyone".</p>
                        <pre class="overflow-auto text-xs rounded-xl bg-black/90 text-white p-4"><code>curl -X POST "<?= htmlspecialchars($curlEndpoint) ?>/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "5585999999999-1234567890@g.us",
    "message": "Olá todos! Esta é uma mensagem para todos os participantes.",
    "everyone": true
  }'</code></pre>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-dark mb-2">Envio de Imagem com Mencão a Todos</h3>
                        <p class="text-sm text-slate-500 mb-3">Envie uma imagem com legenda e mencione todos os participantes do grupo.</p>
                        <pre class="overflow-auto text-xs rounded-xl bg-black/90 text-white p-4"><code>curl -X POST "<?= htmlspecialchars($curlEndpoint) ?>/send-message" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "5585999999999-1234567890@g.us",
    "image_url": "https://example.com/group-image.jpg",
    "caption": "Veja esta imagem importante para todos!",
    "everyone": true
  }'</code></pre>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-dark mb-2">Verificar Status da Instância</h3>
                        <p class="text-sm text-slate-500 mb-3">Verifique se a instância está conectada e funcionando.</p>
                        <pre class="overflow-auto text-xs rounded-xl bg-black/90 text-white p-4"><code>curl -X GET "<?= htmlspecialchars($curlEndpoint) ?>/status"</code></pre>
                    </div>

                    <div>
                        <h3 class="text-lg font-semibold text-dark mb-2">Obter QR Code</h3>
                        <p class="text-sm text-slate-500 mb-3">Obtenha o QR code atual para conectar o WhatsApp (se disponível).</p>
                        <pre class="overflow-auto text-xs rounded-xl bg-black/90 text-white p-4"><code>curl -X GET "<?= htmlspecialchars($curlEndpoint) ?>/qr"</code></pre>
                    </div>
                </div>
            </section>
            
            <!-- Referência para documentação completa -->
            <section class="mt-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
                <h4 class="text-sm font-semibold text-blue-800 mb-2">📚 Documentação Completa</h4>
                <p class="text-xs text-blue-700 mb-3">
                    Veja a documentação completa da API com todos os endpoints disponíveis.
                </p>
                <a href="API_DOCUMENTATION.md" target="_blank" class="inline-flex items-center px-3 py-2 text-xs font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700">
                    Ver Documentação API
                    <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                    </svg>
                </a>
            </section>
        </div>
        <?php
    }
}
