/**
 * @fileoverview Calendar availability checking and slot suggestions
 * @module whatsapp-server/calendar/availability
 * 
 * Code extracted from: whatsapp-server-intelligent.js (lines ~1456-1564)
 * Verifies availability and suggests time slots using Google Calendar API
 */

const oauth = require('./oauth');

// Default availability config (business hours)
const DEFAULT_AVAILABILITY = {
    monday: [{ start: "08:00", end: "18:00" }],
    tuesday: [{ start: "08:00", end: "18:00" }],
    wednesday: [{ start: "08:00", end: "18:00" }],
    thursday: [{ start: "08:00", end: "18:00" }],
    friday: [{ start: "08:00", end: "18:00" }],
    saturday: [],
    sunday: []
};

/**
 * Parse availability JSON from database
 * @param {string|null} availabilityJson - JSON string or null
 * @returns {Object}
 */
function parseAvailabilityJson(availabilityJson) {
    if (!availabilityJson) return DEFAULT_AVAILABILITY;
    try {
        const parsed = JSON.parse(availabilityJson);
        return parsed && typeof parsed === 'object' ? parsed : DEFAULT_AVAILABILITY;
    } catch {
        return DEFAULT_AVAILABILITY;
    }
}

/**
 * Parse calendar date string (YYYY-MM-DD)
 * @param {string} dateStr - Date string
 * @returns {Object}
 */
function parseCalendarDate(dateStr) {
    const match = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) throw new Error(`Invalid date format: ${dateStr}`);
    return {
        year: parseInt(match[1], 10),
        month: parseInt(match[2], 10),
        day: parseInt(match[3], 10)
    };
}

/**
 * Parse time string (HH:mm or HH:mm:ss)
 * @param {string} timeStr - Time string
 * @returns {Object}
 */
function parseCalendarTime(timeStr) {
    const match = String(timeStr).match(/^(\d{2}):(\d{2})(?::(\d{2}))?$/);
    if (!match) throw new Error(`Invalid time format: ${timeStr}`);
    return {
        hour: parseInt(match[1], 10),
        minute: parseInt(match[2], 10),
        second: match[3] ? parseInt(match[3], 10) : 0
    };
}

/**
 * Parse calendar datetime string
 * @param {string} dateStr - Date string (YYYY-MM-DD)
 * @param {string|null} timeStr - Time string or null
 * @param {string} timeZone - Timezone
 * @returns {Date}
 */
function parseCalendarDateTime(dateStr, timeStr = null, timeZone = "America/Fortaleza") {
    const { year, month, day } = parseCalendarDate(dateStr);
    
    let hour = 0, minute = 0, second = 0;
    if (timeStr) {
        const time = parseCalendarTime(timeStr);
        hour = time.hour;
        minute = time.minute;
        second = time.second || 0;
    }
    
    // Create date in specified timezone
    const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
    
    // Simple UTC return (production would use proper timezone conversion)
    return date;
}

/**
 * Parse window range (e.g., "08:00-12:00")
 * @param {string} windowArg - Window string
 * @returns {Object}
 */
function parseWindowRange(windowArg) {
    const match = String(windowArg).match(/^(\d{2}:\d{2})(?:-\s*(\d{2}:\d{2}))?$/);
    if (!match) {
        throw new Error(`Invalid window format: ${windowArg}. Use HH:mm-HH:mm`);
    }
    return {
        start: match[1],
        end: match[2] || match[1]
    };
}

/**
 * Convert to UTC date from zoned time
 * @param {Object} time - Time object with year, month, day, hour, minute
 * @param {string} timeZone - Timezone string
 * @returns {Date}
 */
function zonedTimeToUtcDate(time, timeZone) {
    // Simplified implementation - production would use proper timezone library
    return new Date(Date.UTC(time.year, time.month - 1, time.day, time.hour, time.minute));
}

/**
 * Normalize availability config
 * @param {Object|null} availability - Availability config
 * @returns {Object}
 */
function normalizeAvailability(availability) {
    if (!availability) return { step_minutes: 30 };
    return {
        ...availability,
        step_minutes: availability.step_minutes || 30
    };
}

/**
 * Check if slot overlaps with busy periods
 * @param {Date} slotStart 
 * @param {Date} slotEnd 
 * @param {Date} busyStart 
 * @param {Date} busyEnd 
 * @returns {boolean}
 */
function slotsOverlap(slotStart, slotEnd, busyStart, busyEnd) {
    return slotStart.getTime() < busyEnd.getTime() && slotEnd.getTime() > busyStart.getTime();
}

/**
 * Check if slot is within availability windows
 * @param {Date} slotStart 
 * @param {Date} slotEnd 
 * @param {Object} availability - Availability config
 * @param {string} timeZone - Timezone
 * @returns {boolean}
 */
function isSlotWithinAvailability(slotStart, slotEnd, availability, timeZone) {
    if (!availability) return true;
    
    // Get day name in Portuguese
    const days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
    const dayName = days[slotStart.getDay()];
    const windows = availability[dayName] || [];
    
    if (!windows.length) return false;
    
    // Get slot time in local timezone
    const slotHour = slotStart.getHours();
    const slotMinute = slotStart.getMinutes();
    const slotTime = slotHour * 60 + slotMinute;
    
    // Check if slot fits within any availability window
    for (const window of windows) {
        const [startHour, startMinute] = window.start.split(':').map(Number);
        const [endHour, endMinute] = window.end.split(':').map(Number);
        
        const windowStart = startHour * 60 + startMinute;
        const windowEnd = endHour * 60 + endMinute;
        
        if (slotTime >= windowStart && slotTime + (slotEnd - slotStart) / 60000 <= windowEnd) {
            return true;
        }
    }
    
    return false;
}

/**
 * Format datetime in timezone
 * @param {Date} date - Date object
 * @param {string} timeZone - Timezone
 * @returns {string}
 */
function formatDateTimeInTimeZone(date, timeZone) {
    const formatter = new Intl.DateTimeFormat("pt-BR", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false
    });
    return formatter.format(date);
}

/**
 * Format calendar datetime
 * @param {Date} date - Date object
 * @param {string} timeZone - Timezone
 * @returns {string}
 */
function formatCalendarDateTime(date, timeZone) {
    const formatter = new Intl.DateTimeFormat("en-CA", {
        timeZone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false
    });
    const parts = formatter.formatToParts(date);
    const values = Object.fromEntries(parts.map(part => [part.type, part.value]));
    return `${values.year}-${values.month}-${values.day}T${values.hour}:${values.minute}:${values.second}`;
}

/**
 * Parse calendar slot arguments
 * @param {Array} args - Command arguments
 * @param {string|null} timeZoneFallback - Default timezone
 * @returns {Object}
 */
function parseCalendarSlotArgs(args, timeZoneFallback) {
    const [arg1, arg2, arg3, arg4, arg5] = args;
    const a1 = (arg1 || "").trim();
    const a2 = (arg2 || "").trim();
    const a3 = (arg3 || "").trim();
    const a4 = (arg4 || "").trim();
    const a5 = (arg5 || "").trim();
    
    // Format: date time duration calendarId timeZone
    if (a1 && a2 && /^[0-9]+$/.test(a2)) {
        const timeZone = a4 || timeZoneFallback;
        const start = parseCalendarDateTime(a1, null, timeZone);
        const duration = Number(a2);
        return {
            start,
            end: new Date(start.getTime() + duration * 60000),
            duration,
            calendarId: a3 || null,
            timeZone: timeZone || null
        };
    }
    
    // Format: date time endTime calendarId timeZone
    if (a1 && a2 && a3 && /^[0-9]+$/.test(a3)) {
        const timeZone = a5 || timeZoneFallback;
        const start = parseCalendarDateTime(a1, a2, timeZone);
        const duration = Number(a3);
        return {
            start,
            end: new Date(start.getTime() + duration * 60000),
            duration,
            calendarId: a4 || null,
            timeZone: timeZone || null
        };
    }
    
    // Format: date endDate calendarId timeZone
    if (a1 && a2) {
        const timeZone = a4 || timeZoneFallback;
        const start = parseCalendarDateTime(a1, null, timeZone);
        const end = parseCalendarDateTime(a2, null, timeZone);
        return {
            start,
            end,
            duration: null,
            calendarId: a3 || null,
            timeZone: timeZone || null
        };
    }
    
    throw new Error("calendar: informe inicio e fim ou duração");
}

/**
 * Parse attendees string to array
 * @param {string} input - Attendees input
 * @returns {Array}
 */
function parseAttendees(input) {
    if (!input) return [];
    const raw = String(input);
    const parts = raw.split(/[,;]+/).map(item => item.trim()).filter(Boolean);
    return parts.map(email => ({ email }));
}

/**
 * Check availability for a specific slot
 * @param {Object} calendarService - Calendar API service
 * @param {string} calendarId - Calendar ID
 * @param {Date} start - Start time
 * @param {Date} end - End time
 * @param {Object} config - Calendar config
 * @returns {Promise<Object>}
 */
async function verificar_disponibilidade(calendarService, calendarId, start, end, config) {
    try {
        // Fetch busy slots from calendar
        const busySlots = await calendarService.fetchBusySlots(calendarId, start, end);
        const hasBusy = busySlots.some(entry => 
            slotsOverlap(start, end, entry.start, entry.end)
        );
        
        const allowed = isSlotWithinAvailability(start, end, config.availability, config.timezone);
        
        return {
            available: !hasBusy && allowed,
            timeZone: config.timezone,
            calendarId: config.calendar_id,
            busyCount: busySlots.length,
            busySlots
        };
    } catch (error) {
        console.error('Error checking availability:', error);
        return {
            available: false,
            error: error.message
        };
    }
}

/**
 * Suggest available time slots
 * @param {Object} calendarService - Calendar API service
 * @param {string} calendarId - Calendar ID
 * @param {string} dateArg - Date string
 * @param {string} windowArg - Time window
 * @param {number} durationMinutes - Required duration
 * @param {number} limitArg - Max suggestions
 * @param {Object} config - Calendar config
 * @returns {Promise<Object>}
 */
async function sugerir_horarios(calendarService, calendarId, dateArg, windowArg, durationMinutes, limitArg, config) {
    try {
        const { start: windowStart, end: windowEnd } = parseWindowRange(windowArg);
        const { day, month, year } = parseCalendarDate(dateArg);
        const startTime = parseCalendarTime(windowStart);
        const endTime = parseCalendarTime(windowEnd);
        
        const timeZone = config.timezone || "America/Fortaleza";
        const windowStartDate = zonedTimeToUtcDate({ year, month, day, hour: startTime.hour, minute: startTime.minute }, timeZone);
        const windowEndDate = zonedTimeToUtcDate({ year, month, day, hour: endTime.hour, minute: endTime.minute }, timeZone);
        
        if (windowEndDate <= windowStartDate) {
            throw new Error("calendar: janela inválida");
        }
        
        const duration = Math.max(1, Number(durationMinutes || 30));
        const limit = Math.max(1, Number(limitArg || 5));
        const availability = config.availability;
        const stepMinutes = normalizeAvailability(availability)?.step_minutes || 30;
        
        // Fetch busy slots
        const busySlots = await calendarService.fetchBusySlots(calendarId, windowStartDate, windowEndDate);
        
        const suggestions = [];
        for (let cursor = new Date(windowStartDate); 
             cursor.getTime() + duration * 60000 <= windowEndDate.getTime(); 
             cursor = new Date(cursor.getTime() + stepMinutes * 60000)) {
            
            const slotStart = new Date(cursor);
            const slotEnd = new Date(cursor.getTime() + duration * 60000);
            
            if (!isSlotWithinAvailability(slotStart, slotEnd, availability, timeZone)) {
                continue;
            }
            
            const overlaps = busySlots.some(entry => 
                slotsOverlap(slotStart, slotEnd, entry.start, entry.end)
            );
            
            if (overlaps) continue;
            
            suggestions.push({
                start: slotStart.toISOString(),
                end: slotEnd.toISOString(),
                local_start: formatDateTimeInTimeZone(slotStart, timeZone),
                local_end: formatDateTimeInTimeZone(slotEnd, timeZone)
            });
            
            if (suggestions.length >= limit) break;
        }
        
        return {
            timeZone,
            calendarId,
            durationMinutes: duration,
            suggestions
        };
    } catch (error) {
        console.error('Error suggesting slots:', error);
        return {
            suggestions: [],
            error: error.message
        };
    }
}

module.exports = {
    DEFAULT_AVAILABILITY,
    parseAvailabilityJson,
    parseCalendarDate,
    parseCalendarTime,
    parseCalendarDateTime,
    parseWindowRange,
    zonedTimeToUtcDate,
    normalizeAvailability,
    slotsOverlap,
    isSlotWithinAvailability,
    formatDateTimeInTimeZone,
    formatCalendarDateTime,
    parseCalendarSlotArgs,
    parseAttendees,
    verificar_disponibilidade,
    sugerir_horarios
};
