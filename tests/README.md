# Maestro Test Suite

> Documentação de testes para o sistema Maestro WhatsApp Orchestrator

## Visão Geral

Este diretório contém a suíte de testes completa para o sistema Maestro, incluindo testes unitários, de integração, E2E e de API.

### Estrutura de Diretórios

```
tests/
├── README.md                 # Este arquivo
├── package.json              # Dependências de teste
├── unit/                     # Testes unitários
│   ├── database/             # Testes do módulo de banco de dados
│   ├── ai/                   # Testes de provedores de IA
│   ├── whatsapp/            # Testes de funções WhatsApp
│   └── utils/                # Testes de utilitários
├── e2e/                      # Testes end-to-end
│   ├── instance-flows/      # Fluxos completos de instância
│   ├── ai-chat/              # Fluxos de chat com IA
│   └── messaging/           # Fluxos de mensagens
├── integration/              # Testes de integração
│   ├── whatsapp-api/         # Integração WhatsApp API
│   ├── ai-providers/        # Integração com provedores IA
│   └── calendar/            # Integração Google Calendar
├── api/                      # Testes de API REST
│   ├── instance/             # Endpoints de instância
│   ├── messages/             # Endpoints de mensagens
│   └── ai/                   # Endpoints de IA
├── helpers/                  # Utilitários de teste
│   ├── mockData.js          # Dados mockados
│   ├── setup.js             # Configuração de ambiente
│   └── teardown.js          # Limpeza após testes
└── fixtures/                # Arquivos fixos para testes
    ├── images/              # Imagens de teste
    ├── audio/               # Áudios de teste
    └── json/                # JSONs de teste
```

---

## Stack de Testes

### Dependências Recomendadas

```json
{
  "devDependencies": {
    "jest": "^29.0.0",
    "supertest": "^6.3.0",
    "sinon": "^15.0.0",
    "nock": "^13.3.0",
    "faker": "^5.5.3",
    "cross-env": "^7.0.3"
  }
}
```

### Instalação

```bash
npm install --save-dev jest supertest sinon nock faker cross-env
```

---

## Categorias de Testes

### 1. Testes Unitários (`unit/`)

Testam funções isoladas do sistema.

#### 1.1 Database (`unit/database/`)

| Módulo | Arquivo | O que testar |
|--------|---------|--------------|
| `db.js` | `database.test.js` | `initDatabase()`, `saveMessage()`, `getMessages()`, `getChats()` |
| `db.js` | `contacts.test.js` | `saveContactMetadata()`, `getContacts()`, `updateContact()` |
| `db.js` | `scheduled.test.js` | `saveScheduledMessage()`, `getScheduledMessages()`, `updateStatus()` |

**Exemplo de teste:**

```javascript
// tests/unit/database/contacts.test.js
const db = require('../../../db');
const { faker } = require('@faker-js/faker');

describe('Contact Operations', () => {
  beforeAll(async () => {
    await db.initDatabase();
  });

  test('should save contact metadata', async () => {
    const instanceId = 'inst_test123';
    const remoteJid = faker.phone.number() + '@s.whatsapp.net';
    
    const result = await db.saveContactMetadata(instanceId, remoteJid, {
      contact_name: faker.person.fullName(),
      status_name: 'online',
      temperature: 'warm'
    });
    
    expect(result).toHaveProperty('persisted_count');
  });

  test('should retrieve contacts by instance', async () => {
    const contacts = await db.getContacts('inst_test123');
    expect(Array.isArray(contacts)).toBe(true);
  });
});
```

**Executar:**

```bash
npx jest tests/unit/database/
```

#### 1.2 AI Providers (`unit/ai/`)

| Módulo | Arquivo | O que testar |
|--------|---------|--------------|
| `openai.js` | `openai.test.js` | `generateResponse()`, validação de API key |
| `gemini.js` | `gemini.test.js` | `generateResponse()`, `transcribeAudio()` |
| `openrouter.js` | `openrouter.test.js` | `generateResponse()`, fallback sequencing |

**Exemplo de teste:**

```javascript
// tests/unit/ai/gemini.test.js
const { generateResponse } = require('../../../src/whatsapp-server/ai/providers/gemini');

describe('Gemini Provider', () => {
  const mockConfig = {
    api_key: 'test-api-key',
    model: 'gemini-pro',
    system_prompt: 'You are a helpful assistant.'
  };

  test('should generate response with valid config', async () => {
    nock('https://generativelanguage.googleapis.com')
      .post('/v1/models/gemini-pro:generateContent')
      .reply(200, {
        candidates: [{
          content: {
            parts: [{ text: 'Test response' }]
          }
        }]
      });

    const response = await generateResponse('Hello', mockConfig);
    expect(response).toBe('Test response');
  });

  test('should throw error with invalid API key', async () => {
    const invalidConfig = { ...mockConfig, api_key: 'invalid' };
    await expect(generateResponse('Hello', invalidConfig))
      .rejects.toThrow('Invalid API key');
  });
});
```

**Executar:**

```bash
npx jest tests/unit/ai/
```

#### 1.3 WhatsApp Functions (`unit/whatsapp/`)

| Módulo | Arquivo | O que testar |
|--------|---------|--------------|
| `messages.js` | `formatMessage.test.js` | Formatação de números, validação JID |
| `messages.js` | `processMedia.test.js` | Processamento de mídia |
| `connection.js` | `status.test.js` | Estados de conexão |

**Exemplo de teste:**

```javascript
// tests/unit/whatsapp/messages.test.js
const { formatPhoneNumber, isValidJID, processMediaUrl } = require('../../../src/whatsapp-server/whatsapp/utils');

describe('WhatsApp Message Utils', () => {
  describe('formatPhoneNumber', () => {
    test('should format Brazilian number correctly', () => {
      expect(formatPhoneNumber('558586030781')).toBe('+55 85 8603-0781');
    });

    test('should handle number with country code', () => {
      expect(formatPhoneNumber('+558586030781')).toBe('+55 85 8603-0781');
    });
  });

  describe('isValidJID', () => {
    test('should validate correct JID format', () => {
      expect(isValidJID('558586030781@s.whatsapp.net')).toBe(true);
    });

    test('should reject invalid JID', () => {
      expect(isValidJID('invalid-jid')).toBe(false);
    });
  });
});
```

**Executar:**

```bash
npx jest tests/unit/whatsapp/
```

#### 1.4 Utils (`unit/utils/`)

| Módulo | Arquivo | O que testar |
|--------|---------|--------------|
| `helpers.php` | `validation.test.php` | Validação de dados |
| `timezone.php` | `timezone.test.php` | Conversão de timezone |
| `auth.php` | `auth.test.php` | Autenticação, tokens |

---

### 2. Testes de Integração (`integration/`)

Testam a interação entre múltiplos módulos.

#### 2.1 WhatsApp API Integration

```javascript
// tests/integration/whatsapp-api/instance.test.js
const request = require('supertest');
const { app } = require('../../../whatsapp-server-intelligent');

describe('WhatsApp Instance API', () => {
  const instancePort = 3099; // Test instance port

  beforeAll(async () => {
    // Start test instance
    await startTestInstance(instancePort);
  });

  afterAll(async () => {
    await stopTestInstance(instancePort);
  });

  describe('GET /health', () => {
    test('should return instance health status', async () => {
      const response = await request(`http://localhost:${instancePort}`)
        .get('/health');
      
      expect(response.status).toBe(200);
      expect(response.body).toHaveProperty('ok', true);
      expect(response.body).toHaveProperty('instanceId');
    });
  });

  describe('GET /status', () => {
    test('should return connection status', async () => {
      const response = await request(`http://localhost:${instancePort}`)
        .get('/status');
      
      expect(response.status).toBe(200);
      expect(response.body).toHaveProperty('connectionStatus');
    });
  });

  describe('POST /send-message', () => {
    test('should send text message successfully', async () => {
      const response = await request(`http://localhost:${instancePort}`)
        .post('/send-message')
        .send({
          to: '558586030781',
          message: 'Test message'
        });
      
      expect(response.status).toBe(200);
      expect(response.body).toHaveProperty('ok', true);
    });
  });
});
```

#### 2.2 AI Providers Integration

```javascript
// tests/integration/ai-providers/fallback.test.js
const { generateWithFallback } = require('../../../src/whatsapp-server/ai/index');

describe('AI Provider Fallback', () => {
  test('should fallback to Gemini when OpenAI fails', async () => {
    // Mock OpenAI failure
    nock('https://api.openai.com')
      .post('/v1/chat/completions')
      .reply(500, { error: 'Server error' });

    // Mock Gemini success
    nock('https://generativelanguage.googleapis.com')
      .post('/v1/models/gemini-pro:generateContent')
      .reply(200, {
        candidates: [{ content: { parts: [{ text: 'Fallback response' }] } }]
      });

    const response = await generateWithFallback('Hello', {
      providers: ['openai', 'gemini'],
      openai: { api_key: 'sk-test' },
      gemini: { api_key: 'test-key' }
    });

    expect(response).toBe('Fallback response');
  });
});
```

#### 2.3 Google Calendar Integration

```javascript
// tests/integration/calendar/calendar.test.js
const { getAvailability, createEvent } = require('../../../api/calendar/google-calendar');

describe('Google Calendar Integration', () => {
  const mockToken = {
    instance_id: 'inst_test',
    access_token: 'mock-access-token',
    refresh_token: 'mock-refresh-token'
  };

  test('should fetch availability from calendar', async () => {
    nock('https://www.googleapis.com')
      .get('/calendar/v3/calendars/primary/freeBusy')
      .reply(200, {
        calendars: {
          'primary': {
            busy: [{ start: '2026-03-01T09:00:00Z', end: '2026-03-01T12:00:00Z' }]
          }
        }
      });

    const availability = await getAvailability(mockToken, {
      timezone: 'America/Sao_Paulo',
      start: '2026-03-01T00:00:00Z',
      end: '2026-03-01T23:59:59Z'
    });

    expect(Array.isArray(availability)).toBe(true);
  });
});
```

---

### 3. Testes E2E (`e2e/`)

Testes de fluxo completo do sistema.

#### 3.1 Instance Flow

```javascript
// tests/e2e/instance-flows/create-connect.test.js
const { spawn } = require('child_process');
const request = require('supertest');

describe('Instance Creation and Connection Flow', () => {
  let instanceId;
  let instancePort = 3100;

  test('should create new instance and connect via QR', async () => {
    // 1. Create instance via API
    const createResponse = await request('http://localhost:8080')
      .post('/api.php')
      .send({
        action: 'create_instance',
        name: 'Test Instance'
      });
    
    expect(createResponse.body).toHaveProperty('instance_id');
    instanceId = createResponse.body.instance_id;

    // 2. Start instance process
    await startInstanceProcess(instanceId, instancePort);
    
    // 3. Wait for QR code
    await waitForQRCode(instancePort, 30000);

    // 4. Fetch QR
    const qrResponse = await request(`http://localhost:${instancePort}`)
      .get('/qr');
    
    expect(qrResponse.body).toHaveProperty('qr');
    
    // 5. Simulate QR scan (mock)
    await simulateQRScan(instancePort);
    
    // 6. Verify connection
    await waitForConnection(instancePort, 60000);
    
    const statusResponse = await request(`http://localhost:${instancePort}`)
      .get('/status');
    
    expect(statusResponse.body.connectionStatus).toBe('connected');
  }, 120000);

  afterAll(async () => {
    if (instanceId) {
      await stopInstanceProcess(instanceId);
      await deleteInstance(instanceId);
    }
  });
});
```

#### 3.2 AI Chat Flow

```javascript
// tests/e2e/ai-chat/automated-response.test.js
describe('AI Chat Automated Response Flow', () => {
  const instanceId = 'inst_test_ai';
  const testContact = '558599999999@s.whatsapp.net';

  beforeAll(async () => {
    await configureAI(instanceId, {
      enabled: true,
      provider: 'openai',
      api_key: process.env.TEST_OPENAI_KEY,
      system_prompt: 'You are a helpful assistant.'
    });
  });

  test('should automatically respond to incoming message', async () => {
    // Simulate incoming message
    const incomingMessage = {
      key: { remoteJid: testContact, fromMe: false },
      message: { conversation: 'Hello, how can you help me?' },
      pushName: 'Test User'
    };

    // Trigger message handler
    await simulateIncomingMessage(instanceId, incomingMessage);

    // Wait for AI response
    await waitForAIResponse(10000);

    // Verify AI response was sent
    const messages = await getMessages(instanceId, testContact);
    const lastMessage = messages[messages.length - 1];
    
    expect(lastMessage.role).toBe('assistant');
    expect(lastMessage.content).toBeTruthy();
  });
});
```

#### 3.3 Messaging Flow

```javascript
// tests/e2e/messaging/scheduled-messages.test.js
describe('Scheduled Messages Flow', () => {
  test('should send scheduled message at specified time', async () => {
    const scheduledTime = new Date(Date.now() + 60000); // 1 minute from now
    
    // Schedule message
    const scheduleResponse = await request('http://localhost:8080')
      .post('/api.php')
      .send({
        action: 'schedule_message',
        instance: 'inst_test',
        to: '558586030781',
        message: 'Scheduled test message',
        scheduled_at: scheduledTime.toISOString()
      });
    
    expect(scheduleResponse.body.success).toBe(true);
    const messageId = scheduleResponse.body.message_id;

    // Wait for scheduled time
    await wait(scheduledTime.getTime() - Date.now() + 5000);

    // Verify message was sent
    const statusResponse = await request('http://localhost:8080')
      .get(`/api.php?action=get_scheduled&id=${messageId}`);
    
    expect(statusResponse.body.status).toBe('sent');
  }, 90000);
});
```

---

### 4. Testes de API (`api/`)

Testes dos endpoints REST.

#### 4.1 Instance Endpoints

```javascript
// tests/api/instance/endpoints.test.js
const request = require('supertest');

describe('Instance API Endpoints', () => {
  const baseURL = 'http://localhost:8080';

  describe('POST /api.php', () => {
    test('action=create_instance', async () => {
      const response = await request(baseURL)
        .post('/api.php')
        .send({
          action: 'create_instance',
          name: 'API Test Instance'
        });
      
      expect(response.body).toHaveProperty('instance_id');
      expect(response.body).toHaveProperty('port');
    });

    test('action=update_instance', async () => {
      const response = await request(baseURL)
        .post('/api.php')
        .send({
          action: 'update_instance',
          instance_id: 'inst_test',
          name: 'Updated Name'
        });
      
      expect(response.body.success).toBe(true);
    });

    test('action=delete_instance', async () => {
      const response = await request(baseURL)
        .post('/api.php')
        .send({
          action: 'delete_instance',
          instance_id: 'inst_test_delete'
        });
      
      expect(response.body.success).toBe(true);
    });

    test('action=list_instances', async () => {
      const response = await request(baseURL)
        .post('/api.php')
        .send({ action: 'list_instances' });
      
      expect(Array.isArray(response.body.instances)).toBe(true);
    });
  });
});
```

#### 4.2 Message Endpoints

```javascript
// tests/api/messages/endpoints.test.js
describe('Message API Endpoints', () => {
  test('action=send_message via instance API', async () => {
    const response = await request(`http://localhost:3010`)
      .post('/send-message')
      .send({
        to: '558586030781',
        message: 'API Test Message'
      });
    
    expect(response.status).toBe(200);
    expect(response.body.ok).toBe(true);
  });

  test('action=get_messages', async () => {
    const response = await request('http://localhost:8080')
      .post('/api.php')
      .send({
        action: 'get_messages',
        instance: 'inst_3010',
        contact: '558586030781@s.whatsapp.net',
        limit: 50
      });
    
    expect(Array.isArray(response.body.messages)).toBe(true);
  });
});
```

#### 4.3 AI Configuration Endpoints

```javascript
// tests/api/ai/endpoints.test.js
describe('AI Configuration API', () => {
  test('action=save_openai', async () => {
    const response = await request('http://localhost:8080')
      .post('/api.php')
      .send({
        action: 'save_openai',
        instance_id: 'inst_3010',
        openai: {
          enabled: true,
          api_key: 'sk-test-key',
          model: 'gpt-4',
          system_prompt: 'You are a helpful assistant.'
        }
      });
    
    expect(response.body.success).toBe(true);
  });

  test('action=get_ai_settings', async () => {
    const response = await request('http://localhost:8080')
      .post('/api.php')
      .send({
        action: 'get_ai_settings',
        instance_id: 'inst_3010'
      });
    
    expect(response.body).toHaveProperty('ai_config');
  });
});
```

---

## Executando os Testes

### Todos os Testes

```bash
npm test
# ou
npx jest
```

### Por Categoria

```bash
# Unitários
npx jest tests/unit/

# Integração
npx jest tests/integration/

# E2E
npx jest tests/e2e/

# API
npx jest tests/api/
```

### Com Coverage

```bash
npx jest --coverage
```

### Modo Watch

```bash
npx jest --watch
```

### Testes Específicos

```bash
# Testar um arquivo específico
npx jest tests/unit/database/contacts.test.js

# Testar por padrão (pattern)
npx jest --testNamePattern="should save"
```

---

## Configuração de Ambiente

### Variáveis de Ambiente para Testes

```env
# .env.test
NODE_ENV=test

# Database
DATABASE_PATH=./test_chat_data.db

# WhatsApp Test Instance
TEST_INSTANCE_PORT=3099

# AI Providers (use mock keys for testing)
TEST_OPENAI_KEY=sk-test-key
TEST_GEMINI_KEY=SUA_CHAVE_GEMINI_AQUI
TEST_OPENROUTER_KEY=or-test-key

# Google Calendar (mock)
GOOGLE_CLIENT_ID=test-client-id
GOOGLE_CLIENT_SECRET=test-secret
```

### Jest Config

```javascript
// jest.config.js
module.exports = {
  testEnvironment: 'node',
  testMatch: ['**/tests/**/*.test.js'],
  coverageDirectory: 'tests/coverage',
  collectCoverageFrom: [
    'src/**/*.js',
    'api/**/*.php',
    'models/**/*.php',
    'controllers/**/*.php',
    '!**/node_modules/**'
  ],
  coverageThreshold: {
    global: {
      branches: 70,
      functions: 70,
      lines: 70,
      statements: 70
    }
  },
  setupFilesAfterEnv: ['<rootDir>/tests/helpers/setup.js'],
  testTimeout: 30000,
  verbose: true
};
```

---

## Funcionalidades do Sistema para Testar

### Checklist de Cobertura

#### Core Features

- [ ] **Gerenciamento Multi-Instância**
  - [ ] Criar instância
  - [ ] Listar instâncias
  - [ ] Atualizar instância (nome, porta)
  - [ ] Deletar instância
  - [ ] Reiniciar instância
  - [ ] Status da instância

- [ ] **Autenticação QR Code**
  - [ ] Gerar QR code
  - [ ] Validar QR code
  - [ ] Reconexão automática
  - [ ] Manutenção de sessão

- [ ] **Mensagens**
  - [ ] Enviar mensagem de texto
  - [ ] Enviar imagem (URL)
  - [ ] Enviar imagem (Base64)
  - [ ] Enviar áudio
  - [ ] Enviar vídeo
  - [ ] Mensagens agendadas
  - [ ] Mensagens de campanha

- [ ] **IA e Automação**
  - [ ] OpenAI integration
  - [ ] Gemini integration
  - [ ] OpenRouter integration
  - [ ] Fallback sequencing
  - [ ] Transcription de áudio
  - [ ] Secretary mode
  - [ ] Function calling

#### Advanced Features

- [ ] **Google Calendar**
  - [ ] OAuth connection
  - [ ] List calendars
  - [ ] Check availability
  - [ ] Create events
  - [ ] Timezone handling

- [ ] **Meta API**
  - [ ] Template management
  - [ ] Template approval status
  - [ ] Send template messages
  - [ ] 24h window enforcement
  - [ ] Message status tracking

- [ ] **Multi-User**
  - [ ] Criar usuário
  - [ ] Autenticação
  - [ ] Permissões (Manager/Operator)
  - [ ] Acesso a instâncias

---

## Dados Mockados

### Mock para Mensagens

```javascript
// tests/helpers/mockData.js
const { faker } = require('@faker-js/faker');

const mockMessage = (overrides = {}) => ({
  key: {
    remoteJid: faker.phone.number() + '@s.whatsapp.net',
    fromMe: false,
    id: faker.string.alphanumeric(20)
  },
  message: {
    conversation: faker.lorem.sentence()
  },
  pushName: faker.person.fullName(),
  timestamp: Date.now(),
  ...overrides
});

const mockContact = (overrides = {}) => ({
  remote_jid: faker.phone.number() + '@s.whatsapp.net',
  contact_name: faker.person.fullName(),
  status_name: 'online',
  profile_picture: null,
  ...overrides
});

const mockScheduledMessage = (overrides = {}) => ({
  instance_id: 'inst_test',
  remote_jid: faker.phone.number() + '@s.whatsapp.net',
  message: faker.lorem.sentence(),
  scheduled_at: new Date(Date.now() + 3600000).toISOString(),
  status: 'pending',
  ...overrides
});

module.exports = {
  mockMessage,
  mockContact,
  mockScheduledMessage
};
```

---

## Boas Práticas

### 1. Nomeação de Testes

```javascript
// ✅ Bom
test('should save message to database with correct fields', async () => {});
test('should return 400 for invalid phone number', async () => {});
test('should fallback to Gemini when OpenAI fails', async () => {});

// ❌ Ruim
test('save message', async () => {});
test('test error', async () => {});
test('fallback', async () => {});
```

### 2. Estrutura AAA

```javascript
test('should send message successfully', async () => {
  // Arrange
  const messageData = { to: '558586030781', message: 'Test' };
  
  // Act
  const result = await sendMessage(messageData);
  
  // Assert
  expect(result.ok).toBe(true);
});
```

### 3. Cleanup

```javascript
afterEach(async () => {
  // Limpar dados de teste
  await cleanupTestDatabase();
  
  // Limpar mocks
  nock.cleanAll();
});

afterAll(async () => {
  // Fechar conexões
  await closeDatabase();
  
  // Parar instâncias de teste
  await stopAllTestInstances();
});
```

---

## Troubleshooting

### Problemas Comuns

| Problema | Solução |
|----------|---------|
| ECONNREFUSED | Verificar se servidor está rodando na porta correta |
| EAI_AGAIN | Verificar conexão de rede/DNS |
| Timeout | Aumentar `testTimeout` ou verificar lógica assíncrona |
| Mock não funciona | Verificar se `nock` está ativado corretamente |

### Debugging

```javascript
// Adicionar console.log para debug
test('debug test', async () => {
  console.log('Debug info:', someVariable);
  // ...
});
```

---

## Referências

- [Jest Docs](https://jestjs.io/docs/getting-started)
- [Supertest](https://github.com/visionmedia/supertest)
- [Sinon](https://sinonjs.org/)
- [Nock](https://github.com/nock/nock)
- [Faker](https://fakerjs.dev/)
