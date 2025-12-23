# Tech Context

## Technologies Used

- **Backend Languages**:
  - PHP 8.0+ (Web interface and API)
  - Node.js 18+ (WhatsApp instance management)

- **Database**:
  - SQLite 3 (Single file database, no server required)

- **WhatsApp Integration**:
  - Baileys (@whiskeysockets/baileys) - WhatsApp Web API library

- **AI Integration**:
  - OpenAI API (GPT models and Assistants API)
  - Google Gemini API (@google/genai)

- **Frontend**:
  - HTML5, CSS3, JavaScript (ES6+)
  - Tailwind CSS (Utility-first CSS framework)

- **Communication**:
  - WebSocket (ws library) for real-time updates
  - HTTP/cURL for inter-process communication

- **Additional Libraries**:
  - Express.js (Node.js web framework)
  - QRCode (QR code generation)
  - Google APIs (Calendar integration)
  - UUID (Unique identifier generation)

## Development Setup

- **PHP Dependencies**: Managed via Composer (`composer.json`)
- **Node.js Dependencies**: Managed via NPM (`package.json`)
- **Environment Configuration**: `.env` file for sensitive settings
- **Database**: Auto-created `chat_data.db` on first run
- **Development Server**: PHP built-in server (`php -S localhost:8080 index.php`)

## Technical Constraints

- **Hosting Compatibility**: Must work on shared hosting (PHP-only environments)
- **Database Choice**: SQLite chosen over MySQL to avoid server dependencies
- **WhatsApp Limitations**: Subject to WhatsApp's rate limits and API changes
- **Browser Support**: Modern browsers with WebSocket support required
- **Memory Usage**: Node.js instances should be monitored for memory leaks

## Dependencies and Requirements

- **PHP Extensions**: curl, sqlite3, json, mbstring
- **Node.js Modules**: See `package.json` for full list
- **System Requirements**: Linux/Windows/Mac with Node.js and PHP installed
- **External APIs**: OpenAI API key, Gemini API key (optional), Google OAuth (optional)

## Tool Usage Patterns

- **Version Control**: Git for source code management
- **IDE**: VSCode recommended with PHP and JavaScript extensions
- **API Testing**: Postman or curl for API endpoint testing
- **Database Management**: SQLite browser tools for database inspection
- **Debugging**: PHP error logs, Node.js console output, debug file in project root

## Development Workflow

1. **Local Development**: Run `php -S localhost:8080 index.php` for web interface
2. **Instance Management**: Use provided shell scripts for starting/stopping instances
3. **Database Changes**: Direct SQL queries or PHP scripts for schema updates
4. **Testing**: Manual testing through web interface, API calls via Postman
5. **Deployment**: Copy files to web server, ensure PHP and Node.js availability