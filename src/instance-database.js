// src/instance-database.js - Instance Database Manager
// Manages instance metadata in SQLite database
// Supports both master_instances (new) and instances (legacy) tables

const sqlite3 = require('sqlite3').verbose();
const path = require('path');
const fs = require('fs');

const DB_PATH = path.join(__dirname, '..', 'api', 'envio', 'wpp', 'chat_data.db');

const LOG_FILE = path.join(__dirname, '..', 'instance-database.log');

function log(...args) {
    const timestamp = new Date().toISOString();
    const message = '[' + timestamp + '] ' + args.map(arg => 
        typeof arg === 'string' ? arg : JSON.stringify(arg)
    ).join(' ');
    console.log(message);
    fs.appendFileSync(LOG_FILE, message + '\n', { encoding: 'utf8' });
}

class InstanceDatabase {
    constructor() {
        this.db = null;
        this.initialized = false;
    }

    async initialize() {
        if (this.initialized) return;
        
        return new Promise((resolve, reject) => {
            this.db = new sqlite3.Database(DB_PATH, (err) => {
                if (err) {
                    log('Error opening database:', err.message);
                    reject(err);
                } else {
                    log('Connected to database');
                    this.createTables().then(() => {
                        this.initialized = true;
                        resolve();
                    }).catch(reject);
                }
            });
        });
    }

    createTables() {
        return new Promise((resolve, reject) => {
            const sql = `
                CREATE TABLE IF NOT EXISTS master_instances (
                    instance_id TEXT PRIMARY KEY,
                    name TEXT,
                    port INTEGER,
                    status TEXT DEFAULT 'pending',
                    ai_settings TEXT,
                    whatsapp_settings TEXT,
                    alarms TEXT,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            `;
            
            this.db.run(sql, (err) => {
                if (err) {
                    log('Error creating tables:', err.message);
                    reject(err);
                } else {
                    log('Tables created/verified');
                    resolve();
                }
            });
        });
    }

    async loadAllInstances() {
        await this.initialize();
        
        // First, try to load from master_instances table
        const masterInstances = await this.loadFromMasterTable();
        
        // Also load from legacy instances table and merge
        const legacyInstances = await this.loadFromLegacyTable();
        
        // Merge, preferring master_instances data
        const merged = [...masterInstances];
        const existingIds = new Set(masterInstances.map(i => i.instance_id));
        
        for (const inst of legacyInstances) {
            if (!existingIds.has(inst.instance_id)) {
                merged.push(inst);
            }
        }
        
        log('Loaded ' + merged.length + ' total instances');
        return merged;
    }

    loadFromMasterTable() {
        return new Promise((resolve, reject) => {
            this.db.all('SELECT * FROM master_instances', [], (err, rows) => {
                if (err) {
                    log('Error loading master instances:', err.message);
                    resolve([]);
                } else {
                    const instances = rows.map(row => ({
                        ...row,
                        ai_settings: row.ai_settings ? JSON.parse(row.ai_settings) : {},
                        whatsapp_settings: row.whatsapp_settings ? JSON.parse(row.whatsapp_settings) : {},
                        alarms: row.alarms ? JSON.parse(row.alarms) : {}
                    }));
                    resolve(instances);
                }
            });
        });
    }

    loadFromLegacyTable() {
        return new Promise((resolve, reject) => {
            // Check if instances table exists
            this.db.get("SELECT name FROM sqlite_master WHERE type='table' AND name='instances'", [], (err, row) => {
                if (err || !row) {
                    resolve([]);
                    return;
                }
                
                this.db.all('SELECT * FROM instances', [], (err, rows) => {
                    if (err) {
                        log('Error loading legacy instances:', err.message);
                        resolve([]);
                    } else {
                        const instances = rows.map(row => ({
                            instance_id: row.instance_id,
                            name: row.name || row.instance_id,
                            port: row.port || 3010,
                            status: row.status || row.connection_status || 'unknown',
                            ai_settings: {},
                            whatsapp_settings: {},
                            alarms: {},
                            created_at: row.created_at || new Date().toISOString(),
                            updated_at: row.updated_at || new Date().toISOString(),
                            _is_legacy: true
                        }));
                        resolve(instances);
                    }
                });
            });
        });
    }

    async getAllInstances() {
        return await this.loadAllInstances();
    }

    async getInstance(instanceId) {
        await this.initialize();
        
        // Try master_instances first
        let instance = await this.getFromMasterTable(instanceId);
        
        // If not found, try legacy
        if (!instance) {
            instance = await this.getFromLegacyTable(instanceId);
        }
        
        return instance;
    }

    getFromMasterTable(instanceId) {
        return new Promise((resolve, reject) => {
            this.db.get('SELECT * FROM master_instances WHERE instance_id = ?', [instanceId], (err, row) => {
                if (err) {
                    resolve(null);
                } else if (!row) {
                    resolve(null);
                } else {
                    resolve({
                        ...row,
                        ai_settings: row.ai_settings ? JSON.parse(row.ai_settings) : {},
                        whatsapp_settings: row.whatsapp_settings ? JSON.parse(row.whatsapp_settings) : {},
                        alarms: row.alarms ? JSON.parse(row.alarms) : {}
                    });
                }
            });
        });
    }

    getFromLegacyTable(instanceId) {
        return new Promise((resolve, reject) => {
            this.db.get('SELECT * FROM instances WHERE instance_id = ?', [instanceId], (err, row) => {
                if (err || !row) {
                    resolve(null);
                } else {
                    resolve({
                        instance_id: row.instance_id,
                        name: row.name || row.instance_id,
                        port: row.port || 3010,
                        status: row.connection_status || row.status || 'unknown',
                        ai_settings: {},
                        whatsapp_settings: {},
                        alarms: {},
                        created_at: row.created_at,
                        updated_at: row.updated_at,
                        _is_legacy: true
                    });
                }
            });
        });
    }

    async saveInstance(instance) {
        await this.initialize();
        
        return new Promise((resolve, reject) => {
            const sql = `
                INSERT INTO master_instances (instance_id, name, port, status, ai_settings, whatsapp_settings, alarms, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            `;
            
            this.db.run(sql, [
                instance.instance_id,
                instance.name,
                instance.port,
                instance.status || 'pending',
                JSON.stringify(instance.ai_settings || {}),
                JSON.stringify(instance.whatsapp_settings || {}),
                JSON.stringify(instance.alarms || {}),
                instance.created_at || new Date().toISOString(),
                instance.updated_at || new Date().toISOString()
            ], (err) => {
                if (err) {
                    log('Error saving instance:', err.message);
                    reject(err);
                } else {
                    log('Instance saved: ' + instance.instance_id);
                    resolve();
                }
            });
        });
    }

    async updateInstance(instanceId, updates) {
        await this.initialize();
        
        return new Promise((resolve, reject) => {
            const fields = [];
            const values = [];
            
            if (updates.name !== undefined) {
                fields.push('name = ?');
                values.push(updates.name);
            }
            if (updates.port !== undefined) {
                fields.push('port = ?');
                values.push(updates.port);
            }
            if (updates.status !== undefined) {
                fields.push('status = ?');
                values.push(updates.status);
            }
            if (updates.ai_settings !== undefined) {
                fields.push('ai_settings = ?');
                values.push(JSON.stringify(updates.ai_settings));
            }
            if (updates.whatsapp_settings !== undefined) {
                fields.push('whatsapp_settings = ?');
                values.push(JSON.stringify(updates.whatsapp_settings));
            }
            if (updates.alarms !== undefined) {
                fields.push('alarms = ?');
                values.push(JSON.stringify(updates.alarms));
            }
            
            fields.push('updated_at = ?');
            values.push(new Date().toISOString());
            
            values.push(instanceId);
            
            const sql = 'UPDATE master_instances SET ' + fields.join(', ') + ' WHERE instance_id = ?';
            
            this.db.run(sql, values, (err) => {
                if (err) {
                    log('Error updating instance:', err.message);
                    reject(err);
                } else {
                    log('Instance updated: ' + instanceId);
                    resolve();
                }
            });
        });
    }

    async updateInstanceStatus(instanceId, status) {
        await this.initialize();
        
        // Try updating master_instances first
        return new Promise((resolve) => {
            const sql = 'UPDATE master_instances SET status = ?, updated_at = ? WHERE instance_id = ?';
            this.db.run(sql, [status, new Date().toISOString(), instanceId], (err) => {
                if (err) {
                    log('Error updating master instance status:', err.message);
                }
                resolve();
            });
        });
    }

    async deleteInstance(instanceId) {
        await this.initialize();
        
        return new Promise((resolve, reject) => {
            this.db.run('DELETE FROM master_instances WHERE instance_id = ?', [instanceId], (err) => {
                if (err) {
                    log('Error deleting instance:', err.message);
                    reject(err);
                } else {
                    log('Instance deleted: ' + instanceId);
                    resolve();
                }
            });
        });
    }

    async getInstanceCount() {
        await this.initialize();
        
        return new Promise((resolve, reject) => {
            // Count from both tables
            this.db.get('SELECT (SELECT COUNT(*) FROM master_instances) + (SELECT COUNT(*) FROM instances WHERE instance_id NOT IN (SELECT instance_id FROM master_instances)) as total', [], (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row ? row.total : 0);
                }
            });
        });
    }
}

module.exports = { InstanceDatabase };
