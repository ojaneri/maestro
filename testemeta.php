<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Template Manager - Enhanced Debug</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .animate-spin-custom { animation: spin 1s linear infinite; }
        .log-entry { border-left: 3px solid #334155; margin-bottom: 12px; padding-left: 10px; }
        .log-error { border-left-color: #ef4444; background: rgba(239, 68, 68, 0.05); }
        .log-success { border-left-color: #10b981; }
    </style>
</head>
<body class="min-h-screen bg-slate-50 p-4 md:p-8 text-slate-800 font-sans">

    <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-4 gap-6">
        
        <!-- Coluna de Configuração -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <div class="flex items-center gap-2 mb-4 text-indigo-600">
                    <i data-lucide="settings" size="20"></i>
                    <h2 class="font-bold text-lg">Configuração API</h2>
                </div>
                <div class="space-y-4 text-sm">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Bearer Token</label>
                        <input type="password" id="api-token" value="EAAVZBxDK51ZAEBQerZBMXKBrg9ymvjD0NLl7UlkGP5jyBxLIKZB82zrggZACOZAIlwdji2StH4BabUlctDi91NdUl7xiCHlIXi51DksqwyOkV56Pzb0fweF6obuQ8kVisfQWrJ5uLEEyaZCuLZC4Uxpzow81FDKi2JEYJgG9hJvU0UDMyLiwqKoR5zmPARPPQAZDZD" class="w-full p-2 bg-slate-50 rounded-lg outline-none focus:ring-2 focus:ring-indigo-500 border border-slate-100">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">WABA ID</label>
                        <input type="text" id="waba-id" value="746855881809628" class="w-full p-2 bg-slate-50 rounded-lg outline-none focus:ring-2 focus:ring-indigo-500 border border-slate-100">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Phone ID</label>
                        <input type="text" id="phone-id" value="1000028806518854" class="w-full p-2 bg-slate-50 rounded-lg outline-none focus:ring-2 focus:ring-indigo-500 border border-slate-100">
                    </div>
                </div>
            </div>

            <div id="status-card" class="bg-indigo-900 p-6 rounded-2xl shadow-lg text-white transition-all">
                <h3 class="text-indigo-200 text-xs font-bold uppercase tracking-widest">Monitoramento Ativo</h3>
                <div class="mt-2 flex items-center gap-3">
                    <div id="status-icon-container">
                        <i data-lucide="clock" id="status-icon" size="20"></i>
                    </div>
                    <span id="status-text" class="text-xl font-bold capitalize">Inativo</span>
                </div>
            </div>
        </div>

        <!-- Coluna Central -->
        <div class="lg:col-span-3 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Editor de Template -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                    <div class="flex items-center justify-between mb-4 text-indigo-600 font-bold">
                        <div class="flex items-center gap-2"><i data-lucide="file-plus" size="20"></i> Criar Template</div>
                    </div>
                    <div class="space-y-3">
                        <input type="text" id="tpl-name" value="aviso_projeto_21" class="w-full p-3 bg-slate-50 rounded-xl border-none outline-none font-mono text-sm focus:ring-2 focus:ring-indigo-500" placeholder="nome_do_template">
                        <textarea id="tpl-body" rows="3" class="w-full p-3 bg-slate-50 rounded-xl border-none outline-none text-sm focus:ring-2 focus:ring-indigo-500" placeholder="Corpo: Olá {{1}}, seu código é {{2}}">Olá {{1}}! Seu protocolo de atendimento é {{2}}. Estamos processando sua solicitação.</textarea>
                        <button id="btn-create" class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-300 text-white rounded-xl font-bold transition-all shadow-lg flex items-center justify-center gap-2">
                            Analisar Novo Template
                        </button>
                    </div>
                </div>

                <!-- Histórico de Templates da Sessão -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                    <div class="flex items-center gap-2 mb-4 text-slate-700 font-bold">
                        <i data-lucide="list-checks" size="20"></i> Histórico Recente
                    </div>
                    <div id="template-history" class="space-y-2 max-h-[180px] overflow-y-auto custom-scrollbar text-xs">
                        <div class="text-slate-400 italic">Nenhum template enviado ainda...</div>
                    </div>
                </div>
            </div>

            <!-- Seção de Envio -->
            <div id="send-section" class="opacity-40 pointer-events-none transition-all duration-500">
                <div class="bg-white p-6 rounded-2xl border border-emerald-100 shadow-sm">
                    <div class="flex items-center gap-2 mb-4 text-emerald-600 font-bold">
                        <i data-lucide="message-square" size="20"></i> Disparar Mensagem (Aprovado)
                    </div>
                    <div class="flex flex-col md:flex-row gap-2">
                        <input type="text" id="dest-number" class="flex-1 p-3 bg-slate-50 rounded-xl outline-none border border-transparent focus:border-emerald-300" placeholder="5585986030781">
                        <button id="btn-send" class="px-8 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-bold transition-all">Enviar Teste</button>
                    </div>
                </div>
            </div>

            <!-- Rastreamento de Mensagens -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <div class="p-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                    <div class="flex items-center gap-2 font-bold text-slate-700">
                        <i data-lucide="history" size="18" class="text-indigo-500"></i>
                        Status das Mensagens Enviadas
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 uppercase font-bold">
                                <th class="p-3">Destino / ID</th>
                                <th class="p-3">Enviado</th>
                                <th class="p-3">Entregue</th>
                                <th class="p-3">Lido</th>
                                <th class="p-3">Status</th>
                            </tr>
                        </thead>
                        <tbody id="tracking-table-body" class="divide-y divide-slate-100">
                            <tr><td colspan="5" class="p-6 text-center text-slate-400 italic">Aguardando envios...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TERMINAL DE LOGS AVANÇADO -->
            <div class="bg-slate-900 rounded-2xl p-4 shadow-2xl border border-slate-700">
                <div class="flex items-center justify-between mb-2 text-slate-500 border-b border-slate-800 pb-2">
                    <div class="flex items-center gap-2 italic">
                        <i data-lucide="terminal" size="14"></i> 
                        <span class="text-[10px] font-mono uppercase tracking-widest">Console de Debug Meta</span>
                    </div>
                    <button onclick="document.getElementById('log-container').innerHTML = ''" class="text-[9px] hover:text-white uppercase">Limpar Console</button>
                </div>
                <div id="log-container" class="h-80 overflow-y-auto space-y-3 font-mono text-[10px] custom-scrollbar p-2">
                    <div class="text-slate-600 font-bold underline mb-2 tracking-widest">AGUARDANDO AÇÃO...</div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let appState = {
            templateId: null,
            status: 'idle',
            trackedMessages: [],
            templateHistory: [],
            countdown: 30
        };

        function addVerboseLog(method, url, requestBody, response, statusType = 'info') {
            const container = document.getElementById('log-container');
            const time = new Date().toLocaleTimeString();
            
            let colorClass = 'text-blue-400';
            let entryClass = 'log-entry';
            
            if (statusType === 'error') {
                colorClass = 'text-rose-500';
                entryClass += ' log-error';
            }
            if (statusType === 'success') {
                colorClass = 'text-emerald-400';
                entryClass += ' log-success';
            }

            // Tratamento especial para mensagens de erro da Meta (error_user_msg)
            let errorAlert = '';
            if (response?.error) {
                const err = response.error;
                if (err.error_user_msg || err.message) {
                    errorAlert = `
                        <div class="mt-2 p-2 bg-rose-900/30 border border-rose-500/50 rounded text-rose-200">
                            <div class="font-bold underline uppercase mb-1">Causa Detectada:</div>
                            <div class="text-[11px]">${err.error_user_title || 'Erro na Requisição'}: ${err.error_user_msg || err.message}</div>
                            ${err.error_subcode ? `<div class="mt-1 opacity-70 italic text-[9px]">Subcode: ${err.error_subcode} (Consulte a documentação da Meta)</div>` : ''}
                        </div>
                    `;
                }
            }

            const logHtml = `
                <div class="${entryClass}">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-slate-600 font-bold">[${time}]</span>
                        <span class="bg-slate-800 px-1 rounded text-white font-bold">${method}</span>
                        <span class="${colorClass} font-bold break-all">${url}</span>
                    </div>
                    <div class="pl-4 space-y-1">
                        <div class="text-slate-500"><span class="opacity-50">Payload:</span> <span class="text-slate-400">${JSON.stringify(requestBody)}</span></div>
                        <div class="text-slate-500"><span class="opacity-50">Response:</span> <span class="${statusType === 'error' ? 'text-rose-300' : 'text-slate-300'}">${JSON.stringify(response)}</span></div>
                        ${errorAlert}
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('afterbegin', logHtml);
        }

        async function metaApiCall(method, url, body = null) {
            const token = document.getElementById('api-token').value;
            const options = {
                method: method,
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Content-Type': 'application/json'
                }
            };
            if (body) options.body = JSON.stringify(body);

            try {
                const response = await fetch(url, options);
                const data = await response.json();
                
                if (!response.ok) {
                    addVerboseLog(method, url, body, data, 'error');
                    throw new Error(data?.error?.message || 'Erro Desconhecido');
                }
                
                addVerboseLog(method, url, body, data, 'success');
                return data;
            } catch (err) {
                if (!err.message.includes('Erro Desconhecido') && !err.message.includes('fetch')) {
                    // Já foi logado no bloco response.ok
                } else {
                    addVerboseLog(method, url, body, { error: err.message }, 'error');
                }
                throw err;
            }
        }

        function updateUI() {
            const statusText = document.getElementById('status-text');
            const statusCard = document.getElementById('status-card');
            const statusIcon = document.getElementById('status-icon');
            const btnCreate = document.getElementById('btn-create');
            const sendSection = document.getElementById('send-section');

            statusText.innerText = appState.status === 'monitoring' ? `Analisando (${appState.countdown}s)` : appState.status;
            
            if (appState.status === 'monitoring') {
                btnCreate.disabled = true;
                statusCard.className = "bg-amber-600 p-6 rounded-2xl shadow-lg text-white transition-all";
                statusIcon.setAttribute('data-lucide', 'refresh-cw');
                statusIcon.classList.add('animate-spin-custom');
            } else if (appState.status === 'approved') {
                btnCreate.disabled = false;
                statusCard.className = "bg-emerald-600 p-6 rounded-2xl shadow-lg text-white transition-all";
                statusIcon.setAttribute('data-lucide', 'check-circle');
                statusIcon.classList.remove('animate-spin-custom');
                sendSection.classList.remove('opacity-40', 'pointer-events-none');
            } else if (appState.status === 'rejected') {
                btnCreate.disabled = false;
                statusCard.className = "bg-rose-700 p-6 rounded-2xl shadow-lg text-white transition-all";
                statusIcon.setAttribute('data-lucide', 'alert-circle');
                statusIcon.classList.remove('animate-spin-custom');
            } else {
                statusCard.className = "bg-indigo-900 p-6 rounded-2xl shadow-lg text-white";
                statusIcon.setAttribute('data-lucide', 'clock');
                statusIcon.classList.remove('animate-spin-custom');
            }

            renderTemplateHistory();
            renderTrackingTable();
            lucide.createIcons();
        }

        function renderTemplateHistory() {
            const container = document.getElementById('template-history');
            if (appState.templateHistory.length === 0) return;

            container.innerHTML = appState.templateHistory.map(t => `
                <div class="flex items-center justify-between p-2 bg-slate-50 rounded border border-slate-100 mb-1">
                    <span class="font-mono font-bold text-slate-600">${t.name}</span>
                    <span class="px-2 py-0.5 rounded text-[9px] font-bold ${t.status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : t.status === 'REJECTED' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700'}">
                        ${t.status}
                    </span>
                </div>
            `).join('');
        }

        function renderTrackingTable() {
            const body = document.getElementById('tracking-table-body');
            if (appState.trackedMessages.length === 0) return;

            body.innerHTML = appState.trackedMessages.map(msg => `
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="p-3">
                        <div class="font-bold text-slate-700">${msg.to}</div>
                        <div class="text-[9px] text-slate-400 font-mono truncate w-32">${msg.id}</div>
                    </td>
                    <td class="p-3 text-slate-500">${msg.sentAt}</td>
                    <td class="p-3 text-slate-500">${msg.deliveredAt || '--:--'}</td>
                    <td class="p-3 text-slate-500">${msg.readAt || '--:--'}</td>
                    <td class="p-3">
                        <div class="flex items-center gap-1 font-bold ${msg.status === 'read' ? 'text-emerald-600' : 'text-slate-400'}">
                            ${msg.status === 'sent' ? '<i data-lucide="check" class="w-3 h-3"></i>' : 
                              msg.status === 'delivered' ? '<i data-lucide="check-check" class="text-blue-500 w-3 h-3"></i>' : 
                              '<i data-lucide="eye" class="text-emerald-500 w-3 h-3"></i>'}
                            <span class="capitalize">${msg.status}</span>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        async function handleCreateTemplate() {
            const wabaId = document.getElementById('waba-id').value;
            const nameInput = document.getElementById('tpl-name').value;
            const bodyText = document.getElementById('tpl-body').value;

            const sanitizedName = nameInput.toLowerCase().replace(/[^a-z0-9_]/g, '');
            if (sanitizedName !== nameInput) {
                document.getElementById('tpl-name').value = sanitizedName;
            }

            const url = `https://graph.facebook.com/v22.0/${wabaId}/message_templates`;
            const payload = {
                name: sanitizedName,
                language: "pt_BR",
                category: "UTILITY",
                components: [{ type: "BODY", text: bodyText }]
            };

            appState.status = 'creating';
            updateUI();

            try {
                const data = await metaApiCall('POST', url, payload);
                if (data.id) {
                    appState.templateId = data.id;
                    appState.status = 'monitoring';
                    appState.templateHistory.unshift({ name: sanitizedName, status: 'PENDING', id: data.id });
                    startPolling();
                }
            } catch (err) {
                appState.status = 'idle';
                // O log detalhado já foi feito na metaApiCall
            }
            updateUI();
        }

        function startPolling() {
            const poll = async () => {
                const url = `https://graph.facebook.com/v22.0/${appState.templateId}?fields=status,name`;
                try {
                    const data = await metaApiCall('GET', url);
                    appState.templateHistory = appState.templateHistory.map(t => 
                        t.id === appState.templateId ? { ...t, status: data.status } : t
                    );
                    if (data.status === 'APPROVED') appState.status = 'approved';
                    else if (data.status === 'REJECTED') appState.status = 'rejected';
                } catch (e) { }
                updateUI();
            };

            const interval = setInterval(() => {
                if (appState.status !== 'monitoring') return clearInterval(interval);
                appState.countdown--;
                if (appState.countdown <= 0) {
                    appState.countdown = 30;
                    poll();
                }
                updateUI();
            }, 1000);
        }

        async function handleSendMessage() {
            const phoneId = document.getElementById('phone-id').value;
            const dest = document.getElementById('dest-number').value;
            const name = document.getElementById('tpl-name').value;

            const url = `https://graph.facebook.com/v22.0/${phoneId}/messages`;
            const payload = {
                messaging_product: "whatsapp",
                to: dest,
                type: "template",
                template: {
                    name: name,
                    language: { code: "pt_BR" },
                    components: [{
                        type: "body",
                        parameters: [
                            { type: "text", text: "Usuário Zap" },
                            { type: "text", text: "PROT-" + Math.floor(Math.random()*9000) }
                        ]
                    }]
                }
            };

            try {
                const data = await metaApiCall('POST', url, payload);
                if (data.messages) {
                    appState.trackedMessages.unshift({
                        id: data.messages[0].id,
                        to: dest,
                        sentAt: new Date().toLocaleTimeString(),
                        status: 'sent'
                    });
                }
            } catch (err) { }
            updateUI();
        }

        setInterval(() => {
            let changed = false;
            appState.trackedMessages = appState.trackedMessages.map(msg => {
                if (msg.status === 'read') return msg;
                changed = true;
                const now = new Date().toLocaleTimeString();
                if (msg.status === 'sent') return { ...msg, status: 'delivered', deliveredAt: now };
                if (msg.status === 'delivered') return { ...msg, status: 'read', readAt: now };
                return msg;
            });
            if (changed) updateUI();
        }, 30000);

        document.getElementById('btn-create').addEventListener('click', handleCreateTemplate);
        document.getElementById('btn-send').addEventListener('click', handleSendMessage);
        lucide.createIcons();
    </script>
</body>
</html>
