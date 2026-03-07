# Progress

## What Works

- ✅ Multi-instance WhatsApp management via central dashboard
- ✅ QR code authentication for new instances
- ✅ Real-time chat interface with WhatsApp-style UI
- ✅ AI-powered automated responses (OpenAI GPT, Google Gemini, and OpenRouter with fallback sequencing)
- ✅ Web instances inherit the same Baileys automation controls (secretária, transcrição e auto pause)
- ✅ Multi-user system with role-based access (Admin, Manager, Operator)
- ✅ SQLite database as single source of truth
- ✅ Google Calendar integration for scheduling
- ✅ Message history and conversation threading
- ✅ Audio transcription using Gemini
- ✅ Secretary mode for automated follow-ups
- ✅ Email alerts for connection issues
- ✅ Campaign management and scheduled messages
- ✅ File upload and media handling
- ✅ RESTful API for external integrations
- ✅ Meta API integration with message templates
- ✅ Template status checking and approval management for Meta API
- ✅ Template sending functionality with test and bulk sending capabilities
- ✅ Conditional UI rendering for different integration types (Baileys/Meta)
- ✅ Message status tracking (sent, delivered, read, failed) for Meta API
- ✅ Webhook 24h window rule enforcement for free-text responses
- ✅ Simplified Meta API configuration (removed Meta Phone Number ID field)
- ✅ Comprehensive health checks with detailed system information
- ✅ Performance metrics endpoint with real-time statistics
- ✅ Enhanced monitoring and alerting system
- ✅ Scheduling commands support internal silent mode with structured JSON responses

## What's Left to Build

- 🔄 Performance optimization for large-scale deployments
- 🔄 Advanced analytics and reporting dashboard
- 🔄 Mobile-responsive improvements for chat interface
- 🔄 Bulk message operations and templates
- 🔄 Integration with more AI providers
- 🔄 Automated testing suite
- 🔄 Docker containerization for easier deployment
- 🔄 Backup and restore functionality
- 🔄 Multi-language support

## Current Status

**Version 1.5** - Production Ready

The system is stable and suitable for production use with multiple concurrent instances. All core features are implemented and tested.

## Known Issues

- Minor: WebSocket reconnection issues in unstable network conditions
- Minor: Rate limiting not fully implemented for API endpoints

## Bugs Corrigidos

- [x] Bug 1: Correção do Parser de Argumentos (agendar2) - 2026-02-01
- [x] LID-based Identity Resolution (Baileys v7) - 2026-02-02
  - Implemented `resolveLIDtoPN()`, `extractPNfromMessage()`, `extractPushName()`
  - Implemented `getUserStatus()`, `processMessageIdentity()`
  - Added persistent caching with database persistence
  - Contact sync via `contacts.upsert` and `messaging-history.set` events
- [x] Correção de troca de porta da instância (Quick Config) - 2026-02-16
  - Added port validation (numeric/range/conflict check) before save
  - Added automatic PM2 process recreation on port change (`stop_instance.sh` + `create_instance.sh`)
  - Added sync retry to Node endpoint after restart
  - Fixed local Base URL generation to `http://127.0.0.1:{port}`
- [x] Migração de segurança de `base_url` local - 2026-02-16
  - Added idempotent migration in `instance_data.php` for legacy local URLs
  - Normalizes `https://127.0.0.1:*` / `https://localhost:*` (including URL-encoded variants)
  - Result after execution: `legacy_count=0`
- [x] Correção de listagem vazia em Conversas (`inst_6992ed0c735f0`) - 2026-02-16
  - Root cause: `getChats()` dependia apenas de `messages`
  - Fix: `getChats()` passou a unir contatos de `messages` e `contact_metadata`
  - Effect: contatos com metadados já aparecem na sidebar mesmo sem histórico persistido
- [x] Fallback visual de histórico no chat - 2026-02-16
  - `last_message` com fallback de sistema para contatos sem persistência
  - Área de mensagens passou a renderizar estado informativo ao abrir contato sem histórico
- [x] Correção de persistência de mensagens (SQL) - 2026-02-16
  - Root cause: `saveMessage()` usava 9 placeholders para 10 colunas em `messages`
  - Added missing placeholder no `INSERT`
  - Corrigido fallback de `temperature` em `saveContactMetadata()` para evitar violação `NOT NULL`
  - Instância `wpp_inst_6992ed0c735f0` reiniciada após patch
- [x] Ajuste de identidade Baileys para compatibilidade - 2026-02-16
  - Removida assinatura fixa `Chrome 1.0.0` no `browser` tuple
  - Aplicado `Browsers.macOS("Desktop")` com fallback controlado
  - Instâncias ativas reiniciadas para carregar identidade nova

## Evolution of Project Decisions

- **Database Migration**: Moved from JSON files to SQLite in v1.0 for better data consistency and concurrent access
- **Architecture Shift**: Adopted PHP + Node.js architecture for better separation of concerns
- **User Management**: Added multi-user support in v1.5 to enable team collaboration
- **AI Integration**: Expanded from basic OpenAI to support multiple providers (OpenAI, Gemini, OpenRouter with fallback sequencing)
- **Calendar Integration**: Added Google Calendar support for advanced scheduling features
- **Security**: Implemented API key authentication and role-based access control
- [x] Correção de split de conversa LID x PN + fallback visual - 2026-02-16
  - `conversas.php`: removida heurística que inferia telefone a partir de `@lid` (evita número falso no detalhe)
  - `whatsapp-server-intelligent.js`: persistência de identidade por mensagem/contato (`LID <-> PN`) com fallback por `sock.contacts`
  - `db-updated.js`: `saveLIDPNMapping()` corrigido (upsert válido), `getMessages/getLastMessages/counts/clearConversation` agora resolvem aliases relacionados
  - `saveMessage()` passou a persistir `remote_jid_alt` e `sender_pn` para facilitar unificação futura
  - `getChats()` recebeu fallback de `last_message` por agrupamento PN quando houver contatos sem histórico próprio
