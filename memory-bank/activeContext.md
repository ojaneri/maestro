# Active Context - Debug Session

## Issue: QR Code Not Displaying in Frontend

### Problem Summary
- User clicks "Conectar QR" but sees "QR code not yet available" immediately
- Backend IS generating QR codes (confirmed via instance logs showing "QR code atualizado")
- Error "QR refs attempts ended" happens AFTER ~20 seconds (this is normal behavior)

### Root Cause Analysis
1. **Frontend Flow**: qr-proxy.php receives request from frontend UI
2. **Backend Call**: qr-proxy.php calls `http://localhost:{port}/api/qr` 
3. **404 Error**: Endpoint `/api/qr` does NOT exist in whatsapp-server-intelligent.js
4. **Actual Endpoint**: The correct endpoint is `/qr` (line 5747 in whatsapp-server-intelligent.js)

### Evidence from Logs
```
[2026-02-15 23:48:08] Failed to fetch QR from server on port 3011: HTTP 404
```

### Solution Applied
Changed qr-proxy.php line 135:
- BEFORE: `$url = "http://localhost:{$port}/api/qr";`
- AFTER: `$url = "http://localhost:{$port}/qr";`

### Verification
After fix, logs show:
```
[2026-02-15 23:55:25] Successfully fetched real QR for instance: inst_69923d6fed578
```

### Files Modified
- `qr-proxy.php` - Fixed endpoint path

### Key Takeaways
- whatsapp-server-intelligent.js uses `/qr` NOT `/api/qr`
- The /status endpoint was correct (line 5736)
- This was a simple path mismatch bug, not a backend issue

## Active Instance
- Instance ID: inst_69923d6fed578
- Port: 3011
- Status: Generating QR codes correctly

---

## Issue: Porta da Instância Alterada Sem Reinício do Processo

### Problem Summary
- Ao alterar a instância `inst_6992ec9e78d1c` de `3012` para `3011` no painel, a instância ficou offline.
- O banco foi atualizado para a nova porta, mas o processo Node/PM2 continuou no binding antigo.

### Evidence from Logs
```
2026-02-16 07:09:15 - Status check ... inst_6992ec9e78d1c on port 3012: server=Running
2026-02-16 07:09:15 - Status check ... inst_6992ec9e78d1c on port 3011: server=Stopped
2026-02-16 07:09:15 - Quick config node sync failed: Failed to connect to 127.0.0.1 port 3011
```

### Root Cause
1. Quick Config salvava nova porta no SQLite.
2. Sync para Node era tentado imediatamente na nova porta.
3. Não havia restart/recreate automático do processo PM2 para aplicar `--port` novo.
4. Front-end também gerava `https://127.0.0.1:{porta}` no Base URL local.

### Solution Applied
- `index.php` (fluxo `update_instance`):
  - validação de porta (formato/range/conflito);
  - em mudança de porta, executa `stop_instance.sh` + `create_instance.sh` com a nova porta;
  - espera a porta subir antes do sync;
  - retry no sync para `/api/instance`.
- Front-end Quick Config:
  - Base URL local padronizada para `http://127.0.0.1:{porta}`;
  - encoding de `instance_base_url_b64` ajustado para manter consistência.

### Safety Migration Applied
- `instance_data.php` recebeu migração idempotente:
  - normaliza `base_url` local legada (`https://127.0.0.1:*`, `https://localhost:*` e variantes URL-encoded)
  - converte para `http://127.0.0.1:{porta}`.
- Migração executada com sucesso em 2026-02-16 e validada com `legacy_count=0`.

---

## Issue: Conversas Vazias Mesmo Com Envio Realizado

### Problem Summary
- Em `conversas.php?instance=inst_6992ed0c735f0`, a UI mostrava "Nenhuma conversa encontrada".
- O envio retornava sucesso (`/send-message` HTTP 200), mas a lista de conversas seguia vazia.

### Evidence from Data
- `messages` para `inst_6992ed0c735f0`: `0`
- `chat_history` para `inst_6992ed0c735f0`: `0`
- `contact_metadata` para `inst_6992ed0c735f0`: possui registros atualizados (atividade recente)

### Root Cause
1. O endpoint de chats usa `db.getChats()`.
2. `getChats()` consultava apenas `messages` como fonte de contatos.
3. Quando há metadados de contato sem linhas em `messages`, o retorno vem vazio.

### Solution Applied
- Arquivo: `db-updated.js`
- Função: `getChats(instanceId, search, limit, offset)`
- Ajuste:
  - união (`UNION`) de contatos vindos de `messages` e `contact_metadata`;
  - manutenção dos subselects de `last_message/last_timestamp/last_role` em `messages`;
  - `message_count` convertido para subselect por contato.

### Expected Behavior After Fix
- A sidebar de conversas passa a listar contatos já conhecidos via `contact_metadata`, mesmo sem histórico em `messages`.
- Isso elimina o estado de "Nenhuma conversa encontrada" para instâncias com atividade parcial.

### Follow-up Adjustment (UI)
- `conversas.php` agora trata explicitamente o caso de contato sem mensagens persistidas:
  - `chatStatus` mostra "Histórico não persistido nesta instância" quando aplicável.
  - A área de mensagens exibe fallback explicando ausência de persistência e, se existir, mostra a última atividade detectada.
- Sidebar também exibe preview coerente para `last_role = system` (sem prefixo de IA/Você).

### Root Cause Confirmed (Persistence Failure)
- A ausência de mensagens persistidas não era apenas histórico antigo: havia erro ativo de SQL na rotina de gravação.
- Em `db-updated.js::saveMessage()`, o `INSERT INTO messages` declarava 10 colunas e só 9 placeholders.
- Erro observado em log da instância:
  - `SQLITE_ERROR: 9 values for 10 columns`
- Erro secundário identificado:
  - `saveContactMetadata()` podia tentar inserir `temperature = null`, violando `NOT NULL` em `contact_metadata.temperature`.

### Fix Applied
- `db-updated.js`
  - `saveMessage()`: placeholders corrigidos para 10 valores.
  - `saveContactMetadata()`: temperatura normalizada com fallback seguro para `'warm'`.
- Instância `wpp_inst_6992ed0c735f0` reiniciada após patch.
- Validação técnica executada: gravação de teste via `db.saveMessage()` retornou `persisted_count=1`.

---

## Issue: Aviso de "versão antiga do WhatsApp" na outra ponta

### Diagnosis
- A dependência Baileys em uso está em `@whiskeysockets/baileys@7.0.0-rc.9`.
- A conexão já usa `fetchLatestBaileysVersion()` para negociar versão de protocolo.
- Havia assinatura de cliente customizada fixa:
  - `browser: ["Janeri WPP Panel", "Chrome", "1.0.0"]`
- Essa assinatura pode acionar heurística de cliente legado no WhatsApp receptor.

### Solution Applied
- Atualizado para identidade compatível via helper oficial do Baileys:
  - `Browsers.macOS("Desktop")`
  - fallback seguro: `["Mac OS", "Chrome", "120.0.0"]`
- Arquivos ajustados:
  - `whatsapp-server-intelligent.js`
  - `src/whatsapp-server/whatsapp/connection.js`
  - `src/infra/whatsapp-service.js`
- Instâncias PM2 ativas reiniciadas para aplicar o patch em runtime.

---

## Contexto Atualizado (2026-02-16)

### Sintoma investigado
- Sidebar mostrava contato, mas ao abrir não apareciam mensagens.
- Em alguns casos o LID era exibido como telefone (número incorreto), ex.: `759136...@lid` formatado como se fosse PN.

### Diagnóstico confirmado
- Conversas estavam fragmentadas entre `remote_jid` em formato `@lid` e `@s.whatsapp.net`.
- O frontend tinha fallback visual que inferia telefone a partir dos dígitos do LID, causando identificação falsa.
- O mapeamento `LID -> PN` não estava sendo persistido de forma robusta no runtime monolítico.

### Ajustes aplicados
- `conversas.php`: bloqueada inferência de telefone por LID sem PN resolvido.
- `whatsapp-server-intelligent.js`:
  - adicionada extração/persistência de identidade por mensagem;
  - reforço por `contacts.upsert` e sincronização inicial via `sock.contacts` (`syncContactStoreIdentities`).
- `db-updated.js`:
  - `saveLIDPNMapping()` corrigido para operação válida com `instance_id`;
  - `getMessages`, `getLastMessages`, contadores e `clearConversation` com resolução de aliases;
  - `saveMessage()` grava `remote_jid_alt` e `sender_pn`.

### Situação residual
- Para dados históricos já gravados sem vínculo `pn` em `contact_metadata`, a unificação total depende de o runtime voltar a receber eventos/contatos que revelem a correspondência real `LID <-> PN`.
