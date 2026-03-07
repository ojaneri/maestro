/**
 * @fileoverview Main module index for WhatsApp Server
 * @module whatsapp-server
 * 
 * This is the main entry point that exports all modules of the WhatsApp Server.
 * All modules are imported and re-exported for convenient access.
 */

// Server infrastructure
const WhatsAppServer = require('./server');
const expressApp = require('./server/express-app');
const websocketServer = require('./server/websocket-server');

// WhatsApp connection
const WhatsAppConnection = require('./whatsapp/connection');
const WhatsAppSendMessage = require('./whatsapp/send-message');
const WhatsAppHandlers = {
    connection: require('./whatsapp/handlers/connection'),
    contacts: require('./whatsapp/handlers/contacts'),
    messages: require('./whatsapp/handlers/messages')
};

// AI Engine
const AIEngine = require('./ai');
const OpenAIProvider = require('./ai/providers/openai');
const GeminiProvider = require('./ai/providers/gemini');
const OpenRouterProvider = require('./ai/providers/openrouter');
const AIResponseBuilder = require('./ai/response-builder');

// Command System
const CommandSystem = require('./commands');
const CommandParser = require('./commands/parser');
const CommandRoundtrip = require('./commands/roundtrip');
const CommandHandlers = {
    calendar: require('./commands/handlers/calendar'),
    context: require('./commands/handlers/context'),
    dados: require('./commands/handlers/dados'),
    mail: require('./commands/handlers/mail'),
    scheduling: require('./commands/handlers/scheduling'),
    template: require('./commands/handlers/template'),
    whatsapp: require('./commands/handlers/whatsapp')
};

// Calendar Service
const CalendarService = require('./calendar');
const CalendarAvailability = require('./calendar/availability');
const CalendarOAuth = require('./calendar/oauth');

// Scheduler Service
const SchedulerService = require('./scheduler');
const SchedulerJobs = require('./scheduler/jobs');

// Monitoring
const Monitoring = require('./monitoring');
const Alarms = require('./monitoring/alarms');

// Contacts
const ContactsService = require('./contacts');
const TemperatureService = require('./contacts/temperature');

// Config
const Config = require('./config/constants');
const SettingsKeys = require('./config/settings-keys');

/**
 * WhatsApp Server Modular Architecture
 * 
 * This module provides a clean, modular interface to all WhatsApp server components.
 * The architecture separates concerns into distinct modules:
 * 
 * - server: Express + WebSocket server infrastructure
 * - whatsapp: Baileys connection and message handlers
 * - ai: AI provider integration and response processing
 * - commands: Command parsing and execution system
 * - calendar: Google Calendar integration
 * - scheduler: Scheduled message processing
 * - monitoring: Health checks and alarms
 * - contacts: Contact management and temperature tracking
 */

module.exports = {
    // Server infrastructure
    WhatsAppServer,
    expressApp,
    websocketServer,
    
    // WhatsApp connection
    WhatsAppConnection,
    WhatsAppSendMessage,
    WhatsAppHandlers,
    
    // AI Engine
    AIEngine,
    OpenAIProvider,
    GeminiProvider,
    OpenRouterProvider,
    AIResponseBuilder,
    
    // Command System
    CommandSystem,
    CommandParser,
    CommandRoundtrip,
    CommandHandlers,
    
    // Calendar Service
    CalendarService,
    CalendarAvailability,
    CalendarOAuth,
    
    // Scheduler Service
    SchedulerService,
    SchedulerJobs,
    
    // Monitoring
    Monitoring,
    Alarms,
    
    // Contacts
    ContactsService,
    TemperatureService,
    
    // Config
    Config,
    SettingsKeys
};
