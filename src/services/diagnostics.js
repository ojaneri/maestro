const { monitorEventLoopDelay } = require("perf_hooks");

let diagEventLoopMonitor = null;
let diagReconnectCount = 0;
let diagAwaitingReconnect = false;
let diagLastDisconnectInfo = null;
let hadSuccessfulConnection = false;

function startEventLoopMonitor() {
    if (!diagEventLoopMonitor) {
        diagEventLoopMonitor = monitorEventLoopDelay({ resolution: 10 });
        diagEventLoopMonitor.enable();
    }
}

function getEventLoopLagMs() {
    if (!diagEventLoopMonitor) return null;
    const mean = diagEventLoopMonitor.mean || 0;
    diagEventLoopMonitor.reset();
    return Number((mean || 0) / 1e6);
}

function determineIsNewLogin(lastDisconnect) {
    const payload = lastDisconnect?.error?.output?.payload || lastDisconnect?.payload || {};
    if (payload?.isNewLogin !== undefined) {
        return Boolean(payload.isNewLogin);
    }
    if (typeof lastDisconnect?.isNewLogin === "boolean") {
        return lastDisconnect.isNewLogin;
    }
    return false;
}

function normalizeDisconnectReason(lastDisconnect) {
    const statusCode = lastDisconnect?.error?.output?.statusCode || 
                       lastDisconnect?.statusCode || 
                       (lastDisconnect?.error?.message?.includes("logged out") ? 401 : 500);
    
    let reason = "unknown";
    if (statusCode === 401) reason = "logged_out";
    else if (statusCode === 408) reason = "timed_out";
    else if (statusCode === 411) reason = "multidevice_mismatch";
    else if (statusCode === 428) reason = "connection_closed";
    else if (statusCode === 440) reason = "stream_error";
    else if (statusCode === 500) reason = "server_error";
    else if (statusCode === 503) reason = "service_unavailable";
    
    return { statusCode, reason };
}

module.exports = {
    startEventLoopMonitor,
    getEventLoopLagMs,
    determineIsNewLogin,
    normalizeDisconnectReason,
    diagState: {
        get reconnectCount() { return diagReconnectCount; },
        set reconnectCount(v) { diagReconnectCount = v; },
        get awaitingReconnect() { return diagAwaitingReconnect; },
        set awaitingReconnect(v) { diagAwaitingReconnect = v; },
        get lastDisconnectInfo() { return diagLastDisconnectInfo; },
        set lastDisconnectInfo(v) { diagLastDisconnectInfo = v; },
        get hadSuccessfulConnection() { return hadSuccessfulConnection; },
        set hadSuccessfulConnection(v) { hadSuccessfulConnection = v; }
    }
};
