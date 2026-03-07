// src/worker-manager.js - Worker Instance Manager
// Manages worker processes for WhatsApp instances

const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const http = require('http');
const { logDebug, logInfo, logWarn, logError, logCritical } = require('./utils/logger');

const LOG_FILE = path.join(__dirname, '..', 'worker-manager.log');

function log(...args) {
    const timestamp = new Date().toISOString();
    const message = '[' + timestamp + '] ' + args.map(arg => 
        typeof arg === 'string' ? arg : JSON.stringify(arg)
    ).join(' ');
    console.log(message);
    fs.appendFileSync(LOG_FILE, message + '\n', { encoding: 'utf8' });
    
    // Also write to centralized logging
    const logMessage = args.map(arg => 
        typeof arg === 'string' ? arg : JSON.stringify(arg)
    ).join(' ');
    logDebug(logMessage, { component: 'worker-manager', type: 'general' });
}

class WorkerManager {
    constructor(instanceDatabase) {
        this.instanceDb = instanceDatabase;
        this.workers = new Map();
        this.startingWorkers = new Map();
        this.serverPath = path.join(__dirname, 'index.js');
    }

    async killProcessOnPort(port) {
        return new Promise((resolve) => {
            const { exec } = require('child_process');
            log(`Cleaning up any process on port ${port}...`);
            // Find and kill process on port using fuser (linux)
            exec(`fuser -k ${port}/tcp`, (err, stdout, stderr) => {
                if (err) {
                    // fuser returns non-zero if no process found, which is fine
                    resolve(true);
                } else {
                    log(`Port ${port} cleared.`);
                    resolve(true);
                }
            });
        });
    }

    async startInstance(instanceId, port, config) {
        if (this.workers.has(instanceId)) {
            log('Instance ' + instanceId + ' already running');
            return { ok: true, message: 'Already running' };
        }

        // AGGRESSIVE CLEANUP BEFORE START
        await this.killProcessOnPort(port);
        await new Promise(resolve => setTimeout(resolve, 1000));

        if (this.startingWorkers.has(instanceId)) {
            log('Instance ' + instanceId + ' is already starting, waiting existing startup');
            return this.startingWorkers.get(instanceId);
        }

        const startupPromise = (async () => {
            log('Starting worker for instance ' + instanceId + ' on port ' + port);

            const authDir = path.join(__dirname, '..', 'auth_' + instanceId);
            if (!fs.existsSync(authDir)) {
                fs.mkdirSync(authDir, { recursive: true });
            }

            const logPath = path.join(__dirname, '..', 'instance_' + instanceId + '.log');
            
            const workerProcess = spawn('node', [
                this.serverPath,
                '--id=' + instanceId,
                '--port=' + port
            ], {
                cwd: path.join(__dirname, '..'),
                stdio: ['ignore', 'pipe', 'pipe'],
                env: {
                    ...process.env,
                    INSTANCE_ID: instanceId,
                    INSTANCE_PORT: port,
                    INSTANCE_LOG_PATH: logPath
                }
            });

            workerProcess.stdout.on('data', (data) => {
                fs.appendFileSync(logPath, data.toString(), { encoding: 'utf8' });
            });

            workerProcess.stderr.on('data', (data) => {
                fs.appendFileSync(logPath, '[ERROR] ' + data.toString(), { encoding: 'utf8' });
            });

            workerProcess.on('exit', (code) => {
                log('Worker ' + instanceId + ' exited with code ' + code);
                this.workers.delete(instanceId);
                this.instanceDb.updateInstanceStatus(instanceId, 'stopped');
            });

            workerProcess.on('error', (err) => {
                log('Worker ' + instanceId + ' error: ' + err.message);
                this.workers.delete(instanceId);
                this.instanceDb.updateInstanceStatus(instanceId, 'error');
            });

            try {
                await this.waitForWorker(instanceId, port);
            } catch (err) {
                const processAlive = workerProcess.exitCode === null && !workerProcess.killed;
                if (processAlive) {
                    log('Health check failed for ' + instanceId + ', but worker process is alive. Continuing startup.');
                } else {
                    throw err;
                }
            }

            this.workers.set(instanceId, {
                process: workerProcess,
                port: port,
                status: 'running',
                config: config,
                startedAt: Date.now()
            });

            await this.instanceDb.updateInstanceStatus(instanceId, 'running');

            log('Worker started for instance ' + instanceId);
            return { ok: true, message: 'Worker started successfully' };
        })()
        .finally(() => {
            this.startingWorkers.delete(instanceId);
        });

        this.startingWorkers.set(instanceId, startupPromise);
        return startupPromise;
    }

    async stopInstance(instanceId) {
        const worker = this.workers.get(instanceId);
        if (!worker) {
            log('No worker found for instance ' + instanceId);
            return { ok: false, error: 'Worker not found' };
        }

        log('Stopping worker for instance ' + instanceId);

        worker.process.kill('SIGTERM');

        await new Promise((resolve) => {
            setTimeout(() => {
                if (this.workers.has(instanceId)) {
                    try {
                        worker.process.kill('SIGKILL');
                    } catch (e) {}
                }
                resolve();
            }, 5000);
        });

        this.workers.delete(instanceId);
        await this.instanceDb.updateInstanceStatus(instanceId, 'stopped');

        log('Worker stopped for instance ' + instanceId);
        return { ok: true, message: 'Worker stopped successfully' };
    }

    async restartInstance(instanceId) {
        log('Restarting instance ' + instanceId);
        
        const config = await this.instanceDb.getInstance(instanceId);
        if (!config) {
            return { ok: false, error: 'Instance not found' };
        }

        await this.stopInstance(instanceId);
        await new Promise(resolve => setTimeout(resolve, 2000));
        return await this.startInstance(instanceId, config.port, config);
    }

    async updateInstanceSettings(instanceId, settings) {
        const worker = this.workers.get(instanceId);
        if (worker) {
            worker.config = { ...worker.config, ...settings };
            log('Updated settings for instance ' + instanceId);
        }
        return { ok: true };
    }

    getInstanceStatus(instanceId) {
        const worker = this.workers.get(instanceId);
        if (!worker) {
            return 'stopped';
        }
        return worker.status;
    }

    getWorkerStatuses() {
        const statuses = {};
        for (const [id, worker] of this.workers) {
            statuses[id] = {
                port: worker.port,
                status: worker.status,
                uptime: Date.now() - worker.startedAt
            };
        }
        return statuses;
    }

    getInstanceCount() {
        return this.workers.size;
    }

    async stopAllInstances() {
        log('Stopping all workers...');
        const stopPromises = [];
        for (const instanceId of this.workers.keys()) {
            stopPromises.push(this.stopInstance(instanceId));
        }
        await Promise.all(stopPromises);
        log('All workers stopped');
    }

    waitForWorker(instanceId, port, maxAttempts = 30) {
        return new Promise((resolve, reject) => {
            let attempts = 0;
            let settled = false;
            let retryTimer = null;

            const finishResolve = () => {
                if (settled) return;
                settled = true;
                if (retryTimer) {
                    clearTimeout(retryTimer);
                    retryTimer = null;
                }
                resolve();
            };

            const finishReject = (error) => {
                if (settled) return;
                settled = true;
                if (retryTimer) {
                    clearTimeout(retryTimer);
                    retryTimer = null;
                }
                reject(error);
            };

            const scheduleRetry = () => {
                if (settled) return;
                if (attempts < maxAttempts) {
                    retryTimer = setTimeout(checkHealth, 1000);
                } else {
                    finishReject(new Error('Worker failed to start after ' + maxAttempts + ' attempts'));
                }
            };
            
            const checkHealth = () => {
                if (settled) return;
                attempts++;
                
                const req = http.get('http://127.0.0.1:' + port + '/health', (res) => {
                    if (settled) return;
                    res.resume();
                    if (res.statusCode === 200) {
                        log('Worker ' + instanceId + ' is ready after ' + attempts + ' attempts');
                        finishResolve();
                    } else {
                        scheduleRetry();
                    }
                });
                
                req.on('error', () => {
                    scheduleRetry();
                });

                req.setTimeout(2000, () => {
                    req.destroy(new Error('Health check timeout'));
                });
            };
            
            retryTimer = setTimeout(checkHealth, 3000);
        });
    }

    isPortAvailable(port) {
        return new Promise((resolve) => {
            const server = http.createServer();
            server.listen(port, () => {
                server.close(() => resolve(true));
            });
            server.on('error', () => resolve(false));
        });
    }

    async findAvailablePort(startPort = 3010) {
        let port = startPort;
        while (!(await this.isPortAvailable(port))) {
            port++;
        }
        return port;
    }
}

module.exports = { WorkerManager };
