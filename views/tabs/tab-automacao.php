<?php
/**
 * Tab: Automacao (Automation)
 * renderTabAutomacao() — audio transcription, virtual secretary, instance alarms.
 *
 * Expected globals:
 *   $quickConfigIntegrationType,
 *   $audioTranscriptionEnabled, $audioTranscriptionGeminiApiKey, $audioTranscriptionPrefix,
 *   $secretaryEnabled, $secretaryIdleHours, $secretaryInitialResponse, $secretaryQuickReplies,
 *   $alarmConfig, $_SESSION['csrf_token']
 */

if (!function_exists('renderTabAutomacao')) {
    function renderTabAutomacao(): void
    {
        global $quickConfigIntegrationType,
               $audioTranscriptionEnabled, $audioTranscriptionGeminiApiKey, $audioTranscriptionPrefix,
               $secretaryEnabled, $secretaryIdleHours, $secretaryInitialResponse, $secretaryQuickReplies,
               $alarmConfig;
?>
    <div data-tab-pane="tab-automacao" class="tab-pane space-y-6">
      <section id="audioTranscriptionSection" class="bg-white border border-mid rounded-2xl p-6 card-soft" style="display: <?= in_array($quickConfigIntegrationType, ['baileys', 'web'], true) ? '' : 'none' ?>">
        <div class="font-medium mb-1">Transcrever áudio</div>
        <p class="text-sm text-slate-500 mb-4">
          Responda automaticamente com a transcrição do áudio recebido nesta instância.
        </p>

        <form id="audioTranscriptionForm" class="space-y-4" onsubmit="return false;">
          <div class="flex items-center gap-2">
            <input type="checkbox" id="audioTranscriptionEnabled" class="h-4 w-4 rounded"
                   <?= $audioTranscriptionEnabled ? 'checked' : '' ?>>
            <label for="audioTranscriptionEnabled" class="text-sm text-slate-600">
              Habilitar transcrição de áudio
            </label>
          </div>

          <div>
            <label class="text-xs text-slate-500">Gemini API Key</label>
            <div class="relative mt-1">
              <input id="audioTranscriptionGeminiKey" type="password" autocomplete="new-password"
                     class="w-full px-3 py-2 rounded-xl border border-mid bg-light pr-10"
                     placeholder="GAPI..." value="<?= htmlspecialchars($audioTranscriptionGeminiApiKey) ?>">
              <button id="toggleAudioGeminiKey" type="button"
                      class="absolute inset-y-0 right-2 flex items-center justify-center text-slate-500 hover:text-primary"
                      aria-pressed="false" aria-label="Mostrar ou ocultar chave">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                        d="M1.5 12s4.5-8.5 10.5-8.5S22.5 12 22.5 12s-4.5 8.5-10.5 8.5S1.5 12 1.5 12z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
              </button>
            </div>
            <p class="text-[11px] text-slate-500 mt-1">
              Necessário para transcrever arquivos de áudio.
            </p>
          </div>

          <div>
            <label class="text-xs text-slate-500">Prefixo da transcrição</label>
            <input id="audioTranscriptionPrefix" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($audioTranscriptionPrefix) ?>" placeholder="🔊">
            <p class="text-[11px] text-slate-500 mt-1">
              Será enviado como "PREFIXO: texto transcrito".
            </p>
          </div>

          <div class="flex flex-wrap gap-2 items-center">
            <button type="button" id="saveAudioTranscriptionButton"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar
            </button>
            <p id="audioTranscriptionStatus" aria-live="polite" class="text-sm text-slate-500 mt-2 sm:mt-0">
              &nbsp;
            </p>
          </div>
        </form>
      </section>

      <section id="secretarySection" class="bg-white border border-mid rounded-2xl p-6 card-soft" style="display: <?= in_array($quickConfigIntegrationType, ['baileys', 'web'], true) ? '' : 'none' ?>">
        <div class="font-medium mb-1">Secretária virtual</div>
        <p class="text-sm text-slate-500 mb-4">
          Responda automaticamente quando o contato voltar após um tempo sem interação.
        </p>

        <form id="secretaryForm" class="space-y-4" onsubmit="return false;">
          <div class="flex items-center gap-2">
            <input type="checkbox" id="secretaryEnabled" class="h-4 w-4 rounded"
                   <?= $secretaryEnabled ? 'checked' : '' ?>>
            <label for="secretaryEnabled" class="text-sm text-slate-600">
              Habilitar secretária virtual
            </label>
          </div>

          <div>
            <label class="text-xs text-slate-500">Tempo sem contato (horas)</label>
            <input id="secretaryIdleHours" type="number" min="1" step="1"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars((string)$secretaryIdleHours) ?>">
          </div>

          <div>
            <label class="text-xs text-slate-500">Resposta inicial</label>
            <textarea id="secretaryInitialResponse" rows="3"
                      class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                      placeholder="oi, já já lhe atendo"><?= htmlspecialchars($secretaryInitialResponse) ?></textarea>
          </div>

          <div class="space-y-3">
            <div class="text-xs text-slate-500 uppercase tracking-widest">Respostas rápidas</div>
            <div id="secretaryQuickReplies" class="grid grid-cols-1 gap-3"></div>
            <button type="button" id="addSecretaryReply"
                    class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
              Adicionar resposta rápida
            </button>
          </div>

          <div class="flex flex-wrap gap-2 items-center">
            <button type="button" id="saveSecretaryButton"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar
            </button>
            <p id="secretaryStatus" aria-live="polite" class="text-sm text-slate-500 mt-2 sm:mt-0">
              &nbsp;
            </p>
          </div>
        </form>
      </section>

      <section id="alarmSettingsSection" class="xl:col-span-2 bg-white border border-mid rounded-2xl p-6 card-soft">
        <div class="flex items-start justify-between">
          <div>
            <div class="font-medium mb-1">Alarmes de instância</div>
            <p class="text-sm text-slate-500">
              Receba alertas por e-mail quando algo crítico acontecer na instância selecionada.
            </p>
          </div>
          <span class="text-xs text-slate-500">Configurado via serviço Node</span>
        </div>
        <form id="alarmConfigForm" class="space-y-5 mt-4" onsubmit="return false;">
          <?php
          $alarmEvents = [
            'whatsapp' => [
              'label' => 'WhatsApp desconectado',
              'help' => 'Dispara sempre que a conexão com o WhatsApp cair.'
            ],
            'server' => [
              'label' => 'Servidor desconectado',
              'help' => 'Detecta quando a porta da instância não responde (rodar pelo monitor).'
            ],
            'error' => [
              'label' => 'Erro encontrado',
              'help' => 'Quando o serviço registrar um erro crítico e parar de funcionar corretamente.'
            ]
          ];
          foreach ($alarmEvents as $eventKey => $eventMeta):
            $alarmEntry = $alarmConfig[$eventKey] ?? ['enabled' => false, 'recipients' => '', 'interval' => 120];
            $intervalValue = (int)($alarmEntry['interval'] ?? 120);
            $intervalValue = max(1, min(1440, $intervalValue));
            $displayStyle = ($quickConfigIntegrationType === 'meta' && $eventKey !== 'error') ? 'style="display:none"' : '';
          ?>
          <div class="rounded-2xl border border-mid/70 bg-light/60 p-4 space-y-3" data-alarm-event="<?= $eventKey ?>" <?= $displayStyle ?>>
            <div class="flex items-center justify-between gap-3">
              <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input id="alarm_<?= $eventKey ?>_enabled" type="checkbox" class="h-4 w-4 rounded border-mid text-primary"
                       <?= (!empty($alarmEntry['enabled']) ? 'checked' : '') ?>>
                <?= htmlspecialchars($eventMeta['label']) ?>
              </label>
              <span class="text-[11px] text-slate-500"><?= htmlspecialchars($eventMeta['help']) ?></span>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
              <div class="lg:col-span-3">
                <label class="text-xs text-slate-500">E-mails destino (separe por vírgula)</label>
                <input id="alarm_<?= $eventKey ?>_recipients" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm"
                       placeholder="ex: devops@empresa.com" value="<?= htmlspecialchars($alarmEntry['recipients'] ?? '') ?>">
              </div>
              <div>
                <label class="text-xs text-slate-500">Intervalo (minutos)</label>
                <input id="alarm_<?= $eventKey ?>_interval" type="range" min="1" max="1440" step="1"
                       value="<?= $intervalValue ?>" class="mt-2 w-full accent-primary">
                <div class="text-xs text-slate-500 mt-1">
                  <span id="alarm_<?= $eventKey ?>_interval_label"></span>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            <button id="saveAlarmButton" type="button"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar alarmes
            </button>
            <p id="alarmStatus" aria-live="polite" class="text-xs text-slate-500 sm:text-sm">
              &nbsp;
            </p>
          </div>
        </form>
      </section>
    </div>
<?php
    }
}
