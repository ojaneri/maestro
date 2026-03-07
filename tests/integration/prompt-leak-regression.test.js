/**
 * Integration Tests - Prompt Leak Regression
 * Tests that there is NO "bleeding" of prompts between instances
 * 
 * Validates:
 * 1. Multiple instances loading configs simultaneously don't leak prompts
 * 2. Each instance maintains strict isolation
 * 3. Warnings are logged appropriately
 * 
 * Run: npx jest tests/integration/prompt-leak-regression.test.js --verbose
 */

// Mock the providers before requiring the AI module
jest.mock('../../src/whatsapp-server/ai/providers/openai', () => ({}));
jest.mock('../../src/whatsapp-server/ai/providers/gemini', () => ({}));
jest.mock('../../src/whatsapp-server/ai/providers/openrouter', () => ({}));
jest.mock('../../src/whatsapp-server/ai/response-builder', () => ({}));

// Mock database for isolation testing
const createIsolatedMockDb = (instanceId, settings) => {
    return {
        getSettings: jest.fn(async (key, keys) => {
            // Simulate database - returns ONLY what was stored for that key
            if (key === instanceId) {
                return settings || {};
            }
            if (key === '') {
                return {
                    'ai_system_prompt': 'GLOBAL_SHOULD_NOT_LEAK',
                    'ai_provider': 'openai'
                };
            }
            return {};
        })
    };
};

// Test scenarios
const ISOLATION_TEST_CASES = [
    {
        name: 'inst_6992ec9e78d1c',
        ownPrompt: 'Prompt for instance 1 - specific and unique',
        provider: 'openai'
    },
    {
        name: 'inst_6992ed0c735f0', 
        ownPrompt: 'Prompt for instance 2 - different content',
        provider: 'gemini'
    },
    {
        name: 'inst_6992eddf5c2a7',
        ownPrompt: 'Prompt for instance 3 - completely different',
        provider: 'openrouter'
    }
];

describe('Prompt Leak Regression Tests', () => {
    let loadAIConfig;
    
    beforeAll(async () => {
        const aiModule = require('../../src/whatsapp-server/ai/index.js');
        loadAIConfig = aiModule.loadAIConfig;
    });

    test('Multiple instances should NOT leak prompts to each other', async () => {
        console.log('\n🔒 Testing prompt isolation between multiple instances...');
        
        // Load configs for all instances
        const configs = [];
        
        for (const testCase of ISOLATION_TEST_CASES) {
            const mockDb = createIsolatedMockDb(testCase.name, {
                'ai_system_prompt': testCase.ownPrompt,
                'ai_provider': testCase.provider,
                'ai_model': 'gpt-4.1-mini',
                'ai_enabled': 'true'
            });
            
            const config = await loadAIConfig(mockDb, testCase.name);
            configs.push({ name: testCase.name, config, expected: testCase.ownPrompt });
        }
        
        // Verify each instance has its own prompt
        for (const { name, config, expected } of configs) {
            expect(config.system_prompt).toBe(expected);
            console.log(`  ✅ ${name}: Prompt is isolated`);
        }
        
        // Verify prompts are different
        const prompts = configs.map(c => c.config.system_prompt);
        const uniquePrompts = new Set(prompts);
        expect(uniquePrompts.size).toBe(ISOLATION_TEST_CASES.length);
        
        console.log('✅ No prompt leakage between instances');
    });

    test('Global settings should NOT be inherited by instances', async () => {
        console.log('\n🌐 Testing that global settings are NOT inherited...');
        
        const instanceId = 'inst_no_global_inherit';
        
        // Create mock with both global and instance settings
        const mockDb = {
            getSettings: jest.fn(async (key, keys) => {
                if (key === instanceId) {
                    return {
                        'ai_system_prompt': 'MY_OWN_PROMPT',
                        'ai_provider': 'openai'
                    };
                }
                if (key === '') {
                    return {
                        'ai_system_prompt': 'GLOBAL_SHOULD_NOT_APPEAR',
                        'ai_provider': 'different-provider'
                    };
                }
                return {};
            })
        };
        
        const config = await loadAIConfig(mockDb, instanceId);
        
        // CRITICAL: Instance should use its OWN prompt, NOT the global one
        expect(config.system_prompt).toBe('MY_OWN_PROMPT');
        expect(config.system_prompt).not.toContain('GLOBAL');
        
        // Provider should also be instance-specific
        expect(config.provider).toBe('openai');
        
        console.log('✅ Global settings are NOT inherited');
    });

    test('Concurrent config loads should maintain isolation', async () => {
        console.log('\n⚡ Testing concurrent config loads maintain isolation...');
        
        // Simulate concurrent loading
        const loadPromises = ISOLATION_TEST_CASES.map(async (testCase) => {
            const mockDb = createIsolatedMockDb(testCase.name, {
                'ai_system_prompt': testCase.ownPrompt,
                'ai_provider': testCase.provider,
                'ai_model': 'gpt-4.1-mini',
                'ai_enabled': 'true'
            });
            
            return loadAIConfig(mockDb, testCase.name);
        });
        
        // Load all concurrently
        const results = await Promise.all(loadPromises);
        
        // Verify each result matches expected
        for (let i = 0; i < results.length; i++) {
            expect(results[i].system_prompt).toBe(ISOLATION_TEST_CASES[i].ownPrompt);
        }
        
        console.log('✅ Concurrent loads maintain isolation');
    });

    test('Should log warnings for missing prompts', async () => {
        console.log('\n⚠️  Testing warning logs for missing prompts...');
        
        const consoleSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        
        const instanceId = 'inst_no_prompt';
        const mockDb = {
            getSettings: jest.fn(async (key, keys) => {
                return {
                    'ai_provider': 'openai',
                    'ai_model': 'gpt-4.1-mini',
                    'ai_system_prompt': '' // Empty prompt
                };
            })
        };
        
        await loadAIConfig(mockDb, instanceId);
        
        // Should have logged error
        expect(consoleSpy).toHaveBeenCalled();
        const loggedContent = consoleSpy.mock.calls.join(' ');
        expect(loggedContent).toContain('NO ai_system_prompt');
        
        consoleSpy.mockRestore();
        
        console.log('✅ Warning logs correctly for missing prompts');
    });

    test('Empty database should return defaults (not global)', async () => {
        console.log('\n📦 Testing empty database returns defaults...');
        
        const mockDb = {
            getSettings: jest.fn(async () => {
                return {}; // Empty settings
            })
        };
        
        const config = await loadAIConfig(mockDb, 'any-instance');
        
        // Should return defaults, not global or other instance's settings
        expect(config.enabled).toBe(false);
        expect(config.provider).toBe('openai');
        expect(config.model).toBe('gpt-4.1-mini');
        expect(config.system_prompt).toBe('');
        
        console.log('✅ Empty database returns correct defaults');
    });
});

describe('Regression: Original Bug Behavior', () => {
    let loadAIConfig;
    
    beforeAll(async () => {
        const aiModule = require('../../src/whatsapp-server/ai/index.js');
        loadAIConfig = aiModule.loadAIConfig;
    });

    test('OLD BUG: Would merge global into instance (should NOT happen now)', async () => {
        console.log('\n🔴 Testing that old bug does NOT occur...');
        
        // In the old buggy version:
        // const settings = { ...globalSettings, ...instanceSettings };
        // This caused global to override instance settings
        
        // Now the fix:
        // const settings = { ...instanceSettings }; // NO global merge
        
        const instanceId = 'inst_test_bug';
        const mockDb = {
            getSettings: jest.fn(async (key, keys) => {
                if (key === instanceId) {
                    return {
                        'ai_system_prompt': 'MY_SPECIFIC_PROMPT'
                    };
                }
                if (key === '') {
                    return {
                        'ai_system_prompt': 'GLOBAL_OVERRIDE_ATTEMPT'
                    };
                }
                return {};
            })
        };
        
        const config = await loadAIConfig(mockDb, instanceId);
        
        // CRITICAL: Should use INSTANCE prompt, not GLOBAL
        expect(config.system_prompt).toBe('MY_SPECIFIC_PROMPT');
        expect(config.system_prompt).not.toBe('GLOBAL_OVERRIDE_ATTEMPT');
        
        console.log('✅ Old bug is fixed - no global override');
    });
});
