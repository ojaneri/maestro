/**
 * Centralized Logging System for Node.js
 * Writes to debug.log (all logs) and critical.log (ERROR/CRITICAL only)
 * Thread-safe with file locking and log rotation (max 10MB per file)
 */

const fs = require('fs');
const path = require('path');

// Log levels
const LOG_LEVELS = {
    DEBUG: 0,
    INFO: 1,
    WARN: 2,
    ERROR: 3,
    CRITICAL: 4
};

const LOG_LEVEL_NAMES = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'CRITICAL'];

// Configuration
const LOG_DIR = path.join(__dirname, '..', '..');
const DEBUG_LOG_FILE = path.join(LOG_DIR, 'debug.log');
const CRITICAL_LOG_FILE = path.join(LOG_DIR, 'critical.log');
const MAX_LOG_SIZE = 10 * 1024 * 1024; // 10MB
const MAX_OLD_LOGS = 5;

// File handles for locking
let debugLogHandle = null;
let criticalLogHandle = null;

// Initialize log files
function initLogger() {
    try {
        // Ensure log directory exists
        if (!fs.existsSync(LOG_DIR)) {
            fs.mkdirSync(LOG_DIR, { recursive: true });
        }
        
        // Open file handles for writing
        debugLogHandle = fs.openSync(DEBUG_LOG_FILE, 'a');
        criticalLogHandle = fs.openSync(CRITICAL_LOG_FILE, 'a');
    } catch (error) {
        console.error('Failed to initialize logger:', error.message);
    }
}

// Close file handles on process exit
process.on('exit', () => {
    if (debugLogHandle) fs.closeSync(debugLogHandle);
    if (criticalLogHandle) fs.closeSync(criticalLogHandle);
});

process.on('SIGINT', () => {
    if (debugLogHandle) fs.closeSync(debugLogHandle);
    if (criticalLogHandle) fs.closeSync(criticalLogHandle);
    process.exit(0);
});

/**
 * Check if log rotation is needed and perform rotation
 */
function checkAndRotate(logFile) {
    try {
        if (fs.existsSync(logFile)) {
            const stats = fs.statSync(logFile);
            if (stats.size >= MAX_LOG_SIZE) {
                const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
                const rotatedFile = `${logFile}.${timestamp}.old`;
                fs.renameSync(logFile, rotatedFile);
                
                // Clean up old rotated files
                cleanupOldLogs(logFile);
                
                // Reopen file handle
                return true;
            }
        }
    } catch (error) {
        console.error('Log rotation error:', error.message);
    }
    return false;
}

/**
 * Clean up old rotated log files
 */
function cleanupOldLogs(logFile) {
    try {
        const dir = path.dirname(logFile);
        const basename = path.basename(logFile);
        const pattern = new RegExp(`^${basename}\\.\\d{4}-\\d{2}-\\d{2}-\\d{2}-\\d{2}-\\d{2}\\.old$`);
        
        let files = fs.readdirSync(dir)
            .filter(f => pattern.test(f))
            .map(f => ({
                name: f,
                path: path.join(dir, f),
                time: fs.statSync(path.join(dir, f)).mtime.getTime()
            }))
            .sort((a, b) => a.time - b.time);
        
        // Delete oldest files beyond MAX_OLD_LOGS
        while (files.length > MAX_OLD_LOGS) {
            const oldest = files.shift();
            fs.unlinkSync(oldest.path);
        }
    } catch (error) {
        console.error('Cleanup error:', error.message);
    }
}

/**
 * Write log entry with file locking
 */
function writeLog(handle, message) {
    if (!handle) return false;
    
    try {
        // Check rotation before writing
        const logFile = handle === debugLogHandle ? DEBUG_LOG_FILE : CRITICAL_LOG_FILE;
        checkAndRotate(logFile);
        
        // Write with exclusive lock
        fs.writeFileSync(logFile, message, { flag: 'a' });
        return true;
    } catch (error) {
        console.error('Write log error:', error.message);
        return false;
    }
}

/**
 * Main logging function
 * @param {number} level - Log level (LOG_LEVELS.DEBUG, LOG_LEVELS.INFO, etc.)
 * @param {string} message - Log message
 * @param {object} context - Additional context (instance, function, etc.)
 */
function centralizedLog(level, message, context = {}) {
    // Ensure logger is initialized
    if (!debugLogHandle) initLogger();
    
    const levelName = LOG_LEVEL_NAMES[level] || 'UNKNOWN';
    
    // Build context string
    let contextStr = '';
    if (context && Object.keys(context).length > 0) {
        const contextPairs = Object.entries(context)
            .map(([key, value]) => `${key}=${typeof value === 'object' ? JSON.stringify(value) : value}`)
            .join(' | ');
        contextStr = ` [${contextPairs}]`;
    }
    
    // Format: [ISO8601] [LEVEL] [context] message
    const timestamp = new Date().toISOString();
    const logLine = `[${timestamp}] [${levelName}]${contextStr} ${message}\n`;
    
    // Always write to debug.log
    writeLog(debugLogHandle, logLine);
    
    // Write to critical.log only for ERROR and CRITICAL
    if (level >= LOG_LEVELS.ERROR) {
        writeLog(criticalLogHandle, logLine);
    }
    
    // Also output to console for convenience
    if (level >= LOG_LEVELS.ERROR) {
        console.error(logLine.trim());
    } else if (level === LOG_LEVELS.DEBUG) {
        console.debug(logLine.trim());
    } else {
        console.log(logLine.trim());
    }
    
    return true;
}

// Convenience functions
function logDebug(message, context = {}) {
    return centralizedLog(LOG_LEVELS.DEBUG, message, context);
}

function logInfo(message, context = {}) {
    return centralizedLog(LOG_LEVELS.INFO, message, context);
}

function logWarn(message, context = {}) {
    return centralizedLog(LOG_LEVELS.WARN, message, context);
}

function logError(message, context = {}) {
    return centralizedLog(LOG_LEVELS.ERROR, message, context);
}

function logCritical(message, context = {}) {
    return centralizedLog(LOG_LEVELS.CRITICAL, message, context);
}

// Legacy compatibility
function log(...args) {
    const { INSTANCE_ID } = require("../config/globals");
    const message = args.map(a => typeof a === 'object' ? JSON.stringify(a) : a).join(' ');
    return logDebug(message, { instance: INSTANCE_ID });
}

// Initialize on module load
initLogger();

module.exports = {
    LOG_LEVELS,
    logDebug,
    logInfo,
    logWarn,
    logError,
    logCritical,
    log, // Legacy compatibility
    centralizedLog
};
