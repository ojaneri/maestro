// db.js - SQLite database module for chat persistence
const sqlite3 = require('sqlite3').verbose()
const path = require("path")

// Database path - isolated from main project
const DB_PATH = path.join(__dirname, 'chat_data.db')

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
                    .then(() => resolve(db))
                    .catch(reject)
            }
        })
    })
}

// Create required tables
function createTables(db) {
    return new Promise((resolve, reject) => {
        // Table for chat history
        const chatHistorySQL = `
            CREATE TABLE IF NOT EXISTS chat_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                contact_name TEXT,
                role TEXT NOT NULL CHECK(role IN ('user', 'assistant')),
                content TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `

        // Table for AI settings (replacing the current instance config approach)
        const aiSettingsSQL = `
            CREATE TABLE IF NOT EXISTS ai_settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL UNIQUE,
                openai_enabled INTEGER DEFAULT 0,
                openai_api_key TEXT,
                openai_model TEXT DEFAULT 'gpt-3.5-turbo',
                system_prompt TEXT,
                assistant_prompt TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        `

        // Table for contacts (to store contact info)
        const contactsSQL = `
            CREATE TABLE IF NOT EXISTS contacts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id TEXT NOT NULL,
                remote_jid TEXT NOT NULL,
                contact_name TEXT,
                last_message_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                message_count INTEGER DEFAULT 0,
                UNIQUE(instance_id, remote_jid)
            )
        `

        // Execute table creation
        db.serialize(() => {
            db.run(chatHistorySQL, (err) => {
                if (err) {
                    reject(err)
                    return
                }
                
                db.run(aiSettingsSQL, (err) => {
                    if (err) {
                        reject(err)
                        return
                    }
                    
                    db.run(contactsSQL, (err) => {
                        if (err) {
                            reject(err)
                            return
                        }
                        
                        // Create indexes separately (SQLite doesn't support inline indexes)
                        const indexes = [
                            'CREATE INDEX IF NOT EXISTS idx_chat_instance_contact ON chat_history(instance_id, remote_jid)',
                            'CREATE INDEX IF NOT EXISTS idx_chat_timestamp ON chat_history(timestamp)',
                            'CREATE INDEX IF NOT EXISTS idx_contacts_instance ON contacts(instance_id)',
                            'CREATE INDEX IF NOT EXISTS idx_contacts_last_message ON contacts(last_message_at)'
                        ]
                        
                        let completed = 0
                        indexes.forEach(indexSQL => {
                            db.run(indexSQL, (err) => {
                                completed++
                                if (completed === indexes.length) {
                                    if (err) {
                                        reject(err)
                                    } else {
                                        console.log('Database tables and indexes created successfully')
                                        resolve()
                                    }
                                }
                            })
                        })
                    })
                })
            })
        })
    })
}

// Save chat message
async function saveChatMessage(instanceId, remoteJid, contactName, role, content) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        db.serialize(() => {
            // Insert message
            const insertMessageSQL = `
                INSERT INTO chat_history (instance_id, remote_jid, contact_name, role, content)
                VALUES (?, ?, ?, ?, ?)
            `
            
            db.run(insertMessageSQL, [instanceId, remoteJid, contactName, role, content], function(err) {
                if (err) {
                    db.close()
                    reject(err)
                    return
                }
                
                // Update contact info
                const updateContactSQL = `
                    INSERT OR REPLACE INTO contacts (instance_id, remote_jid, contact_name, last_message_at, message_count)
                    VALUES (
                        ?, ?, ?, 
                        COALESCE((SELECT last_message_at FROM contacts WHERE instance_id = ? AND remote_jid = ?), CURRENT_TIMESTAMP),
                        COALESCE((SELECT message_count FROM contacts WHERE instance_id = ? AND remote_jid = ?) + 1, 1)
                    )
                `
                
                db.run(updateContactSQL, [instanceId, remoteJid, contactName, instanceId, remoteJid, instanceId, remoteJid], (err) => {
                    db.close()
                    if (err) reject(err)
                    else resolve({ messageId: this.lastID })
                })
            })
        })
    })
}

// Get chat history for a contact
async function getChatHistory(instanceId, remoteJid, limit = 50, offset = 0) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT id, role, content, timestamp, contact_name
            FROM chat_history 
            WHERE instance_id = ? AND remote_jid = ?
            ORDER BY timestamp DESC 
            LIMIT ? OFFSET ?
        `
        
        db.all(sql, [instanceId, remoteJid, limit, offset], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows.reverse()) // Return in chronological order
        })
    })
}

// Get all contacts with last message info
async function getContacts(instanceId, limit = 100, offset = 0) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT 
                remote_jid,
                contact_name,
                last_message_at,
                message_count,
                (
                    SELECT content 
                    FROM chat_history 
                    WHERE instance_id = contacts.instance_id 
                    AND remote_jid = contacts.remote_jid 
                    ORDER BY timestamp DESC 
                    LIMIT 1
                ) as last_message,
                (
                    SELECT role 
                    FROM chat_history 
                    WHERE instance_id = contacts.instance_id 
                    AND remote_jid = contacts.remote_jid 
                    ORDER BY timestamp DESC 
                    LIMIT 1
                ) as last_role
            FROM contacts 
            WHERE instance_id = ?
            ORDER BY last_message_at DESC
            LIMIT ? OFFSET ?
        `
        
        db.all(sql, [instanceId, limit, offset], (err, rows) => {
            db.close()
            if (err) reject(err)
            else resolve(rows)
        })
    })
}

// Save or update AI settings
async function saveAISettings(instanceId, settings) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            INSERT OR REPLACE INTO ai_settings 
            (instance_id, openai_enabled, openai_api_key, openai_model, system_prompt, assistant_prompt, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        `
        
        const params = [
            instanceId,
            settings.enabled ? 1 : 0,
            settings.api_key || null,
            settings.model || 'gpt-3.5-turbo',
            settings.system_prompt || null,
            settings.assistant_prompt || null
        ]
        
        db.run(sql, params, (err) => {
            db.close()
            if (err) reject(err)
            else resolve()
        })
    })
}

// Get AI settings
async function getAISettings(instanceId) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise((resolve, reject) => {
        const sql = `
            SELECT openai_enabled, openai_api_key, openai_model, system_prompt, assistant_prompt
            FROM ai_settings 
            WHERE instance_id = ?
        `
        
        db.get(sql, [instanceId], (err, row) => {
            db.close()
            if (err) reject(err)
            else if (row) {
                resolve({
                    enabled: !!row.openai_enabled,
                    api_key: row.openai_api_key,
                    model: row.openai_model || 'gpt-3.5-turbo',
                    system_prompt: row.system_prompt,
                    assistant_prompt: row.assistant_prompt
                })
            } else {
                resolve({
                    enabled: false,
                    api_key: null,
                    model: 'gpt-3.5-turbo',
                    system_prompt: null,
                    assistant_prompt: null
                })
            }
        })
    })
}

// Get conversation context (last N messages + system prompt)
async function getConversationContext(instanceId, remoteJid, limit = 10) {
    const db = new sqlite3.Database(DB_PATH)
    
    return new Promise(async (resolve, reject) => {
        try {
            // Get recent messages
            const messages = await new Promise((resolve, reject) => {
                const sql = `
                    SELECT role, content, timestamp
                    FROM chat_history 
                    WHERE instance_id = ? AND remote_jid = ?
                    ORDER BY timestamp DESC 
                    LIMIT ?
                `
                
                db.all(sql, [instanceId, remoteJid, limit], (err, rows) => {
                    if (err) reject(err)
                    else resolve(rows.reverse()) // Return in chronological order
                })
            })
            
            // Get AI settings for system prompt
            const aiSettings = await getAISettings(instanceId)
            
            // Build context
            const context = []
            
            if (aiSettings.system_prompt) {
                context.push({
                    role: 'system',
                    content: aiSettings.system_prompt
                })
            }
            
            // Add recent messages
            messages.forEach(msg => {
                context.push({
                    role: msg.role,
                    content: msg.content
                })
            })
            
            db.close()
            resolve({
                messages: context,
                aiSettings
            })
        } catch (err) {
            db.close()
            reject(err)
        }
    })
}

// Export functions
module.exports = {
    initDatabase,
    saveChatMessage,
    getChatHistory,
    getContacts,
    saveAISettings,
    getAISettings,
    getConversationContext
}