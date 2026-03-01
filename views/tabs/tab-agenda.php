<?php
/**
 * Tab: Agenda (Google Calendar)
 * renderTabAgenda() — calendar connection, availability builder, saved calendars.
 *
 * No external PHP globals required (calendar data loaded via JS).
 */

if (!function_exists('renderTabAgenda')) {
    function renderTabAgenda(): void
    {
?>
    <div data-tab-pane="tab-agenda" class="tab-pane space-y-6">
      <section id="calendarSettingsSection" class="bg-white border border-mid rounded-2xl p-6 mt-6 card-soft">
        <div class="font-medium mb-1">Google Calendar</div>
          <p class="text-sm text-slate-500 mb-4">
            Conecte o calendário da instância e cadastre disponibilidade para a IA agendar compromissos.
          </p>

          <div class="space-y-3">
            <div id="calendarStatus" class="text-xs text-slate-500">Carregando...</div>
            <div class="flex flex-wrap gap-2">
              <button id="calendarConnectButton" type="button"
                      class="px-3 py-2 rounded-xl bg-primary text-white text-sm font-medium hover:opacity-90">
                Conectar
              </button>
              <button id="calendarDisconnectButton" type="button"
                      class="px-3 py-2 rounded-xl border border-primary text-primary text-sm hover:bg-primary/5">
                Desconectar
              </button>
              <button id="calendarForceConnectButton" type="button"
                      class="px-3 py-2 rounded-xl border border-red-300 text-red-500 text-sm hover:bg-red-50">
                Forçar conectar
              </button>
            </div>
          </div>

          <div class="mt-5 border-t border-mid/70 pt-4 space-y-3">
            <div class="flex items-center justify-between">
              <label class="text-xs text-slate-500">Calendários do Google</label>
              <button id="calendarRefreshButton" type="button"
                      class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                Atualizar lista
              </button>
            </div>
            <select id="calendarGoogleSelect" class="w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm">
              <option value="">Selecione um calendário</option>
            </select>
          </div>

          <div class="mt-5 space-y-3">
            <div>
              <label class="text-xs text-slate-500">ID do calendário</label>
              <input id="calendarIdInput" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     placeholder="ex: primary ou id@group.calendar.google.com">
            </div>
            <div>
              <label class="text-xs text-slate-500">Timezone</label>
              <input id="calendarTimezoneInput" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     placeholder="America/Sao_Paulo">
            </div>
            <input type="hidden" id="calendarAvailabilityInput">
            <div id="calendarAvailabilityBuilder" class="space-y-3">
              <div class="flex items-center justify-between">
                <label class="text-xs text-slate-500 uppercase tracking-widest">Disponibilidade visual</label>
                <span class="text-[11px] text-slate-400">Adicione faixas de horário por dia</span>
              </div>
              <?php
              $availabilityDays = [
                  'mon' => 'Segunda-feira',
                  'tue' => 'Terça-feira',
                  'wed' => 'Quarta-feira',
                  'thu' => 'Quinta-feira',
                  'fri' => 'Sexta-feira',
                  'sat' => 'Sábado',
                  'sun' => 'Domingo'
              ];
              foreach ($availabilityDays as $dayKey => $dayLabel):
              ?>
                <div data-availability-day="<?= $dayKey ?>" class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3">
                  <div class="flex items-center justify-between">
                    <div class="text-xs font-semibold text-slate-600"><?= $dayLabel ?></div>
                    <button type="button" data-add-range-day="<?= $dayKey ?>"
                            class="text-[11px] font-semibold text-primary hover:underline">
                      Adicionar faixa
                    </button>
                  </div>
                  <div class="mt-2 space-y-2" data-availability-rows></div>
                  <p class="text-[11px] text-slate-400 mt-2">
                    Combine horários seguidos para definir quando a IA pode agendar eventos.
                  </p>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="flex items-center gap-2">
              <input type="checkbox" id="calendarDefaultCheckbox" class="h-4 w-4 rounded">
              <label for="calendarDefaultCheckbox" class="text-xs text-slate-500">Definir como padrão</label>
            </div>
            <div class="flex items-center gap-2">
              <button id="calendarSaveButton" type="button"
                      class="px-4 py-2 rounded-xl bg-primary text-white text-sm font-medium hover:opacity-90">
                Salvar calendário
              </button>
              <span id="calendarSaveStatus" class="text-xs text-slate-500">&nbsp;</span>
            </div>
          </div>

          <div class="mt-6 space-y-2">
            <div class="text-xs text-slate-500 uppercase tracking-widest">Calendários cadastrados</div>
            <div id="calendarConfigsList" class="space-y-2 text-sm text-slate-600">
              <div class="text-xs text-slate-400">Nenhum calendário cadastrado.</div>
            </div>
          </div>
        </div>
      </section>
    </div>
<?php
    }
}
