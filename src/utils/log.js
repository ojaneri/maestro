function buildTelemetryContext() {
    const memory = process.memoryUsage()
    const cpuUsage = process.cpuUsage()
    const uptimeSec = process.uptime()
    const cpuLoad = (() => {
        const load = require('os').loadavg && require('os').loadavg()[0]
        return Number.isFinite(load) ? Number(load.toFixed(2)) : null
    })()
    return {
        ts: new Date().toISOString(),
        uptimeSec: Number(uptimeSec.toFixed(2)),
        memRssMb: Number((memory.rss / 1024 / 1024).toFixed(2)),
        heapUsedMb: Number((memory.heapUsed / 1024 / 1024).toFixed(2)),
        cpuLoad,
        nodePid: process.pid,
        hostname: require('os').hostname(),
        cpuUserSec: Number((cpuUsage.user / 1e6).toFixed(3)),
        cpuSystemSec: Number((cpuUsage.system / 1e6).toFixed(3))
    }
}

function logStructured(event, payload = {}, instanceId = process.env.INSTANCE_ID || 'default', waVersion = null, baileysVersion = null) {
    const telemetry = buildTelemetryContext()
    const base = {
        ts: telemetry.ts,
        instance: instanceId,
        event,
        nodePid: telemetry.nodePid,
        hostname: telemetry.hostname,
        uptimeSec: telemetry.uptimeSec,
        memRssMb: telemetry.memRssMb,
        heapUsedMb: telemetry.heapUsedMb,
        cpuLoad: telemetry.cpuLoad,
        waVersion,
        baileysVersion,
        ...payload
    }
    console.log(JSON.stringify(base))
}

function logStructuredException(event, error, extra = {}) {
    if (!error) return
    logStructured(event, {
        error: error?.message || String(error),
        stack: require('./format').truncateText(error?.stack || (typeof error === "string" ? error : ""), 2000),
        ...extra
    })
}

module.exports = {
    buildTelemetryContext,
    logStructured,
    logStructuredException
}