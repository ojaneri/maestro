# API WhatsApp Instance Server

Base URL por instância:

```
http://HOST:PORT/
```

Exemplo local:

```
http://localhost:3010/
```

## 1. Healthcheck

Verifica se a instância está online.

**Endpoint:**
```
GET /health
```

**Resposta:**
```json
{
  "ok": true,
  "instanceId": "inst_xxx",
  "status": "connected",
  "whatsappConnected": true
}
```

## 2. Status detalhado

Mostra estado completo da instância.

**Endpoint:**
```
GET /status
```

**Resposta:**
```json
{
  "instanceId": "inst_xxx",
  "connectionStatus": "connected",
  "whatsappConnected": true,
  "hasQR": false,
  "lastConnectionError": null
}
```

**Estados possíveis:**
- `starting` → iniciando conexão
- `qr` → aguardando pareamento
- `connected` → conectado e funcionando
- `disconnected` → caiu, mas tenta reconectar
- `error` → erro (verificados nos logs)

## 3. Obter QR Code

Usado quando connectionStatus === "qr".

**Endpoint:**
```
GET /qr
```

**Se houver QR:**
```json
{
  "qr": "data:image/png;base64,..."
}
```

**Se não houver:**
```json
{
  "error": "QR não disponível"
}
```

## 4. Enviar mensagem

Envia mensagem de texto para um número WhatsApp.

**Endpoint:**
```
POST /send-message
```

**Body JSON:**
```json
{
  "to": "558586030781",
  "message": "Olá, teste!"
}
```

**Resposta:**
```json
{
  "ok": true,
  "instanceId": "inst_xxx",
  "to": "558586030781@s.whatsapp.net",
  "result": {
    "key": {
      "remoteJid": "558586030781@s.whatsapp.net",
      "fromMe": true,
      "id": "3EB051C84764D7365B4613"
    },
    "message": {
      "extendedTextMessage": {
        "text": "Olá, teste!"
      }
    },
    "messageTimestamp": "1763594046",
    "status": "PENDING"
  }
}
```

## 5. Logout (desconectar e invalidar sessão)

Força o WhatsApp a pedir QR novamente.

**Endpoint:**
```
POST /disconnect
```

**Resposta:**
```json
{
  "ok": true,
  "instanceId": "inst_xxx",
  "message": "Logout realizado"
}
```

## 6. Reiniciar conexão

Reinicia Baileys sem apagar a sessão salva.

**Endpoint:**
```
POST /restart
```

**Resposta:**
```json
{
  "ok": true,
  "instanceId": "inst_xxx",
  "message": "Restart solicitado"
}
```

## 7. Configurar OpenAI Integration

Salva as configurações de integração com OpenAI para respostas automáticas.

**Endpoint:**
```
POST /api.php (via painel web)
```

**Body JSON:**
```json
{
  "action": "save_openai",
  "openai": {
    "enabled": true,
    "api_key": "sk-...",
    "system_prompt": "You are a helpful assistant.",
    "assistant_prompt": ""
  }
}
```

**Resposta de Sucesso:**
```json
{
  "success": true
}
```

**Resposta de Erro:**
```json
{
  "error": "Invalid OpenAI API key format"
}
```

## 8. WebSocket – Eventos em tempo real

Conectar em:

```
ws://HOST:PORT/ws
```

**O servidor envia:**

**Status:**
```json
{
  "type": "status",
  "data": {
    "instanceId": "...",
    "connectionStatus": "connected",
    "whatsappConnected": true
  }
}
```

**QR:**
```json
{
  "type": "qr",
  "data": { "qr": "..." }
}
```

**Mensagens recebidas:**
```json
{
  "type": "messages",
  "data": {
    "type": "notify",
    "messages": [
      {
        "key": { "remoteJid": "5585...", "fromMe": false },
        "pushName": "Contato",
        "fromMe": false,
        "remoteJid": "5585...",
        "messageStubType": null
      }
    ]
  }
}
```

## Resumo das Rotas

| Rota | Método | Descrição |
|------|--------|-----------|
| `/health` | GET | Verifica se a instância está viva |
| `/status` | GET | Mostra estado completo e erro, se houver |
| `/qr` | GET | Obtém o QR atual (se disponível) |
| `/send-message` | POST | Envia mensagem de texto |
| `/disconnect` | POST | Faz logout e pede QR |
| `/restart` | POST | Reinicia a conexão mantendo sessão |
| `/ws` | WS | Eventos em tempo real |
| `/api.php` | POST | Configurações OpenAI (via painel) |

## Exemplos de Uso

### Usando cURL - Envio de Mensagem
```bash
curl -X POST "http://localhost:3010/send-Message" \
   -H "Content-Type: application/json" \
   -d '{
         "to": "558586030781",
         "message": "Test message"
       }'
```

### Usando cURL - Verificar Status
```bash
curl -X GET "http://localhost:3010/status"
```

### Usando cURL - Obter QR Code
```bash
curl -X GET "http://localhost:3010/qr"
```

### Usando JavaScript/Fetch
```javascript
// Enviar mensagem
fetch('http://localhost:3010/send-message', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        to: '558586030781',
        message: 'Olá do JavaScript!'
    })
})
.then(response => response.json())
.then(data => console.log(data));
```

## Características Técnicas

- **Protocolo:** HTTP/1.1 e WebSocket
- **Formato de Dados:** JSON
- **Codificação:** UTF-8
- **Autenticação:** Não requerida (portas locais)
- **CORS:** Habilitado para todos os origins
- **Timeout padrão:** 5 segundos para requisições
- **Logs:** Disponíveis via console e arquivos de log

## Códigos de Status HTTP

- `200` - Sucesso
- `400` - Parâmetros inválidos
- `404` - Recurso não encontrado
- `500` - Erro interno do servidor
- `503` - Serviço indisponível (WhatsApp não conectado)

## Notas Importantes

1. **Instância deve estar conectada** para envio de mensagens
2. **QR Code só está disponível** quando `connectionStatus` é "qr"
3. **Números devem estar** em formato internacional (55 + DDD + número)
4. **Reconexão automática** acontece quando a conexão cai (exceto logout)
5. **Sessões são mantidas** entre reinicializações do servidor
6. **OpenAI Integration** permite respostas automáticas a mensagens recebidas quando habilitado
7. **API Key OpenAI** deve começar com "sk-" e ter pelo menos 48 caracteres