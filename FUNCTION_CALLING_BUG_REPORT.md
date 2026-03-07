# 🐛 Bug Report: Function Calling não está sendo executado pela IA

## 🚨 Resumo Executivo

**STATUS:** ❌ CRÍTICO - Function Calling completamente não funcional

**PROBLEMA:** As funções internas como `agendar()`, `listarAgendamentos()`, etc. NÃO estão sendo executadas quando a IA tenta chamá-las. A infraestrutura existe, mas há um bug no fluxo de processamento.

**IMPACTO:**
- Usuário pede: "Agende mensagem para amanhã às 14h"
- IA detecta que precisa chamar `agendar()`
- Sistema retorna erro: "IA retornou resposta vazia"
- Nenhuma ação é executada

**CAUSA RAIZ:** O código em [`dispatchAIResponse()`](src/whatsapp-server/ai/index.js:685) verifica se há texto na resposta antes de verificar se há function calls. Quando a IA quer chamar uma função, ela retorna `text: null` e `functionCalls: [...]`, mas o código lança erro ao ver `text: null` sem processar os `functionCalls`.

**PROVIDERS AFETADOS:**
- ❌ Gemini (tem tools definidas mas não são executadas)
- ❌ OpenAI (não tem tools definidas)
- ❌ OpenRouter (não tem tools definidas)

---

## 📋 Detalhamento Técnico

As funções internas como `agendar()`, `listarAgendamentos()`, `sendMessage()`, etc. **NÃO estão sendo executadas** quando a IA (Gemini) tenta chamá-las. O sistema possui toda a infraestrutura necessária, mas há um bug crítico no fluxo de processamento.

## 🔍 Análise Técnica

### Estrutura Atual (✅ Existente)

1. **Definições de Ferramentas** - [`src/whatsapp-server/ai/providers/gemini.js:15-115`](src/whatsapp-server/ai/providers/gemini.js:15)
   - Existe `DEFAULT_TOOL_DEFINITIONS` com ferramentas como:
     - `sendMessage`
     - `getContactInfo`
     - `searchContacts`
     - `createContact`
     - `listContacts`
     - `getConversationHistory`

2. **Função de Execução** - [`src/whatsapp-server/ai/providers/gemini.js:433`](src/whatsapp-server/ai/providers/gemini.js:433)
   - Existe `executeTool()` que mapeia chamadas para handlers

3. **Handlers de Comandos** - [`src/whatsapp-server/commands/handlers/scheduling.js`](src/whatsapp-server/commands/handlers/scheduling.js:1)
   - Existe `agendar()`, `agendar2()`, `cancelar()`, etc.

4. **Detecção de Function Calls** - [`src/whatsapp-server/ai/providers/gemini.js:184-199`](src/whatsapp-server/ai/providers/gemini.js:184)
   ```javascript
   const functionCalls = response.functionCalls();
   
   if (functionCalls && functionCalls.length > 0) {
       console.log('[Gemini] Function calls detected:', functionCalls.map(fc => fc.name).join(', '));
       
       return {
           text: null,  // ⚠️ PROBLEMA: text é null quando há function calls
           functionCalls: functionCalls.map(fc => ({
               name: fc.name,
               args: fc.args ? JSON.parse(fc.args) : {}
           })),
           usage: null,
           model: modelName
       };
   }
   ```

### 🔴 O Bug Crítico

Em [`src/whatsapp-server/ai/index.js:676-689`](src/whatsapp-server/ai/index.js:685):

```javascript
async function dispatchAIResponse(sessionContext, messageBody, providedConfig = null, options = {}, dependencies = {}) {
    const aiResponse = await generateAIResponse(sessionContext, messageBody, providedConfig, dependencies);
    const aiText = aiResponse.text?.trim();
    
    // ⚠️ BUG: Lança erro quando text é null (que acontece quando há functionCalls)
    if (!aiText) {
        throw new Error('IA retornou resposta vazia');
    }
    
    // ❌ NUNCA CHEGA AQUI: O código não verifica aiResponse.functionCalls
    // ❌ As function calls são ignoradas completamente
}
```

### 📊 Fluxo do Bug

```
1. Usuário: "Agende uma mensagem para 558599999999 às 14h"
   ↓
2. IA (Gemini) detecta que precisa chamar função
   ↓
3. Gemini retorna: { text: null, functionCalls: [{ name: 'agendar', args: {...} }] }
   ↓
4. dispatchAIResponse recebe a resposta
   ↓
5. ❌ Verifica aiText e vê que é null
   ↓
6. ❌ Lança erro: "IA retornou resposta vazia"
   ↓
7. ❌ functionCalls nunca são processadas
   ↓
8. ❌ Função agendar() nunca é chamada
```

## 🛠️ Solução Necessária

### 1. Adicionar Processamento de Function Calls

Modificar [`src/whatsapp-server/ai/index.js`](src/whatsapp-server/ai/index.js:685) para:

```javascript
async function dispatchAIResponse(sessionContext, messageBody, providedConfig = null, options = {}, dependencies = {}) {
    const { sock, db, sendWhatsAppMessage, persistSessionMessage } = dependencies;
    const remoteJid = sessionContext?.remoteJid || '';

    // Generate AI response
    const aiResponse = await generateAIResponse(sessionContext, messageBody, providedConfig, dependencies);
    
    // ✅ NOVO: Verificar se há function calls antes de verificar texto
    if (aiResponse.functionCalls && aiResponse.functionCalls.length > 0) {
        console.log('[AI] Processing function calls:', aiResponse.functionCalls.map(fc => fc.name).join(', '));
        
        const results = [];
        for (const fc of aiResponse.functionCalls) {
            try {
                const result = await executeFunctionCall(fc, sessionContext, dependencies);
                results.push({ function: fc.name, result });
            } catch (err) {
                console.error(`[AI] Error executing ${fc.name}:`, err);
                results.push({ function: fc.name, error: err.message });
            }
        }
        
        // Chamar IA novamente com os resultados para gerar resposta ao usuário
        const followUpPrompt = buildFunctionResultsPrompt(results);
        const followUpResponse = await generateAIResponse(
            sessionContext, 
            followUpPrompt, 
            { ...providedConfig, history_limit: 0 }, 
            dependencies
        );
        
        // Enviar resposta ao usuário
        if (followUpResponse.text && sendWhatsAppMessage) {
            await sendWhatsAppMessage(remoteJid, { text: followUpResponse.text });
        }
        
        return {
            ...aiResponse,
            text: followUpResponse.text,
            functionCalls: results
        };
    }
    
    // Fluxo normal de texto
    const aiText = aiResponse.text?.trim();
    if (!aiText) {
        throw new Error('IA retornou resposta vazia');
    }
    
    // ... resto do código existente
}
```

### 2. Adicionar Function Executor

Criar função para executar function calls:

```javascript
async function executeFunctionCall(functionCall, sessionContext, dependencies) {
    const { name, args } = functionCall;
    const { db } = dependencies;
    
    console.log(`[Function Call] Executing: ${name}`, args);
    
    // Import commands module
    const commands = require('../commands/index');
    const geminiProvider = require('./providers/gemini');
    
    // Try to execute via Gemini's executeTool first
    try {
        const result = await geminiProvider.executeTool(name, args, {
            ...dependencies,
            instanceId: sessionContext.instanceId
        });
        
        if (result.success) {
            return result.result;
        }
    } catch (err) {
        console.warn(`[Function Call] Gemini executeTool failed, trying commands:`, err.message);
    }
    
    // Try to execute via commands system
    if (commands.COMMANDS[name]) {
        const handler = commands.COMMANDS[name];
        const result = await handler(args, { 
            ...sessionContext, 
            ...dependencies 
        });
        return result;
    }
    
    throw new Error(`Function not found: ${name}`);
}
```

### 3. Adicionar Ferramentas de Agendamento

Adicionar em [`src/whatsapp-server/ai/providers/gemini.js`](src/whatsapp-server/ai/providers/gemini.js:15):

```javascript
const DEFAULT_TOOL_DEFINITIONS = [
    // ... existing tools ...
    
    // ✅ NOVO: Ferramentas de agendamento
    {
        name: 'agendar',
        description: 'Agendar uma mensagem para ser enviada em uma data e hora específicas',
        parameters: {
            type: 'object',
            properties: {
                destinatario: {
                    type: 'string',
                    description: 'Número de telefone do destinatário (com código do país, ex: 558599999999)'
                },
                mensagem: {
                    type: 'string',
                    description: 'Conteúdo da mensagem a ser agendada'
                },
                data: {
                    type: 'string',
                    description: 'Data no formato YYYY-MM-DD (ex: 2026-02-27)'
                },
                hora: {
                    type: 'string',
                    description: 'Hora no formato HH:MM (ex: 14:30)'
                },
                tag: {
                    type: 'string',
                    description: 'Tag opcional para identificar o agendamento'
                }
            },
            required: ['destinatario', 'mensagem', 'data', 'hora']
        }
    },
    {
        name: 'listarAgendamentos',
        description: 'Listar todos os agendamentos de mensagens',
        parameters: {
            type: 'object',
            properties: {}
        }
    },
    {
        name: 'cancelar',
        description: 'Cancelar um agendamento de mensagem',
        parameters: {
            type: 'object',
            properties: {
                mensagem_id: {
                    type: 'string',
                    description: 'ID da mensagem agendada a ser cancelada'
                }
            },
            required: ['mensagem_id']
        }
    }
];
```

### 4. Atualizar executeTool

Adicionar casos em [`src/whatsapp-server/ai/providers/gemini.js:433`](src/whatsapp-server/ai/providers/gemini.js:433):

```javascript
async function executeTool(functionName, args, dependencies = {}) {
    const { db, sendMessage, getContactInfo, searchContacts, createContact, 
            listContacts, getConversationHistory, instanceId } = dependencies;
    
    console.log(`[Gemini] Executing tool: ${functionName}`, args);
    
    try {
        switch (functionName) {
            // ... existing cases ...
            
            // ✅ NOVO: Scheduling tools
            case 'agendar': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const result = await schedulingHandler.agendar(args, { instanceId, db });
                return { success: true, result };
            }
            
            case 'listarAgendamentos': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const result = await schedulingHandler.listarAgendamentos(args, { instanceId, db });
                return { success: true, result };
            }
            
            case 'cancelar': {
                const schedulingHandler = require('../../commands/handlers/scheduling');
                const result = await schedulingHandler.cancelar(args, { instanceId, db });
                return { success: true, result };
            }
            
            default:
                return { success: false, error: `Tool not found: ${functionName}` };
        }
    } catch (err) {
        console.error(`[Gemini] Error executing ${functionName}:`, err);
        return { success: false, error: err.message };
    }
}
```

## 📝 Impacto

### Ferramentas Atualmente Não Funcionam
- ❌ `agendar()` - Agendamento de mensagens
- ❌ `listarAgendamentos()` - Listar agendamentos
- ❌ `cancelar()` - Cancelar agendamentos
- ❌ `sendMessage()` - Enviar mensagens
- ❌ `getContactInfo()` - Buscar informações de contato
- ❌ Todas as outras ferramentas definidas

### Sintoma do Bug
Quando o usuário pede para a IA executar uma ação que requer function calling:
- ❌ Aparece erro: "IA retornou resposta vazia"
- ❌ Nenhuma ação é executada
- ❌ Logs mostram: `[Gemini] Function calls detected: agendar` mas nada acontece

## ✅ Prioridade

**CRÍTICA** - A funcionalidade de function calling está completamente quebrada. A IA pode detectar quando precisa executar ações, mas essas ações nunca são executadas.

## 🔗 Arquivos Relacionados

1. [`src/whatsapp-server/ai/index.js:676`](src/whatsapp-server/ai/index.js:676) - dispatchAIResponse (PRECISA CORREÇÃO)
2. [`src/whatsapp-server/ai/providers/gemini.js:184`](src/whatsapp-server/ai/providers/gemini.js:184) - Function call detection (OK)
3. [`src/whatsapp-server/ai/providers/gemini.js:433`](src/whatsapp-server/ai/providers/gemini.js:433) - executeTool (PRECISA EXPANSÃO)
4. [`src/whatsapp-server/commands/handlers/scheduling.js`](src/whatsapp-server/commands/handlers/scheduling.js:1) - Handlers (OK)

## 📅 Data do Relatório
2026-02-26 17:28 UTC-3
