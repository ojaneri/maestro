#!/bin/bash

# setup_ai_chat.sh - Setup script for AI Chat integration
# This script helps integrate the new AI chat features with existing WhatsApp bot

echo "🚀 Setting up AI Chat Integration for WhatsApp Bot"
echo "=================================================="

# Check if we're in the right directory
if [ ! -f "whatsapp-server-intelligent.js" ]; then
    echo "❌ Error: Please run this script from the project root directory"
    echo "   Current directory should contain whatsapp-server-intelligent.js"
    exit 1
fi

echo "✅ WhatsApp intelligent server script found (whatsapp-server-intelligent.js)"

# Check if database module exists
if [ ! -f "db.js" ]; then
    echo "❌ Error: db.js not found. Please ensure database module is present."
    exit 1
fi

echo "✅ Database module found"

# Check if dashboard exists
if [ ! -f "dashboard_chat.php" ]; then
    echo "❌ Error: dashboard_chat.php not found. Please ensure dashboard is present."
    exit 1
fi

echo "✅ Chat dashboard found"

# Install npm dependencies if needed
echo "📦 Checking npm dependencies..."
if ! npm list sqlite3 >/dev/null 2>&1; then
    echo "Installing missing dependencies..."
    npm install sqlite sqlite3
    echo "✅ Dependencies installed"
else
    echo "✅ Dependencies already installed"
fi

# Test database connection
echo "🧪 Testing database connection..."
node -e "
const db = require('./db');
db.initDatabase().then(() => {
    console.log('✅ Database test successful');
    process.exit(0);
}).catch(err => {
    console.log('❌ Database test failed:', err.message);
    process.exit(1);
});
"

if [ $? -eq 0 ]; then
    echo "✅ Database setup complete"
else
    echo "❌ Database setup failed"
    exit 1
fi

echo ""
echo "🎉 AI Chat Integration Setup Complete!"
echo "====================================="
echo ""
echo "Next steps:"
echo "1. Start your WhatsApp instance: bash restart_instance.sh <instance_id>"
echo "2. Open the chat dashboard: /api/envio/wpp/dashboard_chat.php?instance=<instance_id>"
echo "3. Configure OpenAI settings via the dashboard or API"
echo "4. Test the AI responses"
echo ""
echo "API Endpoints added:"
echo "  GET  /contacts          - List all contacts"
echo "  GET  /history           - Get chat history"
echo "  GET  /ai-settings       - Get AI configuration"
echo "  POST /ai-settings       - Save AI configuration"
echo ""
echo "Files created/modified:"
echo "  ✅ db.js                               - Database module for history & AI state"
echo "  ✅ whatsapp-server-intelligent.js      - WhatsApp server with AI/logging"
echo "  ✅ dashboard_chat.php                  - Chat interface"
echo ""
echo "⚠️  Remember to configure your OpenAI API key in the dashboard!"
