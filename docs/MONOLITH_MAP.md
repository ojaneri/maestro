# Monolith Map: whatsapp-server-intelligent.js

## Overview
This document provides a comprehensive analysis of the `whatsapp-server-intelligent.js` file and its modular refactored structure.

## Architecture Transition

### Legacy Monolith (Original)
- **Total Lines**: 6,531
- **Language**: JavaScript (Node.js)
- **Architecture**: Single-file monolith with embedded Express server, WebSocket support, and Baileys WhatsApp integration

### Modular Architecture (New)
The code has been refactored into a clean modular structure located in `src/whatsapp-server/`. The monolithic file is maintained for backward compatibility but delegates to modular components.

## File Tree: New Modular Structure

```
src/whatsapp-server/
├── index.js                          # Main module exports
├── server/
│   ├── index.js                      # Main server coordinator
│   ├── express-app.js                # Express app initialization
│   └── websocket-server.js           # WebSocket server setup
├── whatsapp/
│   ├── connection.js                  # Baileys WhatsApp connection
│   ├── send-message.js               # Message sending
│   └── handlers/
│       ├── connection.js             # Connection event handlers
│       ├── contacts.js              # Contact event handlers
│       └── messages.js              # Message event handlers
├── ai/
│   ├── index.js                      # AI orchestration
│   ├── response-builder.js          # Response building
│   └── providers/
│       ├── openai.js                # OpenAI provider
│       ├── gemini.js                # Gemini provider
│       └── openrouter.js            # OpenRouter provider
├── commands/
│   ├── index.js                      # Command system
│   ├── parser.js                    # Command parsing
│   ├── roundtrip.js                 # AI command roundtrip
│   └── handlers/
│       ├── calendar.js              # Calendar commands
│       ├── context.js               # Context commands
│       ├── dados.js                 # Data commands
│       ├── mail.js                  # Mail commands
│       ├── scheduling.js            # Scheduling commands
│       ├── template.js              # Template commands
│       └── whatsapp.js              # WhatsApp commands
├── calendar/
│   ├── index.js                      # Calendar service
│   ├── availability.js              # Availability checking
│   └── oauth.js                     # OAuth handling
├── scheduler/
│   ├── index.js                      # Scheduler service
│   └── jobs.js                      # Scheduled jobs
├── monitoring/
│   ├── index.js                      # Monitoring service
│   └── alarms.js                    # Alarm system
├── contacts/
│   ├── index.js                      # Contacts service
│   └── temperature.js               # Contact temperature
└── config/
    ├── constants.js                 # Configuration constants
    └── settings-keys.js             # Settings keys
```

## Module Responsibilities

### Server Infrastructure (`server/`)
- **Purpose**: Express + WebSocket server coordination
- **Key Functions**:
  - `startServer()`: Initialize and start the WhatsApp server
  - `stopServer()`: Graceful shutdown
  - `restartWhatsApp()`: Restart WhatsApp connection
  - `getServerStatus()`: Get server status

### WhatsApp Connection (`whatsapp/`)
- **Purpose**: Baileys WhatsApp integration
- **Key Functions**:
  - Connection lifecycle management
  - Message sending and receiving
  - Contact management
  - Media handling

### AI Engine (`ai/`)
- **Purpose**: AI provider integration and response processing
- **Key Functions**:
  - `generateAIResponse()`: Route to configured AI provider
  - `dispatchAIResponse()`: Process and send AI responses
  - Support for OpenAI, Gemini, OpenRouter

### Command System (`commands/`)
- **Purpose**: Command parsing and execution
- **Key Functions**:
  - Command parsing and validation
  - Command routing to handlers
  - AI command roundtrip processing

### Calendar Service (`calendar/`)
- **Purpose**: Google Calendar integration
- **Key Functions**:
  - OAuth authentication
  - Event CRUD operations
  - Availability checking
  - Slot suggestion

### Scheduler Service (`scheduler/`)
- **Purpose**: Scheduled message processing
- **Key Functions**:
  - `start()`: Start scheduler
  - `scheduleMessage()`: Schedule a message
  - `cancelScheduledMessage()`: Cancel a scheduled message

### Monitoring (`monitoring/`)
- **Purpose**: Health checks and alarms
- **Key Functions**:
  - Health check endpoints
  - Alarm notifications
  - Status reporting

### Contacts (`contacts/`)
- **Purpose**: Contact management
- **Key Functions**:
  - Contact temperature calculation
  - Contact metadata storage

## Migration Guide: Monolith to Modular

### Before (Monolith)
```javascript
// Direct function calls from monolithic file
const express = require("express");
const { startWhatsApp, sendWhatsAppMessage } = require('./whatsapp-server-intelligent.js');
```

### After (Modular)
```javascript
// Import from modular structure
const { WhatsAppServer } = require('./src/whatsapp-server/server');
const { AIEngine } = require('./src/whatsapp-server/ai');
const { CalendarService } = require('./src/whatsapp-server/calendar');
const { SchedulerService } = require('./src/whatsapp-server/scheduler');

// Start server
WhatsAppServer.startServer({ port: 3000 });
```

### Using Main Index
```javascript
// Import all modules from main index
const {
    WhatsAppServer,
    AIEngine,
    CalendarService,
    SchedulerService,
    CommandSystem,
    Monitoring,
    ContactsService
} = require('./src/whatsapp-server');
```

## API Reference

### WhatsAppServer
| Function | Description |
|----------|-------------|
| `startServer(options)` | Start the WhatsApp server |
| `stopServer(server)` | Stop the server gracefully |
| `restartWhatsApp()` | Restart WhatsApp connection |
| `getServerStatus()` | Get server status |

### AIEngine
| Function | Description |
|----------|-------------|
| `generateAIResponse(sessionContext, messageBody, config)` | Generate AI response |
| `dispatchAIResponse(sessionContext, messageBody, config, options, deps)` | Dispatch AI response |
| `loadAIConfig(db, instanceId)` | Load AI configuration |

### CalendarService
| Function | Description |
|----------|-------------|
| `createEvent(instanceId, eventData)` | Create calendar event |
| `updateEvent(instanceId, eventId, eventData)` | Update calendar event |
| `deleteEvent(instanceId, eventId)` | Delete calendar event |
| `listEvents(instanceId, start, end)` | List calendar events |
| `checkAvailability(instanceId, start, end)` | Check availability |
| `suggestSlots(instanceId, date, window, duration)` | Suggest available slots |

### SchedulerService
| Function | Description |
|----------|-------------|
| `start(options)` | Start scheduler |
| `stop()` | Stop scheduler |
| `scheduleMessage(messageData)` | Schedule a message |
| `cancelScheduledMessage(messageId)` | Cancel scheduled message |
| `listScheduledMessages(filters)` | List scheduled messages |

## Backward Compatibility

The monolithic file `whatsapp-server-intelligent.js` is maintained for backward compatibility. It:
1. Imports from the modular structure
2. Exports key functions and modules
3. Maintains the same entry point behavior
4. Supports existing code that requires the monolithic file

## Environment Variables

All environment variables remain unchanged:

| Variable | Description |
|----------|-------------|
| `APP_TIMEZONE` | Application timezone |
| `INSTANCE_ID` | Instance identifier |
| `PORT` | Server port |
| `PUBLIC_BASE_URL` | Public base URL |
| `GOOGLE_OAUTH_CLIENT_ID` | Google OAuth client ID |
| `GOOGLE_OAUTH_CLIENT_SECRET` | Google OAuth client secret |
| `CALENDAR_TOKEN_SECRET` | Calendar token encryption secret |
| `CUSTOMER_DB_*` | Customer database configuration |
| `BAILEYS_USER_AGENT` | Baileys user agent |

## Dependencies

### Core
- `express`: HTTP server framework
- `@whiskeysockets/baileys`: WhatsApp Bot SDK
- `ws`: WebSocket implementation
- `mysql2/promise`: MySQL database driver

### Optional
- `googleapis`: Google Calendar integration
- `openai`: OpenAI API integration
- `google-auth-library`: Google authentication

## Complexity Metrics

### Original Monolith
- **Function Count**: ~200+ functions
- **Cyclomatic Complexity**: High
- **State Management**: Complex global state

### Modular Structure
- **Module Count**: 10+ modules
- **Function per Module**: ~10-30 functions
- **State Management**: Encapsulated per module
- **Testability**: Improved with separated concerns

## Benefits of Modular Architecture

1. **Maintainability**: Each module can be modified independently
2. **Testability**: Units can be tested in isolation
3. **Reusability**: Modules can be reused across projects
4. **Scalability**: Easy to add new features as modules
5. **Readability**: Clear separation of concerns
6. **Performance**: Lazy loading of modules

## Next Steps

1. Migrate existing code to use modular imports
2. Add unit tests for each module
3. Implement dependency injection
4. Add module documentation
5. Consider breaking out into separate npm packages
