<?php
/**
 * Tab: Templates (Meta only)
 * renderTabTemplates() — WhatsApp Business API template management.
 *
 * Expected globals: $quickConfigIntegrationType
 */

if (!function_exists('renderTabTemplates')) {
    function renderTabTemplates(): void
    {
        global $quickConfigIntegrationType;

        if ($quickConfigIntegrationType !== 'meta') {
            return;
        }
?>
        <div data-tab-pane="tab-templates" class="tab-pane space-y-6">
          <div class="flex items-center justify-between mb-4">
            <div>
              <div class="text-lg font-semibold text-dark">Templates WhatsApp</div>
              <p class="text-sm text-slate-500">Gerencie templates de mensagem para a API do Meta</p>
            </div>
            <button id="refreshTemplatesBtn" type="button" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Atualizar Status
            </button>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Approved Templates -->
            <div class="lg:col-span-1 bg-white border border-mid rounded-2xl p-6 card-soft">
              <div class="font-medium mb-4 flex items-center gap-2">
                <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                Templates Aprovados
              </div>
              <div id="approvedTemplatesList" class="space-y-3">
                <div class="text-xs text-slate-500">Carregando...</div>
              </div>
            </div>

            <!-- Test Send -->
            <div class="lg:col-span-1 bg-white border border-mid rounded-2xl p-6 card-soft">
              <div class="font-medium mb-4 flex items-center gap-2">
                <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                Envio de Teste
              </div>
              <form id="testSendForm" class="space-y-4">
                <div>
                  <label class="text-xs text-slate-500">Template</label>
                  <select id="testTemplateSelect" name="template_name" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" required>
                    <option value="">Selecione um template...</option>
                  </select>
                </div>
                <div>
                  <label class="text-xs text-slate-500">Número de destino</label>
                  <input type="text" id="testPhoneInput" name="to" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="5585999999999" required>
                </div>
                <div id="testVariablesContainer" class="space-y-2">
                  <!-- Variables will be added dynamically -->
                </div>
                <button type="submit" id="testSendBtn" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
                  Enviar Teste
                </button>
                <div id="testSendStatus" class="text-sm text-slate-500">&nbsp;</div>
              </form>
            </div>

            <!-- Bulk Send -->
            <div class="lg:col-span-1 bg-white border border-mid rounded-2xl p-6 card-soft">
              <div class="font-medium mb-4 flex items-center gap-2">
                <div class="w-3 h-3 bg-purple-500 rounded-full"></div>
                Envio em Massa
              </div>
              <form id="bulkSendForm" class="space-y-4">
                <div>
                  <label class="text-xs text-slate-500">Template</label>
                  <select id="bulkTemplateSelect" name="template_name" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" required>
                    <option value="">Selecione um template...</option>
                  </select>
                </div>
                <div>
                  <label class="text-xs text-slate-500">Lista de números (um por linha)</label>
                  <textarea id="bulkPhonesTextarea" name="recipients" rows="4" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light" placeholder="5585999999999&#10;5585988888888&#10;5585977777777" required></textarea>
                </div>
                <div id="bulkVariablesContainer" class="space-y-2">
                  <!-- Variables will be added dynamically -->
                </div>
                <button type="submit" id="bulkSendBtn" class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
                  Enviar em Massa
                </button>
                <div id="bulkSendStatus" class="text-sm text-slate-500">&nbsp;</div>
              </form>
            </div>
          </div>

            <!-- Pending Templates -->
            <div class="lg:col-span-1 bg-white border border-mid rounded-2xl p-6 card-soft">
              <div class="font-medium mb-4 flex items-center gap-2">
                <div class="w-3 h-3 bg-yellow-500 rounded-full"></div>
                Templates Pendentes
              </div>
              <div id="pendingTemplatesList" class="space-y-3">
                <div class="text-xs text-slate-500">Carregando...</div>
              </div>
            </div>

            <!-- Rejected Templates -->
            <div class="lg:col-span-1 bg-white border border-mid rounded-2xl p-6 card-soft">
              <div class="font-medium mb-4 flex items-center gap-2">
                <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                Templates Rejeitados
              </div>
              <div id="rejectedTemplatesList" class="space-y-3">
                <div class="text-xs text-slate-500">Carregando...</div>
              </div>
            </div>
          </div>

        </div>
<?php
    }
}
