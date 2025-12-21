// db.js - SQLite database module for intelligent chat system
const sqlite3 = require('sqlite3').verbose()
const fs = require('fs')
const path = require("path")

// Database path - isolated from main project
const DB_PATH = path.join(__dirname, 'chat_data.db')
const SETTINGS_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS settings (
        instance_id TEXT NOT NULL DEFAULT '',
        key TEXT NOT NULL,
        value TEXT,
        PRIMARY KEY (instance_id, key)
    )
`
const PERSISTENT_VARIABLES_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS persistent_variables (
        instance_id TEXT NOT NULL,
        key TEXT NOT NULL,
        value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (instance_id, key)
    )
`
const INSTANCES_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS instances (
        instance_id TEXT PRIMARY KEY,
        name TEXT,
        port INTEGER,
        api_key TEXT,
        status TEXT,
        connection_status TEXT,
        base_url TEXT,
        phone TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
`

// Initialize database
function initDatabase() {
    return new Promise((resolve, reject) => {
        const db = new sqlite3.Database(DB_PATH, (err) => {
            if (err) {
                console.error('Error opening database:', err.message)
                reject(err)
            } else {
                console.log('Connected to SQLite chat database')
                createTables(db)
                    .then(() => ensureSettingsSchema(db))
                    .then(() => ensureInstancesSchema(db))
                    .then(() => ensureDirectionColumn(db))
                    .then(() => ensureMetadataColumn(db))
                    .then(() => ensureScheduledSchema(db))
                    .then(() => ensureContactContextSchema(db))
                    .then(() => ensureEventLogsSchema(db))
                    .then(() => ensurePersistentVariablesSchema(db))
                    .then(() => resolve(db))
                    .catch(reject)
            }
        })
    })
}

// Create required tables according to specifications
function createTables(db) {
    return new Promise((resolve, reject) => {
        const messagesSQL = `
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                role TEXT NOT NULL CHECK(role IN ('user', 'assistant')),
                content TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `

        const threadsSQL = `
            CREATE TABLE IF NOT EXISTS threads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                thread_id TEXT NOT NULL,
                last_message_id TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(instance_id, remote_jid)
            )
        `

const contactMetadataSQL = `
            CREATE TABLE IF NOT EXISTS contact_metadata (
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                contact_name TEXT,
                status_name TEXT,
                profile_picture TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (instance_id, remote_jid)
            )
        `

        const scheduledSQL = `
            CREATE TABLE IF NOT EXISTS scheduled_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                message TEXT NOT NULL,
                scheduled_at TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending','sent','failed')) DEFAULT 'pending',
                last_attempt_at TEXT,
                error TEXT,
                is_paused INTEGER NOT NULL DEFAULT 0,
                tag TEXT NOT NULL DEFAULT 'default',
                tipo TEXT NOT NULL DEFAULT 'followup',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `
        const whatsappCacheSQL = `
            CREATE TABLE IF NOT EXISTS whatsapp_number_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT NOT NULL UNIQUE,
                is_whatsapp INTEGER NOT NULL,
                checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `

        const contactContextSQL = `
            CREATE TABLE IF NOT EXISTS contact_context (
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (instance_id, remote_jid, key)
            )
        `

        const eventLogsSQL = `
            CREATE TABLE IF NOT EXISTS event_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT,
                category TEXT NOT NULL,
                description TEXT NOT NULL,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `

        const statements = [
            SETTINGS_TABLE_SQL,
            PERSISTENT_VARIABLES_TABLE_SQL,
            INSTANCES_TABLE_SQL,
            messagesSQL,
            threadsSQL,
            contactMetadataSQL,
            scheduledSQL,
            whatsappCacheSQL,
            contactContextSQL,
            eventLogsSQL
        ]

        const indexes = [
            'CREATE INDEX IF NOT EXISTS idx_messages_instance ON messages(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_messages_contact ON messages(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_messages_timestamp ON messages(timestamp)',
            'CREATE INDEX IF NOT EXISTS idx_messages_instance_contact ON messages(instance_id, remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_threads_instance_contact ON threads(instance_id, remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_contact_metadata_instance ON contact_metadata(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_contact_metadata_remote ON contact_metadata(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_scheduled_instance_status ON scheduled_messages(instance_id, status)',
            'CREATE INDEX IF NOT EXISTS idx_scheduled_due ON scheduled_messages(scheduled_at)',
            'CREATE INDEX IF NOT EXISTS idx_scheduled_campaign ON scheduled_messages(campaign_id)',
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_cache_phone ON whatsapp_number_cache(phone)',
            'CREATE INDEX IF NOT EXISTS idx_contact_context_instance ON contact_context(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_contact_context_remote ON contact_context(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_event_logs_instance ON event_logs(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_event_logs_remote ON event_logs(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_persistent_vars_instance ON persistent_variables(instance_id)'
        ]

        db.serialize(() => {
            const runStatement = (index = 0) => {
                if (index >= statements.length) {
                    runIndex(0)
                    return
                }
                db.run(statements[index], err => {
                    if (err) {
                        reject(err)
                        return
                    }
                    runStatement(index + 1)
                })
            }

            const runIndex = (index = 0) => {
                if (index >= indexes.length) {
                    console.log('Database tables and indexes created successfully')
                    resolve()
                    return
                }
                db.run(indexes[index], err => {
                    if (err) {
                        reject(err)
                        return
                    }
                    runIndex(index + 1)
                })
            }

            runStatement()
        })
    })
}

function ensureSettingsSchema(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(settings)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasInstanceCol = rows.some(row => row.name === 'instance_id')
            if (hasInstanceCol) {
                return resolve()
            }

            if (!rows.length) {
                db.run(SETTINGS_TABLE_SQL, (createErr) => {
                    if (createErr) reject(createErr)
                    else resolve()
                })
                return
            }

            db.serialize(() => {
                db.run(`DROP TABLE IF EXISTS settings_old`, () => {
                    db.run(`ALTER TABLE settings RENAME TO settings_old`, errRename => {
                        if (errRename) {
                            reject(errRename)
                            return
                        }
                        db.run(SETTINGS_TABLE_SQL, errCreate => {
                            if (errCreate) {
                                reject(errCreate)
                                return
                            }
                            db.run(`
                                INSERT INTO settings (instance_id, key, value)
                                SELECT '' as instance_id, key, value FROM settings_old
                            `, insertErr => {
                                if (insertErr) {
                                    reject(insertErr)
                                    return
                                }
                                db.run(`DROP TABLE settings_old`, dropErr => {
                                    if (dropErr) {
                                        reject(dropErr)
                                    } else {
                                        resolve()
                                    }
                                })
                            })
                        })
                    })
                })
            })
        })
    })
}

function ensureInstancesSchema(db) {
    return new Promise((resolve, reject) => {
        db.run(INSTANCES_TABLE_SQL, (err) => {
            if (err) {
                reject(err)
                return
            }
            resolve()
        })
    })
}

function ensureMetadataColumn(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(messages)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasMetadata = rows.some(row => row.name === 'metadata')
            if (hasMetadata) {
                return resolve()
            }
            db.run(
                `ALTER TABLE messages ADD COLUMN metadata TEXT`,
                (alterErr) => {
                    if (alterErr) {
                        return reject(alterErr)
                    }
                    resolve()
                }
            )
        })
    })
}

function ensureScheduledSchema(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(scheduled_messages)`, (err, rows) => {
            if (err) {
                return reject(err)
            }

            const hasTag = rows.some(row => row.name === 'tag')
            const hasTipo = rows.some(row => row.name === 'tipo')
            const hasCampaignId = rows.some(row => row.name === 'campaign_id')
            const hasIsPaused = rows.some(row => row.name === 'is_paused')

            const tasks = []
            if (!hasTag) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE scheduled_messages ADD COLUMN tag TEXT NOT NULL DEFAULT 'default'`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasTipo) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE scheduled_messages ADD COLUMN tipo TEXT NOT NULL DEFAULT 'followup'`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasIsPaused) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE scheduled_messages ADD COLUMN is_paused INTEGER NOT NULL DEFAULT 0`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasCampaignId) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE scheduled_messages ADD COLUMN campaign_id TEXT`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }

            if (!tasks.length) {
                return resolve()
            }

            Promise.all(tasks).then(() => resolve()).catch(reject)
        })
    })
}

function ensureContactContextSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS contact_context (
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (instance_id, remote_jid, key)
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureEventLogsSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS event_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT,
                category TEXT NOT NULL,
                description TEXT NOT NULL,
                metadata TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensurePersistentVariablesSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS persistent_variables (
                instance_id TEXT NOT NULL,
                key TEXT NOT NULL,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (instance_id, key)
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureDirectionColumn(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(messages)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasDirection = rows.some(row => row.name === 'direction')
            if (hasDirection) {
                return resolve()
            }
            db.run(
                `ALTER TABLE messages ADD COLUMN direction TEXT CHECK(direction IN ('inbound','outbound')) NOT NULL DEFAULT 'inbound'`,
                (alterErr) => {
                    if (alterErr) {
                        return reject(alterErr)
                    }
                    resolve()
                }
            )
        })
    })
}

// ===== SETTINGS MANAGEMENT =====

// Get setting value by key for an instance
async function getSetting(instanceIdOrKey, maybeKey) {
    const { instanceId, key } = (maybeKey === undefined)
        ? { instanceId: '', key: instanceIdOrKey }
        : { instanceId: instanceIdOrKey || '', key: maybeKey }

    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `SELECT value FROM settings WHERE instance_id = ? AND key = ?`
        
        db.get(sql, [instanceId, key], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row ? row.value : null)
        })
    })
}

// Set setting value by key for an instance
async function setSetting(arg1, arg2, arg3) {
    let instanceId = ''
    let key
    let value

    if (arg3 === undefined) {
        key = arg1
        value = arg2
    } else {
        instanceId = arg1 || ''
        key = arg2
        value = arg3
    }

    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT OR REPLACE INTO settings (instance_id, key, value)
            VALUES (?, ?, ?)
        `
        
        db.run(sql, [instanceId, key, value], (err) => {
            db.close()
            if (err) reject(err)
            else resolve()
        })
    })
}

// Get multiple settings for an instance
async function getSettings(instanceIdOrKeys, maybeKeys) {
    let instanceId = ''
    let keys = []

    if (maybeKeys === undefined) {
        keys = Array.isArray(instanceIdOrKeys) ? instanceIdOrKeys : [instanceIdOrKeys]
    } else {
        instanceId = instanceIdOrKeys || ''
        keys = Array.isArray(maybeKeys) ? maybeKeys : [maybeKeys]
    }

    if (!keys.length) {
        return {}
    }

    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const placeholders = keys.map(() => '?').join(',')
        const sql = `
            SELECT key, value
            FROM settings
            WHERE instance_id = ?
              AND key IN (${placeholders})
        `
        
        const params = [instanceId, ...keys]
        db.all(sql, params, (err, rows) => {
            db.close()
            if (err) {
                reject(err)
                return
            }
            const settings = {}
            rows.forEach(row => {
                settings[row.key] = row.value
            })
            resolve(settings)
        })
    })
}

// ===== MESSAGES MANAGEMENT =====

// Save message to database
async function saveMessage(instanceId, remoteJid, role, content, direction = 'inbound', metadata = null) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO messages (instance_id, remote_jid, role, content, direction, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        `

        db.run(sql, [instanceId, remoteJid, role, content, direction, metadata], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ messageId: this.lastID })
        })
    })
}

// Save contact metadata (status/profile information)
async function saveContactMetadata(instanceId, remoteJid, contactName = null, statusName = null, profilePicture = null) {
    if (!remoteJid) {
        throw new Error('Remote JID is required to save contact metadata')
    }

    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO contact_metadata (instance_id, remote_jid, contact_name, status_name, profile_picture, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, remote_jid) DO UPDATE SET
                contact_name = COALESCE(excluded.contact_name, contact_metadata.contact_name),
                status_name = COALESCE(excluded.status_name, contact_metadata.status_name),
                profile_picture = COALESCE(excluded.profile_picture, contact_metadata.profile_picture),
                updated_at = CURRENT_TIMESTAMP
        `
        const params = [
            instanceId,
            remoteJid,
            contactName,
            statusName,
            profilePicture
        ]

        db.run(sql, params, function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function getContactMetadata(instanceId, remoteJid) {
    if (!instanceId || !remoteJid) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT contact_name, status_name, profile_picture
            FROM contact_metadata
            WHERE instance_id = ?
              AND remote_jid = ?
            LIMIT 1
        `
        db.get(sql, [instanceId, remoteJid], (err, row) => {
            db.close()
            if (err) {
                reject(err)
            } else {
                resolve(row || null)
            }
        })
    })
}

// Get messages for a specific contact
async function getMessages(instanceId, remoteJid, limit = 50, offset = 0) {
    const db = new sqlite3.Database(DB_PATH)
    const normalizedRemote = typeof remoteJid === "string" ? remoteJid.toLowerCase() : ""
    if (normalizedRemote.startsWith("status@broadcast")) {
        return Promise.resolve([])
    }
    
    return new Promise((resolve, reject) => {
        const sqlBuilder = []
        sqlBuilder.push(`
            SELECT id, role, direction, content, timestamp, metadata
            FROM messages 
            WHERE instance_id = ? AND remote_jid = ?
            ORDER BY timestamp ASC
        `)
        const params = [instanceId, remoteJid]
        const normalizedLimit = Number.isFinite(limit) ? limit : 50
        const normalizedOffset = Number.isFinite(offset) && offset > 0 ? offset : 0

        if (normalizedLimit > 0) {
            sqlBuilder.push("LIMIT ? OFFSET ?")
            params.push(normalizedLimit, normalizedOffset)
        } else if (normalizedOffset > 0) {
            sqlBuilder.push("LIMIT -1 OFFSET ?")
            params.push(normalizedOffset)
        }

        const finalSql = sqlBuilder.join("\n")
        db.all(finalSql, params, (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows)
        })
    })
}

// Get last N messages for context
async function getLastMessages(instanceId, remoteJid, limit = 15) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT role, content
            FROM (
                SELECT role, content, id
                FROM messages
                WHERE instance_id = ?
                  AND remote_jid = ?
                  AND (metadata IS NULL OR metadata NOT LIKE '%"severity":"error"%')
                ORDER BY id DESC
                LIMIT ?
            ) sub
            ORDER BY id ASC
        `
        
        db.all(sql, [instanceId, remoteJid, limit], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows)
        })
    })
}

async function enqueueScheduledMessage(instanceId, remoteJid, message, scheduledAt, tag = "default", tipo = "followup") {
    if (!instanceId || !remoteJid || !message) {
        throw new Error("Instance ID, remote JID e mensagem são obrigatórios para agendar")
    }
    const db = new sqlite3.Database(DB_PATH)
    const scheduledDate = scheduledAt instanceof Date ? scheduledAt : new Date(scheduledAt)
    const scheduledIso = scheduledDate.toISOString()
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO scheduled_messages (instance_id, remote_jid, message, scheduled_at, status, tag, tipo, is_paused)
            VALUES (?, ?, ?, ?, 'pending', ?, ?, 0)
        `
        db.run(sql, [instanceId, remoteJid, message, scheduledIso, tag, tipo], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ scheduledId: this.lastID, scheduledAt: scheduledIso })
        })
    })
}

async function fetchDueScheduledMessages(instanceId, limit = 10) {
    const db = new sqlite3.Database(DB_PATH)
    const nowIso = new Date().toISOString()
    
    return new Promise((resolve, reject) => {
        const sql = `
        SELECT id, remote_jid, message, scheduled_at, tag, tipo
        FROM scheduled_messages
        WHERE instance_id = ?
          AND status = 'pending'
          AND is_paused = 0
          AND scheduled_at <= ?
            ORDER BY scheduled_at ASC
            LIMIT ?
        `
        db.all(sql, [instanceId, nowIso, limit], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows)
        })
    })
}

async function getScheduledMessages(instanceId, remoteJid) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, message, scheduled_at, status, last_attempt_at, tag, tipo
            FROM scheduled_messages
            WHERE instance_id = ?
              AND remote_jid = ?
            ORDER BY scheduled_at ASC
        `
        db.all(sql, [instanceId, remoteJid], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows)
        })
    })
}

async function listScheduledMessages(instanceId, remoteJid, tag = null, tipo = null) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const filters = [
            "instance_id = ?",
            "remote_jid = ?"
        ]
        const params = [instanceId, remoteJid]
        if (tag) {
            filters.push("tag = ?")
            params.push(tag)
        }
        if (tipo) {
            filters.push("tipo = ?")
            params.push(tipo)
        }
        const sql = `
            SELECT id, message, scheduled_at, status, tag, tipo
            FROM scheduled_messages
            WHERE ${filters.join(" AND ")}
            ORDER BY scheduled_at ASC
        `
        db.all(sql, params, (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows)
        })
    })
}

async function deleteScheduledMessagesByTag(instanceId, remoteJid, tag) {
    if (!tag) {
        throw new Error("Tag obrigatória")
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.run(
            `DELETE FROM scheduled_messages WHERE instance_id = ? AND remote_jid = ? AND tag = ?`,
            [instanceId, remoteJid, tag],
            function(err) {
                db.close()
                if (err) reject(err)
                else resolve({ deleted: this.changes })
            }
        )
    })
}

async function deleteScheduledMessagesByTipo(instanceId, remoteJid, tipo) {
    if (!tipo) {
        throw new Error("Tipo obrigatório")
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.run(
            `DELETE FROM scheduled_messages WHERE instance_id = ? AND remote_jid = ? AND tipo = ?`,
            [instanceId, remoteJid, tipo],
            function(err) {
                db.close()
                if (err) reject(err)
                else resolve({ deleted: this.changes })
            }
        )
    })
}

async function markPendingScheduledMessagesFailed(instanceId, remoteJid, reason = "cancelled") {
    const db = new sqlite3.Database(DB_PATH)
    const now = new Date().toISOString()
    return new Promise((resolve, reject) => {
        db.run(
            `
            UPDATE scheduled_messages
            SET status = 'failed',
                error = ?,
                last_attempt_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE instance_id = ?
              AND remote_jid = ?
              AND status = 'pending'
            `,
            [reason, now, instanceId, remoteJid],
            function(err) {
                db.close()
                if (err) reject(err)
                else resolve({ canceled: this.changes })
            }
        )
    })
}

// ===== WHATSAPP NUMBER CACHE =====

async function getWhatsAppNumberCache(phone, maxAgeDays = 90) {
    const normalized = String(phone || "").replace(/\D/g, "")
    if (!normalized) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    const ttlDays = Number.isFinite(maxAgeDays) ? Math.max(1, maxAgeDays) : 90
    const cutoff = `-${ttlDays} days`
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT phone, is_whatsapp, checked_at
            FROM whatsapp_number_cache
            WHERE phone = ?
              AND checked_at >= datetime('now', ?)
            LIMIT 1
        `
        db.get(sql, [normalized, cutoff], (err, row) => {
            db.close()
            if (err) {
                reject(err)
            } else if (!row) {
                resolve(null)
            } else {
                resolve({
                    phone: row.phone,
                    exists: row.is_whatsapp === 1,
                    checked_at: row.checked_at
                })
            }
        })
    })
}

async function setWhatsAppNumberCache(phone, exists) {
    const normalized = String(phone || "").replace(/\D/g, "")
    if (!normalized) {
        return { saved: 0 }
    }
    const db = new sqlite3.Database(DB_PATH)
    const isWhatsapp = exists ? 1 : 0
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO whatsapp_number_cache (phone, is_whatsapp, checked_at, updated_at)
            VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT(phone) DO UPDATE SET
                is_whatsapp = excluded.is_whatsapp,
                checked_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [normalized, isWhatsapp], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ saved: this.changes })
        })
    })
}


async function updateScheduledMessageStatus(scheduledId, status, error = null) {
    const db = new sqlite3.Database(DB_PATH)
    const allowed = ['pending', 'sent', 'failed']
    if (!allowed.includes(status)) {
        throw new Error("Status inválido para agendamento")
    }
    const lastAttempt = new Date().toISOString()
    
    return new Promise((resolve, reject) => {
        const sql = `
            UPDATE scheduled_messages
            SET status = ?, last_attempt_at = ?, error = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        `
        db.run(sql, [status, lastAttempt, error, scheduledId], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ changes: this.changes })
        })
    })
}

async function deleteScheduledMessage(scheduledId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `DELETE FROM scheduled_messages WHERE id = ?`
        db.run(sql, [scheduledId], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ changes: this.changes })
        })
    })
}

async function listContactContext(instanceId, remoteJid) {
    if (!instanceId || !remoteJid) {
        return []
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT key, value
            FROM contact_context
            WHERE instance_id = ? AND remote_jid = ?
            ORDER BY key ASC
        `
        db.all(sql, [instanceId, remoteJid], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function setContactContext(instanceId, remoteJid, key, value) {
    if (!instanceId || !remoteJid || !key) {
        throw new Error("instanceId, remoteJid e key são obrigatórios para contexto")
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO contact_context (instance_id, remote_jid, key, value, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, remote_jid, key) DO UPDATE SET
                value = excluded.value,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, remoteJid, key, value], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function getContactContext(instanceId, remoteJid, key) {
    if (!instanceId || !remoteJid || !key) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT value
            FROM contact_context
            WHERE instance_id = ? AND remote_jid = ? AND key = ?
            LIMIT 1
        `
        db.get(sql, [instanceId, remoteJid, key], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row ? row.value : null)
        })
    })
}

async function deleteContactContext(instanceId, remoteJid, keys = null) {
    if (!instanceId || !remoteJid) {
        throw new Error("instanceId e remoteJid são obrigatórios para limpar contexto")
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        let sql
        let params
        if (!keys || !keys.length) {
            sql = `
                DELETE FROM contact_context
                WHERE instance_id = ? AND remote_jid = ?
            `
            params = [instanceId, remoteJid]
        } else {
            const placeholders = keys.map(() => '?').join(',')
            sql = `
                DELETE FROM contact_context
                WHERE instance_id = ? AND remote_jid = ? AND key IN (${placeholders})
            `
            params = [instanceId, remoteJid, ...keys]
        }
        db.run(sql, params, function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
        })
    })
}

async function setPersistentVariable(instanceId, key, value) {
    if (!instanceId || !key) {
        throw new Error("instanceId e key são obrigatórios para variáveis persistentes")
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO persistent_variables (instance_id, key, value, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, key) DO UPDATE SET
                value = excluded.value,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, key, value], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function getPersistentVariable(instanceId, key) {
    if (!instanceId || !key) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT value
            FROM persistent_variables
            WHERE instance_id = ? AND key = ?
            LIMIT 1
        `
        db.get(sql, [instanceId, key], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row ? row.value : null)
        })
    })
}

async function listPersistentVariables(instanceId) {
    if (!instanceId) {
        return []
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT key, value, updated_at
            FROM persistent_variables
            WHERE instance_id = ?
            ORDER BY key ASC
        `
        db.all(sql, [instanceId], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function logEvent(instanceId, remoteJid, category, description, metadata = null) {
    if (!instanceId || !category || !description) {
        throw new Error("instanceId, categoria e descricao são obrigatórios para log_evento")
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO event_logs (instance_id, remote_jid, category, description, metadata)
            VALUES (?, ?, ?, ?, ?)
        `
        db.run(sql, [instanceId, remoteJid, category, description, metadata], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ loggedId: this.lastID })
        })
    })
}

async function getTimeSinceLastInboundMessage(instanceId, remoteJid) {
    if (!instanceId || !remoteJid) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT timestamp
            FROM messages
            WHERE instance_id = ? AND remote_jid = ? AND direction = 'inbound'
            ORDER BY timestamp DESC
            LIMIT 1
        `
        db.get(sql, [instanceId, remoteJid], (err, row) => {
            db.close()
            if (err) {
                reject(err)
                return
            }
            if (!row || !row.timestamp) {
                resolve(null)
                return
            }
            resolve(new Date(row.timestamp))
        })
    })
}

// ===== INSTANCES DATA =====

async function saveInstanceRecord(instanceId, payload = {}) {
    if (!instanceId) {
        throw new Error("instance_id é obrigatório")
    }
    const {
        name = null,
        port = null,
        api_key = null,
        status = null,
        connection_status = null,
        base_url = null,
        phone = null
    } = payload

    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO instances (instance_id, name, port, api_key, status, connection_status, base_url, phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(instance_id) DO UPDATE SET
                name = excluded.name,
                port = excluded.port,
                api_key = excluded.api_key,
                status = excluded.status,
                connection_status = excluded.connection_status,
                base_url = excluded.base_url,
                phone = excluded.phone,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, name, port, api_key, status, connection_status, base_url, phone], function (err) {
            db.close()
            if (err) reject(err)
            else resolve({ changes: this.changes, lastID: this.lastID })
        })
    })
}

async function getInstanceRecord(instanceId) {
    if (!instanceId) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT instance_id, name, port, api_key, status, connection_status, base_url, phone, created_at, updated_at
            FROM instances
            WHERE instance_id = ?
            LIMIT 1
        `
        db.get(sql, [instanceId], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row || null)
        })
    })
}

async function listInstancesRecords() {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT instance_id, name, port, api_key, status, connection_status, base_url, phone, created_at, updated_at
            FROM instances
            ORDER BY created_at ASC
        `
        db.all(sql, [], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

// ===== CHATS LIST (for sidebar) =====

// Get list of chats grouped by contact
async function getChats(instanceId, search = '', limit = 50, offset = 0) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        let sql = `
            SELECT 
                chats.remote_jid,
                cm.contact_name,
                cm.status_name,
                cm.profile_picture,
                (
                    SELECT content 
                    FROM messages 
                    WHERE instance_id = chats.instance_id 
                    AND remote_jid = chats.remote_jid 
                    ORDER BY timestamp DESC 
                    LIMIT 1
                ) as last_message,
                (
                    SELECT timestamp 
                    FROM messages 
                    WHERE instance_id = chats.instance_id 
                    AND remote_jid = chats.remote_jid 
                    ORDER BY timestamp DESC 
                    LIMIT 1
                ) as last_timestamp,
                (
                    SELECT role 
                    FROM messages 
                    WHERE instance_id = chats.instance_id 
                    AND remote_jid = chats.remote_jid 
                    ORDER BY timestamp DESC 
                    LIMIT 1
                ) as last_role,
                COUNT(*) as message_count
            FROM (
                SELECT DISTINCT remote_jid, instance_id
                FROM messages 
                WHERE instance_id = ?
                  AND remote_jid NOT LIKE 'status@broadcast%'
                ${search ? 'AND remote_jid LIKE ?' : ''}
            ) as chats
            LEFT JOIN contact_metadata cm ON cm.instance_id = chats.instance_id AND cm.remote_jid = chats.remote_jid
            GROUP BY chats.remote_jid, cm.contact_name, cm.status_name, cm.profile_picture
            ORDER BY last_timestamp DESC
            LIMIT ? OFFSET ?
        `
        
        const params = search 
            ? [instanceId, `%${search}%`, limit, offset]
            : [instanceId, limit, offset]
        
        db.all(sql, params, (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows)
        })
    })
}

// ===== HEALTH & DIAGNOSTICS =====

// Get database health status
async function getDatabaseHealth() {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const stats = {}
        
        // Get file size
        try {
            const fileSize = fs.statSync(DB_PATH).size
            stats.fileSize = fileSize
            stats.fileSizeMB = (fileSize / 1024 / 1024).toFixed(2)
        } catch (err) {
            stats.fileSize = 0
            stats.fileSizeMB = '0.00'
        }
        
        // Get message count
        const countSQL = `SELECT COUNT(*) as total_messages FROM messages`
        
        db.get(countSQL, (err, row) => {
            db.close()
            if (err) {
                reject(err)
            } else {
                stats.totalMessages = row.total_messages
                stats.status = 'connected'
                resolve(stats)
            }
        })
    })
}

// ===== CONVERSATION CONTEXT =====

// Get conversation context for AI
async function getConversationContext(instanceId, remoteJid, limit = 15) {
    try {
        // Get system prompt from settings
        const systemPrompt = await getSetting('system_prompt')
        
        // Get last messages
        const recentMessages = await getLastMessages(instanceId, remoteJid, limit)
        
        // Build context
        const context = []
        
        if (systemPrompt) {
            context.push({
                role: 'system',
                content: systemPrompt
            })
        }
        
        // Add recent messages
        recentMessages.forEach(msg => {
            context.push({
                role: msg.role,
                content: msg.content
            })
        })
        
        return {
            messages: context,
            systemPrompt: systemPrompt,
            messageCount: recentMessages.length
        }
    } catch (err) {
        throw new Error(`Error getting conversation context: ${err.message}`)
    }
}

// Thread metadata helpers (Assistants API)
async function getThreadMetadata(instanceId, remoteJid) {
    const db = new sqlite3.Database(DB_PATH)

    return new Promise((resolve, reject) => {
        const sql = `
            SELECT thread_id, last_message_id
            FROM threads
            WHERE instance_id = ? AND remote_jid = ?
            LIMIT 1
        `
        db.get(sql, [instanceId, remoteJid], (err, row) => {
            db.close()
            if (err) {
                reject(err)
            } else if (row) {
                resolve({
                    threadId: row.thread_id,
                    lastMessageId: row.last_message_id
                })
            } else {
                resolve(null)
            }
        })
    })
}

async function saveThreadMetadata(instanceId, remoteJid, threadId, lastMessageId = null) {
    const db = new sqlite3.Database(DB_PATH)

    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO threads (instance_id, remote_jid, thread_id, last_message_id, updated_at)
            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, remote_jid) DO UPDATE SET
                thread_id = excluded.thread_id,
                last_message_id = excluded.last_message_id,
                updated_at = CURRENT_TIMESTAMP
        `

        db.run(sql, [instanceId, remoteJid, threadId, lastMessageId], (err) => {
            db.close()
            if (err) reject(err)
            else resolve()
        })
    })
}

async function clearConversation(instanceId, remoteJid) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        db.serialize(() => {
            const cleanRemote = remoteJid || ''
            const statements = [
                `DELETE FROM messages WHERE instance_id = ? AND remote_jid = ?`,
                `DELETE FROM chat_history WHERE instance_id = ? AND remote_jid = ?`,
                `DELETE FROM threads WHERE instance_id = ? AND remote_jid = ?`,
                `DELETE FROM scheduled_messages WHERE instance_id = ? AND remote_jid = ?`,
                `DELETE FROM contact_context WHERE instance_id = ? AND remote_jid = ?`
            ]
            let completed = 0
            let hasError = false

            const finish = () => {
                db.close()
                if (hasError) reject(new Error("Erro ao limpar conversa"))
                else resolve()
            }

            statements.forEach(sql => {
                db.run(sql, [instanceId, cleanRemote], err => {
                    if (err) {
                        hasError = true
                    }
                    completed++
                    if (completed === statements.length) {
                        finish()
                    }
                })
            })
        })
    })
}

// Export functions
module.exports = {
    initDatabase,
    // Settings
    getSetting,
    setSetting,
    getSettings,
    // Messages
    saveMessage,
    saveContactMetadata,
    getContactMetadata,
    getMessages,
    getLastMessages,
    enqueueScheduledMessage,
    fetchDueScheduledMessages,
    updateScheduledMessageStatus,
    deleteScheduledMessage,
    listScheduledMessages,
    deleteScheduledMessagesByTag,
    deleteScheduledMessagesByTipo,
    markPendingScheduledMessagesFailed,
    listContactContext,
    setContactContext,
    getContactContext,
    deleteContactContext,
    setPersistentVariable,
    getPersistentVariable,
    listPersistentVariables,
    logEvent,
    getTimeSinceLastInboundMessage,
    getScheduledMessages,
    getWhatsAppNumberCache,
    setWhatsAppNumberCache,
    // Instances
    saveInstanceRecord,
    getInstanceRecord,
    listInstancesRecords,
    // Chats
    getChats,
    // Health
    getDatabaseHealth,
    // Context
    getConversationContext,
    // Threads
    getThreadMetadata,
    saveThreadMetadata,
    clearConversation
}
