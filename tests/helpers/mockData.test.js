/**
 * Unit Tests for Mock Data Helpers
 * Tests the mock data generation utilities
 */

const {
  mockMessage,
  mockTextMessage,
  mockImageMessage,
  mockContact,
  mockInstance,
  mockScheduledMessage,
  mockUser,
  mockAIConfig,
  mockHealthResponse,
  mockStatusResponse,
  mockSendMessageResponse,
  generateValidPhoneNumber,
  generateValidJID,
  generateGroupJID,
  generateTestDatabasePath
} = require('./mockData');

describe('Mock Data Helpers', () => {
  
  describe('mockMessage', () => {
    test('should generate message with required fields', () => {
      const msg = mockMessage();
      
      expect(msg).toHaveProperty('key');
      expect(msg).toHaveProperty('message');
      expect(msg).toHaveProperty('pushName');
      expect(msg).toHaveProperty('timestamp');
      expect(msg.key.remoteJid).toContain('@s.whatsapp.net');
      expect(msg.key.fromMe).toBe(false);
    });

    test('should allow overriding defaults', () => {
      const msg = mockMessage({ 
        key: { fromMe: true, remoteJid: 'test@s.whatsapp.net', id: 'test' }
      });
      
      expect(msg.key.fromMe).toBe(true);
    });
  });

  describe('mockTextMessage', () => {
    test('should create text message with correct format', () => {
      const phone = '558586030781';
      const text = 'Hello World';
      const msg = mockTextMessage(phone, text);
      
      expect(msg.message.conversation).toBe(text);
      expect(msg.key.remoteJid).toContain(phone);
    });
  });

  describe('mockImageMessage', () => {
    test('should create image message with imageMessage object', () => {
      const phone = '558586030781';
      const url = 'https://example.com/image.jpg';
      const msg = mockImageMessage(phone, url);
      
      expect(msg.message).toHaveProperty('imageMessage');
      expect(msg.message.imageMessage.url).toBe(url);
    });
  });

  describe('mockContact', () => {
    test('should generate contact with required fields', () => {
      const contact = mockContact();
      
      expect(contact).toHaveProperty('remote_jid');
      expect(contact).toHaveProperty('contact_name');
      expect(contact).toHaveProperty('status_name');
      expect(contact).toHaveProperty('temperature');
      expect(contact.remote_jid).toContain('@s.whatsapp.net');
    });
  });

  describe('mockInstance', () => {
    test('should generate instance with valid port range', () => {
      const instance = mockInstance();
      
      expect(instance).toHaveProperty('instance_id');
      expect(instance).toHaveProperty('port');
      expect(instance).toHaveProperty('name');
      expect(instance).toHaveProperty('status');
      expect(instance.port).toBeGreaterThanOrEqual(3000);
      expect(instance.port).toBeLessThanOrEqual(3999);
      expect(instance.instance_id).toMatch(/^inst_/);
    });

    test('should allow custom instance_id', () => {
      const instance = mockInstance({ instance_id: 'inst_custom123' });
      expect(instance.instance_id).toBe('inst_custom123');
    });
  });

  describe('mockScheduledMessage', () => {
    test('should generate scheduled message with future timestamp', () => {
      const now = Date.now();
      const msg = mockScheduledMessage();
      
      expect(msg).toHaveProperty('scheduled_at');
      expect(msg).toHaveProperty('status');
      expect(msg).toHaveProperty('instance_id');
      expect(msg.status).toBe('pending');
    });
  });

  describe('mockUser', () => {
    test('should generate user with valid role', () => {
      const user = mockUser();
      
      expect(user).toHaveProperty('email');
      expect(user).toHaveProperty('role');
      expect(['user', 'manager']).toContain(user.role);
      expect(user.email).toMatch(/@/);
    });
  });

  describe('mockAIConfig', () => {
    test('should generate AI config with valid provider', () => {
      const config = mockAIConfig();
      
      expect(config).toHaveProperty('enabled');
      expect(config).toHaveProperty('provider');
      expect(config).toHaveProperty('api_key');
      expect(['openai', 'gemini', 'openrouter']).toContain(config.provider);
      expect(config.api_key).toMatch(/^sk-/);
    });
  });

  describe('mockHealthResponse', () => {
    test('should generate valid health response', () => {
      const health = mockHealthResponse();
      
      expect(health).toHaveProperty('ok', true);
      expect(health).toHaveProperty('instanceId');
      expect(health).toHaveProperty('status');
    });
  });

  describe('mockStatusResponse', () => {
    test('should generate valid status response', () => {
      const status = mockStatusResponse();
      
      expect(status).toHaveProperty('connectionStatus');
      expect(status).toHaveProperty('whatsappConnected');
      expect(status).toHaveProperty('hasQR');
    });
  });

  describe('mockSendMessageResponse', () => {
    test('should generate valid send message response', () => {
      const response = mockSendMessageResponse();
      
      expect(response).toHaveProperty('ok', true);
      expect(response).toHaveProperty('result');
      expect(response.result).toHaveProperty('key');
      expect(response.result.key).toHaveProperty('remoteJid');
    });
  });

  describe('generateValidPhoneNumber', () => {
    test('should generate Brazilian phone number format', () => {
      const phone = generateValidPhoneNumber();
      
      expect(phone).toMatch(/^55\d{10}$/);
    });

    test('should respect custom country code', () => {
      const phone = generateValidPhoneNumber('1', '212');
      
      expect(phone).toMatch(/^1\d+$/);
    });
  });

  describe('generateValidJID', () => {
    test('should generate valid WhatsApp JID', () => {
      const jid = generateValidJID('558586030781');
      
      expect(jid).toBe('558586030781@s.whatsapp.net');
    });
  });

  describe('generateGroupJID', () => {
    test('should generate group JID format', () => {
      const jid = generateGroupJID();
      
      expect(jid).toMatch(/^.+@g\.us$/);
    });
  });

  describe('generateTestDatabasePath', () => {
    test('should generate test db path', () => {
      const path = generateTestDatabasePath();
      
      expect(path).toMatch(/^\.\/test_.+\.db$/);
    });
  });
});
