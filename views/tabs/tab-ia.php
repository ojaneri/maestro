<?php
/**
 * Tab: IA (AI Settings)
 * renderTabIA() — AI provider config, model presets, prompts, functions panel.
 *
 * Expected globals:
 *   $selectedInstance, $selectedInstanceId, $integrationType,
 *   $aiEnabled, $aiProvider, $aiModel,
 *   $aiHistoryLimit, $aiTemperature, $aiMaxTokens, $aiMultiInputDelay,
 *   $aiSystemPrompt, $aiAssistantPrompt, $aiAssistantId,
 *   $aiOpenaiMode, $aiOpenaiApiKey,
 *   $aiGeminiApiKey, $aiGeminiInstruction,
 *   $aiModelFallback1, $aiModelFallback2,
 *   $aiOpenRouterApiKey, $aiOpenRouterBaseUrl,
 *   $aiConfig
 * Expected constants: DEFAULT_OPENROUTER_BASE_URL, DEFAULT_GEMINI_INSTRUCTION
 */

if (!function_exists('renderTabIA')) {
    function renderTabIA(): void
    {
        global $selectedInstance, $selectedInstanceId, $integrationType,
               $aiEnabled, $aiProvider, $aiModel,
               $aiHistoryLimit, $aiTemperature, $aiMaxTokens, $aiMultiInputDelay,
               $aiSystemPrompt, $aiAssistantPrompt, $aiAssistantId,
               $aiOpenaiMode, $aiOpenaiApiKey,
               $aiGeminiApiKey, $aiGeminiInstruction,
               $aiModelFallback1, $aiModelFallback2,
               $aiOpenRouterApiKey, $aiOpenRouterBaseUrl,
               $aiConfig;
?>
    <div data-tab-pane="tab-ia" class="tab-pane space-y-6">
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

      <section id="aiSettingsSection" class="xl:col-span-3 bg-white border border-mid rounded-2xl p-6 card-soft">
        <div class="font-medium mb-1">IA – OpenAI, Gemini &amp; OpenRouter</div>
        <p class="text-sm text-slate-500 mb-4">Defina o comportamento das respostas automáticas desta instância.</p>

        <form id="aiSettingsForm" class="space-y-4" onsubmit="return false;">
          <div class="flex items-center gap-2">
            <input type="checkbox" id="aiEnabled" class="h-4 w-4 rounded" <?= $aiEnabled ? 'checked' : '' ?>>
            <label for="aiEnabled" class="text-sm text-slate-600">
              Habilitar respostas automáticas
            </label>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <label class="text-xs text-slate-500">Provider</label>
              <select id="aiProvider" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm">
                <option value="openai" <?= $aiProvider === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                <option value="gemini" <?= $aiProvider === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                <option value="openrouter" <?= $aiProvider === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
              </select>
              <p class="text-xs text-slate-500 mt-1">
                O OpenRouter permite usar provedores agregados (via https://openrouter.ai). Configure a chave e URL abaixo para habilitá-lo.
              </p>
            </div>
            <div>
              <label class="text-xs text-slate-500">Modelo</label>
              <div class="space-y-3 mt-1">
                <select id="aiModelPreset" class="w-full px-3 py-2 rounded-xl border border-mid bg-white text-sm">
                  <!-- preenchido via JS -->
                </select>
                <input id="aiModel" class="w-full px-3 py-2 rounded-xl border border-mid bg-light"
                       value="<?= htmlspecialchars($aiModel) ?>" placeholder="Modelo principal">
                <div class="grid gap-2">
                  <input id="aiModelFallback1" class="w-full px-3 py-2 rounded-xl border border-mid bg-light text-xs"
                         value="<?= htmlspecialchars($aiModelFallback1) ?>" placeholder="Fallback 1 (opcional)">
                  <input id="aiModelFallback2" class="w-full px-3 py-2 rounded-xl border border-mid bg-light text-xs"
                         value="<?= htmlspecialchars($aiModelFallback2) ?>" placeholder="Fallback 2 (opcional)">
                </div>
                <p class="text-xs text-slate-500">
                  Informe até três modelos (principal + dois fallbacks). O sistema tentará os fallbacks caso o modelo anterior falhe.
                </p>
              </div>
            </div>
          </div>

          <div id="openaiFields" class="space-y-4 <?= $aiProvider === 'openai' ? '' : 'hidden' ?>">
            <div>
              <label class="text-xs text-slate-500">OpenAI API Key</label>
              <div class="relative mt-1">
                <input id="openaiApiKey" type="password" autocomplete="new-password"
                       class="w-full px-3 py-2 rounded-xl border border-mid bg-light pr-10"
                       placeholder="sk-..." value="<?= htmlspecialchars($aiOpenaiApiKey) ?>">
                <button id="toggleOpenaiKey" type="button"
                        class="absolute inset-y-0 right-2 flex items-center justify-center text-slate-500 hover:text-primary"
                        aria-pressed="false" aria-label="Mostrar ou ocultar chave">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M1.5 12s4.5-8.5 10.5-8.5S22.5 12 22.5 12s-4.5 8.5-10.5 8.5S1.5 12 1.5 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
              <p class="text-[11px] text-slate-500 mt-1">
                Use uma chave com acesso ao Responses e Assistants.
              </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
              <div>
                <label class="text-xs text-slate-500">API Mode</label>
                <select id="openaiMode" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm">
                  <option value="responses" <?= $aiOpenaiMode === 'responses' ? 'selected' : '' ?>>Responses API</option>
                  <option value="assistants" <?= $aiOpenaiMode === 'assistants' ? 'selected' : '' ?>>Assistants API</option>
                </select>
                <p class="text-xs text-slate-500 mt-1">
                  Choose if the instance keeps a thread (Assistants) or uses context snapshots (Responses).
                </p>
              </div>
              <div id="openaiAssistantRow" style="<?= $aiOpenaiMode === 'assistants' ? '' : 'display:none;' ?>">
                <label class="text-xs text-slate-500">Assistant ID</label>
                <input id="openaiAssistantId" class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light text-sm"
                       placeholder="assistant_id" value="<?= htmlspecialchars($aiAssistantId) ?>">
                <p class="text-xs text-slate-500 mt-1">
                  Obrigatório apenas no modo Assistants API.
                </p>
              </div>
            </div>

          <div>
            <div class="flex items-start justify-between gap-2">
              <label class="text-xs text-slate-500">System prompt</label>
              <button id="openaiSystemExpandBtn" type="button"
                      class="text-xs text-slate-600 border border-slate-300 rounded-full px-3 py-1 hover:border-primary hover:text-primary transition">
                Expandir
              </button>
            </div>
            <textarea id="aiSystemPrompt" rows="4"
                      class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                      placeholder="Descreva o papel do assistente"><?= htmlspecialchars($aiSystemPrompt) ?></textarea>
          </div>

          <div class="space-y-2">
            <div class="flex items-start justify-between gap-2">
              <label class="text-xs text-slate-500">Assistant instructions</label>
              <div class="flex items-center gap-2">
                <button id="openaiAssistantExpandBtn" type="button"
                        class="text-xs text-slate-600 border border-slate-300 rounded-full px-3 py-1 hover:border-primary hover:text-primary transition">
                  Expandir
                </button>
                <button id="aiFunctionsButton" type="button"
                        class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                  Funções disponíveis
                </button>
              </div>
            </div>
            <textarea id="aiAssistantPrompt" rows="4"
                      class="w-full px-3 py-2 rounded-xl border border-mid bg-light"
                      placeholder="Como o assistente deve responder?"><?= htmlspecialchars($aiAssistantPrompt) ?></textarea>
          </div>
          </div>

          <div id="geminiFields" class="space-y-4 <?= $aiProvider === 'gemini' ? '' : 'hidden' ?>">
            <div>
              <label class="text-xs text-slate-500">Gemini API Key</label>
              <div class="relative mt-1">
                <input id="geminiApiKey" type="password" autocomplete="new-password"
                       class="w-full px-3 py-2 rounded-xl border border-mid bg-light pr-10"
                       placeholder="GAPI..." value="<?= htmlspecialchars($aiGeminiApiKey) ?>">
                <button id="toggleGeminiKey" type="button"
                        class="absolute inset-y-0 right-2 flex items-center justify-center text-slate-500 hover:text-primary"
                        aria-pressed="false" aria-label="Mostrar ou ocultar chave">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M1.5 12s4.5-8.5 10.5-8.5S22.5 12 22.5 12s-4.5 8.5-10.5 8.5S1.5 12 1.5 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                  </svg>
                </button>
              </div>
              <p class="text-xs text-slate-500 mt-1">
                Utilize sua chave da Google Generative AI.
              </p>
            </div>
          </div>

          <div id="openrouterFields" class="space-y-4 <?= $aiProvider === 'openrouter' ? '' : 'hidden' ?>">
            <div>
              <label class="text-xs text-slate-500">OpenRouter API Key</label>
              <div class="relative mt-1">
                <input id="openrouterApiKey" type="password" autocomplete="new-password"
                       class="w-full px-3 py-2 rounded-xl border border-mid bg-light pr-10"
                       placeholder="Bearer ..." value="<?= htmlspecialchars($aiOpenRouterApiKey) ?>">
                <button id="toggleOpenrouterKey" type="button"
                        class="absolute inset-y-0 right-2 flex items-center justify-center text-slate-500 hover:text-primary"
                        aria-pressed="false" aria-label="Mostrar ou ocultar chave">
                </button>
              </div>
                <p class="text-xs text-slate-500 mt-1">
                  Use a chave Bearer disponível em https://openrouter.ai.
                </p>
            </div>
            <div>
              <label class="text-xs text-slate-500">Base URL do OpenRouter</label>
              <input id="openrouterBaseUrl" type="text"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     placeholder="<?= htmlspecialchars(DEFAULT_OPENROUTER_BASE_URL) ?>"
                     value="<?= htmlspecialchars($aiOpenRouterBaseUrl) ?>">
              <p class="text-xs text-slate-500 mt-1">
                Defina outro domínio caso esteja usando um deployment próprio; deixe em branco para usar <?= htmlspecialchars(DEFAULT_OPENROUTER_BASE_URL) ?>.
              </p>
            </div>
          </div>

          <div class="space-y-4">
            <?php if ($integrationType === 'meta'): ?>
              <div class="text-sm font-medium text-slate-700">Integração com Meta API (WhatsApp Business)</div>
              <p class="text-[11px] text-slate-500 mb-3">
                Configure as credenciais para enviar mensagens templates pre-aprovadas via WhatsApp Business API da Meta.
              </p>
              
              <div>
                <label class="text-xs text-slate-500">Meta Access Token</label>
                <input id="metaAccessToken" type="password" autocomplete="new-password"
                       class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                       placeholder="EAAM..." value="<?= htmlspecialchars($aiConfig['meta_access_token'] ?? '') ?>">
                <p class="text-[11px] text-slate-500 mt-1">
                  Token de acesso da Meta para autenticação na WhatsApp Business API.
                </p>
              </div>

              <div>
                <label class="text-xs text-slate-500">WABA ID</label>
                <input id="metaBusinessAccountId" type="text"
                       class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                       placeholder="123456789012345" value="<?= htmlspecialchars($aiConfig['meta_business_account_id'] ?? '') ?>">
                <p class="text-[11px] text-slate-500 mt-1">
                  ID da conta de negócio WhatsApp. Encontre em <a href="https://business.facebook.com/latest/settings/whatsapp_account" target="_blank" class="text-primary underline">https://business.facebook.com/latest/settings/whatsapp_account</a>.
                </p>
              </div>


              <div>
                <label class="text-xs text-slate-500">Telephone ID</label>
                <input id="metaTelephoneId" type="text"
                       class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                       placeholder="+5511999999999" value="<?= htmlspecialchars($aiConfig['meta_telephone_id'] ?? '') ?>">
                <p class="text-[11px] text-slate-500 mt-1">
                  O número de telefone associado à conta WhatsApp Business. Encontre em <a href="https://business.facebook.com/latest/whatsapp_manager/phone_numbers" target="_blank" class="text-primary underline">https://business.facebook.com/latest/whatsapp_manager/phone_numbers</a>.
                </p>
              </div>
            <?php endif; ?>
            <div class="space-y-2">
              <div class="flex items-start justify-between gap-2">
                <label class="text-xs text-slate-500">Instruções do Gemini</label>
                <div class="flex items-center gap-2">
                  <button id="geminiExpandBtn" type="button"
                          class="text-xs text-slate-600 border border-slate-300 rounded-full px-3 py-1 hover:border-primary hover:text-primary transition">
                    Expandir
                  </button>
                  <button id="geminiFunctionsButton" type="button"
                          class="text-xs text-primary border border-primary/60 rounded-full px-3 py-1 hover:bg-primary/5 transition">
                    Funções disponíveis
                  </button>
                </div>
              </div>
              <div id="geminiInstructionWrap" class="relative">
                <textarea id="geminiInstruction" rows="4"
                          class="w-full px-3 py-2 rounded-xl border border-mid bg-light"
                          placeholder="Instrua o Gemini"><?= htmlspecialchars($aiGeminiInstruction) ?></textarea>
              </div>
            </div>
            <div>
              <label class="text-xs text-slate-500">Credencial Gemini</label>
              <p class="text-[11px] text-slate-500 mt-1">
                O Gemini aceita apenas a API key configurada acima; não é necessário enviar um arquivo JSON de credenciais.
              </p>
            </div>
          </div>

            <div id="functionsPanel" class="hidden border border-mid/70 rounded-2xl bg-white p-4 shadow-sm text-sm text-slate-600 space-y-3">
            <div class="text-[11px] font-semibold uppercase tracking-widest text-slate-400">Funções disponíveis</div>
            <p class="text-[11px] text-slate-500">
              O parâmetro opcional <code>interno</code> (falso/0 por padrão) determina se o texto enviado deve chegar ao contato ou apenas figurar nos registros internos para a IA lembrar algo antes de mandar outro texto. Use <code>interno=1</code> para mensagens silenciosas e <code>interno=0</code> para que o cliente receba a mensagem diretamente.
            </p>
            <ul class="space-y-2">
              <li>
                <span class="font-semibold text-slate-800">dados("email")</span> – traz cadastro do cliente (nome, status, assinatura e expiração) para enriquecer o contexto.
              </li>
              <li>
                <span class="font-semibold text-slate-800">verificar_disponibilidade("inicio","fim","calendar_num","timezone")</span> – consulta se o intervalo está livre no Google Calendar (usa disponibilidade configurada). Use <code>calendar_num=1</code> para o primeiro calendário, <code>2</code> para o segundo, etc.
              </li>
              <li>
                <span class="font-semibold text-slate-800">sugerir_horarios("data","janela","duracao_min","limite","calendar_num","timezone")</span> – sugere horários livres dentro de uma janela (ex: "09:00-18:00"). Use <code>calendar_num=1</code>, <code>2</code>, etc.
              </li>
              <li>
                <span class="font-semibold text-slate-800">marcar_evento("titulo","inicio","fim","participantes","descricao","calendar_num","timezone")</span> – cria evento no Google Calendar usando o calendário número especificado.
              </li>
              <li>
                <span class="font-semibold text-slate-800">remarcar_evento("evento_id","novo_inicio","novo_fim","calendar_num","timezone")</span> – remarca evento existente no calendário especificado.
              </li>
              <li>
                <span class="font-semibold text-slate-800">desmarcar_evento("evento_id","calendar_num")</span> – remove evento do calendário número especificado.
              </li>
              <li>
                <span class="font-semibold text-slate-800">listar_eventos("inicio","fim","calendar_num","timezone")</span> – lista eventos no período do calendário especificado.
              </li>
              <li>
                <span class="font-semibold text-slate-800">agendar("DD/MM/AAAA","HH:MM","Texto","tag","tipo", interno?)</span> – agenda lembrete fixo em UTC-3 e retorna ID, horário, tag e tipo (tag padrão <code>default</code>, tipo <code>followup</code>). <span class="text-[11px] text-slate-500">Ex: <code>interno=0</code> envia “Texto” ao cliente; <code>interno=1</code> grava o texto como contexto interno para que a IA dispare uma nova resposta.</span>
              </li>
              <li>
                <span class="font-semibold text-slate-800">agendar2("+5m","Texto","tag","tipo", interno?)</span> – lembra em tempo relativo (m/h/d), também com tag/tipo configuráveis. <span class="text-[11px] text-slate-500">Ex: em <code>interno=1</code> o texto não chega ao cliente, mas fica visível apenas no log interno para que a IA continue o fluxo.</span>
              </li>
              <li>
                <span class="font-semibold text-slate-800">agendar3("YYYY-MM-DD HH:mm:ss","Mensagem","tag","tipo", interno?)</span> – agenda mensagem para data e hora exatas, ignorando o tempo decorrido até agora. <span class="text-[11px] text-slate-500">Ex: use <code>interno=1</code> quando quiser registrar uma nota temporária à IA antes de disparar outra mensagem às 16:00.</span>
              </li>
              <li>
                <span class="font-semibold text-slate-800">cancelar_e_agendar2("+24h","Texto","tag","tipo", interno?)</span> – cancela pendentes, dispara novo lembrete e devolve quantos foram cancelados. <span class="text-[11px] text-slate-500">Ex: defina <code>interno=1</code> para instruir a IA a ajustar o tom antes de reenviar um lembrete real.</span>
              </li>
              <li>
                <span class="font-semibold text-slate-800">cancelar_e_agendar3("YYYY-MM-DD HH:mm:ss","Mensagem","tag","tipo", interno?)</span> – limpa a fila pendente da tag e define o novo compromisso para a data/hora exatas indicadas. <span class="text-[11px] text-slate-500">Ex: <code>interno=0</code> confirma o envio direto; <code>interno=1</code> mantém o motivo registrado sem notificar o usuário.</span>
              </li>
              <li>
                <span class="font-semibold text-slate-800">listar_agendamentos("tag","tipo", interno?) / apagar_agenda("scheduledId", interno?) / apagar_agendas_por_tag("tag", interno?) / apagar_agendas_por_tipo("tipo", interno?)</span> – controlam o inventário de lembretes. <span class="text-[11px] text-slate-500">Inclua <code>interno=1</code> nas operações para tratar alterações apenas como notas internas (sem mensagens visíveis) antes de atualizar o usuário.</span>
              </li>
              <li>
                <span class="font-semibold text-slate-800">set_estado("estado") / get_estado()</span> – mantém o estágio atual do funil.
              </li>
              <li>
                <span class="font-semibold text-slate-800">set_contexto("chave","valor") / get_contexto("chave") / limpar_contexto(["chave"])</span> – memória curta por contato para pistas extras.
              </li>
              <li>
                <span class="font-semibold text-slate-800">set_variavel("chave","valor") / get_variavel("chave")</span> – variáveis persistentes por instância (não vinculadas ao contato).
              </li>
              <li>
                <span class="font-semibold text-slate-800">Contexto automático</span> – estado, contexto e status_followup são injetados em todos os prompts para a IA (não aparecem para o usuário final).
              </li>
              <li>
                <span class="font-semibold text-slate-800">optout()</span> – cancela follow-ups e marca o contato para não receber novas tentativas.
              </li>
              <li>
                <span class="font-semibold text-slate-800">template("ID_Template", "var1", "var2", "var3")</span> – envia uma mensagem template pre-aprovada via Meta API. As variáveis var1, var2 e var3 são opcionais.
              </li>
              <li>
                <span class="font-semibold text-slate-800">status_followup()</span> – resumo de estado, trilhas ativas e próximos agendamentos.
              </li>
              <li>
                <span class="font-semibold text-slate-800">tempo_sem_interacao()</span> – responde quanto tempo passou desde a última resposta do cliente.
              </li>
              <li>
                <span class="font-semibold text-slate-800">log_evento("categoria","descrição","json_opcional")</span> – auditoria leve com categoria e mensagem.
              </li>
              <li>
                <span class="font-semibold text-slate-800">boomerang()</span> – dispara imediatamente outra resposta (“Boomerang acionado”) e registra o aviso.
              </li>
              <li>
                <span class="font-semibold text-slate-800">whatsapp("numero","mensagem")</span> – envia mensagem direta via WhatsApp.
              </li>
              <li>
                <span class="font-semibold text-slate-800">mail("destino","assunto","corpo","remetente")</span> – envia um e-mail com sendmail local; o remetente é opcional e, se omitido, usa <code>noreply@janeri.com.br</code>.
              </li>
              <li>
                <span class="font-semibold text-slate-800">get_web("URL")</span> – busca até 1.200 caracteres de outra página para contexto.
              </li>
              <li>
                <span class="font-semibold text-slate-800">IMG:uploads/imagem.jpg|Legenda opcional</span> – envia a imagem indicada para o usuário (local em assets/uploads). Também aceita URL remota (http/https) e faz cache.
              </li>
              <li>
                <span class="font-semibold text-slate-800">VIDEO:uploads/video.mp4|Legenda opcional</span> – envia o vídeo indicado para o usuário (local em assets/uploads). Também aceita URL remota (http/https) e faz cache.
              </li>
              <li>
                <span class="font-semibold text-slate-800">AUDIO:uploads/audio.mp3</span> – envia o áudio indicado para o usuário (local em assets/uploads). Também aceita URL remota (http/https) e faz cache.
              </li>
              <li>
                <span class="font-semibold text-slate-800">CONTACT:+55DDDNNNNNNNN|Nome|Nota opcional</span> – envia um cartão de contato (o nome e a nota são opcionais). O bot também entende quando o usuário envia um contato e repassa os dados para a IA.
              </li>
            </ul>
            <p class="text-[11px] text-slate-500">
              É possível encadear várias funções em uma única resposta; elas serão executadas na ordem em que aparecem e não serão expostas ao usuário final.
            </p>
            <p class="text-[11px] text-slate-400">
              Clique novamente em “Funções disponíveis” para esconder este card.
            </p>
            <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3 text-[12px] space-y-2">
              <div class="font-medium text-slate-800">Guia para prompts</div>
              <p class="text-[11px] text-slate-500">
                Copie esse texto para o prompt da IA que alimenta o bot. Ele explica o comportamento esperado e todas as funções já disponíveis.
              </p>
              <pre id="functionsGuide" class="p-3 rounded-xl bg-slate-100 text-xs overflow-auto max-h-48" style="white-space: pre-wrap;">
Instruções de funções:

- dados("email"): traz nome, email, telefone, status e validade da assinatura do cadastro no MySQL kitpericia.
- verificar_disponibilidade("inicio","fim","calendar_num","timezone"): consulta se o intervalo está livre no Google Calendar. Use calendar_num=1 para o primeiro calendário, 2 para o segundo, etc.
- sugerir_horarios("data","janela","duracao_min","limite","calendar_num","timezone"): sugere horários livres dentro de uma janela (ex: "09:00-18:00"). Use calendar_num para especificar qual calendário usar.
- marcar_evento("titulo","inicio","fim","participantes","descricao","calendar_num","timezone"): cria evento no Google Calendar no calendário especificado.
- remarcar_evento("evento_id","novo_inicio","novo_fim","calendar_num","timezone"): remarca evento existente no calendário especificado.
- desmarcar_evento("evento_id","calendar_num"): remove evento do Google Calendar do calendário especificado.
- listar_eventos("inicio","fim","calendar_num","timezone"): lista eventos no período do calendário especificado.
- Importante: Use SEMPRE calendar_num (1, 2, 3...) em vez do ID longo do Google Calendar. O sistema mapeia automaticamente para o calendário correto.
- calendar_num começa em 1; calendar_num=0 ainda é aceito (depreciado) mas gera aviso de migração no log.
- timezone padrão usado quando você não envia outro é America/Fortaleza (a instância pode ter outro timezone configurado).
- agendar("DD/MM/AAAA","HH:MM","Texto","tag","tipo", interno?) / agendar2("+5m","Texto","tag","tipo", interno?): agendam lembretes com tag/tipo (padrões tag=default, tipo=followup) e retornam ID + horário; <code>interno=false</code> envia “Texto” ao cliente final, <code>interno=true</code> registra o texto como contexto interno sem expor ao usuário enquanto a IA prepara outra resposta.
- agendar3("YYYY-MM-DD HH:mm:ss","Texto","tag","tipo", interno?): agenda lembrete para o horário exato informado, ignorando o tempo até agora.
- agendar3("YYYY-MM-DD HH:mm:ss","Texto","tag","tipo", interno?): agenda lembrete para o horário exato informado, ignorando o tempo até agora; use <code>interno=true</code> para manter a nota somente no log interno e permitir que a IA gere nova mensagem no momento exato indicado.
- cancelar_e_agendar2("+24h","Texto","tag","tipo", interno?): cancela tudo pendente, cria novo lembrete e informa quantos foram cancelados.
- cancelar_e_agendar2("+24h","Texto","tag","tipo", interno?): cancela tudo pendente, cria novo lembrete e informa quantos foram cancelados; defina <code>interno=true</code> quando quiser replanejar internamente sem mandar mensagem ao cliente imediatamente.
- cancelar_e_agendar3("YYYY-MM-DD HH:mm:ss","Texto","tag","tipo", interno?): limpa os pendentes da tag e cria um lembrete para o horário exato fornecido.
- cancelar_e_agendar3("YYYY-MM-DD HH:mm:ss","Texto","tag","tipo", interno?): limpa os pendentes da tag e cria um lembrete para o horário exato fornecido; a flag <code>interno=true</code> registra a alteração apenas como contexto interno.
- listar_agendamentos("tag","tipo", interno?): lista agendamentos do contato; apagar_agenda("scheduledId", interno?), apagar_agendas_por_tag("tag", interno?) e apagar_agendas_por_tipo("tipo", interno?) mantêm o painel limpo.
- listar_agendamentos("tag","tipo", interno?) / apagar_agenda("scheduledId", interno?) / apagar_agendas_por_tag("tag", interno?) / apagar_agendas_por_tipo("tipo", interno?): listam e limpam os lembretes; combine com <code>interno=true</code> para operações que só devem ficar como memória interna, sem notificar o usuário.
- set_estado("estado") / get_estado(): salva e consulta o estágio do funil.
- set_contexto("chave","valor") / get_contexto("chave") / limpar_contexto(["chave"]): memória curta por contato para pistas extras.
- set_variavel("chave","valor") / get_variavel("chave"): variáveis persistentes por instância.
- optout(): cancela follow-ups pendentes e marca que o cliente não deve receber novas tentativas.
- template("ID_Template", "var1", "var2", "var3"): envia uma mensagem template pre-aprovada via Meta API. As variáveis var1, var2 e var3 são opcionais.
- status_followup(): resumo de estado, trilhas ativas e próximos agendamentos pendentes.
- estado, contexto e status_followup são injetados automaticamente em todo prompt enviado à IA.
- tempo_sem_interacao(): retorna há quantos segundos o cliente está em silêncio, útil para ajustar o tom (curto = gentil, longo = acolhedor).
- log_evento("categoria","descrição","json_opcional"): auditoria leve para métricas.
- boomerang(): sinaliza envio imediato de "Boomerang acionado".
- whatsapp("numero","mensagem"), mail("destino","assunto","corpo","remetente") e get_web("URL") seguem como antes (remetente opcional; padrão noreply@janeri.com.br).
- Use `IMG:uploads/<arquivo>` para enviar imagens direto de assets/uploads. Também aceita URL remota (http/https) e faz cache. Você pode anotar uma legenda com `|Legenda`. Combine com `#` para manter o texto organizado.
- Use `VIDEO:uploads/<arquivo>` para enviar vídeos direto de assets/uploads. Também aceita URL remota (http/https) e faz cache. Legenda opcional com `|Legenda`.
- Use `AUDIO:uploads/<arquivo>` para enviar áudios direto de assets/uploads. Também aceita URL remota (http/https) e faz cache.

Se der erro em qualquer função do Google Calendar, observe `data.allowed_calendar_nums` e `data.calendars_debug` no retorno para saber quais calendários estão configurados.
- Use `CONTACT:<telefone>|Nome|Nota` para enviar um cartão vCard; o bot também repassa contatos recebidos para a IA no formato “CONTATO RECEBIDO”.

Retorno recomendado:
{
  ok: true|false,
  code: "OK"|"ERR_INVALID_ARGS"|...,
  message: "texto curto",
  data: { ... }
}

Como usar:
1. Sempre finalize sua resposta com as funções desejadas no formato `funcao("arg1","arg2",...)`; múltiplas funções podem ser separadas por linha ou espaço.
2. Evite texto livre extra quando quiser apenas acionar funções; explicações podem vir antes dos comandos.
3. O bot remove esses comandos antes de responder ao usuário.
4. Ajuste o tom usando `tempo_sem_interacao()` e, quando necessário, `status_followup()` para acompanhar o funil.
5. Separe o texto destinado ao usuário das instruções/funções com `&&&`; o que vier depois do marcador será tratado como comandos e não será enviado ao WhatsApp.
</pre>
              <div class="flex justify-end gap-2">
                <button id="copyFunctionsGuide" class="px-3 py-1 text-[11px] font-medium rounded-full border border-primary text-primary hover:bg-primary/10 transition">Copiar guia</button>
                <span id="functionsGuideFeedback" class="text-[11px] text-success hidden">Copiado!</span>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div>
              <label class="text-xs text-slate-500">Histórico (últimas mensagens)</label>
              <input id="aiHistoryLimit" type="number" min="1" step="1"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     value="<?= htmlspecialchars($aiHistoryLimit) ?>">
            </div>
            <div>
              <label class="text-xs text-slate-500">Temperatura</label>
              <input id="aiTemperature" type="number" min="0" max="2" step="0.1"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     value="<?= htmlspecialchars($aiTemperature) ?>">
            </div>
            <div>
              <label class="text-xs text-slate-500">Tokens máximos</label>
              <input id="aiMaxTokens" type="number" min="64" step="1"
                     class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                     value="<?= htmlspecialchars($aiMaxTokens) ?>">
            </div>
          </div>

          <div>
            <label class="text-xs text-slate-500">Delay multi-input (segundos)</label>
            <input id="aiMultiInputDelay" type="number" min="0" step="1"
                   class="mt-1 w-full px-3 py-2 rounded-xl border border-mid bg-light"
                   value="<?= htmlspecialchars($aiMultiInputDelay) ?>">
            <p class="text-[11px] text-slate-500 mt-1">
              Aguarda esta quantidade de segundos antes de responder para coletar mensagens adicionais do usuário.
            </p>
          </div>

          <div class="flex flex-wrap gap-2 items-center">
            <button type="button" id="saveAIButton"
                    class="px-4 py-2 rounded-xl bg-primary text-white font-medium hover:opacity-90">
              Salvar
            </button>
            <button type="button" id="testAIButton"
                    class="px-4 py-2 rounded-xl border border-primary text-primary hover:bg-primary/5">
              Testar IA
            </button>
            <p id="aiStatus" aria-live="polite" class="text-sm text-slate-500 mt-2 sm:mt-0">
              &nbsp;
            </p>
          </div>
        </form>
      </section>

      </div>
    </div>
<?php
    }
}
