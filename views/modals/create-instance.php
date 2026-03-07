<?php
/**
 * Modal: Create Instance
 * renderCreateModal() — form to create a new WhatsApp instance.
 *
 * Expected globals: $_SESSION['csrf_token']
 */

if (!function_exists('renderCreateModal')) {
    function renderCreateModal(): void
    {
?>
<!-- Modal for Create Instance -->
<div id="createModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4">
    <div class="flex justify-between items-center mb-4">
      <h2 class="text-lg font-semibold">Criar nova instância</h2>
      <button onclick="closeCreateModal()" class="text-slate-500 hover:text-dark">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">
      <div class="mb-4">
        <label class="text-xs text-slate-500">Nome da instância</label>
        <input type="text" name="name" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="Ex: Instância Principal" required>
      </div>
      <button type="submit" name="create" class="w-full px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">Criar instância</button>
    </form>
  </div>
</div>
<?php
    }
}
