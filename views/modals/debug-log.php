<?php
/**
 * Modal: Debug Baileys Log Overlay
 * renderDebugLogOverlay() — full-screen overlay showing Baileys stdout/stderr logs.
 *
 * Expected globals:
 *   $selectedInstance, $selectedInstanceId,
 *   $baileysDebugPaths, $baileysDebugLogs
 * Expected constants: BAILEYS_LOG_LINE_LIMIT
 */

if (!function_exists('renderDebugLogOverlay')) {
    function renderDebugLogOverlay(): void
    {
        global $selectedInstance, $selectedInstanceId, $baileysDebugPaths, $baileysDebugLogs;
?>
<div id="debugLogOverlay" class="hidden fixed inset-0 z-50 flex items-center justify-center px-4 py-6 backdrop-blur bg-slate-900/60">
  <div class="relative w-full max-w-5xl max-h-[90vh] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl flex flex-col">
    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-6 py-4">
      <div>
        <h3 class="text-lg font-semibold text-slate-900">Debug Baileys</h3>
        <p id="debugLogInstanceLabel" class="text-xs text-slate-500">
          <?= $selectedInstance ? htmlspecialchars($selectedInstance['name'] ?? '') : ($selectedInstanceId ? htmlspecialchars($selectedInstanceId) : 'Selecione uma instância') ?>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500 rounded-full border border-slate-200 bg-white hover:border-slate-400" data-log-type="out">
          stdout
        </button>
        <button type="button" class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500 rounded-full border border-slate-200 bg-white hover:border-slate-400" data-log-type="error">
          stderr
        </button>
        <button id="closeDebugLogOverlay" type="button" class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-500 rounded-full border border-slate-200 bg-white hover:border-slate-400">
          Fechar
        </button>
      </div>
    </div>
    <div class="flex-1 flex flex-col bg-slate-900 px-6 py-5">
      <div class="flex items-center justify-between text-[11px] text-slate-300">
        <span id="debugLogSourceLabel">Arquivo: <?= htmlspecialchars($baileysDebugPaths['out'] ?? 'não disponível') ?></span>
        <span>Últimos <?= BAILEYS_LOG_LINE_LIMIT ?> registros</span>
      </div>
      <pre id="debugLogOutput"
           class="mt-3 flex-1 overflow-y-auto rounded-2xl border border-slate-800 bg-slate-950/90 p-4 text-[12px] leading-relaxed text-white font-mono whitespace-pre-wrap"><?= htmlspecialchars($baileysDebugLogs['out'] ?: 'Nenhum log disponível para stdout.', ENT_QUOTES) ?></pre>
    </div>
  </div>
</div>
<?php
    }
}
