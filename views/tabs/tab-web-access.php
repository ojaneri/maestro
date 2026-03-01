<?php
/**
 * Tab: Acesso Web
 * renderTabWebAccess() — public chat URL, email identity, embed/floating snippets.
 *
 * Expected globals: $webAccessUrl, $webEmbedSnippet, $webFloatingSnippet
 */

if (!function_exists('renderTabWebAccess')) {
    function renderTabWebAccess(): void
    {
        global $webAccessUrl, $webEmbedSnippet, $webFloatingSnippet;
?>
    <div data-tab-pane="tab-web-access" class="tab-pane space-y-6">
      <section class="bg-white border border-mid rounded-2xl p-6 card-soft">
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div>
            <h3 class="text-lg font-semibold text-dark">Acesso Web</h3>
            <p class="text-sm text-slate-500">Link público que replica a conversa como se fosse WhatsApp, pronto para embedar.</p>
          </div>
          <span class="px-3 py-1 rounded-full border border-slate-200 text-[11px] text-slate-500 uppercase tracking-widest">Sem autenticação</span>
        </div>
        <?php if ($webAccessUrl): ?>
          <div class="mt-4 space-y-2 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-semibold text-slate-500">URL:</span>
              <a href="<?= htmlspecialchars($webAccessUrl, ENT_QUOTES) ?>" target="_blank" class="text-primary font-semibold hover:underline"><?= htmlspecialchars($webAccessUrl, ENT_QUOTES) ?></a>
            </div>
            <p class="text-xs text-slate-400">
              Sem login: basta compartilhar o link, e qualquer visitante pode conversar com o robô. Insira o mesmo e-mail usado em WhatsApp para compartilhar o LID automaticamente.
            </p>
          </div>
          <form id="webIdentityForm" class="mt-4 grid gap-3 lg:grid-cols-[1.7fr_auto]">
            <div>
              <label class="text-xs text-slate-500">Identificação por e-mail</label>
              <input id="webIdentityInput" type="email" placeholder="cliente@email.com"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm">
              <p class="text-[11px] text-slate-400 mt-1">
                Use exatamente o e-mail do seu lead no WhatsApp para que o mesmo LID seja compartilhado entre os canais.
              </p>
            </div>
            <div class="flex gap-2">
              <button type="submit" class="w-full px-4 py-2 rounded-xl bg-primary text-white text-sm font-semibold hover:opacity-90">
                Aplicar e-mail
              </button>
              <button type="button" id="clearWebIdentity" class="w-full px-4 py-2 rounded-xl border border-mid text-sm font-semibold text-slate-600 hover:bg-slate-50">
                Limpar
              </button>
            </div>
          </form>
          <div id="webRemoteIdInfo" class="mt-3 text-xs text-slate-500">
            ID atual (LID): <span id="webRemoteIdDisplay" class="font-semibold text-slate-700">---</span>
          </div>
        <?php else: ?>
          <p class="mt-4 text-sm text-slate-500">Defina uma instância para habilitar o acesso web público.</p>
        <?php endif; ?>
      </section>

      <section class="bg-white border border-mid rounded-2xl p-6 card-soft">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-base font-semibold text-dark">Código para embutir</h4>
            <p class="text-xs text-slate-500 mt-1">
              Cole em qualquer página para exibir o chat como um iframe.
            </p>
          </div>
          <button type="button" data-copy-snippet="embedCodeSnippet" class="text-xs text-primary hover:underline" <?= $webAccessUrl ? '' : 'disabled' ?>>
            Copiar
          </button>
        </div>
        <textarea id="embedCodeSnippet" class="mt-3 w-full rounded-2xl border border-mid bg-slate-50 p-3 text-xs text-slate-700" rows="4" readonly><?= htmlspecialchars($webEmbedSnippet) ?></textarea>

        <div class="mt-6 flex items-center justify-between">
          <div>
            <h4 class="text-base font-semibold text-dark">Botão flutuante</h4>
            <p class="text-xs text-slate-500 mt-1">
              Um snippet pronto para deixar um botão estilo WhatsApp que abre o iframe.
            </p>
          </div>
          <button type="button" data-copy-snippet="floatingSnippet" class="text-xs text-primary hover:underline" <?= $webAccessUrl ? '' : 'disabled' ?>>
            Copiar
          </button>
        </div>
        <textarea id="floatingSnippet" class="mt-3 w-full rounded-2xl border border-mid bg-slate-50 p-3 text-xs text-slate-700" rows="6" readonly><?= htmlspecialchars($webFloatingSnippet) ?></textarea>
        <p class="text-[11px] text-slate-400 mt-3">
          Os códigos inseridos acima já lidam com o iframe e o botão flutuante; você pode ajustar o estilo se precisar.
        </p>
      </section>
    </div>
<?php
    }
}
