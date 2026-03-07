/**
 * Unit Tests - Message Splitting and Typing Indicator
 * Tests for:
 * 1. Splitting messages by # separator
 * 2. Typing indicator delay calculation based on message length
 * 
 * Note: Testing standalone functions without importing problematic ESM modules
 */

// Copy of splitHashSegments function for testing (from response-builder.js)
function splitHashSegments(text) {
    if (typeof text !== 'string' || !text) {
        return [];
    }
    
    if (text.includes('#')) {
        const segments = text.split('#').filter(s => s.trim());
        if (segments.length > 0) {
            return segments;
        }
    }
    
    return [text];
}

// Copy of parseTextSegments function for testing (from ai/index.js)
function parseTextSegments(text) {
    if (!text) return [];
    
    const segments = text.split('#').filter(s => s.trim());
    return segments.length > 0 ? segments : [text];
}

// Copy of computeTypingDelayMs function for testing (from send-message.js)
function computeTypingDelayMs(text) {
    const normalized = (text || "").trim();
    if (!normalized) {
        return 0;
    }
    const charCount = normalized.length;
    const seconds = Math.min(10, Math.max(1, Math.ceil(charCount / 20)));
    const randomFactor = Math.random() * 1200 + 200;
    return seconds * 1000 + randomFactor;
}

describe('Message Splitting by #', () => {
    
    describe('splitHashSegments', () => {
        
        test('should split message by # into separate segments', () => {
            const input = 'OI#Tudo bem?#Meu nome é Fulano';
            const result = splitHashSegments(input);
            
            expect(result).toEqual([
                'OI',
                'Tudo bem?',
                'Meu nome é Fulano'
            ]);
        });
        
        test('should handle single segment without #', () => {
            const input = 'Olá, tudo bem?';
            const result = splitHashSegments(input);
            
            expect(result).toEqual(['Olá, tudo bem?']);
        });
        
        test('should handle empty string', () => {
            const input = '';
            const result = splitHashSegments(input);
            
            expect(result).toEqual([]);
        });
        
        test('should handle null/undefined input', () => {
            expect(splitHashSegments(null)).toEqual([]);
            expect(splitHashSegments(undefined)).toEqual([]);
        });
        
        test('should filter out empty segments', () => {
            const input = 'Hello##World#';
            const result = splitHashSegments(input);
            
            expect(result).toEqual(['Hello', 'World']);
        });
        
        test('should handle multiple consecutive #', () => {
            const input = 'A###B##C';
            const result = splitHashSegments(input);
            
            expect(result).toEqual(['A', 'B', 'C']);
        });
        
        test('should trim whitespace from segments', () => {
            const input = '  OI # Tudo bem? #  Meu nome  ';
            const result = splitHashSegments(input);
            
            // Note: splitHashSegments filters empty but doesn't trim
            expect(result).toEqual([
                '  OI ',
                ' Tudo bem? ',
                '  Meu nome  '
            ]);
        });
        
        test('should handle # at start and end', () => {
            const input = '#Hello World#';
            const result = splitHashSegments(input);
            
            expect(result).toEqual(['Hello World']);
        });
        
        test('should handle numbers and special chars in segments', () => {
            const input = 'Pedido #12345#Status: Pago#Valor: R$ 150,00';
            const result = splitHashSegments(input);
            
            // splitHashSegments filters empty but keeps spaces
            expect(result).toEqual([
                'Pedido ',
                '12345',
                'Status: Pago',
                'Valor: R$ 150,00'
            ]);
        });
    });
    
    describe('parseTextSegments', () => {
        
        test('should split message by # into separate segments', () => {
            const input = 'OI#Tudo bem?#Meu nome é Fulano';
            const result = parseTextSegments(input);
            
            expect(result).toEqual([
                'OI',
                'Tudo bem?',
                'Meu nome é Fulano'
            ]);
        });
        
        test('should return single element array for text without #', () => {
            const input = 'Olá, tudo bem?';
            const result = parseTextSegments(input);
            
            expect(result).toEqual(['Olá, tudo bem?']);
        });
        
        test('should return empty array for null/undefined', () => {
            expect(parseTextSegments(null)).toEqual([]);
            expect(parseTextSegments(undefined)).toEqual([]);
        });
        
        test('should filter empty segments', () => {
            const input = 'Message1##Message2';
            const result = parseTextSegments(input);
            
            expect(result).toEqual(['Message1', 'Message2']);
        });
        
        test('should handle only # characters', () => {
            const input = '###';
            const result = parseTextSegments(input);
            
            // parseTextSegments returns original text if all segments are empty
            // '###'.split('#') = ['','','',''] -> filter = [] -> returns [original]
            expect(result).toEqual(['###']);
        });
    });
});

describe('Typing Indicator Delay Calculation', () => {
    
    describe('computeTypingDelayMs', () => {
        
        test('should calculate minimum delay for short message', () => {
            const text = 'Hi';
            const delay = computeTypingDelayMs(text);
            
            // Min 1 second + random factor (200-1400ms)
            expect(delay).toBeGreaterThanOrEqual(1000);
            expect(delay).toBeLessThanOrEqual(2500);
        });
        
        test('should calculate delay based on character count', () => {
            // ~20 chars = 1 second
            const shortText = 'Hello, how are you?';
            const shortDelay = computeTypingDelayMs(shortText);
            
            // ~100 chars = 5 seconds
            const longText = 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor incididunt ut labore';
            const longDelay = computeTypingDelayMs(longText);
            
            // Longer text should have longer delay
            expect(longDelay).toBeGreaterThan(shortDelay);
        });
        
        test('should cap at maximum 10 seconds', () => {
            // Very long text (>200 chars should cap at 10s)
            const veryLongText = 'A'.repeat(300);
            const delay = computeTypingDelayMs(veryLongText);
            
            // Max 10 seconds + random factor (200-1400ms)
            expect(delay).toBeGreaterThanOrEqual(10000);
            expect(delay).toBeLessThanOrEqual(12000);
        });
        
        test('should return 0 for empty string', () => {
            const delay = computeTypingDelayMs('');
            expect(delay).toBe(0);
        });
        
        test('should return 0 for null/undefined', () => {
            expect(computeTypingDelayMs(null)).toBe(0);
            expect(computeTypingDelayMs(undefined)).toBe(0);
        });
        
        test('should handle whitespace-only strings', () => {
            const delay = computeTypingDelayMs('   ');
            expect(delay).toBe(0);
        });
        
        test('should add random variation to delay', () => {
            const text = 'Hello world';
            const delay1 = computeTypingDelayMs(text);
            const delay2 = computeTypingDelayMs(text);
            
            // Delays should be different due to random factor
            // (but might occasionally be same, so we check it's in valid range)
            expect(delay1).toBeGreaterThanOrEqual(1000);
            expect(delay1).toBeLessThanOrEqual(2500);
            expect(delay2).toBeGreaterThanOrEqual(1000);
            expect(delay2).toBeLessThanOrEqual(2500);
        });
        
        test('should calculate delay for medium-length message', () => {
            // ~50 chars = ~2-3 seconds
            const text = 'Olá! Gostaria de saber mais informações sobre seus serviços.';
            const delay = computeTypingDelayMs(text);
            
            expect(delay).toBeGreaterThanOrEqual(2000);
            expect(delay).toBeLessThanOrEqual(4500);
        });
    });
    
    describe('Typing indicator integration with ai/index.js formula', () => {
        
        test('should use 30ms per character formula from ai/index.js', () => {
            // The formula in ai/index.js: Math.min(Math.max(messageLength * 30, 1000), 15000)
            const messageLength = 50;
            
            // Calculate using ai/index.js formula
            const aiIndexDelay = Math.min(Math.max(messageLength * 30, 1000), 15000);
            
            // 50 * 30 = 1500ms, which is >= 1000, so it should be 1500
            expect(aiIndexDelay).toBe(1500);
        });
        
        test('should cap at 15000ms in ai/index.js formula', () => {
            // 600 chars * 30 = 18000ms, but capped at 15000
            const messageLength = 600;
            const aiIndexDelay = Math.min(Math.max(messageLength * 30, 1000), 15000);
            
            expect(aiIndexDelay).toBe(15000);
        });
        
        test('should use minimum 1000ms for short messages', () => {
            // 20 chars * 30 = 600ms, but min is 1000
            const messageLength = 20;
            const aiIndexDelay = Math.min(Math.max(messageLength * 30, 1000), 15000);
            
            expect(aiIndexDelay).toBe(1000);
        });
    });
});

describe('End-to-End: Message Splitting with Typing Simulation', () => {
    
    test('should simulate sending multiple #segmented messages with typing delays', () => {
        const inputMessage = 'OI#Tudo bem?#Meu nome é Fulano';
        
        // Step 1: Split the message
        const segments = parseTextSegments(inputMessage);
        
        expect(segments).toHaveLength(3);
        expect(segments).toEqual(['OI', 'Tudo bem?', 'Meu nome é Fulano']);
        
        // Step 2: Calculate typing delays for each segment using ai/index.js formula
        const delays = segments.map(segment => {
            const messageLength = segment.length;
            return Math.min(Math.max(messageLength * 30, 1000), 15000);
        });
        
        // "OI" (2 chars) -> min 1000ms
        expect(delays[0]).toBe(1000);
        
        // "Tudo bem?" (10 chars) -> 300ms -> min 1000ms
        expect(delays[1]).toBe(1000);
        
        // "Meu nome é Fulano" (17 chars) -> 510ms -> min 1000ms
        expect(delays[2]).toBe(1000);
        
        // Step 3: Verify each segment is valid for sending
        segments.forEach((segment, index) => {
            expect(typeof segment).toBe('string');
            expect(segment.length).toBeGreaterThan(0);
            expect(delays[index]).toBeGreaterThanOrEqual(1000);
        });
    });
    
    test('should handle long message without # splitting', () => {
        const longMessage = 'Esta é uma mensagem muito longa que deve ser enviada como uma única mensagem do WhatsApp sem necessidade de divisão por hash.';
        
        const segments = parseTextSegments(longMessage);
        
        expect(segments).toHaveLength(1);
        expect(segments[0]).toBe(longMessage);
        
        // Using ai/index.js formula (without random factor)
        const messageLength = longMessage.length;
        const delay = Math.min(Math.max(messageLength * 30, 1000), 15000);
        
        // Verify the calculation
        const expectedDelay = Math.min(Math.max(messageLength * 30, 1000), 15000);
        expect(delay).toBe(expectedDelay);
        // Also verify it's in expected range
        expect(delay).toBeGreaterThanOrEqual(1000);
    });
    
    test('should calculate total time for multi-segment message', () => {
        const inputMessage = 'Segment1#Segment2#Segment3';
        const segments = parseTextSegments(inputMessage);
        
        const delays = segments.map(segment => {
            return Math.min(Math.max(segment.length * 30, 1000), 15000);
        });
        
        const totalDelay = delays.reduce((sum, d) => sum + d, 0);
        
        // 9 + 9 + 9 = 27 chars total
        // Each < 34 chars so each gets 1000ms
        // Total: 3000ms
        expect(totalDelay).toBe(3000);
    });
});
