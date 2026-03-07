# Test Documentation - Maestro WhatsApp System

## Overview
This document describes the test infrastructure for the Maestro WhatsApp automation system.

## Test Structure

```
tests/
├── README.md                    # Test documentation
├── run-tests.sh                 # Main test runner (CI)
├── package.json                 # Test dependencies
├── helpers/                      # Test utilities
│   ├── mockData.js             # Mock data generators
│   ├── mockData.test.js        # Mock data tests
│   ├── setup.js                # Test setup
│   └── teardown.js             # Test cleanup
├── unit/                        # Unit tests (future)
├── integration/                 # Integration tests
│   ├── api/
│   │   └── messaging.test.js   # WhatsApp messaging API tests
│   └── debug-command.test.js   # Debug command integration test
├── e2e/                         # E2E tests (future)
└── performance/                # Performance metrics
```

## Test Types

### 1. Unit Tests (`tests/unit/`)
- Test individual functions and modules
- Run: `npx jest tests/unit/`
- Focus: Pure logic, isolated components

### 2. Integration Tests (`tests/integration/`)
- Test API endpoints and workflows
- Run: `npx jest tests/integration/`
- Focus: HTTP endpoints, database operations, message flows

### 3. E2E Tests (`tests/e2e/`)
- Test complete user flows
- Run: `npx jest tests/e2e/`
- Focus: End-to-end scenarios

### 4. Standalone Tests
- Direct Node.js execution tests
- Not part of Jest suite
- Run directly with `node`

## Available Tests

### test-instance-3013.js
- **Purpose**: Test function calling on instance 3013
- **Location**: `./test-instance-3013.js`
- **Run**: `node test-instance-3013.js`
- **Target**: Port 3013, phone 5585999999000

### tests/unit/ai-prompt-isolation.test.js
- **Purpose**: Validate AI prompt isolation - each instance uses ONLY its own ai_system_prompt
- **Location**: `./tests/unit/ai-prompt-isolation.test.js`
- **Run**: `npx jest tests/unit/ai-prompt-isolation.test.js --verbose`
- **Validates**:
  - Instance loads its own prompt without global inheritance
  - Different instances have different prompts (isolation)
  - Warning is logged when instance has no ai_system_prompt
  - ai_config JSON is used as fallback when simple fields are empty
  - Strict isolation - no global inheritance

### tests/integration/debug-command-enhanced.test.js
- **Purpose**: Validate #debug# command returns correct information
- **Location**: `./tests/integration/debug-command-enhanced.test.js`
- **Run**: `npx jest tests/integration/debug-command-enhanced.test.js --verbose`
- **Validates**:
  - Response contains all required fields (Instance ID, Port, PID, Uptime, Memory, etc.)
  - Message is NOT sent to AI
  - Response format is correct with emoji formatting
  - System prompt is truncated at 200 chars

### tests/integration/prompt-leak-regression.test.js
- **Purpose**: Regression tests for prompt leakage between instances
- **Location**: `./tests/integration/prompt-leak-regression.test.js`
- **Run**: `npx jest tests/integration/prompt-leak-regression.test.js --verbose`
- **Validates**:
  - Multiple instances do NOT leak prompts to each other
  - Global settings are NOT inherited by instances
  - Concurrent config loads maintain isolation
  - Warning logs for missing prompts
  - Original bug (global merge) is fixed

### tests/integration/debug-command.test.js
- **Purpose**: Test #debug# command on multiple instances
- **Location**: `./tests/integration/debug-command.test.js`
- **Run**: `node tests/integration/debug-command.test.js`
- **Targets**:
  - Port 3011 → Phone 5585999999000 → Instance inst_6992ed0c735f0
  - Port 3013 → Phone 5585920000859 → Instance inst_6992ec9e78d1c

### tests/integration/api/messaging.test.js
- **Purpose**: Test WhatsApp messaging API
- **Location**: `./tests/integration/api/messaging.test.js`
- **Run**: `npx jest tests/integration/api/messaging.test.js`
- **Target**: Port 3011

## Running Tests

### Run All Tests (Jest)
```bash
cd tests
npx jest
```

### Run Specific Test
```bash
# Integration test
npx jest tests/integration/debug-command.test.js

# Standalone test
node test-instance-3013.js

# Debug command integration
node tests/integration/debug-command.test.js
```

### Run Test Runner (CI)
```bash
cd tests
./run-tests.sh
```

## Test Execution Flow

1. **Setup Phase** (`helpers/setup.js`)
   - Initialize test environment
   - Load mock data
   - Connect to test databases

2. **Test Phase**
   - Execute test cases
   - Verify assertions
   - Log results

3. **Teardown Phase** (`helpers/teardown.js`)
   - Cleanup test data
   - Close connections
   - Generate reports

## Test Criteria

### Success Criteria
- ✅ HTTP 200 response for message send
- ✅ AI processes the message
- ✅ Response appears in logs
- ✅ Function calls are detected and executed

### Verification Commands
```bash
# Check instance logs
tail -100 instance_inst_*.log | grep -i "ai\|function\|gemini"

# Verify function calls
tail -100 instance_inst_*.log | grep -i "Function calls detected"
```

## Writing New Tests

### Template for Integration Test
```javascript
const request = require('supertest');

const BASE_URL = 'http://localhost:PORT';

describe('Test Suite Name', () => {
  beforeAll(async () => {
    // Setup
  });

  test('should do something', async () => {
    const response = await request(BASE_URL)
      .post('/endpoint')
      .send({ data: 'value' })
      .timeout(10000);
    
    expect(response.status).toBeDefined();
  });
});
```

### Template for Standalone Test
```javascript
const http = require('http');

function sendMessage(port, phone, message) {
    return new Promise((resolve, reject) => {
        const postData = JSON.stringify({ to: phone, message });
        const options = {
            hostname: 'localhost',
            port: port,
            path: '/send-message',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(postData)
            }
        };
        
        const req = http.request(options, (res) => {
            let data = '';
            res.on('data', (chunk) => { data += chunk; });
            res.on('end', () => resolve(JSON.parse(data)));
        });
        
        req.on('error', reject);
        req.write(postData);
        req.end();
    });
}
```

## CI Integration

The test runner saves performance metrics to:
```
tests/performance/run_YYYYMMDD_HHMMSS.json
```

Metrics include:
- Number of test suites
- Number of tests
- Pass/fail counts
- Execution duration

## Troubleshooting

### Instance Not Running
```bash
# Check if instance is running
netstat -tuln | grep PORT

# Check instance logs
tail -50 instance_inst_*.log
```

### Connection Errors
- Verify instance is running on correct port
- Check firewall settings
- Verify localhost connectivity

### Test Timeouts
- Increase timeout values in tests
- Check network latency
- Verify instance performance
