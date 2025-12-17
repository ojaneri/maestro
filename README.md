# Maestro – WhatsApp Orchestrator

Maestro is a multi-instance WhatsApp management system that allows you to orchestrate multiple WhatsApp Business API instances through a modern, responsive web interface. It provides an easy way to send messages, manage QR codes for authentication, monitor instance statuses, and now includes **AI-powered chat automation** with persistent conversation history.

## Features

- **Multi-Instance Management**: Create and manage multiple WhatsApp instances
- **QR Code Authentication**: Generate and display QR codes for WhatsApp Web authentication
- **Message Sending**: Send text messages to WhatsApp numbers
- **Real-time Status Monitoring**: Check connection and server status for each instance
- **AI Chat Automation**: OpenAI-powered conversational AI with persistent chat history
- **Chat Dashboard**: WhatsApp-style interface for viewing conversations
- **Modern UI**: Responsive design built with Tailwind CSS
- **Authentication**: Secure login system
- **Instance Configuration**: Customize instance names, providers, and settings
- **Persistent Storage**: SQLite database for chat history and AI settings

## New AI Chat Features

### 🤖 AI Integration
- **OpenAI Integration**: GPT-3.5-turbo and GPT-4 support
- **Conversation Context**: Maintains chat history for natural conversations
- **System Prompts**: Customizable AI personality and behavior
- **Per-Instance Settings**: Independent AI configuration per WhatsApp instance
- **Automatic Responses**: AI responds to incoming messages automatically

### 💬 Chat Dashboard
- **WhatsApp-Style Interface**: Native-looking chat interface
- **Contact Management**: View all contacts with message previews
- **Message History**: Persistent chat history with timestamps
- **Real-time Updates**: Auto-refresh for new messages
- **Mobile Responsive**: Works perfectly on all devices

### 🗄️ Database Features
- **SQLite Storage**: Reliable persistent storage for all data
- **Chat History**: Complete conversation logging
- **Contact Tracking**: Contact information and statistics
- **AI Settings**: Secure storage of API keys and configurations
- **Optimized Queries**: Indexed tables for fast performance

## Technologies Used

- **Backend**: PHP 7.4+, Node.js 18+
- **Frontend**: HTML5, Tailwind CSS, JavaScript
- **WhatsApp Integration**: Baileys, Evolution API, Custom providers
- **AI Integration**: OpenAI GPT-3.5-turbo, GPT-4
- **Database**: SQLite for chat data, JSON for instance configurations
- **Web Server**: Built-in PHP server or Apache/Nginx
- **QR Code Generation**: External API (qrserver.com)
- **Dependencies**: Composer for PHP packages, npm for Node.js packages

## Installation

1. Clone the repository:
```bash
git clone https://github.com/ojaneri/maestro.git
cd maestro
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install Node.js dependencies:
```bash
npm install
```

4. Configure environment variables in `.env`:
```env
PANEL_USER_EMAIL=your-email@example.com
PANEL_PASSWORD=your-secure-password
```

5. **NEW**: Setup AI Chat integration:
```bash
chmod +x setup_ai_chat.sh
./setup_ai_chat.sh
```

6. Start the web server:
```bash
php -S localhost:8000 index.php
```

## Usage

### Basic Usage
1. Access the web interface at `http://localhost:8000`
2. Log in with your configured credentials
3. Create a new WhatsApp instance
4. Connect via QR code in the modal
5. Send test messages or integrate with your applications

### AI Chat Setup
1. Configure OpenAI API key in the AI settings section
2. Set custom system prompts for your AI assistant
3. Enable AI responses for your instance
4. Access the chat dashboard at `/dashboard_chat.php?instance=<id>`
5. Monitor conversations and AI responses in real-time

### Chat Dashboard
Access the AI chat interface:
```
http://your-domain.com/api/envio/wpp/dashboard_chat.php?instance=<instance_id>
```

Features:
- View all contacts with last message previews
- Click any contact to see full conversation history
- Send manual messages through the interface
- Monitor AI responses and conversation flow
- Search and filter contacts

## API Endpoints

### Core Endpoints
- `GET /health` - Health check
- `GET /status` - Instance status
- `POST /send` - Send message
- `GET /qr` - Get QR code for authentication

### NEW: AI Chat Endpoints
- `GET /contacts` - List all contacts with last message
- `GET /history?contact=<jid>` - Get chat history for specific contact
- `GET /ai-settings` - Get current AI configuration
- `POST /ai-settings` - Save AI configuration
- Enhanced `POST /send-message` - Send message with persistence

### API Response Examples

**Get Contacts:**
```json
{
  "ok": true,
  "instanceId": "inst_123",
  "contacts": [
    {
      "remote_jid": "5585999999999@s.whatsapp.net",
      "contact_name": "John Doe",
      "last_message": "Hello, how can I help you?",
      "last_role": "assistant",
      "last_message_at": "2025-12-14T19:15:00.000Z",
      "message_count": 15
    }
  ]
}
```

**Save AI Settings:**
```json
{
  "enabled": true,
  "api_key": "sk-your-openai-key",
  "model": "gpt-3.5-turbo",
  "system_prompt": "You are a helpful customer service assistant.",
  "assistant_prompt": "I'm here to help you with any questions."
}
```

## Project Structure

`chat_data.db` substitui o antigo `instances.json` como fonte única de instâncias, credenciais Gemini e configurações de IA.

```
maestro/
├── index.php                    # Main application file
├── dashboard_chat.php           # NEW: AI Chat dashboard interface
├── db.js                        # NEW: SQLite database module
├── whatsapp-server-intelligent.js  # WhatsApp server with AI, history, and logs
├── composer.json                # PHP dependencies
├── package.json                 # Node.js dependencies
├── styles.css                   # Custom styles
├── scripts.js                   # Frontend JavaScript
├── create_instance.sh           # Instance creation script
├── stop_instance.sh             # Instance stop script
├── restart_instance.sh          # NEW: Instance restart script
├── setup_ai_chat.sh            # NEW: AI integration setup script
├── AI_CHAT_INTEGRATION.md      # NEW: Detailed AI integration guide
├── qr-proxy.php                 # QR code proxy
├── ws-proxy.php                 # WebSocket proxy
├── chat_data.db                # SQLite chat database with instance/AI data
└── README.md                    # This file
```

## AI Configuration Examples

### Customer Service Bot
```json
{
  "enabled": true,
  "api_key": "sk-your-openai-key",
  "model": "gpt-3.5-turbo",
  "system_prompt": "You are a helpful customer service representative. Always be polite, professional, and try to resolve customer issues. If you cannot solve a problem, direct them to human support.",
  "assistant_prompt": "I understand your concern and I'm here to help you resolve this."
}
```

### Sales Assistant
```json
{
  "enabled": true,
  "api_key": "sk-your-openai-key",
  "model": "gpt-4",
  "system_prompt": "You are a sales assistant. Help customers find products that meet their needs. Ask clarifying questions and provide product recommendations. Be friendly and enthusiastic about our offerings.",
  "assistant_prompt": "Great choice! Let me help you find the perfect solution."
}
```

## Security Features

- **API Key Protection**: OpenAI keys stored securely per instance
- **Database Isolation**: Chat data separated from main project files
- **Authentication**: Same secure login system as main panel
- **Input Sanitization**: All user inputs properly sanitized
- **Error Handling**: Comprehensive error handling without data exposure

## Performance Optimizations

- **Database Indexing**: Optimized queries for fast chat history retrieval
- **Connection Pooling**: Efficient SQLite connection management
- **Message Limits**: Configurable conversation context length
- **Caching**: Contact list caching for better performance
- **Lazy Loading**: Messages loaded on-demand for better UX

## Troubleshooting

### Database Issues
```bash
# Check database file
ls -la chat_data.db

# Reset database (WARNING: deletes all chat data)
rm chat_data.db

# Test database connection
node -e "const db = require('./db'); db.initDatabase().then(() => console.log('OK')).catch(console.error);"
```

### Server Issues
```bash
# Check server logs
tail -f instance_*.log

# Restart instance
bash restart_instance.sh <instance_id>

# Test API endpoints
curl http://127.0.0.1:3000/health
```

### AI Integration Issues
1. Verify OpenAI API key is valid and has sufficient credits
2. Check instance is connected to WhatsApp
3. Ensure AI is enabled in settings
4. Test with simple system prompts first
5. Check server logs for detailed error messages

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

### Development Setup
1. Fork the repository
2. Create a feature branch
3. Install dependencies: `composer install && npm install`
4. Setup AI integration: `./setup_ai_chat.sh`
5. Make your changes
6. Test thoroughly
7. Submit a pull request

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Credits

Developed by **Osvaldo J. Filho**
- Website: https://perito.digital
- LinkedIn: https://linkedin.com/in/ojaneri

---

# Maestro – Orquestrador WhatsApp

Maestro é um sistema de gerenciamento multi-instância do WhatsApp que permite orquestrar múltiplas instâncias da API do WhatsApp Business através de uma interface web moderna e responsiva. Agora inclui **automação de chat com IA** e histórico de conversas persistente.

## Funcionalidades

- **Gerenciamento Multi-Instância**: Criar e gerenciar múltiplas instâncias do WhatsApp
- **Autenticação por Código QR**: Gerar e exibir códigos QR para autenticação do WhatsApp Web
- **Envio de Mensagens**: Enviar mensagens de texto para números do WhatsApp
- **Monitoramento de Status em Tempo Real**: Verificar status de conexão e servidor para cada instância
- **Automação de Chat com IA**: IA conversacional OpenAI com histórico persistente
- **Dashboard de Chat**: Interface estilo WhatsApp para visualizar conversas
- **Interface Moderna**: Design responsivo construído com Tailwind CSS
- **Autenticação**: Sistema de login seguro
- **Configuração de Instâncias**: Personalizar nomes de instâncias, provedores e configurações
- **Armazenamento Persistente**: Banco de dados SQLite para histórico de chat e configurações de IA

## Novas Funcionalidades de IA

### 🤖 Integração com IA
- **Integração OpenAI**: Suporte a GPT-3.5-turbo e GPT-4
- **Contexto de Conversa**: Mantém histórico de chat para conversas naturais
- **Prompts de Sistema**: Personalize personalidade e comportamento da IA
- **Configurações por Instância**: Configuração independente de IA por instância do WhatsApp
- **Respostas Automáticas**: IA responde automaticamente às mensagens recebidas
 - **Funções inteligentes**: Além de `mail`, `whatsapp` e `get_web`, a IA agora reconhece `dados("email")`, `agendar("DD/MM/AAAA","HH:MM","Texto")` e `agendar2("+5m","Texto")`, consulta o banco `kitpericia` e anexa o status do cliente ativo/expirado na resposta.
- **Agendamentos**: Use `agendar("DD/MM/AAAA", "HH:MM", "Mensagem")` para registrar um envio futuro em UTC-3; o bot confirma o horário e envia automaticamente na data marcada.

#### ⚙️ Configurações de acesso ao banco
- `CUSTOMER_DB_HOST`, `CUSTOMER_DB_PORT`, `CUSTOMER_DB_USER`, `CUSTOMER_DB_PASSWORD` e `CUSTOMER_DB_NAME` podem ser usados para apontar o `dados()` para outro servidor MySQL; os valores padrão mantém compatibilidade com a tabela `users2` do `kitpericia`.

### 💬 Dashboard de Chat
- **Interface Estilo WhatsApp**: Interface de chat com aparência nativa
- **Gerenciamento de Contatos**: Ver todos os contatos com pré-visualização de mensagens
- **Histórico de Mensagens**: Histórico persistente de chat com carimbos de data/hora
- **Atualizações em Tempo Real**: Auto-atualização para novas mensagens
- **Responsivo para Mobile**: Funciona perfeitamente em todos os dispositivos

### 🗄️ Funcionalidades do Banco de Dados
- **Armazenamento SQLite**: Armazenamento persistente confiável para todos os dados
- **Histórico de Chat**: Log completo de conversas
- **Rastreamento de Contatos**: Informações e estatísticas de contatos
- **Configurações de IA**: Armazenamento seguro de chaves de API e configurações
- **Consultas Otimizadas**: Tabelas indexadas para desempenho rápido

## Tecnologias Utilizadas

- **Backend**: PHP 7.4+, Node.js 18+
- **Frontend**: HTML5, Tailwind CSS, JavaScript
- **Integração WhatsApp**: Baileys, Evolution API, Provedores customizados
- **Integração IA**: OpenAI GPT-3.5-turbo, GPT-4
- **Banco de Dados**: SQLite para dados de chat, JSON para configurações de instância
- **Servidor Web**: Servidor PHP integrado ou Apache/Nginx
- **Geração de Código QR**: API externa (qrserver.com)
- **Dependências**: Composer para pacotes PHP, npm para pacotes Node.js

## Instalação

1. Clone o repositório:
```bash
git clone https://github.com/ojaneri/maestro.git
cd maestro
```

2. Instale as dependências PHP:
```bash
composer install
```

3. Instale as dependências Node.js:
```bash
npm install
```

4. Configure as variáveis de ambiente no `.env`:
```env
PANEL_USER_EMAIL=seu-email@exemplo.com
PANEL_PASSWORD=sua-senha-segura
```

5. **NOVO**: Configure integração de chat com IA:
```bash
chmod +x setup_ai_chat.sh
./setup_ai_chat.sh
```

6. Inicie o servidor web:
```bash
php -S localhost:8000 index.php
```

## Uso

### Uso Básico
1. Acesse a interface web em `http://localhost:8000`
2. Faça login com suas credenciais configuradas
3. Crie uma nova instância do WhatsApp
4. Conecte via código QR no modal
5. Envie mensagens de teste ou integre com suas aplicações

### Configuração de Chat com IA
1. Configure a chave da API OpenAI na seção de configurações de IA
2. Defina prompts de sistema personalizados para seu assistente de IA
3. Ative respostas de IA para sua instância
4. Acesse o dashboard de chat em `/dashboard_chat.php?instance=<id>`
5. Monitore conversas e respostas de IA em tempo real

### Dashboard de Chat
Acesse a interface de chat com IA:
```
http://seu-dominio.com/api/envio/wpp/dashboard_chat.php?instance=<instance_id>
```

Funcionalidades:
- Ver todos os contatos com pré-visualização da última mensagem
- Clique em qualquer contato para ver o histórico completo da conversa
- Envie mensagens manuais através da interface
- Monitore respostas de IA e fluxo de conversas
- Pesquise e filtre contatos

## Endpoints da API

### Endpoints Principais
- `GET /health` - Verificação de saúde
- `GET /status` - Status da instância
- `POST /send` - Enviar mensagem
- `GET /qr` - Obter código QR para autenticação

### NOVOS: Endpoints de Chat com IA
- `GET /contacts` - Listar todos os contatos com última mensagem
- `GET /history?contact=<jid>` - Obter histórico de chat para contato específico
- `GET /ai-settings` - Obter configuração atual de IA
- `POST /ai-settings` - Salvar configuração de IA
- Melhorado `POST /send-message` - Enviar mensagem com persistência

## Estrutura do Projeto

```
maestro/
├── index.php                    # Arquivo principal da aplicação
├── dashboard_chat.php           # NOVO: Interface do dashboard de chat com IA
├── db.js                        # NOVO: Módulo do banco de dados SQLite
├── whatsapp-server-intelligent.js  # Servidor WhatsApp com IA, histórico e logs
├── composer.json                # Dependências PHP
├── package.json                 # Dependências Node.js
├── styles.css                   # Estilos customizados
├── scripts.js                   # JavaScript do frontend
├── create_instance.sh           # Script de criação de instância
├── stop_instance.sh             # Script de parada de instância
├── restart_instance.sh          # NOVO: Script de reinicialização de instância
├── setup_ai_chat.sh            # NOVO: Script de configuração de IA
├── AI_CHAT_INTEGRATION.md      # NOVO: Guia detalhado de integração de IA
├── qr-proxy.php                 # Proxy de código QR
├── ws-proxy.php                 # Proxy WebSocket
├── chat_data.db                # Banco SQLite com instâncias, credenciais Gemini e configs de IA
└── README.md                    # Este arquivo
```

## Configuração de IA - Exemplos

### Bot de Atendimento ao Cliente
```json
{
  "enabled": true,
  "api_key": "sk-sua-chave-openai",
  "model": "gpt-3.5-turbo",
  "system_prompt": "Você é um representante útil de atendimento ao cliente. Sempre seja educado, profissional e tente resolver questões dos clientes. Se não puder resolver um problema, direcione-os para suporte humano.",
  "assistant_prompt": "Entendo sua preocupação e estou aqui para ajudar você a resolver isso."
}
```

### Assistente de Vendas
```json
{
  "enabled": true,
  "api_key": "sk-sua-chave-openai",
  "model": "gpt-4",
  "system_prompt": "Você é um assistente de vendas. Ajude os clientes a encontrar produtos que atendam às suas necessidades. Faça perguntas de esclarecimento e forneça recomendações de produtos. Seja amigável e entusiástico sobre nossas ofertas.",
  "assistant_prompt": "Ótima escolha! Deixe-me ajudar você a encontrar a solução perfeita."
}
```

## Recursos de Segurança

- **Proteção de Chaves de API**: Chaves OpenAI armazenadas seguramente por instância
- **Isolamento do Banco de Dados**: Dados de chat separados dos arquivos do projeto principal
- **Autenticação**: Mesmo sistema de login seguro do painel principal
- **Sanitização de Entrada**: Todas as entradas de usuário adequadamente sanitizadas
- **Tratamento de Erros**: Tratamento abrangente de erros sem exposição de dados

## Otimizações de Performance

- **Indexação de Banco de Dados**: Consultas otimizadas para rápida recuperação do histórico de chat
- **Pool de Conexões**: Gerenciamento eficiente de conexões SQLite
- **Limites de Mensagens**: Comprimento de contexto de conversa configurável
- **Cache**: Cache de lista de contatos para melhor desempenho
- **Carregamento Preguiçoso**: Mensagens carregadas sob demanda para melhor UX

## Solução de Problemas

### Problemas com Banco de Dados
```bash
# Verificar arquivo do banco de dados
ls -la chat_data.db

# Redefinir banco de dados (AVISO: exclui todos os dados de chat)
rm chat_data.db

# Testar conexão do banco de dados
node -e "const db = require('./db'); db.initDatabase().then(() => console.log('OK')).catch(console.error);"
```

### Problemas com Servidor
```bash
# Verificar logs do servidor
tail -f instance_*.log

# Reiniciar instância
bash restart_instance.sh <instance_id>

# Testar endpoints da API
curl http://127.0.0.1:3000/health
```

### Problemas com Integração de IA
1. Verifique se a chave da API OpenAI é válida e tem créditos suficientes
2. Certifique-se de que a instância está conectada ao WhatsApp
3. Certifique-se de que a IA está ativada nas configurações
4. Teste com prompts de sistema simples primeiro
5. Verifique os logs do servidor para mensagens de erro detalhadas

## Contribuição

Contribuições são bem-vindas! Sinta-se à vontade para enviar um Pull Request.

### Configuração de Desenvolvimento
1. Faça um fork do repositório
2. Crie uma branch de recurso
3. Instale dependências: `composer install && npm install`
4. Configure integração de IA: `./setup_ai_chat.sh`
5. Faça suas alterações
6. Teste completamente
7. Envie um pull request

## Licença

Este projeto está licenciado sob a Licença MIT - veja o arquivo LICENSE para detalhes.

## Créditos

Desenvolvido por **Osvaldo J. Filho**
- Website: https://perito.digital
- LinkedIn: https://linkedin.com/in/ojaneri
