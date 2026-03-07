// cleanup-service.js - Dedicated cleanup service for orphaned instances
const { cleanupOrphanedInstances } = require('./db-updated.js')
const { exec } = require('child_process')
const path = require('path')
const fs = require('fs')

// Configuration
const CLEANUP_INTERVAL_HOURS = 24
const MAX_INSTANCE_AGE_HOURS = 48
const PID_FILE = path.join(__dirname, 'cleanup-service.pid')
const LOG_FILE = path.join(__dirname, 'cleanup-service.log')

// Ensure log file exists
function ensureLogFile() {
    if (!fs.existsSync(LOG_FILE)) {
        fs.writeFileSync(LOG_FILE, 'Cleanup Service Log\n')
    }
}

// Log message to file
function logMessage(message) {
    const timestamp = new Date().toISOString()
    const logEntry = `[${timestamp}] ${message}\n`
    fs.appendFileSync(LOG_FILE, logEntry)
    console.log(logEntry.trim())
}

// Check if another instance is already running
function isAnotherInstanceRunning() {
    try {
        if (fs.existsSync(PID_FILE)) {
            const pid = fs.readFileSync(PID_FILE, 'utf8').trim()
            // Check if process is still running
            exec(`ps -p ${pid}`, (error, stdout, stderr) => {
                if (!error && stdout.includes(pid)) {
                    return true
                }
            })
        }
        return false
    } catch (err) {
        return false
    }
}

// Write current PID to file
function writePidFile() {
    fs.writeFileSync(PID_FILE, process.pid.toString())
}

// Clean up PID file on exit
function cleanupPidFile() {
    try {
        if (fs.existsSync(PID_FILE)) {
            fs.unlinkSync(PID_FILE)
        }
    } catch (err) {
        console.error('Error cleaning up PID file:', err.message)
    }
}

// Main cleanup function
async function performCleanup() {
    try {
        logMessage('Starting cleanup of orphaned instances...')
        const result = await cleanupOrphanedInstances(MAX_INSTANCE_AGE_HOURS)
        logMessage(`Cleanup completed. Removed ${result.cleaned} orphaned instance(s)`)
        return result
    } catch (err) {
        logMessage(`Cleanup error: ${err.message}`)
        throw err
    }
}

// Start cleanup service
function startCleanupService() {
    ensureLogFile()

    if (isAnotherInstanceRunning()) {
        logMessage('Another cleanup service instance is already running')
        process.exit(1)
    }

    writePidFile()
    logMessage(`Cleanup service started with PID ${process.pid}`)

    // Perform initial cleanup
    performCleanup().catch(err => {
        logMessage(`Initial cleanup failed: ${err.message}`)
    })

    // Set up interval for periodic cleanup
    const intervalMs = CLEANUP_INTERVAL_HOURS * 60 * 60 * 1000
    const interval = setInterval(() => {
        performCleanup().catch(err => {
            logMessage(`Scheduled cleanup failed: ${err.message}`)
        })
    }, intervalMs)

    // Handle process termination
    process.on('SIGINT', () => {
        clearInterval(interval)
        cleanupPidFile()
        logMessage('Cleanup service stopped')
        process.exit(0)
    })

    process.on('SIGTERM', () => {
        clearInterval(interval)
        cleanupPidFile()
        logMessage('Cleanup service stopped')
        process.exit(0)
    })

    process.on('uncaughtException', (err) => {
        logMessage(`Uncaught exception: ${err.message}`)
        cleanupPidFile()
        process.exit(1)
    })

    return interval
}

// Export for external use
module.exports = {
    startCleanupService,
    performCleanup,
    CLEANUP_INTERVAL_HOURS,
    MAX_INSTANCE_AGE_HOURS
}

// Start service if run directly
if (require.main === module) {
    startCleanupService()
}