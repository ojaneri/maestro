<?php
/**
 * Tab: Configuracoes (General)
 * renderTabGeneral() — send message, asset upload, quick config, auto pause, cURL examples.
 *
 * Expected globals:
 *   $selectedInstance, $selectedInstanceId, $_SESSION,
 *   $sendSuccess, $sendError,
 *   $assetUploadMessage, $assetUploadError, $assetUploadCode,
 *   $quickConfigIntegrationType, $quickConfigMessage, $quickConfigError, $quickConfigWarning,
 *   $aiAutoPauseEnabled, $aiAutoPauseMinutes,
 *   $curlEndpoint, $curlEndpointPort, $curlPayload
 */

if (!function_exists('renderTabGeneral')) {
    function renderTabGeneral(): void
    {
        global $selectedInstance, $selectedInstanceId,
               $sendSuccess, $sendError,
               $assetUploadMessage, $assetUploadError, $assetUploadCode,
               $quickConfigIntegrationType, $quickConfigMessage, $quickConfigError, $quickConfigWarning,
               $aiAutoPauseEnabled, $aiAutoPauseMinutes,
               $curlEndpoint, $curlEndpointPort, $curlPayload;
?>
        <div data-tab-pane="tab-general" class="tab-pane space-y-6">
    <!-- GRID -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

      <!-- ENVIO -->
      <section id="sendMessageSection" class="xl:col-span-1 bg-white border border-mid rounded-2xl p-6 card-soft">
        <div class="font-medium mb-4">Enviar mensagem</div>

        <?php
        $sendStatusClass = 'text-slate-500';
        $sendStatusMessage = '';
        if (isset($sendSuccess)) {
            $sendStatusClass = 'text-success font-medium';
            $sendStatusMessage = $sendSuccess;
        } elseif (isset($sendError)) {
            $sendStatusClass = 'text-error font-medium';
            $sendStatusMessage = $sendError;
        }
        ?>
        <form id="sendForm" method="POST" action="?instance=<?= $selectedInstanceId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
          <div class="grid grid-cols-1 gap-4">
            <div>
              <label class="text-xs text-slate-500">Número destino</label>
              <input name="phone" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     placeholder="5585999999999" required>
            </div>

            <div>
              <label class="text-xs text-slate-500">Mensagem</label>
              <textarea name="message" rows="3" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                        placeholder="Digite sua mensagem..." required></textarea>
            </div>
          </div>

          <button type="submit" name="send" id="sendButton"
                  class="mt-4 px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
            Enviar mensagem
          </button>
          <p id="sendStatus" aria-live="polite" class="mt-2 text-sm <?= $sendStatusClass ?>">
            <?= $sendStatusMessage ? htmlspecialchars($sendStatusMessage) : '&nbsp;' ?>
          </p>
        </form>
      </section>

      <section id="assetUploadSection" class="xl:col-span-1 bg-white border border-mid rounded-2xl p-6 card-soft">
        <div class="font-medium mb-4">Upload de arquivos</div>
        <p class="text-xs text-slate-500">
          Envie imagens, vídeos ou áudios para gerar o código que o bot pode usar (IMG, VIDEO, AUDIO). Agora o código sai como caminho local relativo (uploads/...).
        </p>
        <form id="assetUploadForm" method="POST" action="assets/upload_asset.php?instance=<?= urlencode($selectedInstanceId ?? '') ?>" enctype="multipart/form-data" class="mt-4 space-y-3">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
          <div>
            <label class="text-xs text-slate-500">Arquivo</label>
            <input id="assetFileInput" type="file" name="asset_file" accept="image/*,video/*,audio/*"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" required>
          </div>
          <button id="assetUploadButton" type="button" name="upload_asset"
                  class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
            Enviar arquivo
          </button>
          <div id="assetUploadProgress" class="hidden">
            <div class="text-xs text-slate-500 mb-2">Enviando...</div>
            <div class="w-full h-2 rounded-full bg-slate-200 overflow-hidden">
              <div id="assetUploadProgressBar" class="h-full bg-primary" style="width:0%"></div>
            </div>
          </div>
          <?php if ($assetUploadMessage): ?>
            <p class="text-sm text-success mt-2"><?= htmlspecialchars($assetUploadMessage) ?></p>
          <?php elseif ($assetUploadError): ?>
            <p class="text-sm text-error mt-2"><?= htmlspecialchars($assetUploadError) ?></p>
          <?php endif; ?>
          <div id="assetUploadCodeWrap" class="mt-3 rounded-xl border border-mid bg-slate-50 p-3 text-xs text-slate-600 <?= $assetUploadCode ? '' : 'hidden' ?>">
            <div class="text-[11px] text-slate-500 uppercase tracking-widest">Código para o bot</div>
            <div id="assetUploadCode" class="mt-2 font-semibold text-slate-800 break-all"><?= htmlspecialchars($assetUploadCode) ?></div>
          </div>
        </form>
      </section>

      <!-- CONFIG RÁPIDA -->
    <aside id="quickConfigSection" class="bg-white border border-mid rounded-2xl p-6 card-soft">
        <div class="font-medium mb-4">Configuração rápida</div>

      <?php
      $quickConfigName = $selectedInstance['name'] ?? '';
      $quickConfigBaseUrl = $selectedInstance['base_url'] ?? ("http://127.0.0.1:" . ($selectedInstance['port'] ?? ''));
      $quickConfigMeta = $selectedInstance['meta'] ?? [];
      $quickConfigMetaAccessToken = $quickConfigMeta['access_token'] ?? '';
      $quickConfigMetaBusinessAccountId = $quickConfigMeta['business_account_id'] ?? '';
      $quickConfigMetaTelephoneId = $quickConfigMeta['telephone_id'] ?? '';
      ?>
      <form method="POST" class="space-y-3" id="quickConfigForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div id="quickConfigMessage"></div>
        <div>
          <label class="text-xs text-slate-500">Nome da instância</label>
          <input name="instance_name" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                 value="<?= htmlspecialchars($quickConfigName) ?>" required>
        </div>

        <div>
          <label class="text-xs text-slate-500">Porta</label>
          <input name="instance_port" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                 value="<?= htmlspecialchars($selectedInstance['port'] ?? '5000') ?>" required>
        </div>

        <div>
          <label class="text-xs text-slate-500">Tipo de Integração</label>
          <select id="quickConfigIntegrationType" name="integration_type"
                  class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" required>
            <option value="baileys" <?= $quickConfigIntegrationType === 'baileys' ? 'selected' : '' ?>>Baileys</option>
            <option value="meta" <?= $quickConfigIntegrationType === 'meta' ? 'selected' : '' ?>>Meta (WhatsApp Business API)</option>
            <option value="web" <?= $quickConfigIntegrationType === 'web' ? 'selected' : '' ?>>Web</option>
          </select>
        </div>

        <div id="quickConfigBaileysFields" class="<?= in_array($quickConfigIntegrationType, ['baileys', 'web'], true) ? '' : 'hidden' ?>">
          <div>
            <label class="text-xs text-slate-500">Base URL</label>
            <input id="quickConfigBaseUrlInput" type="text"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($quickConfigBaseUrl) ?>" required>
            <input type="hidden" id="quickConfigBaseUrlEncoded" name="instance_base_url_b64"
                   value="<?= htmlspecialchars(base64_encode($quickConfigBaseUrl)) ?>">
            <noscript class="text-[11px] text-error mt-1 block">JavaScript precisa estar ativo para alterar a Base URL.</noscript>
          </div>
        </div>

        <div id="quickConfigMetaFields" class="<?= $quickConfigIntegrationType === 'meta' ? '' : 'hidden' ?>">
          <div>
            <label class="text-xs text-slate-500">Meta Access Token</label>
            <input id="quickConfigMetaAccessToken" type="text" name="meta_access_token"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($quickConfigMetaAccessToken) ?>" required>
            <p class="text-[11px] text-slate-500 mt-1">
              Token de acesso da Meta para autenticação na WhatsApp Business API.
            </p>
          </div>
          <div>
            <label class="text-xs text-slate-500">WABA ID</label>
            <input id="quickConfigMetaBusinessAccountId" type="text" name="meta_business_account_id"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($quickConfigMetaBusinessAccountId) ?>" required>
            <p class="text-[11px] text-slate-500 mt-1">
              ID da conta de negócio WhatsApp. Encontre em <a href="https://business.facebook.com/latest/settings/whatsapp_account" target="_blank" class="text-primary underline">https://business.facebook.com/latest/settings/whatsapp_account</a>.
            </p>
          </div>
          <div>
            <label class="text-xs text-slate-500">Telephone ID</label>
            <input id="quickConfigMetaTelephoneId" type="text" name="meta_telephone_id"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($quickConfigMetaTelephoneId) ?>" required>
            <p class="text-[11px] text-slate-500 mt-1">
              O número de telefone associado à conta WhatsApp Business. Encontre em <a href="https://business.facebook.com/latest/whatsapp_manager/phone_numbers" target="_blank" class="text-primary underline">https://business.facebook.com/latest/whatsapp_manager/phone_numbers</a>.
            </p>
          </div>
        </div>

        <input type="hidden" name="instance" value="<?= htmlspecialchars($selectedInstanceId ?? '') ?>">
        <input type="hidden" name="update_instance" value="1">
        <button id="quickConfigSaveButton" type="button"
                class="w-full px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90"
                onclick="saveQuickConfig()">
          Salvar
        </button>

        <div id="quickConfigMessageArea"></div>

        <?php if (!empty($quickConfigMessage ?? null)): ?>
          <p class="text-xs text-success mt-1"><?= htmlspecialchars($quickConfigMessage) ?></p>
          <?php if (!empty($quickConfigWarning ?? null)): ?>
            <p class="text-xs text-alert mt-1"><?= htmlspecialchars($quickConfigWarning) ?></p>
          <?php endif; ?>
        <?php elseif (!empty($quickConfigError ?? null)): ?>
          <p class="text-xs text-error mt-1"><?= htmlspecialchars($quickConfigError) ?></p>
        <?php endif; ?>
      </form>
    </aside>

    </div>

      <section id="autoPauseSection" class="xl:col-span-2 bg-white border border-mid rounded-2xl p-6 card-soft" style="display: <?= in_array($quickConfigIntegrationType, ['baileys', 'web'], true) ? '' : 'none' ?>">
        <div class="font-medium mb-1">Auto Pause</div>
        <p class="text-sm text-slate-500 mb-4">
          Pausa automaticamente a automação quando o dono enviar uma mensagem diretamente do WhatsApp.
        </p>

        <form id="autoPauseForm" class="space-y-4" onsubmit="return false;">
          <div class="flex items-center gap-2">
            <input type="checkbox" id="autoPauseEnabled" class="h-4 w-4 rounded" <?= $aiAutoPauseEnabled ? 'checked' : '' ?>>
            <label for="autoPauseEnabled" class="text-sm text-slate-600">
              Habilitar Auto Pause
            </label>
          </div>

          <div>
            <label class="text-xs text-slate-500">Minutos para pausar</label>
            <input id="autoPauseMinutes" type="number" min="1" step="1"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($aiAutoPauseMinutes) ?>">
            <p class="text-[11px] text-slate-500 mt-1">
              Quando o dono enviar uma mensagem diretamente do WhatsApp, a automação será pausada por este tempo.
            </p>
          </div>

          <div class="flex flex-wrap gap-2 items-center">
            <button type="button" id="saveAutoPauseButton"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar
            </button>
            <p id="autoPauseStatus" aria-live="polite" class="text-sm text-slate-500 mt-2 sm:mt-0">
              &nbsp;
            </p>
          </div>
        </form>
      </section>

        </div>
<?php
    }
}
