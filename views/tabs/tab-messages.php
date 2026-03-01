<?php
/**
 * Tab: Estatísticas (Messages)
 * renderTabMessages() — stat cards for messages, contacts, schedules, average taxa R.
 *
 * Expected globals:
 *   $logSummary
 */

if (!function_exists('renderTabMessages')) {
    function renderTabMessages(): void
    {
        global $logSummary;
?>
        <div data-tab-pane="tab-messages" class="tab-pane active space-y-6">
          <p class="text-xs text-slate-500">
            Métricas de mensagem em tempo real para monitorar envios e contatos.
          </p>
          <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div class="stat-card">
              <span class="text-xs uppercase tracking-[0.3em] text-slate-200">Mensagens total</span>
              <strong><?= (int)$logSummary['total_messages'] ?></strong>
              <p class="text-xs text-white/70 mt-2">
                Recebidas <?= (int)$logSummary['total_inbound'] ?> • Enviadas <?= (int)$logSummary['total_outbound'] ?>
              </p>
            </div>
            <div class="stat-card">
              <span class="text-xs uppercase tracking-[0.3em] text-slate-200">Contatos</span>
              <strong><?= (int)$logSummary['total_contacts'] ?></strong>
              <p class="text-xs text-white/70 mt-2">Conversas únicas registradas</p>
            </div>
            <div class="stat-card">
              <span class="text-xs uppercase tracking-[0.3em] text-slate-200">Agendamentos</span>
              <strong><?= (int)($logSummary['scheduled_pending'] + $logSummary['scheduled_sent'] + $logSummary['scheduled_failed']) ?></strong>
              <p class="text-xs text-white/70 mt-2">
                Pendentes <?= (int)$logSummary['scheduled_pending'] ?> • Enviados <?= (int)$logSummary['scheduled_sent'] ?>
              </p>
            </div>
            <div class="stat-card">
              <span class="text-xs uppercase tracking-[0.3em] text-slate-200">Taxa R Média</span>
              <strong id="averageTaxarValue">-</strong>
              <p class="text-xs text-white/70 mt-2">Média da taxa de resposta</p>
            </div>
          </div>
        </div>
<?php
    }
}
