const mysql = require("mysql2/promise")
const { CUSTOMER_DB_CONFIG } = require("../config/config")
const { formatDateForBrazil } = require("../utils/utils")

let customerDbPool = null

async function getCustomerDbPool() {
    if (!customerDbPool) {
        customerDbPool = mysql.createPool({
            ...CUSTOMER_DB_CONFIG,
            waitForConnections: true,
            connectionLimit: 2,
            queueLimit: 0
        })
    }
    return customerDbPool
}

async function fetchCustomerProfileByEmail(email) {
    const normalizedEmail = (email || "").trim()
    if (!normalizedEmail) {
        throw new Error("dados(): email obrigatório")
    }

    const pool = await getCustomerDbPool()
    const sql = `
        SELECT
            username,
            email,
            phone,
            expiration_date,
            DATEDIFF(expiration_date, CURDATE()) AS dias_restantes
        FROM users2
        WHERE email = ?
        LIMIT 1
    `
    const [rows] = await pool.execute(sql, [normalizedEmail])
    const row = Array.isArray(rows) ? rows[0] : null
    if (!row) {
        throw new Error(`dados(): usuário não encontrado para ${normalizedEmail}`)
    }

    const diasRestantes = Number(row.dias_restantes ?? 0)
    const status = diasRestantes >= 0 ? "ATIVO" : "EXPIRADO"
    const assinaturaInfo = diasRestantes >= 0
        ? `${diasRestantes} dias restantes`
        : `${Math.abs(diasRestantes)} dias vencidos`
    return {
        nome: row.username || "",
        email: row.email || normalizedEmail,
        telefone: row.phone || "",
        status,
        assinatura_info: assinaturaInfo,
        data_expiracao: formatDateForBrazil(row.expiration_date)
    }
}

module.exports = {
    getCustomerDbPool,
    fetchCustomerProfileByEmail
}