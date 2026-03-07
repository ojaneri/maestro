# Debug Commands Reference

This document describes all diagnostic commands available in the WhatsApp bot system.

## Overview

Debug commands are special messages that trigger diagnostic functions instead of being processed by the AI. They return structured information about the system's current state, configuration, and function test results.

## Available Commands

| Command | Description | Function Group |
|---------|-------------|----------------|
| `#debug#` | Instance configuration and status | System Info |
| `#debug2#` | Customer data functions test | dados,_followup |
| `#debug3# optout, status` | Calendar functions test | availability, scheduling |
| `#debug4#` | Scheduling functions test | agendar, agendar2, agendar3 |
| `#debug5#` | Context/State functions test | estado, contexto, variavel |
| `#debug6#` | Messaging functions test | whatsapp, mail, template |

---

## #debug# - Instance Configuration

Returns comprehensive information about the instance configuration and status.

### What it tests:
- Instance ID
- Port and PID
- Uptime
- Memory usage
- System prompt (first 200 chars)
- AI Provider and model
- Auto pause settings
- Node.js version

### Example output:
```
🔍 DEBUG INFO - Instance Diagnostics

📋 Instance: inst_3010
🌐 Port: 3010
⚙️ PID: 450362
⏱️ Uptime: 2h 15m 30s
🧠 Memory: 125.50 MB

📝 System Prompt (200 chars):
"You are a helpful assistant..."

🤖 AI Config:
- Provider: google
- Model: gemini-2.0-flash
- Auto Pause: enabled
- Sleep Delay: 2000ms

💻 Environment:
- Node: v20.x.x
- Env: production
```

---

## #debug2# - Customer Data Functions

Tests all customer data related functions.

### What it tests:

| Function | Description |
|----------|-------------|
| `dados(email)` | Retrieves customer name, email, phone, subscription status from MySQL kitpericia |
| `optout()` | Cancels pending follow-ups and marks client as no longer receiving attempts |
| `status_followup()` | Returns summary of state, active tracks, and pending schedules |
| `tempo_sem_interacao()` | Returns seconds since client last interaction (useful for tone adjustment) |
| `log_evento(category, description, json)` | Light audit logging for metrics |

### Example output:
```
🧪 DEBUG2 - Customer Data Functions

📋 1. dados('teste@exemplo.com')
   ✅ Result: {"ok":true,"data":{"nome":"Test","email":"teste@exemplo.com",...}}

📋 2. optout()
   ✅ Result: {"ok":true,"message":"Opt-out successful"}

📋 3. status_followup()
   ✅ Result: {"ok":true,"estado":"interessado","proximos":[...]}

📋 4. tempo_sem_interacao()
   ✅ Result: {"ok":true,"segundos":3600}

📋 5. log_evento('teste','Debug test', '{}')
   ✅ Result: {"ok":true,"message":"Event logged"}

✅ Debug2 test completed
```

---

## #debug3# - Calendar Functions

Tests Google Calendar integration functions.

### What it tests:

| Function | Description |
|----------|-------------|
| `verificar_disponibilidade(inicio, fim, calendar_num, timezone)` | Checks if time slot is available |
| `sugerir_horarios(data, janela, duracao_min, limite, calendar_num, timezone)` | Suggests available slots |
| `listar_eventos(inicio, fim, calendar_num, timezone)` | Lists events in date range |

### Parameters:
- `calendar_num`: 1 for first calendar, 2 for second, etc.
- `timezone`: Default is America/Fortaleza
- `janela`: Time window like "09:00-18:00"
- `duracao_min`: Duration in minutes (default 60)
- `limite`: Maximum number of suggestions (default 5)

### Example output:
```
🧪 DEBUG3 - Calendar Functions

📋 1. verificar_disponibilidade('2026-03-10T09:00','2026-03-10T10:00', 1, 'America/Fortaleza')
   ✅ Result: {"ok":true,"disponivel":true,"calendar":"principal"}

📋 2. sugerir_horarios('2026-03-10', '09:00-18:00', 60, 5, 1, 'America/Fortaleza')
   ✅ Result: {"ok":true,"sugestoes":["10:00","11:00","14:00",...]}

📋 3. listar_eventos('2026-03-01', '2026-03-31', 1, 'America/Fortaleza')
   ✅ Result: {"ok":true,"eventos":[...]}

✅ Debug3 test completed
```

---

## #debug4# - Scheduling Functions

Tests reminder/scheduler functions.

### What it tests:

| Function | Description |
|----------|-------------|
| `agendar(DD/MM/AAAA, HH:MM, texto, tag, tipo, interno)` | Schedule reminder for specific datetime |
| `agendar2(+5m, texto, tag, tipo, interno)` | Schedule relative to now (e.g., +5m, +1h) |
| `agendar3(YYYY-MM-DD HH:mm:ss, texto, tag, tipo, interno)` | Schedule for exact datetime |
| `cancelar_e_agendar2(+24h, texto, tag, tipo, interno)` | Cancel pending and create new |
| `cancelar_e_agendar3(YYYY-MM-DD HH:mm:ss, texto, tag, tipo, interno)` | Cancel and schedule exact time |
| `listar_agendamentos(tag, tipo, interno)` | List all scheduled reminders |

### Parameters:
- `tag`: Tag identifier (default: "default")
- `tipo`: Type of reminder (default: "followup")
- `interno`: If true, keeps note only in internal log without notifying user
- `+5m`, `+1h`, `+24h`: Relative time notation

### Example output:
```
🧪 DEBUG4 - Scheduling Functions

📋 1. agendar2('+5m', 'Teste de agendamento', 'debug', 'test', false)
   ✅ Result: {"ok":true,"scheduledId":"sched_123","scheduledTime":"2026-03-07T12:40:00Z"}

📋 2. agendar3('2026-03-15 10:00', 'Teste data exata', 'debug', 'test', false)
   ✅ Result: {"ok":true,"scheduledId":"sched_456","scheduledTime":"2026-03-15T10:00:00Z"}

📋 3. listar_agendamentos('debug', 'test', false)
   ✅ Result: {"ok":true,"agendamentos":[...]}

📋 4. cancelar_e_agendar2('+10m', 'Novo agendamento', 'debug', 'test', false)
   ✅ Result: {"ok":true,"cancelledCount":1,"scheduledId":"sched_789"}

✅ Debug4 test completed
```

---

## #debug5# - Context/State Functions

Tests conversation context and state management functions.

### What it tests:

| Function | Description |
|----------|-------------|
| `set_estado(estado)` | Saves funnel stage (e.g., "interessado", "cotando", "fechado") |
| `get_estado()` | Retrieves current funnel stage |
| `set_contexto(chave, valor)` | Short-term memory per contact for extra clues |
| `get_contexto(chave)` | Retrieves context value |
| `limpar_contexto([chave])` | Clears context (single key or all) |
| `set_variavel(chave, valor)` | Persistent variable per instance |
| `get_variavel(chave)` | Retrieves instance variable |

### Example output:
```
🧪 DEBUG5 - Context/State Functions

📋 1. set_estado('interessado')
   ✅ Result: {"ok":true,"estado":"interessado"}

📋 2. get_estado()
   ✅ Result: {"ok":true,"estado":"interessado"}

📋 3. set_contexto('debug_test', 'valor_teste')
   ✅ Result: {"ok":true,"message":"Context saved"}

📋 4. get_contexto('debug_test')
   ✅ Result: {"ok":true,"valor":"valor_teste"}

📋 5. limpar_contexto(['debug_test'])
   ✅ Result: {"ok":true,"message":"Context cleared"}

📋 6. set_variavel('test_var', 'test_value')
   ✅ Result: {"ok":true,"message":"Variable saved"}

📋 7. get_variavel('test_var')
   ✅ Result: {"ok":true,"valor":"test_value"}

✅ Debug5 test completed
```

---

## #debug6# - Messaging Functions

Tests WhatsApp messaging and media functions.

### What it tests:

| Function | Description |
|----------|-------------|
| `whatsapp(numero, mensagem)` | Send WhatsApp message to number |
| `boomerang(mensagem)` | Signal immediate "Boomerang activated" |
| `mail(destino, assunto, corpo, remetente?)` | Send email (sender defaults to noreply@janeri.com.br) |
| `get_web(URL)` | Make HTTP GET request |
| `template(ID_Template, var1, var2, var3)` | Send Meta-approved template |
| `IMG:uploads/...|Legenda` | Send image from uploads with caption |
| `VIDEO:uploads/...|Legenda` | Send video from uploads |
| `AUDIO:uploads/...` | Send audio from uploads |
| `CONTACT:Nome|Note` | Send vCard contact |

### Example output:
```
🧪 DEBUG6 - Messaging Functions

📋 Functions available for testing:
   - whatsapp(numero, mensagem)
   - boomerang(mensagem)
   - mail(destino, assunto, corpo, remetente?)
   - template(id, var1?, var2?, var3?)
   - IMG:uploads/...|legenda
   - VIDEO:uploads/...|legenda
   - AUDIO:uploads/...
   - CONTACT:Nome|Note

📋 1. boomerang('Teste interno')
   ✅ Result: {"ok":true,"message":"Boomerang acionado"}

✅ Debug6 test completed (messaging functions require parameters)
```

---

## Important Notes

1. **Calendar Numbers**: Always use `calendar_num` (1, 2, 3...) instead of the long Google Calendar ID. The system maps automatically.

2. **Deprecated Parameter**: `calendar_num=0` is accepted but generates a migration warning in logs.

3. **Timezone**: Default is `America/Fortaleza` unless the instance has a different timezone configured.

4. **Internal Flag**: When `interno=true`, operations stay as internal memory without notifying the user, allowing the AI to generate a new message at the scheduled time.

5. **Return Format**: All functions return:
```json
{
  "ok": true,
  "code": "OK",
  "message": "short description",
  "data": { ... }
}
```

---

## Troubleshooting

If any function returns an error:

1. Check `data.allowed_calendar_nums` and `data.calendars_debug` in the response for calendar configuration issues
2. Use `#debug#` to verify instance configuration
3. Check instance logs: `tail -100 instance_inst_*.log | grep -i error`
4. Verify database connectivity for scheduling functions
5. Check Google Calendar OAuth tokens for calendar functions
