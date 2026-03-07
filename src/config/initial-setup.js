const path = require("path")
const nodeCrypto = require("crypto")
const { Readable } = require("stream")
const { fetch, Headers, Request, Response } = require("undici")
const { INSTANCE_ID } = require('./globals') // Import INSTANCE_ID for validation

// Set timezone
process.env.TZ = "America/Fortaleza"

// Load .env
try {
    const dotenv = require("dotenv")
    dotenv.config({ path: path.join(__dirname, "..", "..", ".env") })
} catch (err) {
    console.warn("[GLOBAL] dotenv não disponível:", err.message)
}

// Polyfills for Baileys/WebCrypto
if (!Readable.fromWeb) {
    Readable.fromWeb = function (webStream) {
        if (!webStream || typeof webStream.getReader !== "function") {
            throw new Error("Readable.fromWeb polyfill requires a Web ReadableStream")
        }
        const reader = webStream.getReader()
        return new Readable({
            async read() {
                try {
                    const { value, done } = await reader.read()
                    if (done) {
                        this.push(null)
                        return
                    }
                    this.push(Buffer.from(value || []))
                } catch (err) {
                    this.destroy(err)
                }
            }
        }).on("close", () => {
            reader.cancel().catch(() => {})
        })
    }
}

if (!globalThis.crypto) {
    if (nodeCrypto.webcrypto) {
        globalThis.crypto = nodeCrypto.webcrypto
    } else {
        console.error(
            "[GLOBAL] crypto.webcrypto não disponível. " +
            "Atualize o Node para 18+ (você já está em 20.x) ou verifique build."
        )
    }
}

if (!globalThis.fetch) {
    globalThis.fetch = fetch
}
globalThis.Headers = globalThis.Headers || Headers
globalThis.Request = globalThis.Request || Request
globalThis.Response = globalThis.Response || Response

// Validate INSTANCE_ID
if (!INSTANCE_ID) {
    console.error("Faltou parâmetro --id=INSTANCE_ID ou variável INSTANCE_ID")
    process.exit(1)
}