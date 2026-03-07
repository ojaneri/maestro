<?php
/**
 * Modal: QR Reset Confirmation Overlay
 * renderQrResetOverlay() — confirmation before resetting QR session.
 *
 * No PHP globals required.
 */

if (!function_exists('renderQrResetOverlay')) {
    function renderQrResetOverlay(): void
    {
?>
<div id="qrResetOverlay" class="qr-reset-overlay">
  <div class="qr-reset-card">
    <h3>Antes de reiniciar</h3>
    <p>Saia de todas as conexoes WhatsApp Web/desktop vinculadas a este numero. Isso evita conflito e permite gerar um novo QR.</p>
    <label class="qr-reset-check">
      <input id="qrResetConfirm" type="checkbox">
      Ja desconectei todas as sessoes
    </label>
    <div class="qr-reset-actions">
      <button id="qrResetConfirmBtn" class="px-4 py-2 rounded-xl bg-primary text-white hover:opacity-90">Confirmar e reiniciar</button>
      <button id="qrResetCancelBtn" class="px-4 py-2 rounded-xl border border-mid text-slate-600 hover:bg-light">Cancelar</button>
    </div>
  </div>
</div>
<!-- QR styles are in assets/css/dashboard.css -->
<?php
    }
}
