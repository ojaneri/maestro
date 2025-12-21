# 🤖 AI Chat Integration Guide

This guide explains how to integrate the new AI chat functionality with your existing WhatsApp bot system.

## 📋 Overview

The integration adds:
- ✅ **Persistent Chat History** with SQLite database
- ✅ **Enhanced OpenAI Integration** with conversation context
- ✅ **Chat Dashboard UI** matching your existing design
- ✅ **New API Endpoints** for chat management
- ✅ **Zero Breaking Changes** to existing functionality

## 🚀 Quick Setup

### Option 1: Automated Setup (Recommended)
```bash
# Make script executable
chmod +x setup_ai_chat.sh

# Run setup
./setup_ai_chat.sh
```

### Option 2: Manual Setup

1. **Ensure the intelligent server script is present:**
   ```bash
   ls whatsapp-server-intelligent.js
   ```

2. **Install dependencies:**
   ```bash
   npm install sqlite sqlite3
   ```

## 📁 Files Added/Modified

### New Files Created:
- `db.js` - SQLite database module
- `dashboard_chat.php` - Chat dashboard UI
- `setup_ai_chat.sh` - Automated setup script
- `AI_CHAT_INTEGRATION.md` - This guide

### Modified Files:
- `whatsapp-server-intelligent.js` - WhatsApp server with AI, logging, and context persistence

## 🛠️ Features Added

### 1. Database Module (`db.js`)
- **Chat History Storage**: Saves all user/assistant messages
- **Contact Management**: Tracks contact information and statistics
- **AI Settings Persistence**: Stores OpenAI configuration per instance
- **Conversation Context**: Retrieves recent messages for AI context

### 2. Enhanced WhatsApp Server
- **AI Message Processing**: Enhanced with database persistence
- **Conversation Context**: Maintains conversation history
- **New API Endpoints**: Chat management endpoints
- **Backward Compatibility**: All existing features preserved

### 3. Chat Dashboard (`dashboard_chat.php`)
- **WhatsApp-style Interface**: Matches your existing design system
- **Contact List**: Shows all contacts with last message preview
- **Message History**: Displays conversation in chat bubbles
- **Real-time Updates**: Auto-refresh functionality
- **Mobile Responsive**: Works on all devices

## 🔌 New API Endpoints

### Chat Management
```
GET  /contacts          - List all contacts with last message
GET  /history?contact=  - Get chat history for specific contact
POST /send-message      - Send message (enhanced with saving)
```

### AI Configuration
```
GET  /ai-settings       - Get current AI configuration
POST /ai-settings       - Save AI configuration
```

### Response Format
```json
{
  "ok": true,
  "instanceId": "inst_123",
  "contacts": [
    {
      "remote_jid": "5585999999999@s.whatsapp.net",
      "contact_name": "John Doe",
      "last_message": "Hello, how are you?",
      "last_role": "user",
      "last_message_at": "2025-12-14T19:15:00.000Z",
      "message_count": 5
    }
  ]
}
```

## 🎨 Chat Dashboard Access

### Method 1: Direct URL
```
http://your-domain.com/api/envio/wpp/dashboard_chat.php?instance=<instance_id>
```

### Method 2: Add to Navigation (Manual)
Add this button to your `index.php` in the header section (around line 387):

```php
<?php if ($selectedInstance): ?>
  <a href="dashboard_chat.php?instance=<?= $selectedInstanceId ?>" 
     class="px-4 py-2 rounded-xl bg-success text-white font-medium hover:opacity-90">
    💬 Chat IA
  </a>
<?php endif; ?>
```

## ⚙️ Configuration

### OpenAI Settings
Configure via API or dashboard:

```bash
# Save AI settings
curl -X POST http://127.0.0.1:3000/ai-settings \
  -H "Content-Type: application/json" \
  -d '{
    "enabled": true,
    "api_key": "sk-your-openai-key",
    "model": "gpt-3.5-turbo",
    "system_prompt": "You are a helpful assistant...",
    "assistant_prompt": "Respond in a friendly tone..."
  }'
```

### System Prompts Examples

**Customer Service Bot:**
```
You are a helpful customer service representative for our company. 
Always be polite, professional, and try to resolve customer issues. 
If you cannot solve a problem, direct them to human support.
```

**Sales Assistant:**
```
You are a sales assistant. Help customers find products that meet their needs. 
Ask clarifying questions and provide product recommendations. 
Be friendly and enthusiastic about our offerings.
```

## 🧪 Testing

### 1. Test Database Connection
```bash
node -e "const db = require('./db'); db.initDatabase().then(() => console.log('OK')).catch(console.error);"
```

### 2. Test API Endpoints
```bash
# Test contacts endpoint
curl http://127.0.0.1:3000/contacts

# Test AI settings
curl http://127.0.0.1:3000/ai-settings
```

### 3. Test Chat Interface
1. Open dashboard: `/api/envio/wpp/dashboard_chat.php?instance=<id>`
2. Send a message from WhatsApp
3. Check if it appears in the dashboard
4. Verify AI responds automatically

## 🔧 Troubleshooting

### Database Issues
```bash
# Check database file
ls -la chat_data.db

# Reset database (WARNING: deletes all data)
rm chat_data.db
```

### Server Issues
```bash
# Check server logs
tail -f instance_*.log

# Restart instance
bash restart_instance.sh <instance_id>
```

### API Issues
```bash
# Test server health
curl http://127.0.0.1:3000/health

# Check instance status
curl http://127.0.0.1:3000/status
```

## 📊 Database Schema

### Tables Created:
- `chat_history` - Message storage
- `contacts` - Contact information
- `ai_settings` - AI configuration

### Sample Queries:
```sql
-- Get conversation between user and contact
SELECT * FROM chat_history 
WHERE instance_id = 'inst_123' 
AND remote_jid = '5585999999999@s.whatsapp.net'
ORDER BY timestamp DESC 
LIMIT 50;

-- Get contact statistics
SELECT contact_name, message_count, last_message_at 
FROM contacts 
WHERE instance_id = 'inst_123';
```

## 🎯 Usage Examples

### 1. Setting Up AI for Customer Support
```json
{
  "enabled": true,
  "api_key": "sk-...",
  "model": "gpt-3.5-turbo",
  "system_prompt": "You are a helpful customer support agent. Always be polite and try to resolve issues.",
  "assistant_prompt": "I understand your concern and I'm here to help."
}
```

### 2. Setting Up AI for Sales
```json
{
  "enabled": true,
  "api_key": "sk-...",
  "model": "gpt-4",
  "system_prompt": "You are a sales assistant. Help customers find products and close sales.",
  "assistant_prompt": "Great choice! Let me help you with that."
}
```

## 🔐 Security Considerations

1. **API Keys**: Store OpenAI keys securely, never commit to git
2. **Database**: The `chat_data.db` file contains sensitive conversation data
3. **Access Control**: The dashboard requires the same authentication as the main panel
4. **Rate Limiting**: Consider implementing rate limits for AI requests

## 🚀 Performance Optimization

1. **Database Indexes**: Already included for fast queries
2. **Message Limits**: Configurable conversation context length
3. **Caching**: Contact list caching for better performance
4. **Connection Pooling**: SQLite handles concurrent access efficiently

## 📈 Future Enhancements

Planned features:
- [ ] Message search functionality
- [ ] Export chat history
- [ ] Multi-language support
- [ ] Advanced AI models (GPT-4, Claude)
- [ ] File attachment support
- [ ] Chat analytics dashboard

## 🆘 Support

If you encounter issues:
1. Check the logs in `debug.log`
2. Verify database connectivity
3. Test API endpoints individually
4. Ensure OpenAI API key is valid
5. Check WhatsApp connection status

## ✅ Verification Checklist

- [ ] Database module installed and working
- [ ] Enhanced server running without errors
- [ ] Chat dashboard accessible
- [ ] API endpoints responding correctly
- [ ] OpenAI integration working
- [ ] Messages being saved to database
- [ ] Contact list populating
- [ ] Chat history displaying correctly
- [ ] AI responses being generated
- [ ] No breaking changes to existing functionality

---

**🎉 Congratulations! Your WhatsApp bot now has AI chat capabilities!**
