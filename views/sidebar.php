<?php
/**
 * Sidebar rendering function extracted from index.php
 * renderSidebarContent() — renders the left sidebar with instance list
 */

if (!function_exists('renderSidebarContent')) {
    function renderSidebarContent(array $instances, ?string $selectedInstanceId, array $statuses, array $connectionStatuses, bool $showAdminControls = true)
    {
        if (empty($instances)) {
            echo '<div class="p-6 text-center">
                    <p class="text-sm text-slate-400 mb-4">Nenhuma instância encontrada</p>
                    <a href="?create_instance" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-xl font-medium hover:bg-blue-700 transition-colors">
                        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"></path>
                        </svg>
                        Criar Nova Instância
                    </a>
                  </div>';
            return;
        }
        global $dashboardBaseUrl, $dashboardLogoUrl;
        $providerLabelMap = [
            'openai' => 'OpenAI',
            'gemini' => 'Gemini',
            'openrouter' => 'OpenRouter'
        ];
        ?>
    <div class="p-6 border-b border-mid">
      <a href="<?= htmlspecialchars($dashboardBaseUrl) ?>" class="flex items-center gap-3 inline-flex group">
        <div class="flex items-center justify-center h-12">
          <img src="<?= htmlspecialchars($dashboardLogoUrl) ?>" width="56" style="height:auto;" alt="Logomarca Maestro">
        </div>
        <div>
          <div class="text-lg font-semibold text-dark">Maestro</div>
          <div class="text-xs text-slate-500">WhatsApp Orchestrator</div>
        </div>
      </a>

      <?php if ($showAdminControls): ?>
      <button onclick="openCreateModal()" class="mt-4 w-full px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90 transition">
        Nova instância
      </button>
      <button onclick="window.location.href='campanhas.php'" class="mt-3 w-full px-4 py-2 rounded-xl border border-primary text-primary font-medium hover:bg-primary/5 transition">
        Campanhas
      </button>
      <button onclick="window.location.href='external_access.php'" class="mt-3 w-full px-4 py-2 rounded-xl border border-primary text-primary font-medium hover:bg-primary/5 transition">
        Acessos
      </button>
      <?php endif; ?>

      <input class="mt-4 w-full px-3 py-2 rounded-xl bg-light border border-mid text-sm"
             placeholder="Buscar instância...">
      <div class="mt-4 px-4 py-2 rounded-2xl border border-dashed border-slate-200 bg-white/80 text-xs text-slate-500">
        <button id="openDebugLogsButton" type="button"
                class="w-full text-left font-semibold text-slate-600 hover:text-slate-800 transition"
                <?= $selectedInstanceId ? '' : 'disabled' ?> title="Visualizar saída do servidor Baileys">
          Debug Baileys
        </button>
        <p class="text-[10px] text-slate-400 mt-1">Logs do servidor Node (stdout/stderr)</p>
      </div>
    </div>

    <div class="p-3 space-y-2 flex-1 overflow-y-auto">
      <div class="text-xs text-slate-500 px-2">INSTÂNCIAS</div>

      <?php foreach ($instances as $id => $inst): ?>
        <?php
          $isSelected = $id === $selectedInstanceId;
          $aiDetails = $inst['ai'] ?? [];
          $rawProviderLabel = strtolower($aiDetails['provider'] ?? ($inst['openai']['mode'] ?? 'openai'));
          $aiProviderLabel = $providerLabelMap[$rawProviderLabel] ?? ucfirst($rawProviderLabel);
          $aiEnabledTag = !empty($aiDetails['enabled'] ?? $inst['openai']['enabled'] ?? false);
          $secretaryDetails = $inst['secretary'] ?? [];
          $secretaryEnabledTag = !empty($secretaryDetails['enabled']);
          $quickReplies = $secretaryDetails['quick_replies'] ?? [];
          if (empty($quickReplies)) {
            $legacyTerm1 = trim((string)($secretaryDetails['term_1'] ?? ''));
            $legacyResp1 = trim((string)($secretaryDetails['response_1'] ?? ''));
            $legacyTerm2 = trim((string)($secretaryDetails['term_2'] ?? ''));
            $legacyResp2 = trim((string)($secretaryDetails['response_2'] ?? ''));
            if ($legacyTerm1 !== '' && $legacyResp1 !== '') {
              $quickReplies[] = ['term' => $legacyTerm1, 'response' => $legacyResp1];
            }
            if ($legacyTerm2 !== '' && $legacyResp2 !== '') {
              $quickReplies[] = ['term' => $legacyTerm2, 'response' => $legacyResp2];
            }
          }
          $quickRepliesEnabledTag = !empty($quickReplies);
          $transcriptionDetails = $inst['audio_transcription'] ?? [];
          $transcriptionEnabledTag = !empty($transcriptionDetails['enabled']);
          $serverRunning = $statuses[$id] === 'Running';
          $whatsappConnected = strtolower($connectionStatuses[$id] ?? '') === 'connected';
          $online = $serverRunning && $whatsappConnected;
        ?>
        <div class="instance-card <?= $isSelected ? 'is-selected' : '' ?>" data-instance-name="<?= htmlspecialchars(strtolower($inst['name'])) ?>">
          <a href="?instance=<?= $id ?>" class="block">
            <div class="instance-header">
              <div class="instance-name font-semibold text-lg text-dark"><?= htmlspecialchars($inst['name']) ?></div>
              <div class="instance-status-badge">
                <?php if ($online): ?>
                  <span class="badge-online">Online</span>
                <?php else: ?>
                  <span class="badge-offline">Offline</span>
                <?php endif; ?>
              </div>
            </div>
            <div class="instance-subheader">
              <div class="text-xs text-slate-500">
                WhatsApp: <?= $whatsappConnected ? 'conectado' : 'desconectado' ?>
              </div>
            </div>
          </a>
          <div class="instance-footer">
            <div class="instance-icons">
              <?php if ($transcriptionEnabledTag): ?>
                <div class="status-icon" title="Transcrição: ativa">
                  <svg class="w-4 h-4 text-green-600" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                  </svg>
                </div>
              <?php else: ?>
                <div class="status-icon" title="Transcrição: desativada">
                  <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path>
                  </svg>
                </div>
              <?php endif; ?>
              <?php if ($aiEnabledTag): ?>
                <div class="status-icon" title="Respostas automáticas: ativas">
                  <svg class="w-4 h-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                  </svg>
                </div>
              <?php else: ?>
                <div class="status-icon" title="Respostas automáticas: desativadas">
                  <svg class="w-4 h-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                  </svg>
                </div>
              <?php endif; ?>
              <?php if ($whatsappConnected): ?>
                <div class="status-icon" title="WhatsApp: conectado">
                  <svg class="w-4 h-4 text-green-600" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                  </svg>
                </div>
              <?php else: ?>
                <div class="status-icon" title="WhatsApp: desconectado">
                  <svg class="w-4 h-4 text-red-400" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
                  </svg>
                </div>
              <?php endif; ?>
              <?php if ($aiEnabledTag): ?>
                <div class="status-icon" title="IA: <?= htmlspecialchars($aiProviderLabel) ?>">
                  <svg class="w-4 h-4 text-purple-600" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                  </svg>
                </div>
              <?php endif; ?>
            </div>
            <div class="instance-actions">
              <a href="conversas.php?instance=<?= urlencode($id) ?>" class="action-btn">
                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M3 4.5A1.5 1.5 0 014.5 3h11A1.5 1.5 0 0117 4.5v6A1.5 1.5 0 0115.5 12H8l-4 4V4.5z"></path>
                </svg>
                Conversas
              </a>
              <a href="grupos.php?instance=<?= urlencode($id) ?>" class="action-btn">
                <svg class="w-3.5 h-3.5" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M7 7a3 3 0 116 0v1h1a2 2 0 012 2v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5a2 2 0 012-2h1V7z"></path>
                </svg>
                Grupos
              </a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

    </div>

    <div class="mt-auto p-6 border-t border-mid">
      <button onclick="logout()" class="w-full text-left text-sm text-slate-500 hover:text-dark">Logout</button>
      <div class="text-xs text-slate-500 mt-2">Maestro • MVP</div>
    </div>
    <?php
    }
}
