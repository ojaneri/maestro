#!/bin/bash
# Pre-push hook to run tests before git push

echo "🔄 Running tests before push..."

cd tests

# Install dependencies if needed
if [ ! -d "node_modules" ]; then
    echo "📦 Installing dependencies..."
    npm ci
fi

# Run all tests
echo "🧪 Running Unit Tests..."
npm run test:unit
UNIT_RESULT=$?

echo "🧪 Running Integration Tests..."
npm run test:integration
INTEGRATION_RESULT=$?

echo "🧪 Running API Tests..."
npm run test:api
API_RESULT=$?

# Check results
if [ $UNIT_RESULT -ne 0 ] && [ $INTEGRATION_RESULT -ne 0 ] && [ $API_RESULT -ne 0 ]; then
    echo "❌ Tests failed! Push aborted."
    exit 1
fi

echo "✅ Tests passed! Proceeding with push..."
exit 0
