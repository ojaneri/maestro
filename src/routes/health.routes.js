const express = require("express")
const { formatBytes } = require("../utils/format")

module.exports = ({ getRouteSnapshot, messageQueue, pendingMultiInputs }) => {
    const router = express.Router()

    router.get("/health", (req, res) => {
        const snapshot = getRouteSnapshot()
        const memoryUsage = process.memoryUsage()
        const cpuUsage = process.cpuUsage()

        const healthStatus = {
            ok: true,
            instanceId: snapshot.instanceId,
            status: snapshot.connectionStatus,
            whatsappConnected: snapshot.whatsappConnected,
            timestamp: new Date().toISOString(),
            uptime: process.uptime(),
            memory: {
                rss: formatBytes(memoryUsage.rss),
                heapTotal: formatBytes(memoryUsage.heapTotal),
                heapUsed: formatBytes(memoryUsage.heapUsed),
                external: formatBytes(memoryUsage.external)
            },
            cpu: {
                user: cpuUsage.user / 1000000,
                system: cpuUsage.system / 1000000
            },
            messageQueue: {
                size: messageQueue.length
            },
            pendingMultiInputs: {
                count: pendingMultiInputs.size
            },
            browser: snapshot.browser,
            userAgent: snapshot.userAgent,
            whatsappVersion: snapshot.whatsappVersion,
            baileysVersion: snapshot.baileysVersion,
            connectionSince: snapshot.connectionSince
        }

        res.json(healthStatus)
    })

    return router
}
