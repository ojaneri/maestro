const { APP_TIMEZONE } = require("../config/constants");

function parseCalendarDate(dateStr) {
    if (!dateStr) return null;
    const parts = dateStr.split("-");
    if (parts.length !== 3) return null;
    return {
        year: parseInt(parts[0]),
        month: parseInt(parts[1]),
        day: parseInt(parts[2])
    };
}

function parseCalendarTime(timeStr) {
    if (!timeStr) return null;
    const parts = timeStr.split(":");
    if (parts.length < 2) return null;
    return {
        hour: parseInt(parts[0]),
        minute: parseInt(parts[1]),
        second: parts[2] ? parseInt(parts[2]) : 0
    };
}

function getTimeZoneOffsetMs(date, timeZone) {
    const tzDate = new Date(date.toLocaleString("en-US", { timeZone }));
    const utcDate = new Date(date.toLocaleString("en-US", { timeZone: "UTC" }));
    return tzDate.getTime() - utcDate.getTime();
}

function zonedTimeToUtcDate({ year, month, day, hour, minute, second = 0 }, timeZone) {
    const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
    const offset = getTimeZoneOffsetMs(date, timeZone);
    return new Date(date.getTime() - offset);
}

function parseCalendarDateTime(dateArg, timeArg, timeZone) {
    const d = parseCalendarDate(dateArg);
    const t = parseCalendarTime(timeArg);
    if (!d || !t) return null;
    return zonedTimeToUtcDate({ ...d, ...t }, timeZone || APP_TIMEZONE);
}

function getWeekdayKey(date, timeZone) {
    const days = ["sunday", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday"];
    const localDate = new Date(date.toLocaleString("en-US", { timeZone }));
    return days[localDate.getDay()];
}

function toMinutesOfDay({ hour, minute }) {
    return hour * 60 + minute;
}

module.exports = {
    parseCalendarDate,
    parseCalendarTime,
    getTimeZoneOffsetMs,
    zonedTimeToUtcDate,
    parseCalendarDateTime,
    getWeekdayKey,
    toMinutesOfDay
};
