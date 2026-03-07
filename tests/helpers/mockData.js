/**
 * Test Helper - Mock Data
 * Provides mock data generators for tests
 */

const { faker } = require('@faker-js/faker');

// ============================================
// Message Mocks
// ============================================

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

const mockTextMessage = (phoneNumber, text) => mockMessage({
  message: { conversation: text },
  key: { remoteJid: `${phoneNumber}@s.whatsapp.net` }
});

const mockImageMessage = (phoneNumber, imageUrl) => mockMessage({
  message: {
    imageMessage: {
      url: imageUrl,
      caption: faker.lorem.sentence(),
      mimetype: 'image/jpeg'
    }
  },
  key: { remoteJid: `${phoneNumber}@s.whatsapp.net` }
});

const mockAudioMessage = (phoneNumber) => mockMessage({
  message: {
    audioMessage: {
      url: faker.internet.url(),
      mimetype: 'audio/ogg; codecs=opus'
    }
  },
  key: { remoteJid: `${phoneNumber}@s.whatsapp.net` }
});

// ============================================
// Contact Mocks
// ============================================

const mockContact = (overrides = {}) => ({
  remote_jid: faker.phone.number() + '@s.whatsapp.net',
  contact_name: faker.person.fullName(),
  status_name: faker.lorem.words(3),
  profile_picture: null,
  temperature: 'warm',
  ...overrides
});

// ============================================
// Instance Mocks
// ============================================

const mockInstance = (overrides = {}) => ({
  instance_id: 'inst_' + faker.string.alphanumeric(12),
  name: faker.company.name() + ' Instance',
  port: faker.number.int({ min: 3000, max: 3999 }),
  api_key: faker.string.alphanumeric(32),
  status: 'active',
  connection_status: 'disconnected',
  base_url: 'http://127.0.0.1',
  phone: faker.phone.number(),
  created_at: new Date().toISOString(),
  updated_at: new Date().toISOString(),
  ...overrides
});

// ============================================
// Scheduled Message Mocks
// ============================================

const mockScheduledMessage = (overrides = {}) => ({
  instance_id: 'inst_test',
  remote_jid: faker.phone.number() + '@s.whatsapp.net',
  message: faker.lorem.sentence(),
  scheduled_at: new Date(Date.now() + 3600000).toISOString(),
  status: 'pending',
  is_paused: false,
  tag: 'default',
  tipo: 'followup',
  ...overrides
});

// ============================================
// User Mocks
// ============================================

const mockUser = (overrides = {}) => ({
  id: faker.number.int({ min: 1, max: 9999 }),
  name: faker.person.fullName(),
  email: faker.internet.email(),
  password_hash: faker.string.alphanumeric(60),
  role: faker.helpers.arrayElement(['user', 'manager']),
  status: 'active',
  created_at: new Date().toISOString(),
  ...overrides
});

// ============================================
// AI Config Mocks
// ============================================

const mockAIConfig = (overrides = {}) => ({
  enabled: true,
  provider: faker.helpers.arrayElement(['openai', 'gemini', 'openrouter']),
  api_key: 'sk-' + faker.string.alphanumeric(48),
  model: 'gpt-4',
  system_prompt: 'You are a helpful assistant.',
  assistant_prompt: 'Hello! How can I help you?',
  temperature: 0.7,
  max_tokens: 1000,
  ...overrides
});

// ============================================
// Calendar Mocks
// ============================================

const mockCalendarToken = (overrides = {}) => ({
  instance_id: 'inst_test',
  access_token: faker.string.alphanumeric(100),
  refresh_token: faker.string.alphanumeric(100),
  token_type: 'Bearer',
  expires_at: new Date(Date.now() + 3600000).toISOString(),
  ...overrides
});

const mockCalendarEvent = (overrides = {}) => ({
  summary: faker.lorem.sentence(),
  description: faker.lorem.paragraph(),
  start: {
    dateTime: new Date(Date.now() + 86400000).toISOString(),
    timeZone: 'America/Sao_Paulo'
  },
  end: {
    dateTime: new Date(Date.now() + 90000000).toISOString(),
    timeZone: 'America/Sao_Paulo'
  },
  attendees: [],
  ...overrides
});

// ============================================
// API Response Mocks
// ============================================

const mockHealthResponse = (overrides = {}) => ({
  ok: true,
  instanceId: 'inst_test',
  status: 'running',
  whatsappConnected: false,
  ...overrides
});

const mockStatusResponse = (overrides = {}) => ({
  instanceId: 'inst_test',
  connectionStatus: 'disconnected',
  whatsappConnected: false,
  hasQR: false,
  lastConnectionError: null,
  ...overrides
});

const mockSendMessageResponse = (overrides = {}) => ({
  ok: true,
  instanceId: 'inst_test',
  to: '558586030781@s.whatsapp.net',
  result: {
    key: {
      remoteJid: '558586030781@s.whatsapp.net',
      fromMe: true,
      id: faker.string.alphanumeric(20)
    },
    message: {
      extendedTextMessage: {
        text: faker.lorem.sentence()
      }
    },
    messageTimestamp: Date.now().toString(),
    status: 'PENDING'
  },
  ...overrides
});

// ============================================
// Helper Functions
// ============================================

const generateValidPhoneNumber = (countryCode = '55', areaCode = '85') => {
  const number = faker.string.numeric(8);
  return countryCode + areaCode + number;
};

const generateValidJID = (phoneNumber) => {
  return `${phoneNumber}@s.whatsapp.net`;
};

const generateGroupJID = () => {
  return faker.string.alphanumeric(12) + '@g.us';
};

const generateTestDatabasePath = () => {
  return './test_' + faker.string.alphanumeric(8) + '.db';
};

module.exports = {
  // Message mocks
  mockMessage,
  mockTextMessage,
  mockImageMessage,
  mockAudioMessage,
  
  // Contact mocks
  mockContact,
  
  // Instance mocks
  mockInstance,
  
  // Scheduled message mocks
  mockScheduledMessage,
  
  // User mocks
  mockUser,
  
  // AI config mocks
  mockAIConfig,
  
  // Calendar mocks
  mockCalendarToken,
  mockCalendarEvent,
  
  // API response mocks
  mockHealthResponse,
  mockStatusResponse,
  mockSendMessageResponse,
  
  // Helper functions
  generateValidPhoneNumber,
  generateValidJID,
  generateGroupJID,
  generateTestDatabasePath
};
