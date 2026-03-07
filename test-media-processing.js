/**
 * Test script to verify media message processing in messages.js
 * Tests the extractInboundMessageText and detectMediaPayload functions
 */

const messages = require('./src/whatsapp-server/whatsapp/handlers/messages');

console.log('=== Testing Media Message Processing ===\n');

// Test 1: Text message
console.log('--- Test 1: Text Message ---');
const textMessage = {
    conversation: "Hello, this is a text message"
};
const textResult = messages.extractInboundMessageText(textMessage);
console.log('Input:', JSON.stringify(textMessage));
console.log('Result:', textResult);
console.log('✅ PASS:', textResult === "Hello, this is a text message" ? 'YES' : 'NO');
console.log();

// Test 2: Image message with caption
console.log('--- Test 2: Image Message with Caption ---');
const imageMessageWithCaption = {
    imageMessage: {
        caption: "This is my image caption"
    }
};
const imageResult1 = messages.extractInboundMessageText(imageMessageWithCaption);
console.log('Input:', JSON.stringify(imageMessageWithCaption));
console.log('Result:', imageResult1);
console.log('✅ PASS:', imageResult1 === "This is my image caption" ? 'YES' : 'NO');
console.log();

// Test 3: Image message without caption
console.log('--- Test 3: Image Message without Caption ---');
const imageMessageNoCaption = {
    imageMessage: {
        mimetype: "image/jpeg"
    }
};
const imageResult2 = messages.extractInboundMessageText(imageMessageNoCaption);
console.log('Input:', JSON.stringify(imageMessageNoCaption));
console.log('Result:', imageResult2);
console.log('✅ PASS:', imageResult2 === "🖼️ Imagem recebida" ? 'YES' : 'NO');
console.log();

// Test 4: Video message with caption
console.log('--- Test 4: Video Message with Caption ---');
const videoMessageWithCaption = {
    videoMessage: {
        caption: "Check out this video"
    }
};
const videoResult1 = messages.extractInboundMessageText(videoMessageWithCaption);
console.log('Input:', JSON.stringify(videoMessageWithCaption));
console.log('Result:', videoResult1);
console.log('✅ PASS:', videoResult1 === "Check out this video" ? 'YES' : 'NO');
console.log();

// Test 5: Video message without caption
console.log('--- Test 5: Video Message without Caption ---');
const videoMessageNoCaption = {
    videoMessage: {
        mimetype: "video/mp4"
    }
};
const videoResult2 = messages.extractInboundMessageText(videoMessageNoCaption);
console.log('Input:', JSON.stringify(videoMessageNoCaption));
console.log('Result:', videoResult2);
console.log('✅ PASS:', videoResult2 === "🎥 Vídeo recebido" ? 'YES' : 'NO');
console.log();

// Test 6: Audio message
console.log('--- Test 6: Audio Message ---');
const audioMessage = {
    audioMessage: {
        mimetype: "audio/ogg"
    }
};
const audioResult = messages.extractInboundMessageText(audioMessage);
console.log('Input:', JSON.stringify(audioMessage));
console.log('Result:', audioResult);
console.log('✅ PASS:', audioResult === "🎤 Áudio recebido" ? 'YES' : 'NO');
console.log();

// Test 7: Document message with caption
console.log('--- Test 7: Document Message with Caption ---');
const docMessageWithCaption = {
    documentMessage: {
        fileName: "report.pdf",
        caption: "Monthly report"
    }
};
const docResult1 = messages.extractInboundMessageText(docMessageWithCaption);
console.log('Input:', JSON.stringify(docMessageWithCaption));
console.log('Result:', docResult1);
console.log('✅ PASS:', docResult1 === "Monthly report" ? 'YES' : 'NO');
console.log();

// Test 8: Document message without caption
console.log('--- Test 8: Document Message without Caption ---');
const docMessageNoCaption = {
    documentMessage: {
        fileName: "contract.docx"
    }
};
const docResult2 = messages.extractInboundMessageText(docMessageNoCaption);
console.log('Input:', JSON.stringify(docMessageNoCaption));
console.log('Result:', docResult2);
console.log('✅ PASS:', docResult2 === "📄 Documento: contract.docx" ? 'YES' : 'NO');
console.log();

// Test 9: detectMediaPayload for Image
console.log('--- Test 9: detectMediaPayload - Image ---');
const imagePayload = messages.detectMediaPayload(imageMessageWithCaption);
console.log('Input:', JSON.stringify(imageMessageWithCaption));
console.log('Result:', JSON.stringify(imagePayload));
console.log('✅ PASS:', imagePayload?.type === "imagem" && imagePayload?.downloadType === "image" ? 'YES' : 'NO');
console.log();

// Test 10: detectMediaPayload for Video
console.log('--- Test 10: detectMediaPayload - Video ---');
const videoPayload = messages.detectMediaPayload(videoMessageNoCaption);
console.log('Input:', JSON.stringify(videoMessageNoCaption));
console.log('Result:', JSON.stringify(videoPayload));
console.log('✅ PASS:', videoPayload?.type === "video" && videoPayload?.downloadType === "video" ? 'YES' : 'NO');
console.log();

// Test 11: detectMediaPayload for Audio
console.log('--- Test 11: detectMediaPayload - Audio ---');
const audioPayload = messages.detectMediaPayload(audioMessage);
console.log('Input:', JSON.stringify(audioMessage));
console.log('Result:', JSON.stringify(audioPayload));
console.log('✅ PASS:', audioPayload?.type === "audio" && audioPayload?.downloadType === "audio" ? 'YES' : 'NO');
console.log();

// Test 12: detectMediaPayload for Document
console.log('--- Test 12: detectMediaPayload - Document ---');
const docPayload = messages.detectMediaPayload(docMessageNoCaption);
console.log('Input:', JSON.stringify(docMessageNoCaption));
console.log('Result:', JSON.stringify(docPayload));
console.log('✅ PASS:', docPayload?.type === "documento" && docPayload?.downloadType === "document" ? 'YES' : 'NO');
console.log();

// Test 13: Extended text message
console.log('--- Test 13: Extended Text Message ---');
const extendedTextMessage = {
    extendedTextMessage: {
        text: "This is an extended text message"
    }
};
const extendedResult = messages.extractInboundMessageText(extendedTextMessage);
console.log('Input:', JSON.stringify(extendedTextMessage));
console.log('Result:', extendedResult);
console.log('✅ PASS:', extendedResult === "This is an extended text message" ? 'YES' : 'NO');
console.log();

console.log('=== All Tests Completed ===');
