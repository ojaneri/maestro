const express = require("express")

module.exports = ({ getRouteSnapshot }) => {
    const router = express.Router()

    router.get("/", (req, res) => {
        const snapshot = getRouteSnapshot()
        res.json({
            instanceId: snapshot.instanceId,
            message: "WhatsApp Instance Server with AI",
            connectionStatus: snapshot.connectionStatus,
            whatsappConnected: snapshot.whatsappConnected,
            wsPath: snapshot.wsPath
        })
    })

    router.get("/status", (req, res) => {
        const snapshot = getRouteSnapshot()
        res.json({
            instanceId: snapshot.instanceId,
            connectionStatus: snapshot.connectionStatus,
            whatsappConnected: snapshot.whatsappConnected,
            hasQR: snapshot.hasQR,
            lastConnectionError: snapshot.lastConnectionError,
            connectionSince: snapshot.connectionSince,
            whatsappVersion: snapshot.whatsappVersion,
            baileysVersion: snapshot.baileysVersion,
            browser: snapshot.browser,
            userAgent: snapshot.userAgent
        })
    })

    router.get("/qr", (req, res) => {
        const snapshot = getRouteSnapshot()
        if (!snapshot.qrCodeData) {
            return res.status(404).json({ error: "QR não disponível" })
        }
        res.json({ qr: snapshot.qrCodeData })
    })

    return router
}
