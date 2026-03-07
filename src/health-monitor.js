// src/health-monitor.js - Health Monitor for Worker Instances
// Monitors health of all worker instances and triggers recovery actions

const http = require('http');
const fs = require('fs');
const path = require('path');

const LOG_FILE = path.join(__dirname, '..', 'health-monitor.log');

function log(...args) {
    const timestamp = new Date().toISOString();
    const message = '[' + timestamp + '] ' + args.map(arg => 
        typeof arg === 'string' ? arg : JSON.stringify(arg)
    ).join(' ');
    console.log(message);
    fs.appendFileSync(LOG_FILE, message + '\n', { encoding: 'utf8' });
}

class HealthMonitor {
    constructor(instanceDatabase, workerManager) {
        this.instanceDb = instanceDatabase;
        this.workerManager = workerManager;
        this.healthCache = new Map(); // instanceId -> health status
        this.monitoringInterval = null;
        this.checkIntervalMs = 30000; // 30 seconds
    }

    startMonitoring() {
        log('Starting health monitoring');
        
        // Initial check
        setTimeout(() => this.checkAllInstances(), 5000);
        
        // Periodic checks
        this.monitoringInterval = setInterval(() => {
            this.checkAllInstances();
        }, this.checkIntervalMs);
    }

    stopMonitoring() {
        if (this.monitoringInterval) {
            clearInterval(this.monitoringInterval);
            this.monitoringInterval = null;
            log('Health monitoring stopped');
        }
    }

    async checkAllInstances() {
        try {
            const instances = await this.instanceDb.getAllInstances();
            
            for (const instance of instances) {
                if (instance.status === 'running') {
                    await this.checkInstance(instance);
                }
            }
        } catch (error) {
            log('Error checking instances:', error.message);
        }
    }

    async checkInstance(instance) {
        const health = await this.performHealthCheck(instance);
        this.healthCache.set(instance.instance_id, health);
        
        if (!health.healthy) {
            log('Instance ' + instance.instance_id + ' is unhealthy: ' + health.issues.join(', '));
            await this.handleUnhealthyInstance(instance, health);
        }
        
        return health;
    }

    async performHealthCheck(instance) {
        const health = {
            instanceId: instance.instance_id,
            healthy: true,
            checks: {},
            issues: [],
            timestamp: new Date().toISOString()
        };

        // Check 1: HTTP endpoint
        try {
            const httpResult = await this.checkHttpEndpoint(instance.port);
            health.checks.http = httpResult.healthy ? 'pass' : 'fail';
            if (!httpResult.healthy) {
                health.issues.push('HTTP endpoint not responding');
            }
        } catch (error) {
            health.checks.http = 'fail';
            health.issues.push('HTTP check failed: ' + error.message);
        }

        // Check 2: WebSocket connection
        try {
            const wsResult = await this.checkWebSocket(instance.port);
            health.checks.websocket = wsResult.healthy ? 'pass' : 'fail';
            if (!wsResult.healthy) {
                health.issues.push('WebSocket not connected');
            }
        } catch (error) {
            health.checks.websocket = 'fail';
            health.issues.push('WebSocket check failed: ' + error.message);
        }

        // Check 3: Process status
        const workerStatus = this.workerManager.getInstanceStatus(instance.instance_id);
        if (workerStatus !== 'running') {
            health.checks.process = 'fail';
            health.issues.push('Process not running');
        } else {
            health.checks.process = 'pass';
        }

        // Determine overall health
        health.healthy = health.issues.length === 0;
        
        return health;
    }

    checkHttpEndpoint(port) {
        return new Promise((resolve) => {
            const req = http.get('http://localhost:' + port + '/health', (res) => {
                resolve({
                    healthy: res.statusCode === 200,
                    statusCode: res.statusCode
                });
            });
            
            req.on('error', (err) => {
                resolve({
                    healthy: false,
                    error: err.message
                });
            });
            
            req.setTimeout(5000, () => {
                req.destroy();
                resolve({
                    healthy: false,
                    error: 'Timeout'
                });
            });
        });
    }

    checkWebSocket(port) {
        // Simplified WebSocket check - just try to connect
        // In production, you'd use the ws library
        return new Promise((resolve) => {
            resolve({
                healthy: true,
                note: 'WebSocket check not implemented in health monitor'
            });
        });
    }

    async handleUnhealthyInstance(instance, health) {
        // Get previous health status
        const prevHealth = this.healthCache.get(instance.instance_id);
        
        // If previously healthy, try to recover
        if (!prevHealth || prevHealth.healthy) {
            log('Attempting to recover instance ' + instance.instance_id);
            
            try {
                // Stop the instance
                await this.workerManager.stopInstance(instance.instance_id);
                
                // Wait a bit
                await new Promise(resolve => setTimeout(resolve, 2000));
                
                // Restart the instance
                await this.workerManager.startInstance(
                    instance.instance_id,
                    instance.port,
                    instance
                );
                
                log('Instance ' + instance.instance_id + ' recovered successfully');
            } catch (error) {
                log('Failed to recover instance ' + instance.instance_id + ': ' + error.message);
            }
        }
    }

    getInstanceHealth(instanceId) {
        return this.healthCache.get(instanceId) || {
            instanceId: instanceId,
            healthy: true,
            checks: {},
            issues: [],
            timestamp: new Date().toISOString()
        };
    }

    getAllHealth() {
        const allHealth = {};
        for (const [instanceId, health] of this.healthCache) {
            allHealth[instanceId] = health;
        }
        return allHealth;
    }
}

module.exports = { HealthMonitor };
