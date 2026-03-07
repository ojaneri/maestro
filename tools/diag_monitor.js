#!/usr/bin/env node
const { fetch } = require("undici")
const fs = require("fs")
const path = require("path")

const MAESTRO_URL = (process.env.MAESTRO_URL || "http://localhost:3000").replace(/\/+$/, "")
const DIAG_KEY = (process.env.INTERNAL_DIAG_KEY || process.env.MAESTRO_DIAG_KEY || "").trim()
const POLL_INTERVAL_MS = Math.max(5000, Number(process.env.DIAG_POLL_INTERVAL_MS) || 30000)
const TARGET_INSTANCES = (process.env.DIAG_TARGET_INSTANCES || "").split(",").map(s => s.trim()).filter(Boolean)
const RUN_DURATION_MS = Number(process.env.DIAG_RUN_DURATION_MS) || null
const REPORT_DIR = path.join(__dirname, "..", "storage", "diag_reports")

if (!DIAG_KEY) {
    console.error("[diag-monitor] INTERNAL_DIAG_KEY or MAESTRO_DIAG_KEY is required")
    process.exit(1)
}

function ensureReportDir() {
    if (!fs.existsSync(REPORT_DIR)) {
        fs.mkdirSync(REPORT_DIR, { recursive: true })
    }
}

function buildDiagPath(name) {
    return path.join(REPORT_DIR, name)
}

function serializeReport(report) {
    return JSON.stringify(report, null, 2)
}

function shouldIncludeInstance(instanceId) {
    if (!TARGET_INSTANCES.length) return true
    return TARGET_INSTANCES.includes(instanceId)
}

async function fetchSummaries() {
    const url = `${MAESTRO_URL}/internal/diag/instances?diag_key=${encodeURIComponent(DIAG_KEY)}`
    const response = await fetch(url, {
        headers: {
            "x-maestro-diag-key": DIAG_KEY
        }
    })
    if (!response.ok) {
        throw new Error(`Diag endpoint returned ${response.status}`)
    }
    const body = await response.json()
    if (!body || !Array.isArray(body.instances)) {
        throw new Error("Diag endpoint returned invalid payload")
    }
    return body.instances
}

function detectDisconnectEvents(summary, seenEvents) {
    const events = Array.isArray(summary.recentConnectionEvents) ? summary.recentConnectionEvents : []
    const newDisconnects = []
    for (const event of events) {
        const key = `${summary.instanceId}:${event.id}`
        if (seenEvents.has(key)) continue
        seenEvents.add(key)
        const isDisconnect = ["close", "disconnect"].includes((event.connection || event.state || "").toLowerCase())
        if (isDisconnect) {
            newDisconnects.push({ instanceId: summary.instanceId, event })
        }
    }
    return newDisconnects
}

async function persistReport(report) {
    ensureReportDir()
    const latestPath = buildDiagPath("diag-monitor-latest.json")
    fs.writeFileSync(latestPath, serializeReport(report))
    const timestamp = new Date(report.timestamp).toISOString().replace(/[:.]/g, "-")
    const historyPath = buildDiagPath(`diag-monitor-${timestamp}.json`)
    fs.writeFileSync(historyPath, serializeReport(report))
    const logPath = buildDiagPath("diag-monitor-history.ndjson")
    fs.appendFileSync(logPath, report.rawLine + "\n")
}

async function runMonitor() {
    const seenEvents = new Set()
    let iterations = 0
    const startTime = Date.now()

    while (true) {
        if (RUN_DURATION_MS && Date.now() - startTime >= RUN_DURATION_MS) {
            console.log("[diag-monitor] run duration reached, exiting")
            break
        }
        try {
            const summaries = await fetchSummaries()
            const filtered = summaries.filter(item => shouldIncludeInstance(item.instanceId))
            const snapshot = {
                timestamp: Date.now(),
                intervalMs: POLL_INTERVAL_MS,
                targetInstances: TARGET_INSTANCES,
                iteration: ++iterations,
                instances: {},
                newDisconnectEvents: []
            }
            for (const summary of filtered) {
                const newDisconnects = detectDisconnectEvents(summary, seenEvents)
                snapshot.instances[summary.instanceId] = {
                    connectionStatus: summary.connectionStatus,
                    lastDisconnect: summary.lastDisconnect,
                    reconnectCount: summary.reconnectCount,
                    disconnectsLast24h: summary.disconnectsLast24h,
                    averageConnectedSeconds: summary.averageConnectedSeconds,
                    averageDisconnectedSeconds: summary.averageDisconnectedSeconds,
                    recentHeartbeats: summary.recentHeartbeats || [],
                    recentConnectionEvents: summary.recentConnectionEvents || []
                }
                snapshot.newDisconnectEvents.push(...newDisconnects)
            }
            snapshot.rawLine = JSON.stringify({
                timestamp: new Date(snapshot.timestamp).toISOString(),
                instances: filtered.map(item => item.instanceId),
                disconnectEvents: snapshot.newDisconnectEvents.map(entry => entry.event.id)
            })
            await persistReport(snapshot)
            console.log(`[diag-monitor] ${new Date().toISOString()} - captured ${snapshot.newDisconnectEvents.length} new disconnect(s)`)
        } catch (err) {
            console.error("[diag-monitor] error during poll:", err.message)
        }
        await new Promise(resolve => setTimeout(resolve, POLL_INTERVAL_MS))
    }
}

(async () => {
    console.log("[diag-monitor] starting with config", {
        maestroUrl: MAESTRO_URL,
        pollIntervalMs: POLL_INTERVAL_MS,
        targetInstances: TARGET_INSTANCES,
        runDurationMs: RUN_DURATION_MS
    })
    await runMonitor()
    console.log("[diag-monitor] stopped")
})().catch(err => {
    console.error("[diag-monitor] fatal error", err)
    process.exit(1)
})
