<?php
/**
 * Tab: Monitoramento (Status / Logs)
 * renderTabMonitoramento() — log summary panel with date range selector and stats.
 *
 * Expected globals:
 *   $exportLogUrl, $selectedInstanceId, $logRange, $logSummary
 * Uses function: formatLogDateTime() from includes/log-helpers.php
 */

if (!function_exists('renderTabMonitoramento')) {
    function renderTabMonitoramento(): void
    {
        global $exportLogUrl, $selectedInstanceId, $logRange, $logSummary;
?>
    <div data-tab-pane="tab-monitoramento" class="tab-pane space-y-6">
    <section id="logSummarySection" class="bg-white border border-mid rounded-2xl p-6 mt-6 card-soft">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-dark">Painel de logs</h2>
          <p class="text-xs text-slate-500">Resumo para análise via IA e exportação completa.</p>
        </div>
        <a href="<?= htmlspecialchars($exportLogUrl) ?>"
           class="px-3 py-2 rounded-xl bg-primary text-white text-xs font-semibold hover:opacity-90">
          Salvar log completo
        </a>
      </div>
      <form method="GET" class="mt-4 flex flex-wrap items-end gap-3">
        <input type="hidden" name="instance" value="<?= htmlspecialchars($selectedInstanceId ?? '') ?>">
        <div class="min-w-[180px]">
          <label class="text-[11px] text-slate-500 uppercase tracking-widest">Período</label>
          <select id="logRangeSelect" name="log_range" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
            <option value="today" <?= $logRange['preset'] === 'today' ? 'selected' : '' ?>>Hoje</option>
            <option value="yesterday" <?= $logRange['preset'] === 'yesterday' ? 'selected' : '' ?>>Ontem</option>
            <option value="all" <?= $logRange['preset'] === 'all' ? 'selected' : '' ?>>Período total</option>
            <option value="custom" <?= $logRange['preset'] === 'custom' ? 'selected' : '' ?>>Personalizado</option>
          </select>
        </div>
        <div id="logRangeCustomFields" class="<?= $logRange['preset'] === 'custom' ? '' : 'hidden' ?> flex flex-wrap gap-3">
          <div>
            <label class="text-[11px] text-slate-500 uppercase tracking-widest">Início</label>
            <input type="date" name="log_start" value="<?= htmlspecialchars($logRange['custom_start'] ?? '') ?>"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
          </div>
          <div>
            <label class="text-[11px] text-slate-500 uppercase tracking-widest">Fim</label>
            <input type="date" name="log_end" value="<?= htmlspecialchars($logRange['custom_end'] ?? '') ?>"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
          </div>
        </div>
        <button type="submit" class="px-4 py-2 rounded-xl border border-primary text-primary text-sm font-semibold hover:bg-primary/5">
          Atualizar
        </button>
      </form>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-4">
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Mensagens</div>
          <div class="text-2xl font-semibold text-dark"><?= (int)$logSummary['total_messages'] ?></div>
          <div class="text-[11px] text-slate-500 mt-1">
            Recebidas: <?= (int)$logSummary['total_inbound'] ?> • Enviadas: <?= (int)$logSummary['total_outbound'] ?>
          </div>
        </div>
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Contatos</div>
          <div class="text-2xl font-semibold text-dark"><?= (int)$logSummary['total_contacts'] ?></div>
          <div class="text-[11px] text-slate-500 mt-1">Conversas únicas registradas</div>
        </div>
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Comandos</div>
          <div class="text-2xl font-semibold text-dark"><?= (int)$logSummary['total_commands'] ?></div>
          <div class="text-[11px] text-slate-500 mt-1">Funções e retornos identificados</div>
        </div>
        <div class="rounded-2xl border border-mid bg-slate-50 p-3">
          <div class="text-[11px] text-slate-500 uppercase tracking-widest">Agendamentos</div>
          <div class="text-2xl font-semibold text-dark">
            <?= (int)($logSummary['scheduled_pending'] + $logSummary['scheduled_sent'] + $logSummary['scheduled_failed']) ?>
          </div>
          <div class="text-[11px] text-slate-500 mt-1">
            Pendentes: <?= (int)$logSummary['scheduled_pending'] ?> • Enviados: <?= (int)$logSummary['scheduled_sent'] ?> • Falhas: <?= (int)$logSummary['scheduled_failed'] ?>
          </div>
        </div>
      </div>
      <div class="text-[11px] text-slate-500 mt-3">
        Período: <?= htmlspecialchars($logRange['label']) ?> • Última atividade: <?= htmlspecialchars(formatLogDateTime($logSummary['last_message_at']) ?: 'sem registros') ?>
      </div>
    </section>
    </div>
<?php
    }
}
