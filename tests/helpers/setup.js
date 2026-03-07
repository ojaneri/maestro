/**
 * Test Setup Helper
 * Global setup for Jest tests
 */

// Set test environment
process.env.NODE_ENV = 'test';

// Import test utilities
const { generateTestDatabasePath } = require('./mockData');

// Global test configuration
const globalConfig = {
  testDatabasePath: generateTestDatabasePath(),
  testInstancePort: 3099,
  testTimeout: 30000,
  mockTimeout: 5000
};

// Setup before all tests
beforeAll(async () => {
  console.log('========================================');
  console.log('Starting Maestro Test Suite');
  console.log('Environment:', process.env.NODE_ENV);
  console.log('========================================');
});

// Setup before each test
beforeEach(async () => {
  // Reset any global state
  jest.clearAllMocks();
  jest.resetAllMocks();
});

// Export configuration
module.exports = {
  globalConfig,
  beforeAll,
  beforeEach
};
