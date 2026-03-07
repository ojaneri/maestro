const { zonedTimeToUtcDate } = require("./date-time-parsers")
const { log } = require("./logger")

let nextScheduledAction = null;

function isNighttime() {
    const now = new Date();
    const saoPauloFormatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Sao_Paulo',
        hour: 'numeric',
        hour12: false
    });
    const hour = parseInt(saoPauloFormatter.format(now));
    return hour >= 20 || hour < 7;
}

function getNextNightActionTime() {
    const now = new Date();
    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Sao_Paulo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        hour12: false
    });
    const parts = formatter.formatToParts(now);
    const year = parseInt(parts.find(p => p.type === 'year').value);
    const month = parseInt(parts.find(p => p.type === 'month').value);
    const day = parseInt(parts.find(p => p.type === 'day').value);
    const hour = parseInt(parts.find(p => p.type === 'hour').value);
    let targetHour;
    if (hour >= 20 || hour < 7) {
        targetHour = 7;
    } else {
        targetHour = 20;
    }
    let utcTarget = zonedTimeToUtcDate({ year, month, day, hour: targetHour, minute: 0, second: 0 }, 'America/Sao_Paulo');
    if (utcTarget <= now) {
        const nextDay = new Date(now);
        nextDay.setDate(now.getDate() + 1);
        const nextParts = formatter.formatToParts(nextDay);
        const nextYear = parseInt(nextParts.find(p => p.type === 'year').value);
        const nextMonth = parseInt(nextParts.find(p => p.type === 'month').value);
        const nextDayNum = parseInt(nextParts.find(p => p.type === 'day').value);
        utcTarget = zonedTimeToUtcDate({ year: nextYear, month: nextMonth, day: nextDayNum, hour: targetHour, minute: 0, second: 0 }, 'America/Sao_Paulo');
    }
    return utcTarget;
}

/**
 * Schedules the night action to disconnect/connect WhatsApp based on time.
 * @param {boolean} whatsappConnected - Current WhatsApp connection status.
 * @param {function} logoutWhatsApp - Function to log out of WhatsApp.
 * @param {function} startWhatsApp - Function to start WhatsApp connection.
 */
function scheduleNightAction(whatsappConnected, logoutWhatsApp, startWhatsApp) {
    const targetTime = getNextNightActionTime();
    const delay = targetTime - Date.now();
    if (nextScheduledAction) clearTimeout(nextScheduledAction);
    nextScheduledAction = setTimeout(() => {
        if (isNighttime()) {
            // During night (20:00-07:00), reduce activity and go offline
            if (whatsappConnected) {
                logoutWhatsApp().catch(err => log("Erro ao desconectar no horário noturno:", err.message));
            }
        } else {
            // During day (07:00-20:00), connect and respond
            if (!whatsappConnected) {
                startWhatsApp().catch(err => log("Erro ao reconectar no horário:", err.message));
            }
        }
        scheduleNightAction(whatsappConnected, logoutWhatsApp, startWhatsApp); // Recurse with current state
    }, delay);
}

module.exports = {
    isNighttime,
    getNextNightActionTime,
    scheduleNightAction
}