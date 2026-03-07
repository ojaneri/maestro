let db = null
let dbReadyPromise = Promise.resolve()

try {
    const dbModule = require("../../db-updated")
    db = dbModule
    global.db = db; // EXPOSIÇÃO GLOBAL IMEDIATA

    // Initialize database
    dbReadyPromise = db.initDatabase()
        .then(() => {
            console.log("Database initialized successfully")
            return db
        })
        .catch(err => {
            console.log("Database initialization error:", err.message)
            throw err
        })
} catch (err) {
    console.log("Erro ao carregar database module:", err.message)
}

module.exports = {
    db,
    dbReadyPromise
}