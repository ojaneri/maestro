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
const CALENDAR_ACCOUNTS_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS calendar_accounts (
        instance_id TEXT PRIMARY KEY,
        calendar_email TEXT,
        access_token TEXT,
        refresh_token TEXT,
        token_expiry INTEGER,
        scope TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
`
const CALENDAR_CONFIGS_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS calendar_calendars (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_id TEXT NOT NULL,
        calendar_id TEXT NOT NULL,
        summary TEXT,
        timezone TEXT,
        availability_json TEXT,
        is_default INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(instance_id, calendar_id)
    )
`

const CALENDAR_PENDING_STATES_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS calendar_pending_states (
        state TEXT PRIMARY KEY,
        instance_id TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )
`

const AUTH_STATE_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS auth_state (
        instance_id TEXT NOT NULL,
        key TEXT NOT NULL,
        value TEXT,
        PRIMARY KEY (instance_id, key)
    )
`

const DIAG_EVENTS_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS diag_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_id TEXT NOT NULL,
        event_type TEXT NOT NULL,
        state TEXT,
        connection TEXT,
        event_payload TEXT,
        last_disconnect_reason TEXT,
        last_disconnect_status_code INTEGER,
        attempt INTEGER DEFAULT 0,
        message_count INTEGER DEFAULT 0,
        metadata TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
`

const DIAG_HEARTBEATS_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS diag_heartbeats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_id TEXT NOT NULL,
        uptime_sec REAL,
        mem_rss_mb REAL,
        heap_used_mb REAL,
        cpu_user_sec REAL,
        cpu_system_sec REAL,
        cpu_load REAL,
        event_loop_lag_ms REAL,
        listener_count INTEGER,
        socket_count INTEGER,
        http_ping_ms REAL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
`

const DIAG_INSTANCE_STATS_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS diag_instance_stats (
        instance_id TEXT PRIMARY KEY,
        last_disconnect_reason TEXT,
        last_disconnect_status_code INTEGER,
        last_disconnect_at DATETIME,
        reconnect_count INTEGER DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
`

const META_TEMPLATES_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS meta_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_id TEXT NOT NULL,
        template_name TEXT NOT NULL,
        status TEXT NOT NULL CHECK(status IN ('approved', 'pending', 'rejected')) DEFAULT 'pending',
        category TEXT,
        language TEXT NOT NULL DEFAULT 'pt_BR',
        components_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(instance_id, template_name, language)
    )
`

const META_WEBHOOK_EVENTS_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS meta_webhook_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance_id TEXT NOT NULL,
        phone_number_id TEXT,
        event_type TEXT NOT NULL,
        event_data TEXT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        processed INTEGER NOT NULL DEFAULT 0
    )
`

const META_INSTANCE_CONFIG_TABLE_SQL = `
    CREATE TABLE IF NOT EXISTS meta_instance_config (
        instance_id TEXT PRIMARY KEY,
        phone_number_id TEXT,
        business_account_id TEXT,
        access_token TEXT,
        verify_token TEXT,
        app_secret TEXT,
        phone_number TEXT,
        display_phone_number TEXT,
        api_version TEXT NOT NULL DEFAULT 'v22.0',
        status TEXT,
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
                db.serialize(() => {
                    db.run('PRAGMA journal_mode = WAL', (err) => {
                        if (err) {
                            console.error('Error setting WAL mode:', err.message)
                            reject(err)
                        } else {
                            console.log('Database journal mode set to WAL')
                            createTables(db)
                                .then(() => ensureSettingsSchema(db))
                                .then(() => ensureInstancesSchema(db))
                                .then(() => ensureDirectionColumn(db))
                                .then(() => ensureMetadataColumn(db))
                                .then(() => ensureMessagesSessionColumn(db))
                                .then(() => ensureQuotedColumns(db))
                                .then(() => ensureScheduledSchema(db))
                                .then(() => ensureContactContextSchema(db))
                                .then(() => ensureContactContextSessionColumn(db))
                                .then(() => ensureEventLogsSchema(db))
                                .then(() => ensurePersistentVariablesSchema(db))
                                .then(() => ensureGroupMonitoringSchema(db))
                                .then(() => ensureGroupMessagesSchema(db))
                                .then(() => ensureGroupSchedulesSchema(db))
                                .then(() => ensureGroupAutoRepliesSchema(db))
                                .then(() => ensureCalendarSchema(db))
                                .then(() => ensureTemperatureColumn(db))
                                .then(() => ensureTaxaRColumn(db))
                                .then(() => ensureMetaTemplatesSchema(db))
                                .then(() => ensureMetaWebhookEventsSchema(db))
                                .then(() => ensureMetaInstanceConfigSchema(db))
                                .then(() => ensureLIDPNColumns(db))
                                .then(() => ensureLIDMessageColumns(db))
                                .then(() => resolve(db))
                                .catch(reject)
                        }
                    })
                })
            }
        })
    })
}

function ensureMetaTemplatesSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS meta_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                template_name TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('approved', 'pending', 'rejected')) DEFAULT 'pending',
                category TEXT,
                language TEXT NOT NULL DEFAULT 'pt_BR',
                components_json TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(instance_id, template_name, language)
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureMetaWebhookEventsSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS meta_webhook_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                phone_number_id TEXT,
                event_type TEXT NOT NULL,
                event_data TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                processed INTEGER NOT NULL DEFAULT 0
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureMetaInstanceConfigSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS meta_instance_config (
                instance_id TEXT PRIMARY KEY,
                phone_number_id TEXT,
                business_account_id TEXT,
                access_token TEXT,
                verify_token TEXT,
                app_secret TEXT,
                phone_number TEXT,
                display_phone_number TEXT,
                api_version TEXT NOT NULL DEFAULT 'v22.0',
                status TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureTemperatureColumn(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(contact_metadata)`, (err, rows) => {
            if (err) {
                return reject(err);
            }
            const hasTemperature = rows.some(row => row.name === 'temperature');
            if (hasTemperature) {
                return resolve();
            }
            db.run(
                `ALTER TABLE contact_metadata ADD COLUMN temperature TEXT CHECK(temperature IN ('cold', 'warm', 'hot')) NOT NULL DEFAULT 'warm'`,
                (alterErr) => {
                    if (alterErr) {
                        return reject(alterErr);
                    }
                    resolve();
                }
            );
        });
    });
}

function ensureTaxaRColumn(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(contact_metadata)`, (err, rows) => {
            if (err) {
                return reject(err);
            }
            const hasTaxaR = rows.some(row => row.name === 'taxar');
            if (hasTaxaR) {
                return resolve();
            }
            db.run(
                `ALTER TABLE contact_metadata ADD COLUMN taxar REAL DEFAULT 0.0`,
                (alterErr) => {
                    if (alterErr) {
                        return reject(alterErr)
                    }
                    resolve();
                }
            );
        });
    });
}


// Create required tables according to specifications
function createTables(db) {
    return new Promise((resolve, reject) => {
        const messagesSQL = `
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                session_id TEXT NOT NULL DEFAULT '',
                role TEXT NOT NULL CHECK(role IN ('user', 'assistant')),
                content TEXT NOT NULL,
                direction TEXT NOT NULL CHECK(direction IN ('inbound','outbound')) DEFAULT 'inbound',
                metadata TEXT,
                wa_message_id TEXT,
                quoted_message_id TEXT,
                quoted_preview TEXT,
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

        const groupMonitoringSQL = `
            CREATE TABLE IF NOT EXISTS group_monitoring (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                group_name TEXT,
                enabled INTEGER NOT NULL DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(instance_id, group_jid)
            )
        `

        const groupMessagesSQL = `
            CREATE TABLE IF NOT EXISTS group_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                participant_jid TEXT,
                direction TEXT NOT NULL CHECK(direction IN ('inbound','outbound')),
                content TEXT NOT NULL,
                metadata TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `

        const groupSchedulesSQL = `
            CREATE TABLE IF NOT EXISTS group_scheduled_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                message TEXT NOT NULL,
                scheduled_at TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending','sent','failed')) DEFAULT 'pending',
                last_attempt_at TEXT,
                error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `

        const groupAutoRepliesSQL = `
            CREATE TABLE IF NOT EXISTS group_auto_replies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                replies_json TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(instance_id, group_jid)
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
            groupMonitoringSQL,
            groupMessagesSQL,
            groupSchedulesSQL,
            groupAutoRepliesSQL,
            whatsappCacheSQL,
            contactContextSQL,
            eventLogsSQL,
            AUTH_STATE_TABLE_SQL,
            META_TEMPLATES_TABLE_SQL,
            META_WEBHOOK_EVENTS_TABLE_SQL,
            META_INSTANCE_CONFIG_TABLE_SQL,
            DIAG_EVENTS_TABLE_SQL,
            DIAG_HEARTBEATS_TABLE_SQL,
            DIAG_INSTANCE_STATS_TABLE_SQL
        ]

        const indexes = [
            'CREATE INDEX IF NOT EXISTS idx_messages_instance ON messages(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_messages_contact ON messages(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_messages_session ON messages(session_id)',
            'CREATE INDEX IF NOT EXISTS idx_messages_timestamp ON messages(timestamp)',
            'CREATE INDEX IF NOT EXISTS idx_messages_instance_contact ON messages(instance_id, remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_threads_instance_contact ON threads(instance_id, remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_contact_metadata_instance ON contact_metadata(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_contact_metadata_remote ON contact_metadata(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_scheduled_instance_status ON scheduled_messages(instance_id, status)',
            'CREATE INDEX IF NOT EXISTS idx_scheduled_due ON scheduled_messages(scheduled_at)',
            'CREATE INDEX IF NOT EXISTS idx_scheduled_campaign ON scheduled_messages(campaign_id)',
            'CREATE INDEX IF NOT EXISTS idx_group_monitor_instance ON group_monitoring(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_group_messages_instance ON group_messages(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_group_messages_group ON group_messages(group_jid)',
            'CREATE INDEX IF NOT EXISTS idx_group_messages_timestamp ON group_messages(timestamp)',
            'CREATE INDEX IF NOT EXISTS idx_group_schedule_instance ON group_scheduled_messages(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_group_schedule_due ON group_scheduled_messages(scheduled_at)',
            'CREATE INDEX IF NOT EXISTS idx_group_replies_instance ON group_auto_replies(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_whatsapp_cache_phone ON whatsapp_number_cache(phone)',
            'CREATE INDEX IF NOT EXISTS idx_contact_context_instance ON contact_context(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_contact_context_remote ON contact_context(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_contact_context_session ON contact_context(session_id)',
            'CREATE INDEX IF NOT EXISTS idx_event_logs_instance ON event_logs(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_event_logs_remote ON event_logs(remote_jid)',
            'CREATE INDEX IF NOT EXISTS idx_persistent_vars_instance ON persistent_variables(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_meta_templates_instance ON meta_templates(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_meta_templates_status ON meta_templates(status)',
            'CREATE INDEX IF NOT EXISTS idx_meta_templates_name ON meta_templates(template_name)',
            'CREATE INDEX IF NOT EXISTS idx_meta_webhook_events_instance ON meta_webhook_events(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_meta_webhook_events_type ON meta_webhook_events(event_type)',
            'CREATE INDEX IF NOT EXISTS idx_meta_webhook_events_processed ON meta_webhook_events(processed)',
            'CREATE INDEX IF NOT EXISTS idx_meta_webhook_events_timestamp ON meta_webhook_events(timestamp)',
            'CREATE INDEX IF NOT EXISTS idx_meta_config_instance ON meta_instance_config(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_meta_config_phone ON meta_instance_config(phone_number)'
            ,
            'CREATE INDEX IF NOT EXISTS idx_diag_events_instance ON diag_events(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_diag_events_created ON diag_events(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_diag_heartbeats_instance ON diag_heartbeats(instance_id)',
            'CREATE INDEX IF NOT EXISTS idx_diag_heartbeats_created ON diag_heartbeats(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_diag_instance_stats_instance ON diag_instance_stats(instance_id)'
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

function ensureMessagesSessionColumn(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(messages)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasSession = rows.some(row => row.name === 'session_id')
            if (hasSession) {
                return resolve()
            }
            db.run(
                `ALTER TABLE messages ADD COLUMN session_id TEXT NOT NULL DEFAULT ''`,
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

function ensureQuotedColumns(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(messages)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasWaId = rows.some(row => row.name === 'wa_message_id')
            const hasQuotedId = rows.some(row => row.name === 'quoted_message_id')
            const hasQuotedPreview = rows.some(row => row.name === 'quoted_preview')
            const tasks = []
            if (!hasWaId) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE messages ADD COLUMN wa_message_id TEXT`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasQuotedId) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE messages ADD COLUMN quoted_message_id TEXT`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasQuotedPreview) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE messages ADD COLUMN quoted_preview TEXT`, alterErr => {
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
                session_id TEXT NOT NULL DEFAULT '',
                key TEXT NOT NULL,
                value TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (instance_id, remote_jid, session_id, key)
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureContactContextSessionColumn(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(contact_context)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasSession = rows.some(row => row.name === 'session_id')
            if (hasSession) {
                return resolve()
            }
            db.run(
                `ALTER TABLE contact_context ADD COLUMN session_id TEXT NOT NULL DEFAULT ''`,
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

function ensureGroupMonitoringSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS group_monitoring (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                group_name TEXT,
                enabled INTEGER NOT NULL DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(instance_id, group_jid)
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureGroupMessagesSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS group_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                participant_jid TEXT,
                direction TEXT NOT NULL CHECK(direction IN ('inbound','outbound')),
                content TEXT NOT NULL,
                metadata TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureGroupSchedulesSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS group_scheduled_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                message TEXT NOT NULL,
                scheduled_at TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending','sent','failed')) DEFAULT 'pending',
                last_attempt_at TEXT,
                error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureGroupAutoRepliesSchema(db) {
    return new Promise((resolve, reject) => {
        const sql = `
            CREATE TABLE IF NOT EXISTS group_auto_replies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                group_jid TEXT NOT NULL,
                replies_json TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(instance_id, group_jid)
            )
        `
        db.run(sql, err => {
            if (err) reject(err)
            else resolve()
        })
    })
}

function ensureCalendarSchema(db) {
    return new Promise((resolve, reject) => {
        db.serialize(() => {
            db.run(CALENDAR_ACCOUNTS_TABLE_SQL, (err) => {
                if (err) {
                    reject(err)
                    return
                }
                db.run(CALENDAR_CONFIGS_TABLE_SQL, (err2) => {
                    if (err2) {
                        reject(err2)
                        return
                    }
                    db.run(CALENDAR_PENDING_STATES_TABLE_SQL, (err3) => {
                        if (err3) {
                            reject(err3)
                            return
                        }
                        resolve()
                    })
                })
            })
        })
    })
}

async function insertCalendarPendingState(instanceId, state, createdAt = Date.now()) {
    if (!instanceId || !state) {
        return { ok: false }
    }
    const db = new sqlite3.Database(DB_PATH)
    const timestamp = Number(createdAt) || Date.now()
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO calendar_pending_states (state, instance_id, created_at)
            VALUES (?, ?, ?)
            ON CONFLICT(state) DO UPDATE SET
                instance_id = excluded.instance_id,
                created_at = excluded.created_at
        `
        db.run(sql, [state, instanceId, timestamp], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ upserted: this.changes })
        })
    })
}

async function deleteCalendarPendingState(state) {
    if (!state) {
        return { deleted: 0 }
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            DELETE FROM calendar_pending_states
            WHERE state = ?
        `
        db.run(sql, [state], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
        })
    })
}

async function findCalendarPendingState(state) {
    if (!state) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT state, instance_id, created_at
            FROM calendar_pending_states
            WHERE state = ?
            LIMIT 1
        `
        db.get(sql, [state], (err, row) => {
            db.close()
            if (err) {
                reject(err)
                return
            }
            resolve(row || null)
        })
    })
}

async function deleteExpiredCalendarPendingStates(cutoffMs) {
    if (!Number.isFinite(cutoffMs)) {
        return { deleted: 0 }
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            DELETE FROM calendar_pending_states
            WHERE created_at < ?
        `
        db.run(sql, [cutoffMs], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
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

// ===== CALENDAR INTEGRATION =====
async function getCalendarAccount(instanceId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT instance_id, calendar_email, access_token, refresh_token, token_expiry, scope
            FROM calendar_accounts
            WHERE instance_id = ?
        `
        db.get(sql, [instanceId], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row || null)
        })
    })
}

async function upsertCalendarAccount(instanceId, payload) {
    const db = new sqlite3.Database(DB_PATH)
    const {
        calendar_email: email,
        access_token: accessToken,
        refresh_token: refreshToken,
        token_expiry: tokenExpiry,
        scope
    } = payload || {}
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO calendar_accounts (instance_id, calendar_email, access_token, refresh_token, token_expiry, scope, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id) DO UPDATE SET
                calendar_email = COALESCE(excluded.calendar_email, calendar_accounts.calendar_email),
                access_token = COALESCE(excluded.access_token, calendar_accounts.access_token),
                refresh_token = COALESCE(excluded.refresh_token, calendar_accounts.refresh_token),
                token_expiry = COALESCE(excluded.token_expiry, calendar_accounts.token_expiry),
                scope = COALESCE(excluded.scope, calendar_accounts.scope),
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(
            sql,
            [
                instanceId,
                email ?? null,
                accessToken ?? null,
                refreshToken ?? null,
                tokenExpiry ?? null,
                scope ?? null
            ],
            function(err) {
                db.close()
                if (err) reject(err)
                else resolve({ updated: this.changes })
            }
        )
    })
}

async function clearCalendarAccount(instanceId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.serialize(() => {
            db.run(`DELETE FROM calendar_accounts WHERE instance_id = ?`, [instanceId], function(err) {
                if (err) {
                    db.close()
                    reject(err)
                    return
                }
                db.run(`DELETE FROM calendar_calendars WHERE instance_id = ?`, [instanceId], function(err2) {
                    db.close()
                    if (err2) reject(err2)
                    else resolve({ deleted: this.changes })
                })
            })
        })
    })
}

async function listCalendarConfigs(instanceId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, instance_id, calendar_id, summary, timezone, availability_json, is_default
            FROM calendar_calendars
            WHERE instance_id = ?
            ORDER BY is_default DESC, summary ASC, calendar_id ASC
        `
        db.all(sql, [instanceId], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function getCalendarConfig(instanceId, calendarId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, instance_id, calendar_id, summary, timezone, availability_json, is_default
            FROM calendar_calendars
            WHERE instance_id = ? AND calendar_id = ?
        `
        db.get(sql, [instanceId, calendarId], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row || null)
        })
    })
}

async function upsertCalendarConfig(instanceId, calendarId, payload) {
    const db = new sqlite3.Database(DB_PATH)
    const {
        summary = null,
        timezone = null,
        availability_json: availabilityJson = null,
        is_default: isDefault = 0
    } = payload || {}
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO calendar_calendars (instance_id, calendar_id, summary, timezone, availability_json, is_default, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, calendar_id) DO UPDATE SET
                summary = excluded.summary,
                timezone = excluded.timezone,
                availability_json = excluded.availability_json,
                is_default = excluded.is_default,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, calendarId, summary, timezone, availabilityJson, isDefault ? 1 : 0], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function deleteCalendarConfig(instanceId, calendarId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `DELETE FROM calendar_calendars WHERE instance_id = ? AND calendar_id = ?`
        db.run(sql, [instanceId, calendarId], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
        })
    })
}

async function setDefaultCalendarConfig(instanceId, calendarId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.serialize(() => {
            db.run(`UPDATE calendar_calendars SET is_default = 0 WHERE instance_id = ?`, [instanceId], (err) => {
                if (err) {
                    db.close()
                    reject(err)
                    return
                }
                db.run(
                    `UPDATE calendar_calendars SET is_default = 1, updated_at = CURRENT_TIMESTAMP WHERE instance_id = ? AND calendar_id = ?`,
                    [instanceId, calendarId],
                    function(err2) {
                        db.close()
                        if (err2) reject(err2)
                        else resolve({ updated: this.changes })
                    }
                )
            })
        })
    })
}

// ===== MESSAGES MANAGEMENT =====

// Save message to database
async function saveMessage(instanceId, remoteJid, role, content, direction = 'inbound', metadata = null, options = {}) {
    const db = new sqlite3.Database(DB_PATH)
    const {
        waMessageId = null,
        quotedMessageId = null,
        quotedPreview = null,
        sessionId = "",
        remoteJidAlt = null,
        participantAlt = null,
        senderPn = null
    } = options || {}
    const normalizedRemoteJid = String(remoteJid || "").toLowerCase()
    const normalizedRemoteJidAlt = remoteJidAlt ? String(remoteJidAlt).toLowerCase() : null
    const normalizedSenderPn = normalizePhoneDigits(senderPn || extractDigitsFromJid(normalizedRemoteJid))

    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO messages (
                instance_id,
                remote_jid,
                session_id,
                role,
                content,
                direction,
                metadata,
                wa_message_id,
                quoted_message_id,
                quoted_preview,
                remote_jid_alt,
                participant_alt,
                sender_pn
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        `

        db.run(sql, [
            instanceId,
            normalizedRemoteJid,
            String(sessionId || ""),
            role,
            content,
            direction,
            metadata,
            waMessageId,
            quotedMessageId,
            quotedPreview,
            normalizedRemoteJidAlt,
            participantAlt || null,
            normalizedSenderPn || null
        ], async function(err) {
            db.close()
            if (err) reject(err)
            else {
                // Update TaxaR after saving message
                try {
                    await updateTaxaR(instanceId, normalizedRemoteJid)
                } catch (taxaErr) {
                    console.error('Error updating TaxaR:', taxaErr.message)
                }
                resolve({ messageId: this.lastID })
            }
        })
    })
}

// Save contact metadata (status/profile information)
async function saveContactMetadata(instanceId, remoteJid, contactName = null, statusName = null, profilePicture = null, temperature = null) {
    if (!remoteJid) {
        throw new Error('Remote JID is required to save contact metadata')
    }

    const db = new sqlite3.Database(DB_PATH)
    const normalizedTemperature = ['cold', 'warm', 'hot'].includes(String(temperature || '').toLowerCase())
        ? String(temperature).toLowerCase()
        : 'warm'
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO contact_metadata (instance_id, remote_jid, contact_name, status_name, profile_picture, temperature, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, remote_jid) DO UPDATE SET
                contact_name = COALESCE(excluded.contact_name, contact_metadata.contact_name),
                status_name = COALESCE(excluded.status_name, contact_metadata.status_name),
                profile_picture = COALESCE(excluded.profile_picture, contact_metadata.profile_picture),
                temperature = COALESCE(excluded.temperature, contact_metadata.temperature),
                updated_at = CURRENT_TIMESTAMP
        `
        const params = [
            instanceId,
            remoteJid,
            contactName,
            statusName,
            profilePicture,
            normalizedTemperature
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
            SELECT contact_name, status_name, profile_picture, lid, pn, formatted_phone, temperature, taxar
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

function normalizePhoneDigits(value) {
    const digits = String(value || "").replace(/\D/g, "")
    if (!digits) return ""
    if (digits.startsWith("55")) {
        return digits.length >= 12 && digits.length <= 13 ? digits : ""
    }
    if (digits.length >= 10 && digits.length <= 11) {
        return `55${digits}`
    }
    return ""
}

function extractDigitsFromJid(remoteJid) {
    if (!remoteJid || typeof remoteJid !== "string") {
        return ""
    }
    return normalizePhoneDigits(remoteJid.split("@")[0])
}

async function resolveConversationAliases(instanceId, remoteJid) {
    const normalizedRemote = typeof remoteJid === "string" ? remoteJid.trim().toLowerCase() : ""
    if (!instanceId || !normalizedRemote) {
        return { remoteJids: [], senderPn: null }
    }

    const aliases = new Set([normalizedRemote])
    let senderPn = extractDigitsFromJid(normalizedRemote)
    const lidCandidate = normalizedRemote.endsWith("@lid")
        ? normalizedRemote
        : `${String(normalizedRemote.split("@")[0] || "").replace(/\D/g, "")}@lid`

    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT remote_jid, lid, pn
            FROM contact_metadata
            WHERE instance_id = ?
              AND (
                    remote_jid = ?
                 OR lid = ?
                 OR pn = ?
                 OR remote_jid = ?
                 OR lid = ?
              )
            ORDER BY updated_at DESC
            LIMIT 50
        `
        db.all(sql, [instanceId, normalizedRemote, normalizedRemote, senderPn || null, lidCandidate, lidCandidate], (err, rows) => {
            db.close()
            if (err) {
                reject(err)
                return
            }

            ;(rows || []).forEach(row => {
                if (row?.remote_jid) aliases.add(String(row.remote_jid).toLowerCase())
                if (row?.lid) aliases.add(String(row.lid).toLowerCase())
                const rowPn = normalizePhoneDigits(row?.pn)
                if (rowPn) {
                    senderPn = senderPn || rowPn
                    aliases.add(`${rowPn}@s.whatsapp.net`)
                }
            })

            resolve({ remoteJids: Array.from(aliases), senderPn: senderPn || null })
        })
    })
}

// Get messages for a specific contact
async function getMessages(instanceId, remoteJid, limit = 50, offset = 0, sessionId = "") {
    const normalizedRemote = typeof remoteJid === "string" ? remoteJid.toLowerCase() : ""
    if (normalizedRemote.startsWith("status@broadcast")) {
        return Promise.resolve([])
    }
    const normalizedSession = String(sessionId || "")
    const hasSessionFilter = normalizedSession.length > 0
    const { remoteJids, senderPn } = await resolveConversationAliases(instanceId, normalizedRemote)
    if (!remoteJids.length) {
        return []
    }

    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const placeholders = remoteJids.map(() => "?").join(", ")
        const sqlBuilder = []
        sqlBuilder.push(`
            SELECT id, role, direction, content, timestamp, metadata
            FROM messages 
            WHERE instance_id = ?
              ${hasSessionFilter ? "AND session_id = ?" : ""}
              AND (
                    remote_jid IN (${placeholders})
                 OR remote_jid_alt IN (${placeholders})
                 ${senderPn ? "OR sender_pn = ?" : ""}
              )
            ORDER BY timestamp ASC
        `)
        const params = [instanceId, ...(hasSessionFilter ? [normalizedSession] : []), ...remoteJids, ...remoteJids]
        if (senderPn) {
            params.push(senderPn)
        }
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
async function getLastMessages(instanceId, remoteJid, limit = 15, sessionId = "") {
    const normalizedRemote = typeof remoteJid === "string" ? remoteJid.toLowerCase() : ""
    const { remoteJids, senderPn } = await resolveConversationAliases(instanceId, normalizedRemote)
    if (!remoteJids.length) {
        return []
    }

    const db = new sqlite3.Database(DB_PATH)
    const normalizedSession = String(sessionId || "")
    const hasSessionFilter = normalizedSession.length > 0
    
    return new Promise((resolve, reject) => {
        const placeholders = remoteJids.map(() => "?").join(", ")
        
        // Build WHERE clause - session_id is optional
        let whereClause = `WHERE instance_id = ?`
        if (hasSessionFilter) {
            whereClause += ` AND session_id = ?`
        }
        whereClause += ` AND (
                        remote_jid IN (${placeholders})
                     OR remote_jid_alt IN (${placeholders})
                     ${senderPn ? "OR sender_pn = ?" : ""}
                  )
                  AND (metadata IS NULL OR metadata NOT LIKE '%"severity":"error"%')`

        const sql = `
            SELECT role, content, timestamp
            FROM (
                SELECT role, content, timestamp, id
                FROM messages
                ${whereClause}
                ORDER BY id DESC
                LIMIT ?
            ) sub
            ORDER BY id ASC
        `

        // Build params based on whether session filter is active
        const params = [instanceId]
        if (hasSessionFilter) {
            params.push(normalizedSession)
        }
        params.push(...remoteJids, ...remoteJids)
        if (senderPn) {
            params.push(senderPn)
        }
        params.push(limit)

        db.all(sql, params, (err, rows) => {
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

async function setMonitoredGroups(instanceId, groups = []) {
    const db = new sqlite3.Database(DB_PATH)
    const sanitized = Array.isArray(groups)
        ? groups.map(group => ({
            jid: String(group?.jid || "").trim(),
            name: String(group?.name || "").trim() || null
        })).filter(group => group.jid)
        : []

    return new Promise((resolve, reject) => {
        db.serialize(() => {
            db.run("DELETE FROM group_monitoring WHERE instance_id = ?", [instanceId], err => {
                if (err) {
                    db.close()
                    reject(err)
                    return
                }
                if (!sanitized.length) {
                    db.close()
                    resolve({ updated: 0 })
                    return
                }
                const stmt = db.prepare(`
                    INSERT INTO group_monitoring (instance_id, group_jid, group_name, enabled)
                    VALUES (?, ?, ?, 1)
                `)
                sanitized.forEach(group => {
                    stmt.run([instanceId, group.jid, group.name])
                })
                stmt.finalize(err2 => {
                    db.close()
                    if (err2) reject(err2)
                    else resolve({ updated: sanitized.length })
                })
            })
        })
    })
}

async function getMonitoredGroups(instanceId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.all(`
            SELECT group_jid, group_name, enabled, updated_at
            FROM group_monitoring
            WHERE instance_id = ?
            ORDER BY group_name ASC
        `, [instanceId], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function getMonitoredGroup(instanceId, groupJid) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.get(`
            SELECT group_jid, group_name, enabled
            FROM group_monitoring
            WHERE instance_id = ? AND group_jid = ? AND enabled = 1
            LIMIT 1
        `, [instanceId, groupJid], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row || null)
        })
    })
}

async function deleteMonitoredGroup(instanceId, groupJid) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.run(
            "DELETE FROM group_monitoring WHERE instance_id = ? AND group_jid = ?",
            [instanceId, groupJid],
            function(err) {
                db.close()
                if (err) reject(err)
                else resolve({ deleted: this.changes })
            }
        )
    })
}

async function saveGroupMessage(instanceId, groupJid, participantJid, direction, content, metadata = null) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO group_messages (instance_id, group_jid, participant_jid, direction, content, metadata)
            VALUES (?, ?, ?, ?, ?, ?)
        `
        db.run(sql, [instanceId, groupJid, participantJid, direction, content, metadata], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ messageId: this.lastID })
        })
    })
}

async function getGroupMessages(instanceId, groupJid, start = null, end = null, limit = 200) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const clauses = ["instance_id = ?"]
        const params = [instanceId]
        if (groupJid) {
            clauses.push("group_jid = ?")
            params.push(groupJid)
        }
        if (start) {
            clauses.push("timestamp >= ?")
            params.push(start)
        }
        if (end) {
            clauses.push("timestamp <= ?")
            params.push(end)
        }
        const sql = `
            SELECT id, group_jid, participant_jid, direction, content, metadata, timestamp
            FROM group_messages
            WHERE ${clauses.join(" AND ")}
            ORDER BY timestamp DESC
            LIMIT ?
        `
        params.push(limit)
        db.all(sql, params, (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function enqueueGroupScheduledMessage(instanceId, groupJid, message, scheduledAt) {
    const db = new sqlite3.Database(DB_PATH)
    const scheduledDate = scheduledAt instanceof Date ? scheduledAt : new Date(scheduledAt)
    const scheduledIso = scheduledDate.toISOString()
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO group_scheduled_messages (instance_id, group_jid, message, scheduled_at, status)
            VALUES (?, ?, ?, ?, 'pending')
        `
        db.run(sql, [instanceId, groupJid, message, scheduledIso], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ scheduledId: this.lastID, scheduledAt: scheduledIso })
        })
    })
}

async function fetchDueGroupScheduledMessages(instanceId, limit = 10) {
    const db = new sqlite3.Database(DB_PATH)
    const nowIso = new Date().toISOString()
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, group_jid, message, scheduled_at
            FROM group_scheduled_messages
            WHERE instance_id = ?
              AND status = 'pending'
              AND scheduled_at <= ?
            ORDER BY scheduled_at ASC
            LIMIT ?
        `
        db.all(sql, [instanceId, nowIso, limit], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function updateGroupScheduledMessageStatus(id, status, error = null) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            UPDATE group_scheduled_messages
            SET status = ?,
                error = ?,
                last_attempt_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        `
        db.run(sql, [status, error, id], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function deleteGroupScheduledMessage(id) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.run("DELETE FROM group_scheduled_messages WHERE id = ?", [id], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
        })
    })
}

async function getGroupAutoReplies(instanceId, groupJid) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        db.get(`
            SELECT replies_json, enabled
            FROM group_auto_replies
            WHERE instance_id = ? AND group_jid = ?
            LIMIT 1
        `, [instanceId, groupJid], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row || null)
        })
    })
}

async function setGroupAutoReplies(instanceId, groupJid, replies = [], enabled = true) {
    const db = new sqlite3.Database(DB_PATH)
    const payload = Array.isArray(replies) ? replies.filter(Boolean) : []
    const repliesJson = JSON.stringify(payload)
    const enabledValue = enabled ? 1 : 0
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO group_auto_replies (instance_id, group_jid, replies_json, enabled)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(instance_id, group_jid)
            DO UPDATE SET replies_json = excluded.replies_json, enabled = excluded.enabled, updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, groupJid, repliesJson, enabledValue], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
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

async function markPendingScheduledMessagesFailed(instanceId, remoteJid, reason = "cancelled", tag = null) {
    const db = new sqlite3.Database(DB_PATH)
    const now = new Date().toISOString()
    return new Promise((resolve, reject) => {
        let sql = `
            UPDATE scheduled_messages
            SET status = 'failed',
                error = ?,
                last_attempt_at = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE instance_id = ?
              AND remote_jid = ?
              AND status = 'pending'
        `
        const params = [reason, now, instanceId, remoteJid]
        if (tag) {
            sql += " AND tag = ?"
            params.push(tag)
        }
        db.run(sql, params, function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ canceled: this.changes })
        })
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

async function listContactContext(instanceId, remoteJid, sessionId = "") {
    if (!instanceId || !remoteJid) {
        return []
    }
    const normalizedSession = String(sessionId || "")
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT key, value
            FROM contact_context
            WHERE instance_id = ? AND remote_jid = ? AND session_id = ?
            ORDER BY key ASC
        `
        db.all(sql, [instanceId, remoteJid, normalizedSession], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function setContactContext(instanceId, remoteJid, key, value, sessionId = "") {
    if (!instanceId || !remoteJid || !key) {
        throw new Error("instanceId, remoteJid e key são obrigatórios para contexto")
    }
    const normalizedSession = String(sessionId || "")
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO contact_context (instance_id, remote_jid, session_id, key, value, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, remote_jid, session_id, key) DO UPDATE SET
                value = excluded.value,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, remoteJid, normalizedSession, key, value], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function getContactContext(instanceId, remoteJid, key, sessionId = "") {
    if (!instanceId || !remoteJid || !key) {
        return null
    }
    const normalizedSession = String(sessionId || "")
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT value
            FROM contact_context
            WHERE instance_id = ? AND remote_jid = ? AND session_id = ? AND key = ?
            LIMIT 1
        `
        db.get(sql, [instanceId, remoteJid, normalizedSession, key], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row ? row.value : null)
        })
    })
}

async function deleteContactContext(instanceId, remoteJid, keys = null, sessionId = "") {
    if (!instanceId || !remoteJid) {
        throw new Error("instanceId e remoteJid são obrigatórios para limpar contexto")
    }
    const normalizedSession = String(sessionId || "")
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        let sql
        let params
        if (!keys || !keys.length) {
            sql = `
                DELETE FROM contact_context
                WHERE instance_id = ? AND remote_jid = ? AND session_id = ?
            `
            params = [instanceId, remoteJid, normalizedSession]
        } else {
            const placeholders = keys.map(() => '?').join(',')
            sql = `
                DELETE FROM contact_context
                WHERE instance_id = ? AND remote_jid = ? AND session_id = ? AND key IN (${placeholders})
            `
            params = [instanceId, remoteJid, normalizedSession, ...keys]
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

async function deletePersistentVariable(instanceId, key) {
    if (!instanceId || !key) {
        return { deleted: 0 }
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            DELETE FROM persistent_variables
            WHERE instance_id = ? AND key = ?
        `
        db.run(sql, [instanceId, key], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
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

async function getInboundMessageCount(instanceId, remoteJid) {
    if (!instanceId || !remoteJid) {
        return 0
    }
    const { remoteJids, senderPn } = await resolveConversationAliases(instanceId, remoteJid)
    if (!remoteJids.length) {
        return 0
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const placeholders = remoteJids.map(() => "?").join(", ")
        const sql = `
            SELECT COUNT(id) as count
            FROM messages
            WHERE instance_id = ?
              AND direction = 'inbound'
              AND (
                    remote_jid IN (${placeholders})
                 OR remote_jid_alt IN (${placeholders})
                 ${senderPn ? "OR sender_pn = ?" : ""}
              )
        `
        const params = [instanceId, ...remoteJids, ...remoteJids]
        if (senderPn) {
            params.push(senderPn)
        }
        db.get(sql, params, (err, row) => {
            db.close()
            if (err) {
                reject(err)
            } else {
                resolve(row ? row.count : 0)
            }
        })
    })
}

async function getOutboundMessageCount(instanceId, remoteJid) {
    if (!instanceId || !remoteJid) {
        return 0
    }
    const { remoteJids, senderPn } = await resolveConversationAliases(instanceId, remoteJid)
    if (!remoteJids.length) {
        return 0
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const placeholders = remoteJids.map(() => "?").join(", ")
        const sql = `
            SELECT COUNT(id) as count
            FROM messages
            WHERE instance_id = ?
              AND direction = 'outbound'
              AND (
                    remote_jid IN (${placeholders})
                 OR remote_jid_alt IN (${placeholders})
                 ${senderPn ? "OR sender_pn = ?" : ""}
              )
        `
        const params = [instanceId, ...remoteJids, ...remoteJids]
        if (senderPn) {
            params.push(senderPn)
        }
        db.get(sql, params, (err, row) => {
            db.close()
            if (err) {
                reject(err)
            } else {
                resolve(row ? row.count : 0)
            }
        })
    })
}

// ===== INSTANCES DATA =====

async function saveInstanceRecord(instanceId, payload = {}) {
    if (!instanceId) {
        throw new Error("instance_id é obrigatório")
    }
    const {
        name = undefined,
        port = undefined,
        api_key = undefined,
        status = undefined,
        connection_status = undefined,
        base_url = undefined,
        phone = undefined
    } = payload

    const db = new sqlite3.Database(DB_PATH)
    
    // Buscar valores existentes para não sobrescrever com null
    const existingRecord = await new Promise((resolve, reject) => {
        db.get('SELECT name, port, api_key, status, connection_status, base_url, phone FROM instances WHERE instance_id = ?', [instanceId], (err, row) => {
            if (err) reject(err)
            else resolve(row || null)
        })
    })
    
    // Usar valores existentes se o payload não fornecer
    const finalName = name !== undefined ? name : (existingRecord?.name || null)
    const finalPort = port !== undefined ? port : (existingRecord?.port || null)
    const finalApiKey = api_key !== undefined ? api_key : (existingRecord?.api_key || null)
    const finalStatus = status !== undefined ? status : (existingRecord?.status || null)
    const finalConnectionStatus = connection_status !== undefined ? connection_status : (existingRecord?.connection_status || null)
    const finalBaseUrl = base_url !== undefined ? base_url : (existingRecord?.base_url || null)
    const finalPhone = phone !== undefined ? phone : (existingRecord?.phone || null)

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
        db.run(sql, [instanceId, finalName, finalPort, finalApiKey, finalStatus, finalConnectionStatus, finalBaseUrl, finalPhone], function (err) {
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
                cm.lid,
                cm.pn,
                cm.formatted_phone,
                (
                    SELECT COALESCE(
                        (
                            SELECT content 
                            FROM messages 
                            WHERE instance_id = chats.instance_id 
                            AND remote_jid = chats.remote_jid 
                            ORDER BY timestamp DESC 
                            LIMIT 1
                        ),
                        CASE 
                            WHEN cm.updated_at IS NOT NULL THEN 'Histórico não persistido nesta instância'
                            ELSE NULL
                        END
                    )
                ) as last_message,
                (
                    SELECT COALESCE(
                        (
                            SELECT timestamp 
                            FROM messages 
                            WHERE instance_id = chats.instance_id 
                            AND remote_jid = chats.remote_jid 
                            ORDER BY timestamp DESC 
                            LIMIT 1
                        ),
                        cm.updated_at
                    )
                ) as last_timestamp,
                (
                    SELECT COALESCE(
                        (
                            SELECT role 
                            FROM messages 
                            WHERE instance_id = chats.instance_id 
                            AND remote_jid = chats.remote_jid 
                            ORDER BY timestamp DESC 
                            LIMIT 1
                        ),
                        'system'
                    )
                ) as last_role,
                (
                    SELECT COUNT(*)
                    FROM messages
                    WHERE instance_id = chats.instance_id
                      AND remote_jid = chats.remote_jid
                ) as message_count
            FROM (
                SELECT DISTINCT remote_jid, instance_id
                FROM messages 
                WHERE instance_id = ?
                  AND remote_jid NOT LIKE 'status@broadcast%'
                ${search ? 'AND remote_jid LIKE ?' : ''}
                UNION
                SELECT DISTINCT remote_jid, instance_id
                FROM contact_metadata
                WHERE instance_id = ?
                  AND remote_jid NOT LIKE 'status@broadcast%'
                ${search ? 'AND remote_jid LIKE ?' : ''}
            ) as chats
            LEFT JOIN contact_metadata cm ON cm.instance_id = chats.instance_id AND cm.remote_jid = chats.remote_jid
            ORDER BY last_timestamp DESC
            LIMIT ? OFFSET ?
        `
        
        const params = search 
            ? [instanceId, `%${search}%`, instanceId, `%${search}%`, limit, offset]
            : [instanceId, instanceId, limit, offset]
        
        db.all(sql, params, (err, rows) => {
            db.close()
            if (err) reject(err)
            else {
                const enrichedRows = (rows || []).map(row => {
                    const remote = String(row?.remote_jid || "")
                    const isLid = remote.endsWith("@lid")
                    const derivedPn = normalizePhoneDigits(row?.pn || (remote.endsWith("@s.whatsapp.net") ? extractDigitsFromJid(remote) : ""))
                    return {
                        ...row,
                        is_lid: isLid,
                        _pn_digits: derivedPn || null
                    }
                })

                const latestByPn = new Map()
                enrichedRows.forEach(row => {
                    const key = row._pn_digits
                    if (!key) return
                    const current = latestByPn.get(key)
                    const rowTs = row.last_timestamp ? new Date(row.last_timestamp).getTime() : 0
                    const curTs = current?.last_timestamp ? new Date(current.last_timestamp).getTime() : 0
                    if (!current || rowTs >= curTs) {
                        latestByPn.set(key, row)
                    }
                })

                const processedRows = enrichedRows.map(row => {
                    const latestFromGroup = row._pn_digits ? latestByPn.get(row._pn_digits) : null
                    const mergedLastMessage = row.last_message || latestFromGroup?.last_message || 'Histórico não persistido nesta instância'
                    const mergedTimestamp = row.last_timestamp || latestFromGroup?.last_timestamp || null
                    const mergedRole = row.last_role || latestFromGroup?.last_role || 'system'
                    const mergedCount = Math.max(Number(row.message_count || 0), Number(latestFromGroup?.message_count || 0))

                    if (row.is_lid) {
                        const displayPhone = row.formatted_phone || (row._pn_digits ? formatBrazilianPhone(row._pn_digits) : null)
                        return {
                            ...row,
                            display_jid: displayPhone || 'Contato sem telefone resolvido',
                            last_message: mergedLastMessage,
                            last_timestamp: mergedTimestamp,
                            last_role: mergedRole,
                            message_count: mergedCount
                        }
                    }

                    return {
                        ...row,
                        display_jid: row.remote_jid,
                        last_message: mergedLastMessage,
                        last_timestamp: mergedTimestamp,
                        last_role: mergedRole,
                        message_count: mergedCount
                    }
                })
                const visibleRows = processedRows.filter(row => Number(row.message_count || 0) > 0)
                resolve(visibleRows)
            }
        })
    })
}

// ===== HEALTH & DIAGNOSTICS =====

// Get database health status
async function getDatabaseHealth() {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const stats = {}
        
        try {
            const fileSize = fs.statSync(DB_PATH).size
            stats.fileSize = fileSize
            stats.fileSizeMB = (fileSize / 1024 / 1024).toFixed(2)
        } catch (err) {
            stats.fileSize = 0
            stats.fileSizeMB = '0.00'
        }
        
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

async function recordDiagEvent(payload) {
    const db = new sqlite3.Database(DB_PATH)
    const {
        instance_id,
        event_type,
        state = null,
        connection = null,
        event_payload = null,
        last_disconnect_reason = null,
        last_disconnect_status_code = null,
        attempt = 0,
        message_count = 0,
        metadata = null
    } = payload || {}

    if (!instance_id || !event_type) {
        throw new Error('instance_id and event_type are required for diag events')
    }

    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO diag_events (
                instance_id,
                event_type,
                state,
                connection,
                event_payload,
                last_disconnect_reason,
                last_disconnect_status_code,
                attempt,
                message_count,
                metadata
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        `
        db.run(sql, [
            instance_id,
            event_type,
            state,
            connection,
            event_payload ? JSON.stringify(event_payload) : null,
            last_disconnect_reason,
            last_disconnect_status_code,
            attempt,
            message_count,
            metadata ? JSON.stringify(metadata) : null
        ], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ eventId: this.lastID })
        })
    })
}

async function getDiagEvents(instanceId, limit = 20) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT *
            FROM diag_events
            WHERE instance_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        `
        db.all(sql, [instanceId, limit], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function recordHeartbeat(payload) {
    const db = new sqlite3.Database(DB_PATH)
    const {
        instance_id,
        uptime_sec = null,
        mem_rss_mb = null,
        heap_used_mb = null,
        cpu_user_sec = null,
        cpu_system_sec = null,
        cpu_load = null,
        event_loop_lag_ms = null,
        listener_count = null,
        socket_count = null,
        http_ping_ms = null
    } = payload || {}

    if (!instance_id) {
        throw new Error('instance_id is required for heartbeat')
    }

    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO diag_heartbeats (
                instance_id,
                uptime_sec,
                mem_rss_mb,
                heap_used_mb,
                cpu_user_sec,
                cpu_system_sec,
                cpu_load,
                event_loop_lag_ms,
                listener_count,
                socket_count,
                http_ping_ms
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        `
        db.run(sql, [
            instance_id,
            uptime_sec,
            mem_rss_mb,
            heap_used_mb,
            cpu_user_sec,
            cpu_system_sec,
            cpu_load,
            event_loop_lag_ms,
            listener_count,
            socket_count,
            http_ping_ms
        ], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ heartbeatId: this.lastID })
        })
    })
}

async function getHeartbeats(instanceId, limit = 20) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT *
            FROM diag_heartbeats
            WHERE instance_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        `
        db.all(sql, [instanceId, limit], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

async function upsertInstanceDiagStats(instanceId, payload) {
    if (!instanceId) {
        throw new Error('instance_id is required for diag stats')
    }
    const db = new sqlite3.Database(DB_PATH)
    const {
        last_disconnect_reason = null,
        last_disconnect_status_code = null,
        last_disconnect_at = null,
        reconnect_count = 0
    } = payload || {}

    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO diag_instance_stats (
                instance_id,
                last_disconnect_reason,
                last_disconnect_status_code,
                last_disconnect_at,
                reconnect_count,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id) DO UPDATE SET
                last_disconnect_reason = excluded.last_disconnect_reason,
                last_disconnect_status_code = excluded.last_disconnect_status_code,
                last_disconnect_at = excluded.last_disconnect_at,
                reconnect_count = excluded.reconnect_count,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [
            instanceId,
            last_disconnect_reason,
            last_disconnect_status_code,
            last_disconnect_at,
            reconnect_count
        ], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function getInstanceDiagStats(instanceId) {
    if (!instanceId) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT *
            FROM diag_instance_stats
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

async function countDiagEvents(instanceId, filters = {}) {
    if (!instanceId) {
        return 0
    }
    const db = new sqlite3.Database(DB_PATH)
    const { connection, eventType, sinceMs, state } = filters || {}
    const params = [instanceId]
    let sql = `SELECT COUNT(*) as count FROM diag_events WHERE instance_id = ?`
    if (connection) {
        sql += ` AND connection = ?`
        params.push(connection)
    }
    if (eventType) {
        sql += ` AND event_type = ?`
        params.push(eventType)
    }
    if (state) {
        sql += ` AND state = ?`
        params.push(state)
    }
    if (sinceMs) {
        const sinceDate = new Date(Number(sinceMs))
        if (!Number.isNaN(sinceDate.getTime())) {
            sql += ` AND created_at >= ?`
            params.push(sinceDate.toISOString())
        }
    }
    return new Promise((resolve, reject) => {
        db.get(sql, params, (err, row) => {
            db.close()
            if (err) {
                reject(err)
            } else {
                resolve(row ? row.count : 0)
            }
        })
    })
}

async function getDiagEventsSince(instanceId, sinceMs = null, limit = 0) {
    if (!instanceId) {
        return []
    }
    const db = new sqlite3.Database(DB_PATH)
    const params = [instanceId]
    let sql = `SELECT * FROM diag_events WHERE instance_id = ?`
    if (sinceMs) {
        const sinceDate = new Date(Number(sinceMs))
        if (!Number.isNaN(sinceDate.getTime())) {
            sql += ` AND created_at >= ?`
            params.push(sinceDate.toISOString())
        }
    }
    sql += ` ORDER BY created_at ASC`
    if (limit > 0) {
        sql += ` LIMIT ?`
        params.push(limit)
    }
    return new Promise((resolve, reject) => {
        db.all(sql, params, (err, rows) => {
            db.close()
            if (err) {
                reject(err)
            } else {
                resolve(rows || [])
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
    const { remoteJids } = await resolveConversationAliases(instanceId, remoteJid)
    const targets = remoteJids.length ? remoteJids : [remoteJid || ""]
    const db = new sqlite3.Database(DB_PATH)

    return new Promise((resolve, reject) => {
        db.serialize(() => {
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
                targets.forEach(targetRemote => {
                    db.run(sql, [instanceId, targetRemote], err => {
                        if (err) {
                            hasError = true
                        }
                        completed++
                        if (completed === statements.length * targets.length) {
                            finish()
                        }
                    })
                })
            })
        })
    })
}

async function updateTaxaR(instanceId, remoteJid) {
    if (!instanceId || !remoteJid) {
        return 0
    }
    const inboundCount = await getInboundMessageCount(instanceId, remoteJid)
    const outboundCount = await getOutboundMessageCount(instanceId, remoteJid)
    const taxar = outboundCount > 0 ? (inboundCount / outboundCount) * 100 : 0

    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            UPDATE contact_metadata
            SET taxar = ?, updated_at = CURRENT_TIMESTAMP
            WHERE instance_id = ? AND remote_jid = ?
        `
        db.run(sql, [taxar, instanceId, remoteJid], function(err) {
            db.close()
            if (err) reject(err)
            else resolve(taxar)
        })
    })
}

async function getGlobalTaxaRAverage(instanceId) {
    if (!instanceId) {
        return 0
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT AVG(taxar) as avg_taxar
            FROM contact_metadata
            WHERE instance_id = ? AND taxar > 0
        `
        db.get(sql, [instanceId], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row ? row.avg_taxar || 0 : 0)
        })
    })
}

// Meta API Templates functions
async function upsertMetaTemplate(instanceId, templateName, payload = {}) {
    const db = new sqlite3.Database(DB_PATH)
    const {
        status = 'pending',
        category = null,
        language = 'pt_BR',
        components = null
    } = payload
    const componentsJson = components ? JSON.stringify(components) : null

    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO meta_templates (instance_id, template_name, status, category, language, components_json, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, template_name, language) DO UPDATE SET
                status = excluded.status,
                category = excluded.category,
                components_json = excluded.components_json,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, templateName, status, category, language, componentsJson], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function getMetaTemplate(instanceId, templateName, language = 'pt_BR') {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, instance_id, template_name, status, category, language, components_json, created_at, updated_at
            FROM meta_templates
            WHERE instance_id = ? AND template_name = ? AND language = ?
            LIMIT 1
        `
        db.get(sql, [instanceId, templateName, language], (err, row) => {
            db.close()
            if (err) reject(err)
            else if (row) {
                resolve({
                    ...row,
                    components: row.components_json ? JSON.parse(row.components_json) : null
                })
            } else {
                resolve(null)
            }
        })
    })
}

// ===== LID/PN MAPPING FUNCTIONS =====

// Ensure LID/PN columns exist in contact_metadata
function ensureLIDPNColumns(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(contact_metadata)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasLid = rows.some(row => row.name === 'lid')
            const hasPn = rows.some(row => row.name === 'pn')
            const hasFormattedPhone = rows.some(row => row.name === 'formatted_phone')
            
            const tasks = []
            if (!hasLid) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE contact_metadata ADD COLUMN lid TEXT`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasPn) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE contact_metadata ADD COLUMN pn TEXT`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasFormattedPhone) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE contact_metadata ADD COLUMN formatted_phone TEXT`, alterErr => {
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

// Ensure LID message columns exist in messages table
function ensureLIDMessageColumns(db) {
    return new Promise((resolve, reject) => {
        db.all(`PRAGMA table_info(messages)`, (err, rows) => {
            if (err) {
                return reject(err)
            }
            const hasRemoteJidAlt = rows.some(row => row.name === 'remote_jid_alt')
            const hasParticipantAlt = rows.some(row => row.name === 'participant_alt')
            const hasSenderPn = rows.some(row => row.name === 'sender_pn')
            
            const tasks = []
            if (!hasRemoteJidAlt) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE messages ADD COLUMN remote_jid_alt TEXT`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasParticipantAlt) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE messages ADD COLUMN participant_alt TEXT`, alterErr => {
                        if (alterErr) rej(alterErr)
                        else res()
                    })
                }))
            }
            if (!hasSenderPn) {
                tasks.push(new Promise((res, rej) => {
                    db.run(`ALTER TABLE messages ADD COLUMN sender_pn TEXT`, alterErr => {
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

// Save LID to PN mapping.
// Backward compatible signatures:
// - saveLIDPNMapping(instanceId, lid, pn)
// - saveLIDPNMapping(lid, pn)
async function saveLIDPNMapping(arg1, arg2, arg3) {
    const hasInstance = typeof arg3 !== "undefined"
    const instanceId = hasInstance ? String(arg1 || "") : ""
    const lid = hasInstance ? arg2 : arg1
    const pnRaw = hasInstance ? arg3 : arg2
    const pn = normalizePhoneDigits(pnRaw)
    if (!lid || !pn) return Promise.resolve(null)

    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        if (!instanceId) {
            const updateSql = `
                UPDATE contact_metadata
                SET pn = COALESCE(?, pn),
                    formatted_phone = COALESCE(?, formatted_phone),
                    updated_at = CURRENT_TIMESTAMP
                WHERE lid = ?
            `
            db.run(updateSql, [pn, formatBrazilianPhone(pn), lid], function(err) {
                db.close()
                if (err) reject(err)
                else resolve({ ok: this.changes > 0, lid, pn, updated: this.changes || 0 })
            })
            return
        }

        const sql = `
            INSERT INTO contact_metadata (instance_id, remote_jid, lid, pn, formatted_phone, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, remote_jid) DO UPDATE SET
                lid = COALESCE(excluded.lid, contact_metadata.lid),
                pn = COALESCE(excluded.pn, contact_metadata.pn),
                formatted_phone = COALESCE(excluded.formatted_phone, contact_metadata.formatted_phone),
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [instanceId, lid, lid, pn, formatBrazilianPhone(pn)], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ ok: true, lid, pn, updated: this.changes || 0 })
        })
    })
}

// Get PN from LID.
// Backward compatible signatures:
// - getPNFromLID(instanceId, lid)
// - getPNFromLID(lid)
async function getPNFromLID(arg1, arg2) {
    const hasInstance = typeof arg2 !== "undefined"
    const instanceId = hasInstance ? String(arg1 || "") : ""
    const lid = hasInstance ? arg2 : arg1
    if (!lid) return Promise.resolve(null)

    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = hasInstance && instanceId
            ? `SELECT pn FROM contact_metadata WHERE instance_id = ? AND lid = ? AND pn IS NOT NULL ORDER BY updated_at DESC LIMIT 1`
            : `SELECT pn FROM contact_metadata WHERE lid = ? AND pn IS NOT NULL ORDER BY updated_at DESC LIMIT 1`
        const params = hasInstance && instanceId ? [instanceId, lid] : [lid]
        db.get(sql, params, (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(normalizePhoneDigits(row ? row.pn : null) || null)
        })
    })
}

// Format Brazilian phone number
function formatBrazilianPhone(phone) {
    if (!phone) return ''
    
    const digits = String(phone).replace(/\D/g, '')
    if (!digits || digits.length < 10) return phone
    
    // Brazilian format: +55 XX XXXXX-XXXX
    if (digits.startsWith('55') && digits.length >= 12) {
        const country = '55'
        const area = digits.slice(2, 4)
        const prefix = digits.slice(4, -4)
        const suffix = digits.slice(-4)
        return `+${country} ${area} ${prefix}-${suffix}`
    }
    
    // Generic format for other numbers
    if (digits.length >= 10) {
        const area = digits.slice(0, 2)
        const prefix = digits.slice(2, -4)
        const suffix = digits.slice(-4)
        return `${area} ${prefix}-${suffix}`
    }
    
    return phone
}

// Resolve LID to PN and format phone number
async function resolveLIDtoPN(arg1, arg2) {
    const hasInstance = typeof arg2 !== "undefined"
    const instanceId = hasInstance ? String(arg1 || "") : ""
    const lid = hasInstance ? arg2 : arg1
    if (!lid || !String(lid).includes('@lid')) {
        return { pn: null, formattedPhone: null, isLID: false }
    }
    
    // Check database first
    const cachedPN = hasInstance && instanceId
        ? await getPNFromLID(instanceId, lid)
        : await getPNFromLID(lid)
    if (cachedPN) {
        return {
            pn: cachedPN,
            formattedPhone: formatBrazilianPhone(cachedPN),
            isLID: true
        }
    }
    
    return { pn: null, formattedPhone: null, isLID: true }
}

// Update contact metadata with LID/PN info
async function updateContactIdentity(instanceId, remoteJid, lid, pn) {
    if (!instanceId || !remoteJid) return Promise.resolve(null)
    
    const db = new sqlite3.Database(DB_PATH)
    const normalizedPn = normalizePhoneDigits(pn)
    const formattedPhone = normalizedPn ? formatBrazilianPhone(normalizedPn) : null
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO contact_metadata (instance_id, remote_jid, lid, pn, formatted_phone, updated_at)
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id, remote_jid) DO UPDATE SET
                lid = COALESCE(excluded.lid, contact_metadata.lid),
                pn = COALESCE(excluded.pn, contact_metadata.pn),
                formatted_phone = COALESCE(excluded.formatted_phone, contact_metadata.formatted_phone),
                updated_at = CURRENT_TIMESTAMP
        `
        
        db.run(sql, [instanceId, remoteJid, lid, normalizedPn || null, formattedPhone], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes, pn: normalizedPn || null, formattedPhone })
        })
    })
}

// Get contact identity with resolved PN
async function getContactIdentity(instanceId, remoteJid) {
    if (!instanceId || !remoteJid) return Promise.resolve(null)
    
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT contact_name, status_name, profile_picture, lid, pn, formatted_phone, temperature, taxar
            FROM contact_metadata
            WHERE instance_id = ? AND remote_jid = ?
            LIMIT 1
        `
        
        db.get(sql, [instanceId, remoteJid], (err, row) => {
            db.close()
            if (err) reject(err)
            else if (row) {
                resolve({
                    ...row,
                    formatted_phone: row.formatted_phone || formatBrazilianPhone(row.pn)
                })
            } else {
                resolve(null)
            }
        })
    })
}

async function listMetaTemplates(instanceId, status = null, language = null) {
    const db = new sqlite3.Database(DB_PATH)
    const filters = ['instance_id = ?']
    const params = [instanceId]
    
    if (status) {
        filters.push('status = ?')
        params.push(status)
    }
    if (language) {
        filters.push('language = ?')
        params.push(language)
    }

    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, instance_id, template_name, status, category, language, components_json, created_at, updated_at
            FROM meta_templates
            WHERE ${filters.join(' AND ')}
            ORDER BY template_name ASC, language ASC
        `
        db.all(sql, params, (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows.map(row => ({
                ...row,
                components: row.components_json ? JSON.parse(row.components_json) : null
            })))
        })
    })
}

async function deleteMetaTemplate(instanceId, templateName, language = 'pt_BR') {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `DELETE FROM meta_templates WHERE instance_id = ? AND template_name = ? AND language = ?`
        db.run(sql, [instanceId, templateName, language], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
        })
    })
}

// Meta API Webhook Events functions
async function logMetaWebhookEvent(instanceId, eventType, eventData, phoneNumberId = null) {
    const db = new sqlite3.Database(DB_PATH)
    const eventDataJson = JSON.stringify(eventData)
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO meta_webhook_events (instance_id, phone_number_id, event_type, event_data)
            VALUES (?, ?, ?, ?)
        `
        db.run(sql, [instanceId, phoneNumberId, eventType, eventDataJson], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ loggedId: this.lastID })
        })
    })
}

async function getMetaWebhookEvents(instanceId, eventType = null, processed = null, limit = 100) {
    const db = new sqlite3.Database(DB_PATH)
    const filters = ['instance_id = ?']
    const params = [instanceId]
    
    if (eventType) {
        filters.push('event_type = ?')
        params.push(eventType)
    }
    if (processed !== null) {
        filters.push('processed = ?')
        params.push(processed ? 1 : 0)
    }

    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, instance_id, phone_number_id, event_type, event_data, timestamp, processed
            FROM meta_webhook_events
            WHERE ${filters.join(' AND ')}
            ORDER BY timestamp DESC
            LIMIT ?
        `
        db.all(sql, [...params, limit], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows.map(row => ({
                ...row,
                event_data: row.event_data ? JSON.parse(row.event_data) : null
            })))
        })
    })
}

async function markMetaWebhookEventAsProcessed(eventId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            UPDATE meta_webhook_events
            SET processed = 1
            WHERE id = ?
        `
        db.run(sql, [eventId], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function deleteMetaWebhookEvents(instanceId, olderThan = null) {
    const db = new sqlite3.Database(DB_PATH)
    const filters = ['instance_id = ?']
    const params = [instanceId]
    
    if (olderThan) {
        filters.push('timestamp < ?')
        params.push(olderThan)
    }

    return new Promise((resolve, reject) => {
        const sql = `DELETE FROM meta_webhook_events WHERE ${filters.join(' AND ')}`
        db.run(sql, params, function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
        })
    })
}

// Meta API Instance Configuration functions
async function upsertMetaInstanceConfig(instanceId, payload = {}) {
    const db = new sqlite3.Database(DB_PATH)
    const {
        phone_number_id = null,
        business_account_id = null,
        access_token = null,
        verify_token = null,
        app_secret = null,
        phone_number = null,
        display_phone_number = null,
        api_version = 'v22.0',
        status = null
    } = payload

    return new Promise((resolve, reject) => {
        const sql = `
            INSERT INTO meta_instance_config (
                instance_id, phone_number_id, business_account_id, access_token, verify_token, 
                app_secret, phone_number, display_phone_number, api_version, status, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(instance_id) DO UPDATE SET
                phone_number_id = excluded.phone_number_id,
                business_account_id = excluded.business_account_id,
                access_token = excluded.access_token,
                verify_token = excluded.verify_token,
                app_secret = excluded.app_secret,
                phone_number = excluded.phone_number,
                display_phone_number = excluded.display_phone_number,
                api_version = excluded.api_version,
                status = excluded.status,
                updated_at = CURRENT_TIMESTAMP
        `
        db.run(sql, [
            instanceId, phone_number_id, business_account_id, access_token, verify_token,
            app_secret, phone_number, display_phone_number, api_version, status
        ], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ updated: this.changes })
        })
    })
}

async function getMetaInstanceConfig(instanceId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT instance_id, phone_number_id, business_account_id, access_token, verify_token,
                   app_secret, phone_number, display_phone_number, api_version, status, created_at, updated_at
            FROM meta_instance_config
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

async function deleteMetaInstanceConfig(instanceId) {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `DELETE FROM meta_instance_config WHERE instance_id = ?`
        db.run(sql, [instanceId], function(err) {
            db.close()
            if (err) reject(err)
            else resolve({ deleted: this.changes })
        })
    })
}

async function listMetaInstanceConfigs() {
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT instance_id, phone_number_id, business_account_id, phone_number, 
                   display_phone_number, api_version, status, created_at, updated_at
            FROM meta_instance_config
            ORDER BY created_at ASC
        `
        db.all(sql, [], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows || [])
        })
    })
}

// Auth State functions
async function getAuthState(instanceId, key) {
    if (!instanceId || !key) {
        return null
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `SELECT value FROM auth_state WHERE instance_id = ? AND key = ?`
        db.get(sql, [instanceId, key], (err, row) => {
            db.close()
            if (err) reject(err)
            else resolve(row ? row.value : null)
        })
    })
}

async function setAuthState(instanceId, key, value) {
    if (!instanceId || !key) {
        return
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT OR REPLACE INTO auth_state (instance_id, key, value)
            VALUES (?, ?, ?)
        `
        db.run(sql, [instanceId, key, value], (err) => {
            db.close()
            if (err) reject(err)
            else resolve()
        })
    })
}

async function removeAuthState(instanceId, key) {
    if (!instanceId || !key) {
        return
    }
    const db = new sqlite3.Database(DB_PATH)
    return new Promise((resolve, reject) => {
        const sql = `DELETE FROM auth_state WHERE instance_id = ? AND key = ?`
        db.run(sql, [instanceId, key], (err) => {
            db.close()
            if (err) reject(err)
            else resolve()
        })
    })
}

class DatabaseAuthStore {
    constructor(instanceId) {
        this.instanceId = instanceId
    }

    async get(key) {
        try {
            const value = await getAuthState(this.instanceId, key)
            return value ? JSON.parse(value) : null
        } catch (err) {
            console.error('Error getting auth state:', err.message)
            return null
        }
    }

    async set(key, value) {
        try {
            await setAuthState(this.instanceId, key, JSON.stringify(value))
        } catch (err) {
            console.error('Error setting auth state:', err.message)
        }
    }

    async remove(key) {
        try {
            await removeAuthState(this.instanceId, key)
        } catch (err) {
            console.error('Error removing auth state:', err.message)
        }
    }
}

// Cleanup function for orphaned instances
async function cleanupOrphanedInstances(maxAgeHours = 24) {
    const db = new sqlite3.Database(DB_PATH)
    const cutoffTime = new Date(Date.now() - maxAgeHours * 60 * 60 * 1000).toISOString()

    return new Promise((resolve, reject) => {
        const sql = `
            DELETE FROM instances
            WHERE updated_at < ?
            AND status NOT IN ('active', 'starting', 'deleted')
        `
        db.run(sql, [cutoffTime], function(err) {
            db.close()
            if (err) {
                console.error('Error cleaning up orphaned instances:', err.message)
                reject(err)
            } else {
                console.log(`Cleaned up ${this.changes} orphaned instance(s)`)
                resolve({ cleaned: this.changes })
            }
        })
    })
}

// Cleanup completo de uma instância (deleta todos os dados relacionados)
async function deleteInstance(instanceId) {
    if (!instanceId) {
        throw new Error("instance_id é obrigatório")
    }
    
    const DB_PATH = path.join(__dirname, 'chat_data.db')
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        db.serialize(() => {
            const statements = [
                // Meta APIs
                `DELETE FROM meta_templates WHERE instance_id = ?`,
                `DELETE FROM meta_webhook_events WHERE instance_id = ?`,
                `DELETE FROM meta_instance_config WHERE instance_id = ?`,
                
                // Calendar
                `DELETE FROM calendar_accounts WHERE instance_id = ?`,
                `DELETE FROM calendar_calendars WHERE instance_id = ?`,
                
                // Auth
                `DELETE FROM auth_state WHERE instance_id = ?`,
                
                // Diagnostics
                `DELETE FROM diag_events WHERE instance_id = ?`,
                `DELETE FROM diag_heartbeats WHERE instance_id = ?`,
                `DELETE FROM diag_instance_stats WHERE instance_id = ?`,
                
                // Messages & Chat
                `DELETE FROM messages WHERE instance_id = ?`,
                `DELETE FROM chat_history WHERE instance_id = ?`,
                `DELETE FROM threads WHERE instance_id = ?`,
                
                // Scheduled
                `DELETE FROM scheduled_messages WHERE instance_id = ?`,
                `DELETE FROM group_scheduled_messages WHERE instance_id = ?`,
                
                // Groups
                `DELETE FROM group_monitoring WHERE instance_id = ?`,
                `DELETE FROM group_messages WHERE instance_id = ?`,
                `DELETE FROM group_auto_replies WHERE instance_id = ?`,
                
                // Contacts
                `DELETE FROM contacts WHERE instance_id = ?`,
                `DELETE FROM contact_metadata WHERE instance_id = ?`,
                `DELETE FROM contact_context WHERE instance_id = ?`,
                
                // Settings & Variables
                `DELETE FROM settings WHERE instance_id = ?`,
                `DELETE FROM persistent_variables WHERE instance_id = ?`,
                
                // Main instance record (DEVE SER O ÚLTIMO)
                `DELETE FROM instances WHERE instance_id = ?`
            ]
            
            let completed = 0
            const errors = []
            
            statements.forEach((sql, index) => {
                db.run(sql, [instanceId], function(err) {
                    if (err) {
                        errors.push({ statement: index, error: err.message })
                    }
                    completed++
                    if (completed === statements.length) {
                        db.close()
                        if (errors.length > 0) {
                            console.error('[deleteInstance] Errors during cleanup:', errors)
                        }
                        resolve({ 
                            ok: true, 
                            instanceId, 
                            errors: errors.length > 0 ? errors : null 
                        })
                    }
                })
            })
        })
    })
}

// Track instance deletion by marking as deleted
async function markInstanceAsDeleted(instanceId) {
    if (!instanceId) {
        throw new Error("&instance_id& é obrigatório")
    }
    
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            UPDATE instances 
            SET status = 'deleted', updated_at = CURRENT_TIMESTAMP 
            WHERE instance_id = ?
        `
        
        db.run(sql, [instanceId], function(err) {
            db.close()
            if (err) {
                reject(err)
            } else {
                resolve({ marked: this.changes })
            }
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
    // Calendar integration
    getCalendarAccount,
    upsertCalendarAccount,
    clearCalendarAccount,
    listCalendarConfigs,
    getCalendarConfig,
    upsertCalendarConfig,
    deleteCalendarConfig,
    setDefaultCalendarConfig,
    insertCalendarPendingState,
    deleteCalendarPendingState,
    findCalendarPendingState,
    deleteExpiredCalendarPendingStates,
    // Messages
    saveMessage,
    saveContactMetadata,
    getContactMetadata,
    getMessages,
    listMessages: getMessages,
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
    setMonitoredGroups,
    getMonitoredGroups,
    getMonitoredGroup,
    deleteMonitoredGroup,
    saveGroupMessage,
    getGroupMessages,
    enqueueGroupScheduledMessage,
    fetchDueGroupScheduledMessages,
    updateGroupScheduledMessageStatus,
    deleteGroupScheduledMessage,
    getGroupAutoReplies,
    setGroupAutoReplies,
    setPersistentVariable,
    getPersistentVariable,
    listPersistentVariables,
    deletePersistentVariable,
    logEvent,
    getTimeSinceLastInboundMessage,
    getInboundMessageCount,
    getOutboundMessageCount,
    getScheduledMessages,
    getWhatsAppNumberCache,
    setWhatsAppNumberCache,
    // Instances
    saveInstanceRecord,
    getInstanceRecord,
    listInstancesRecords,
    // Meta API
    upsertMetaTemplate,
    getMetaTemplate,
    listMetaTemplates,
    deleteMetaTemplate,
    logMetaWebhookEvent,
    getMetaWebhookEvents,
    markMetaWebhookEventAsProcessed,
    deleteMetaWebhookEvents,
    upsertMetaInstanceConfig,
    getMetaInstanceConfig,
    deleteMetaInstanceConfig,
    listMetaInstanceConfigs,
    // Chats
    getChats,
    // Health
    getDatabaseHealth,
    recordDiagEvent,
    getDiagEvents,
    recordHeartbeat,
    getHeartbeats,
    countDiagEvents,
    getDiagEventsSince,
    upsertInstanceDiagStats,
    getInstanceDiagStats,
    // Context
    getConversationContext,
    // Threads
    getThreadMetadata,
    saveThreadMetadata,
    clearConversation,
    // TaxaR
    updateTaxaR,
    getGlobalTaxaRAverage,
    // LID/PN Mapping
    saveLIDPNMapping,
    getPNFromLID,
    resolveLIDtoPN,
    updateContactIdentity,
    getContactIdentity,
    formatBrazilianPhone,
    // Auth State
    DatabaseAuthStore,
    // Cleanup
    cleanupOrphanedInstances,
    deleteInstance,
    markInstanceAsDeleted
}
