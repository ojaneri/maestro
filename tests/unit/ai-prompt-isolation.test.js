/**
 * Unit Tests - AI Prompt Isolation
 * Tests that each instance uses ONLY its own ai_system_prompt without global inheritance
 * 
 * Validates:
 * 1. Instance loads its own prompt without global inheritance
 * 2. Different instances have different prompts (isolation)
 * 3. Warning is logged when instance has no ai_system_prompt
 * 
 * Run: npx jest tests/unit/ai-prompt-isolation.test.js --verbose
 */

// Mock the providers before requiring the AI module
jest.mock('../../src/whatsapp-server/ai/providers/openai', () => ({}));
jest.mock('../../src/whatsapp-server/ai/providers/gemini', () => ({}));
jest.mock('../../src/whatsapp-server/ai/providers/openrouter', () => ({}));
jest.mock('../../src/whatsapp-server/ai/response-builder', () => ({}));

const path = require('path');

// Mock database
const createMockDb = (settings = {}) => {
    return {
        getSettings: jest.fn(async (instanceId, keys) => {
            // Return settings for the specific instance
            return settings[instanceId] || {};
        }),
        setSetting: jest.fn(async (instanceId, key, value) => {
            if (!settings[instanceId]) {
                settings[instanceId] = {};
            }
            settings[instanceId][key] = value;
            return true;
        })
    };
};

// Test data - different prompts for different instances
const TEST_INSTANCE_PROMPTS = {
    'inst_6992ec9e78d1c': {
        'ai_system_prompt': 'You are a helpful assistant for instance 1. Always be polite.',
        'ai_provider': 'openai',
        'ai_model': 'gpt-4.1-mini',
        'ai_enabled': 'true'
    },
    'inst_6992ed0c735f0': {
        'ai_system_prompt': 'You are a formal assistant for instance 2. Use formal language.',
        'ai_provider': 'gemini',
        'ai_model': 'gemini-pro',
        'ai_enabled': 'true'
    },
    'inst_6992eddf5c2a7': {
        // No system prompt - should trigger warning
        'ai_system_prompt': '',
        'ai_provider': 'openai',
        'ai_model': 'gpt-4.1-mini',
        'ai_enabled': 'true'
    }
};

// Global settings (should NOT be inherited)
const GLOBAL_SETTINGS = {
    '': {
        'ai_system_prompt': 'GLOBAL PROMPT - should NOT be used',
        'ai_provider': 'openai',
        'ai_model': 'gpt-4',
        'ai_enabled': 'true'
    }
};

describe('AI Prompt Isolation', () => {
    let mockDb;
    let loadAIConfig;
    
    beforeAll(async () => {
        // Load the AI module
        const aiModule = require('../../src/whatsapp-server/ai/index.js');
        loadAIConfig = aiModule.loadAIConfig;
    });

    beforeEach(() => {
        // Create fresh mock database for each test
        const allSettings = {
            ...GLOBAL_SETTINGS,
            ...TEST_INSTANCE_PROMPTS
        };
        mockDb = createMockDb(allSettings);
        
        // Clear console mocks
        jest.clearAllMocks();
    });

    test('Instance should use ONLY its own ai_system_prompt (no global inheritance)', async () => {
        // Load config for instance 1
        const config = await loadAIConfig(mockDb, 'inst_6992ec9e78d1c');
        
        // Verify it uses its OWN prompt, NOT the global one
        expect(config.system_prompt).toBe('You are a helpful assistant for instance 1. Always be polite.');
        
        // CRITICAL: Should NOT contain global prompt
        expect(config.system_prompt).not.toContain('GLOBAL PROMPT');
        expect(config.system_prompt).not.toBe('GLOBAL PROMPT - should NOT be used');
        
        console.log('✅ Instance uses its own prompt (not global)');
    });

    test('Different instances should have different prompts (isolation)', async () => {
        // Load config for instance 1
        const config1 = await loadAIConfig(mockDb, 'inst_6992ec9e78d1c');
        
        // Load config for instance 2
        const config2 = await loadAIConfig(mockDb, 'inst_6992ed0c735f0');
        
        // Verify prompts are different
        expect(config1.system_prompt).not.toBe(config2.system_prompt);
        
        // Verify each has correct prompt
        expect(config1.system_prompt).toContain('instance 1');
        expect(config2.system_prompt).toContain('instance 2');
        
        console.log('✅ Different instances have different prompts');
    });

    test('Should log warning when instance has no ai_system_prompt', async () => {
        // Spy on console.error
        const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        
        // Load config for instance with no prompt
        const config = await loadAIConfig(mockDb, 'inst_6992eddf5c2a7');
        
        // Should have logged error/warning
        expect(consoleSpy).toHaveBeenCalled();
        
        // Check that the warning message contains the expected text
        const errorCalls = consoleSpy.mock.calls.join(' ');
        expect(errorCalls).toContain('NO ai_system_prompt');
        
        consoleSpy.mockRestore();
        
        console.log('✅ Warning logged for missing ai_system_prompt');
    });

    test('Should handle missing db gracefully', async () => {
        // Load config without db
        const config = await loadAIConfig(null, 'any-instance');
        
        // Should return default config
        expect(config).toBeDefined();
        expect(config.enabled).toBe(false);
        expect(config.provider).toBe('openai');
        expect(config.model).toBe('gpt-4.1-mini');
        
        console.log('✅ Handles missing db gracefully');
    });

    test('Should use ai_config JSON as fallback when simple fields are empty', async () => {
        // Create mock with ai_config JSON
        const mockDbWithJson = createMockDb({
            'inst_json_test': {
                'ai_system_prompt': '', // empty simple field
                'ai_config': JSON.stringify({
                    system_prompt: 'Prompt from JSON',
                    model: 'gpt-4'
                })
            }
        });
        
        const config = await loadAIConfig(mockDbWithJson, 'inst_json_test');
        
        // Should use prompt from JSON
        expect(config.system_prompt).toBe('Prompt from JSON');
        
        console.log('✅ Uses ai_config JSON as fallback');
    });

    test('Should NOT inherit any global settings (strict isolation)', async () => {
        // Verify that even with global settings present,
        // the instance config is completely independent
        
        // Create db with both global and instance settings
        const mockDbWithBoth = createMockDb({
            '': GLOBAL_SETTINGS[''],
            'inst_strict_test': {
                'ai_system_prompt': 'Instance-only prompt'
            }
        });
        
        const config = await loadAIConfig(mockDbWithBoth, 'inst_strict_test');
        
        // Verify global settings are NOT present
        expect(config.system_prompt).not.toContain('GLOBAL');
        
        console.log('✅ Strict isolation - no global inheritance');
    });
});

describe('AI Config Persistence', () => {
    let mockDb;
    let loadAIConfig;
    let persistAIConfig;
    
    beforeAll(async () => {
        const aiModule = require('../../src/whatsapp-server/ai/index.js');
        loadAIConfig = aiModule.loadAIConfig;
        persistAIConfig = aiModule.persistAIConfig;
    });

    beforeEach(() => {
        mockDb = createMockDb({});
    });

    test('persistAIConfig should save settings for specific instance', async () => {
        const payload = {
            enabled: true,
            provider: 'openai',
            model: 'gpt-4.1-mini',
            system_prompt: 'Test prompt for persistence'
        };
        
        await persistAIConfig(mockDb, 'inst_test', payload);
        
        // Verify settings were saved
        expect(mockDb.setSetting).toHaveBeenCalled();
        
        // Verify we can load them back
        const config = await loadAIConfig(mockDb, 'inst_test');
        expect(config.system_prompt).toBe('Test prompt for persistence');
    });
});
