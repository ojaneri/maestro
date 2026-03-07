<?php
/**
 * Modal: QR Code
 * renderQrModal() — displays QR code for WhatsApp connection via Baileys.
 *
 * No PHP globals required (all data loaded via JavaScript).
 */

if (!function_exists('renderQrModal')) {
    function renderQrModal(): void
    {
?>
<!-- Modal for QR Code -->
<div id="qrModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-lg font-semibold">Conectar WhatsApp</h2>
      <button onclick="closeQRModal()" class="text-slate-500 hover:text-dark">&times;</button>
    </div>
    <p class="text-sm text-slate-600 mb-4">Escaneie o código QR abaixo com o WhatsApp para conectar esta instância.</p>
    <div id="qrBox" class="text-center space-y-3">
      <img id="qrImage" src="" alt="Código QR" class="mx-auto" style="display:none;">
      <p id="qrStatus" class="text-sm text-slate-500 mx-auto"></p>
    </div>
    <div id="qrConnectedCard" class="qr-connected-card hidden">
      <div class="qr-confetti" aria-hidden="true">
        <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
      </div>
      <div class="flex items-center gap-4">
        <div class="qr-badge">
          <svg width="44" height="44" viewBox="0 0 64 64" fill="none" aria-hidden="true">
            <circle cx="32" cy="32" r="28" fill="#0f766e"/>
            <path d="M22 33.5l6.5 6.5L42 26" stroke="#ffffff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div>
          <div class="qr-connected-title">Conectado com sucesso</div>
          <div class="qr-connected-subtitle">WhatsApp online. Pode fechar esta janela.</div>
        </div>
      </div>
      <div class="qr-sparkle" aria-hidden="true"></div>
    </div>
    <div class="qr-status-grid mt-4" id="qrStatusGrid" aria-live="polite">
      <div><span>Status</span><strong id="qrStatusConnection">-</strong></div>
      <div><span>Conectado</span><strong id="qrStatusConnected">-</strong></div>
      <div><span>QR ativo</span><strong id="qrStatusHasQr">-</strong></div>
      <div><span>Ultimo erro</span><strong id="qrStatusError">-</strong></div>
    </div>
    <p id="qrStatusNote" class="text-xs text-slate-500 mt-3">Se o QR nao aparecer, reinicie a sessao e aguarde alguns minutos.</p>
    <div id="qrActions" class="mt-4 space-y-2">
      <button onclick="refreshQR()" class="w-full px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">Atualizar QR</button>
      <button onclick="openQrResetConfirm()" class="w-full px-4 py-2 rounded-xl bg-primary text-white hover:opacity-90">Reiniciar sessao</button>
    </div>
  </div>
</div>
<?php
    }
}
