<?php
/**
 * Modal: AI Test
 * renderAiTestModal() — dialog for testing the AI provider configuration.
 *
 * No PHP globals required (JS injects instance/csrf via inline script).
 */

if (!function_exists('renderAiTestModal')) {
    function renderAiTestModal(): void
    {
?>
<!-- Modal for AI Test -->
<div id="aiTestModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50 px-4">
  <div class="bg-white rounded-2xl p-6 w-full max-w-lg mx-auto relative">
    <button id="closeAiTestModal" class="absolute top-3 right-3 text-slate-400 hover:text-dark">&times;</button>
    <h3 class="text-lg font-semibold mb-2">Testar IA</h3>
    <p class="text-xs text-slate-500 mb-4">Envie uma mensagem e veja como o provedor configurado responde.</p>
    <form id="aiTestForm" class="space-y-3">
      <label class="text-xs text-slate-500">Mensagem para teste</label>
      <textarea id="aiTestMessage" rows="4" class="w-full px-3 py-2 rounded-xl border border-mid bg-light" required></textarea>
      <div class="flex gap-3 items-center">
        <button type="submit" id="aiTestSubmit" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
          Enviar para IA
        </button>
        <span id="aiTestStatus" class="text-xs text-slate-500"></span>
      </div>
      <div id="aiTestResult" class="text-sm text-dark bg-light border border-mid rounded-xl px-3 py-2 min-h-[80px] whitespace-pre-line"></div>
    </form>
  </div>
</div>
<?php
    }
}
