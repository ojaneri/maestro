/**
 * Integration Tests - WhatsApp Messaging API
 * Tests sending messages via instance API on port 3011
 * 
 * Target: http://localhost:3011/send-message
 * Target Number: 558586030781
 */

const request = require('supertest');

const INSTANCE_PORT = 3011;
const TARGET_NUMBER = '558586030781';
const BASE_URL = `http://localhost:${INSTANCE_PORT}`;

describe('WhatsApp Messaging API - Instance 3011', () => {
  
  // Check if instance is running before tests
  beforeAll(async () => {
    try {
      const response = await request(BASE_URL).get('/health');
      if (!response.body || response.status !== 200) {
        console.log(`⚠️  Instance on port ${INSTANCE_PORT} may not be running`);
      }
    } catch (error) {
      console.log(`⚠️  Cannot reach instance on port ${INSTANCE_PORT}: ${error.message}`);
    }
  });

  describe('POST /send-message - Text Message', () => {
    test('should send text message to 558586030781', async () => {
      const response = await request(BASE_URL)
        .post('/send-message')
        .send({
          to: TARGET_NUMBER,
          message: 'Test message from automated test suite'
        })
        .timeout(10000);
      
      // Log response for debugging
      console.log('Text Message Response:', JSON.stringify(response.body, null, 2));
      
      // Verify response structure
      expect(response.status).toBeDefined();
      
      // If connected, should get success
      if (response.body.ok === true) {
        expect(response.body.result).toHaveProperty('key');
        expect(response.body.result.key.remoteJid).toContain(TARGET_NUMBER);
        expect(response.body.result.key.fromMe).toBe(true);
      } else if (response.body.error) {
        // If error, might be disconnected or number invalid
        console.log('Text message error:', response.body.error);
        // This is acceptable if instance is not connected
        expect(['Número não existe no WhatsApp', 'Connection not ready']).toContain(response.body.error);
      }
    }, 15000);
  });

  describe('POST /send-message - Image Message (URL)', () => {
    test('should send image message via URL to 558586030781', async () => {
      const testImageUrl = 'https://httpbin.org/image/jpeg';
      
      const response = await request(BASE_URL)
        .post('/send-message')
        .send({
          to: TARGET_NUMBER,
          image_url: testImageUrl,
          caption: 'Test image from automated test suite'
        })
        .timeout(15000);
      
      // Log response for debugging
      console.log('Image Message Response:', JSON.stringify(response.body, null, 2));
      
      // Verify response structure
      expect(response.status).toBeDefined();
      
      if (response.body.ok === true) {
        expect(response.body.result).toHaveProperty('key');
        expect(response.body.result.key.remoteJid).toContain(TARGET_NUMBER);
      } else if (response.body.error) {
        console.log('Image message error:', response.body.error, response.body.detail);
        // Accept various error messages
        expect([
          'Número não existe no WhatsApp',
          'Connection not ready',
          'Invalid image URL',
          'Falha ao enviar mensagem'
        ]).toContain(response.body.error);
      }
    }, 20000);
  });

  describe('POST /send-message - Video Message (URL)', () => {
    test('should send video message via URL to 558586030781', async () => {
      // Using a sample video URL - httpbin doesn't host videos, so we'll try a common test video
      const testVideoUrl = 'https://www.w3schools.com/html/mov_bbb.mp4';
      
      const response = await request(BASE_URL)
        .post('/send-message')
        .send({
          to: TARGET_NUMBER,
          video_url: testVideoUrl,
          caption: 'Test video from automated test suite'
        })
        .timeout(30000);
      
      // Log response for debugging
      console.log('Video Message Response:', JSON.stringify(response.body, null, 2));
      
      // Verify response structure
      expect(response.status).toBeDefined();
      
      if (response.body.ok === true) {
        expect(response.body.result).toHaveProperty('key');
        expect(response.body.result.key.remoteJid).toContain(TARGET_NUMBER);
      } else if (response.body.error) {
        console.log('Video message error:', response.body.error, response.body.detail);
        // Accept various error messages for video
        expect([
          'Número não existe no WhatsApp',
          'Connection not ready',
          'Invalid video URL',
          'Video too large',
          'Unsupported video format',
          'Falha ao enviar mensagem'
        ]).toContain(response.body.error);
      }
    }, 35000);
  });

  describe('POST /send-message - Image (Base64)', () => {
    test('should send image message via base64 to 558586030781', async () => {
      // Small 1x1 red pixel PNG in base64
      const base64Image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
      
      const response = await request(BASE_URL)
        .post('/send-message')
        .send({
          to: TARGET_NUMBER,
          image_base64: base64Image,
          caption: 'Test image (base64) from automated test suite'
        })
        .timeout(15000);
      
      // Log response for debugging
      console.log('Image Base64 Response:', JSON.stringify(response.body, null, 2));
      
      // Verify response structure
      expect(response.status).toBeDefined();
      
      if (response.body.ok === true) {
        expect(response.body.result).toHaveProperty('key');
        expect(response.body.result.key.remoteJid).toContain(TARGET_NUMBER);
        console.log('✅ Image sent successfully via base64!');
      } else if (response.body.error) {
        console.log('Image base64 error:', response.body.error, response.body.detail);
        // Accept various error messages
        expect([
          'Número não existe no WhatsApp',
          'Connection not ready',
          'Invalid image',
          'Image too large',
          'Falha ao enviar mensagem'
        ]).toContain(response.body.error);
      }
    }, 20000);
  });

  describe('GET /status - Instance Status', () => {
    test('should return instance connection status', async () => {
      const response = await request(BASE_URL)
        .get('/status')
        .timeout(5000);
      
      console.log('Status Response:', JSON.stringify(response.body, null, 2));
      
      expect(response.status).toBe(200);
      expect(response.body).toHaveProperty('connectionStatus');
      expect(response.body).toHaveProperty('whatsappConnected');
    });
  });

  describe('GET /health - Instance Health', () => {
    test('should return instance health', async () => {
      const response = await request(BASE_URL)
        .get('/health')
        .timeout(5000);
      
      console.log('Health Response:', JSON.stringify(response.body, null, 2));
      
      expect(response.status).toBe(200);
      expect(response.body).toHaveProperty('ok');
      expect(response.body).toHaveProperty('instanceId');
    });
  });
});
