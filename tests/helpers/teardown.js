/**
 * Test Teardown Helper
 * Global cleanup for Jest tests
 */

const fs = require('fs');
const path = require('path');

// Cleanup function for test database
const cleanupTestDatabase = async (dbPath) => {
  if (dbPath && fs.existsSync(dbPath)) {
    try {
      fs.unlinkSync(dbPath);
      console.log(`Cleaned up test database: ${dbPath}`);
    } catch (error) {
      console.error(`Failed to cleanup test database: ${error.message}`);
    }
  }
};

// Cleanup function for test files
const cleanupTestFiles = async (testDir) => {
  if (testDir && fs.existsSync(testDir)) {
    const files = fs.readdirSync(testDir);
    for (const file of files) {
      if (file.startsWith('test_') && (file.endsWith('.db') || file.endsWith('.db-wal') || file.endsWith('.db-shm'))) {
        try {
          fs.unlinkSync(path.join(testDir, file));
          console.log(`Cleaned up test file: ${file}`);
        } catch (error) {
          console.error(`Failed to cleanup test file: ${error.message}`);
        }
      }
    }
  }
};

// Cleanup function for test instances
const cleanupTestInstances = async () => {
  // Kill any test instance processes
  // This would need to be implemented based on your process management
  console.log('Cleaning up test instances...');
};

// Cleanup function for nock
const cleanupNock = () => {
  try {
    const nock = require('nock');
    nock.cleanAll();
    nock.restore();
    console.log('Cleaned up nock mocks');
  } catch (error) {
    // Nock might not be installed
  }
};

// Teardown after all tests
afterAll(async () => {
  console.log('========================================');
  console.log('Test Suite Completed');
  console.log('Running global cleanup...');
  console.log('========================================');
  
  cleanupNock();
});

// Teardown after each test
afterEach(async () => {
  // Cleanup after each test if needed
  jest.clearAllTimers();
});

module.exports = {
  cleanupTestDatabase,
  cleanupTestFiles,
  cleanupTestInstances,
  cleanupNock,
  afterAll,
  afterEach
};
