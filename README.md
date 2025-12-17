# Maestro – Orquestrador WhatsApp

<p align="center">
  <img src="docs/images/screen1.png" alt="Maestro Dashboard" width="45%">
  &nbsp; &nbsp;
  <img src="docs/images/screen2.png" alt="Maestro Chat" width="45%">
</p>

Maestro é um sistema de gerenciamento multi-instância para WhatsApp, permitindo orquestrar múltiplas instâncias da API de forma centralizada através de uma interface web moderna e responsiva. O sistema foi atualizado para utilizar um banco de dados **SQLite** como fonte única de verdade, eliminando a necessidade de arquivos `instances.json`.

---

# Maestro – WhatsApp Orchestrator

Maestro is a multi-instance WhatsApp management system that allows you to orchestrate multiple API instances centrally through a modern, responsive web interface. The system has been updated to use a **SQLite** database as the single source of truth, eliminating the need for `instances.json` files.

---

## Funcionalidades (PT)

- **Gerenciamento Multi-Instância**: Crie e gerencie múltiplas instâncias do WhatsApp a partir de um banco de dados central.
- **Autenticação por QR Code**: Gere e exiba códigos QR para autenticação.
- **Envio de Mensagens**: Envie mensagens de texto para qualquer número do WhatsApp.
- **Monitoramento de Status em Tempo Real**: Verifique o status de conexão de cada instância.
- **Automação com IA**: Respostas automáticas com IA (OpenAI e Gemini), com histórico de conversas persistente.
- **Dashboard de Chat**: Interface no estilo WhatsApp para visualização e resposta a conversas.
- **Armazenamento Unificado**: Todas as configurações, mensagens e metadados são armazenados em um banco de dados SQLite.

## Features (EN)

- **Multi-Instance Management**: Create and manage multiple WhatsApp instances from a central database.
- **QR Code Authentication**: Generate and display QR codes for authentication.
- **Message Sending**: Send text messages to any WhatsApp number.
- **Real-time Status Monitoring**: Check the connection status of each instance.
- **AI-Powered Automation**: Automatic AI-powered responses (OpenAI and Gemini) with persistent conversation history.
- **Chat Dashboard**: WhatsApp-style interface for viewing and responding to conversations.
- **Unified Storage**: All configurations, messages, and metadata are stored in a single SQLite database.

---

## Tecnologias (PT)

- **Backend**: PHP 8.0+, Node.js 18+
- **Frontend**: HTML5, Tailwind CSS, JavaScript
- **Banco de Dados**: SQLite
- **Integração WhatsApp**: Baileys
- **Integração IA**: OpenAI (GPT), Gemini

## Technologies (EN)

- **Backend**: PHP 8.0+, Node.js 18+
- **Frontend**: HTML5, Tailwind CSS, JavaScript
- **Database**: SQLite
- **WhatsApp Integration**: Baileys
- **AI Integration**: OpenAI (GPT), Gemini

---

## Instalação (PT)

1.  **Clone o repositório:**
    ```bash
    git clone https://github.com/ojaneri/maestro.git
    cd maestro
    ```

2.  **Instale as dependências:**
    ```bash
    composer install
    npm install
    ```

3.  **Configure as variáveis de ambiente:**
    Crie um arquivo `.env` e defina as credenciais de login.
    ```env
    PANEL_PASSWORD=sua-senha-segura
    ```

4.  **Inicie o sistema:**
    ```bash
    php -S localhost:8080 index.php
    ```
    O banco de dados `chat_data.db` e as tabelas serão criados automaticamente no primeiro acesso.

## Installation (EN)

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/ojaneri/maestro.git
    cd maestro
    ```

2.  **Install dependencies:**
    ```bash
    composer install
    npm install
    ```

3.  **Configure environment variables:**
    Create a `.env` file and set your login credentials.
    ```env
    PANEL_PASSWORD=your-secure-password
    ```

4.  **Start the system:**
    ```bash
    php -S localhost:8080 index.php
    ```
    The `chat_data.db` database and its tables will be created automatically on the first run.

---

## Esquema do Banco de Dados (SQL)

O sistema utiliza um banco de dados SQLite (`chat_data.db`) para armazenar todas as informações. As tabelas são criadas automaticamente, mas você pode usar o esquema abaixo como referência.

```sql
CREATE TABLE IF NOT EXISTS settings (
    instance_id TEXT NOT NULL DEFAULT '',
    key TEXT NOT NULL,
    value TEXT,
    PRIMARY KEY (instance_id, key)
);

CREATE TABLE IF NOT EXISTS instances (
    instance_id TEXT PRIMARY KEY,
    name TEXT,
    port INTEGER,
    api_key TEXT,
    status TEXT,
    connection_status TEXT,
    base_url TEXT,
    phone TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id TEXT NOT NULL,
    remote_jid TEXT NOT NULL,
    role TEXT NOT NULL CHECK(role IN ('user', 'assistant')),
    content TEXT NOT NULL,
    direction TEXT CHECK(direction IN ('inbound','outbound')) NOT NULL DEFAULT 'inbound',
    metadata TEXT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS threads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id TEXT NOT NULL,
    remote_jid TEXT NOT NULL,
    thread_id TEXT NOT NULL,
    last_message_id TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(instance_id, remote_jid)
);

CREATE TABLE IF NOT EXISTS contact_metadata (
    instance_id TEXT NOT NULL,
    remote_jid TEXT NOT NULL,
    contact_name TEXT,
    status_name TEXT,
    profile_picture TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (instance_id, remote_jid)
);

CREATE TABLE IF NOT EXISTS scheduled_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id TEXT NOT NULL,
    remote_jid TEXT NOT NULL,
    message TEXT NOT NULL,
    scheduled_at TEXT NOT NULL,
    status TEXT NOT NULL CHECK(status IN ('pending','sent','failed')) DEFAULT 'pending',
    last_attempt_at TEXT,
    error TEXT,
    is_paused INTEGER NOT NULL DEFAULT 0,
    tag TEXT NOT NULL DEFAULT 'default',
    tipo TEXT NOT NULL DEFAULT 'followup',
    campaign_id TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS contact_context (
    instance_id TEXT NOT NULL,
    remote_jid TEXT NOT NULL,
    key TEXT NOT NULL,
    value TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (instance_id, remote_jid, key)
);

CREATE TABLE IF NOT EXISTS event_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id TEXT NOT NULL,
    remote_jid TEXT,
    category TEXT NOT NULL,
    description TEXT NOT NULL,
    metadata TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## Estrutura do Projeto (PT)

A estrutura foi simplificada para refletir a centralização dos dados no banco de dados.

```
maestro/
├── index.php                 # Arquivo principal da aplicação (painel)
├── api.php                   # Endpoints da API para mensagens e integrações
├── gemini.php                # Lógica para integração com Gemini
├── dashboard_chat.php        # Interface do dashboard de chat
├── db-updated.js             # Módulo de gerenciamento do banco de dados SQLite
├── whatsapp-server-intelligent.js # Servidor Node.js que gerencia as instâncias Baileys
├── composer.json             # Dependências do PHP
├── package.json              # Dependências do Node.js
├── .env                      # Arquivo para variáveis de ambiente (NÃO versionar)
├── chat_data.db              # Banco de dados SQLite (NÃO versionar)
└── README.md                 # Este arquivo
```

## Project Structure (EN)

The structure has been simplified to reflect the centralization of data in the database.

```
maestro/
├── index.php                 # Main application file (dashboard)
├── api.php                   # API endpoints for messages and integrations
├── gemini.php                # Logic for Gemini integration
├── dashboard_chat.php        # Chat dashboard interface
├── db-updated.js             # SQLite database management module
├── whatsapp-server-intelligent.js # Node.js server that manages Baileys instances
├── composer.json             # PHP dependencies
├── package.json              # Node.js dependencies
├── .env                      # File for environment variables (DO NOT commit)
├── chat_data.db              # SQLite database (DO NOT commit)
└── README.md                 # This file
```

---

## Créditos (Credits)

Desenvolvido por (Developed by) **Osvaldo J. Filho**
- **Website**: [perito.digital](https://perito.digital)
- **LinkedIn**: [linkedin.com/in/ojaneri](https://linkedin.com/in/ojaneri)