// master-server.js - Centralized Master Server for WhatsApp Instance Management
// Handles all worker instances and provides unified API endpoints

require('dotenv').config();

const express = require('express');
const http = require('http');
const path = require('path');
const fs = require('fs');
const { spawn } = require('child_process');
const { v4: uuidv4 } = require('uuid');
const { WorkerManager } = require('./src/worker-manager');
const { InstanceDatabase } = require('./src/instance-database');
const { HealthMonitor } = require('./src/health-monitor');
const { logDebug, logInfo, logWarn, logError, logCritical } = require('./src/utils/logger');

// Load configuration
const PORT = process.env.PORT || 3001;
const LOG_FILE = path.join(__dirname, 'master-server.log');

// Initialize core components
const instanceDb = new InstanceDatabase();
const workerManager = new WorkerManager(instanceDb);
const healthMonitor = new HealthMonitor(instanceDb, workerManager);

// Create Express app
const app = express();
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// CORS configuration - use ALLOWED_ORIGINS env var (comma-separated list)
const ALLOWED_ORIGINS = process.env.ALLOWED_ORIGINS?.split(',').map(o => o.trim()).filter(o => o) || [];
app.use((req, res, next) => {
    const origin = req.headers.origin;
    // Check if origin is in allowed list (or if ALLOWED_ORIGINS is empty, deny all)
    if (ALLOWED_ORIGINS.length > 0 && origin && ALLOWED_ORIGINS.includes(origin)) {
        res.setHeader('Access-Control-Allow-Origin', origin);
    } else if (ALLOWED_ORIGINS.length === 0) {
        // If no ALLOWED_ORIGINS set, deny all cross-origin requests
        res.setHeader('Access-Control-Allow-Origin', 'null');
    } else {
        // Origin not allowed
        return res.status(403).json({ error: 'Origin not allowed' });
    }
    res.setHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key');
    if (req.method === 'OPTIONS') {
        return res.sendStatus(200);
    }
    next();
});

// Legacy logging utility - now also writes to centralized logs
function log(...args) {
    const timestamp = new Date().toISOString();
    const message = args.map(arg => 
        typeof arg === 'string' ? arg : JSON.stringify(arg)
    ).join(' ');
    const fullMessage = `[${timestamp}] ${message}`;
    console.log(fullMessage);
    
    // Write to legacy log file
    fs.appendFileSync(LOG_FILE, fullMessage + '\n', { encoding: 'utf8' });
    
    // Also write to centralized logging
    logDebug(message, { component: 'master-server', type: 'general' });
}

// Health check endpoint
app.get('/health', (req, res) => {
    res.json({
        service: 'Master Server',
        status: 'healthy',
        timestamp: new Date().toISOString(),
        port: PORT,
        instances: workerManager.getInstanceCount(),
        workers: workerManager.getWorkerStatuses()
    });
});

// Get all instances
app.get('/api/instances', async (req, res) => {
    try {
        const instances = await instanceDb.getAllInstances();
        res.json({
            ok: true,
            instances: instances.map(instance => ({
                ...instance,
                status: workerManager.getInstanceStatus(instance.instance_id),
                health: healthMonitor.getInstanceHealth(instance.instance_id)
            }))
        });
    } catch (error) {
        log('Error fetching instances:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Create new instance
app.post('/api/instances', async (req, res) => {
    try {
        const { name, ai_settings, whatsapp_settings, alarms } = req.body;
        
        if (!name) {
            return res.status(400).json({ ok: false, error: 'Name is required' });
        }

        const instanceId = uuidv4();
        const instanceCount = await instanceDb.getInstanceCount();
        const port = 3010 + Math.max(0, instanceCount);
        
        const newInstance = {
            instance_id: instanceId,
            name,
            port,
            ai_settings: ai_settings || {},
            whatsapp_settings: whatsapp_settings || {},
            alarms: alarms || {},
            status: 'pending',
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString()
        };

        await instanceDb.saveInstance(newInstance);
        await workerManager.startInstance(instanceId, port, newInstance);

        res.json({
            ok: true,
            instance: {
                ...newInstance,
                status: 'starting'
            }
        });
    } catch (error) {
        log('Error creating instance:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Get instance details
app.get('/api/instances/:id', async (req, res) => {
    try {
        const instance = await instanceDb.getInstance(req.params.id);
        if (!instance) {
            return res.status(404).json({ ok: false, error: 'Instance not found' });
        }

        res.json({
            ok: true,
            instance: {
                ...instance,
                status: workerManager.getInstanceStatus(instance.instance_id),
                health: healthMonitor.getInstanceHealth(instance.instance_id)
            }
        });
    } catch (error) {
        log('Error fetching instance:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Update instance settings
app.put('/api/instances/:id', async (req, res) => {
    try {
        const { ai_settings, whatsapp_settings, alarms } = req.body;
        const instance = await instanceDb.getInstance(req.params.id);
        
        if (!instance) {
            return res.status(404).json({ ok: false, error: 'Instance not found' });
        }

        const updates = {
            ai_settings: ai_settings || instance.ai_settings,
            whatsapp_settings: whatsapp_settings || instance.whatsapp_settings,
            alarms: alarms || instance.alarms,
            updated_at: new Date().toISOString()
        };

        await instanceDb.updateInstance(req.params.id, updates);
        await workerManager.updateInstanceSettings(req.params.id, updates);

        res.json({
            ok: true,
            instance: {
                ...instance,
                ...updates,
                status: workerManager.getInstanceStatus(instance.instance_id)
            }
        });
    } catch (error) {
        log('Error updating instance:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Delete instance
app.delete('/api/instances/:id', async (req, res) => {
    try {
        const instance = await instanceDb.getInstance(req.params.id);
        if (!instance) {
            return res.status(404).json({ ok: false, error: 'Instance not found' });
        }

        await workerManager.stopInstance(instance.instance_id);
        await instanceDb.deleteInstance(instance.instance_id);

        res.json({ ok: true, message: 'Instance deleted successfully' });
    } catch (error) {
        log('Error deleting instance:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Start instance
app.post('/api/instances/:id/start', async (req, res) => {
    try {
        const instance = await instanceDb.getInstance(req.params.id);
        if (!instance) {
            return res.status(404).json({ ok: false, error: 'Instance not found' });
        }

        await workerManager.startInstance(instance.instance_id, instance.port, instance);
        res.json({ ok: true, message: 'Instance started successfully' });
    } catch (error) {
        log('Error starting instance:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Stop instance
app.post('/api/instances/:id/stop', async (req, res) => {
    try {
        const instance = await instanceDb.getInstance(req.params.id);
        if (!instance) {
            return res.status(404).json({ ok: false, error: 'Instance not found' });
        }

        await workerManager.stopInstance(instance.instance_id);
        res.json({ ok: true, message: 'Instance stopped successfully' });
    } catch (error) {
        log('Error stopping instance:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Restart instance
app.post('/api/instances/:id/restart', async (req, res) => {
    try {
        const instance = await instanceDb.getInstance(req.params.id);
        if (!instance) {
            return res.status(404).json({ ok: false, error: 'Instance not found' });
        }

        await workerManager.restartInstance(instance.instance_id);
        res.json({ ok: true, message: 'Instance restarted successfully' });
    } catch (error) {
        log('Error restarting instance:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Get instance logs
app.get('/api/instances/:id/logs', async (req, res) => {
    try {
        const instance = await instanceDb.getInstance(req.params.id);
        if (!instance) {
            return res.status(404).json({ ok: false, error: 'Instance not found' });
        }

        const logPath = path.join(__dirname, 'instance_' + instance.instance_id + '.log');
        if (!fs.existsSync(logPath)) {
            return res.json({ ok: true, logs: 'No logs available' });
        }

        const logs = fs.readFileSync(logPath, 'utf8');
        res.json({ ok: true, logs });
    } catch (error) {
        log('Error fetching instance logs:', error);
        res.status(500).json({ ok: false, error: error.message });
    }
});

// Meta API proxy (for backward compatibility)
app.use('/api/meta', require('./master-meta'));

// Start the master server
const server = http.createServer(app);

/**
 * Periodically scan for rogue node processes that look like workers
 * but are not managed by this master instance.
 */
function scanAndKillGhostWorkers() {
    const { exec } = require('child_process');
    log('[VIGILANTE] Iniciando varredura de processos fantasmas...');
    
    // Lista processos node com --id=inst_
    exec('ps aux | grep "node" | grep "--id=inst_" | grep -v grep', (err, stdout) => {
        if (err || !stdout) return;
        
        const lines = stdout.split('\n').filter(Boolean);
        const managedPids = new Set();
        
        // Coleta PIDs gerenciados
        for (const worker of workerManager.workers.values()) {
            if (worker.process && worker.process.pid) {
                managedPids.add(worker.process.pid);
            }
        }
        
        lines.forEach(line => {
            const parts = line.trim().split(/\s+/);
            const pid = parseInt(parts[1]);
            const cmdLine = line.split('node')[1] || '';
            
            if (pid && !managedPids.has(pid) && pid !== process.pid) {
                log(`[VIGILANTE] Detectado processo GHOST! PID: ${pid} | Cmd: ${cmdLine}`);
                try {
                    process.kill(pid, 'SIGKILL');
                    log(`[VIGILANTE] Processo fantasma ${pid} ELIMINADO.`);
                } catch (e) {
                    log(`[VIGILANTE] Erro ao eliminar ghost ${pid}: ${e.message}`);
                }
            }
        });
    });
}

server.listen(PORT, () => {
    log('Master server started on port ' + PORT);
    
    // Initialize health monitoring
    healthMonitor.startMonitoring();
    
    // Inicia vigilante a cada 2 minutos
    setInterval(scanAndKillGhostWorkers, 120000);
    setTimeout(scanAndKillGhostWorkers, 10000); // Primeira varredura após 10s
    
    // Load existing instances
    instanceDb.loadAllInstances().then(instances => {
        log('Loaded ' + instances.length + ' instances from database');
        instances.forEach(instance => {
            log('Instance: ' + instance.instance_id + ', status: ' + instance.status + ', port: ' + instance.port);
            // Start worker if port is defined (regardless of status)
            if (instance.port && !isNaN(parseInt(instance.port))) {
                log('Starting worker for instance ' + instance.instance_id + ' on port ' + instance.port);
                workerManager.startInstance(instance.instance_id, parseInt(instance.port), instance);
            }
        });
    });
});

// Graceful shutdown
process.on('SIGTERM', () => {
    log('SIGTERM received, shutting down gracefully...');
    workerManager.stopAllInstances();
    server.close(() => {
        log('Master server closed');
        process.exit(0);
    });
});

process.on('SIGINT', () => {
    log('SIGINT received, shutting down gracefully...');
    workerManager.stopAllInstances();
    server.close(() => {
        log('Master server closed');
        process.exit(0);
    });
});
