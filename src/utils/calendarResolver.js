/**
 * Calendar Resolver Module
 *
 * Provides helpers to list/configure Google Calendar instances, resolve calendar_num
 * values, build user-friendly errors, and keep the legacy API alive for older callers.
 */

const APP_TIMEZONE_DEFAULT = "America/Fortaleza";

function isValidTimeZone(value) {
    if (!value || typeof value !== "string") {
        return false;
    }
    try {
        new Intl.DateTimeFormat(undefined, { timeZone: value.trim() });
        return true;
    } catch (err) {
        return false;
    }
}

function resolveTimezone(instConfig = {}, tzArg) {
    const requested = (tzArg || "").trim();
    if (requested && isValidTimeZone(requested)) {
        return requested;
    }
    const configTz = (instConfig?.timezone || "").trim();
    if (configTz && isValidTimeZone(configTz)) {
        return configTz;
    }
    return APP_TIMEZONE_DEFAULT;
}

/**
 * Normalize calendar list by removing duplicates, trimming, and removing empty values
 * @param {Array} calendars - Array of calendar IDs
 * @returns {Array} - Normalized array of unique calendar IDs
 */
function normalizeCalendars(calendars) {
    if (!Array.isArray(calendars)) {
        return [];
    }

    const seen = new Set();
    const normalized = [];

    for (const cal of calendars) {
        const trimmed = String(cal || "").trim();
        if (!trimmed) continue;

        if (!seen.has(trimmed)) {
            seen.add(trimmed);
            normalized.push(trimmed);
        }
    }

    return normalized;
}

function getConfiguredCalendars(instConfig = {}) {
    const normalizedIds = normalizeCalendars(instConfig?.google_calendars || instConfig?.calendars || []);
    const timezoneDefault = resolveTimezone(instConfig);
    return normalizedIds.map((id, idx) => ({
        num: idx + 1,
        id,
        timezone_default: timezoneDefault
    }));
}

function maskCalendarId(calendarId) {
    if (!calendarId || typeof calendarId !== "string") {
        return "";
    }
    const cleaned = calendarId.trim();
    if (cleaned.length <= 12) {
        return cleaned;
    }
    const prefix = cleaned.slice(0, 6);
    const suffix = cleaned.slice(-6);
    return `${prefix}…${suffix}`;
}

/**
 * Build a mapping between calendar_num and calendar_id
 * @param {Array} calendars - Array of normalized calendar IDs
 * @returns {Object} - Object with map (num->id) and reverseMap (id->num)
 */
function buildCalendarMap(calendars) {
    const normalized = normalizeCalendars(calendars);
    const map = {};
    const reverseMap = {};

    normalized.forEach((calId, index) => {
        const num = String(index + 1);
        map[num] = calId;
        reverseMap[calId] = num;
    });

    return { map, reverseMap, normalized };
}

/**
 * Check if a value looks like a legacy calendar_id (contains @ or looks like Google Calendar ID)
 * @param {string} value - Value to check
 * @returns {boolean}
 */
function looksLikeLegacyCalendarId(value) {
    if (!value || typeof value !== "string") return false;
    const trimmed = value.trim();
    return trimmed.includes("@") || trimmed.includes("group.calendar.google.com");
}

function isCalendarNum(value) {
    if (!value || typeof value !== "string") return false;
    const trimmed = value.trim();
    return /^\d+$/.test(trimmed);
}

function buildCalendarErrorPayload(fnName, calendarNum, timezoneRequested, timezoneEffective, calendars, cause, extraParams = {}) {
    const allowedNums = calendars.map(cal => cal.num);
    const topNum = allowedNums.length ? allowedNums[allowedNums.length - 1] : 0;
    const hint = cause?.hint || (allowedNums.length ? `Use calendar_num=1..${topNum}. Verifique a configuração da instância.` : "Nenhum calendário configurado. Adicione um calendário antes de tentar novamente.");
    const maskedList = calendars.map(cal => ({ num: cal.num, id_masked: maskCalendarId(cal.id) }));
    const payload = {
        ok: false,
        code: cause?.code || "ERR_CALENDAR_CONFIG",
        message: `Falha ao executar ${fnName}: ${cause?.message || "calendário inválido"}.`,
        data: {
            fn: fnName,
            calendar_num_requested: calendarNum,
            timezone_requested: timezoneRequested || null,
            timezone_effective: timezoneEffective,
            allowed_calendar_nums: allowedNums,
            calendars_debug: maskedList,
            hint,
            extra: Object.keys(extraParams || {}).length ? extraParams : undefined
        }
    };

    console.error(`[calendarError] ${fnName}`, {
        calendar_num: calendarNum,
        timezone_requested: timezoneRequested,
        timezone_effective: timezoneEffective,
        calendar_id: calendars.find(cal => cal.num === calendarNum)?.id || calendars[0]?.id || null,
        cause: cause?.message || null,
        stack: cause?.stack || new Error().stack
    });

    const error = new Error(payload.message);
    Object.assign(error, payload);
    error.calendars = calendars;
    error.payload = payload;
    error.isCalendarError = true;
    error.cause = cause;
    return error;
}

// Default calendar fallback when none configured
const DEFAULT_CALENDAR_ID = "default";

function createDefaultCalendar(timezone) {
    return {
        num: 1,
        id: DEFAULT_CALENDAR_ID,
        timezone_default: timezone,
        is_default: true
    };
}

function resolveCalendar(instConfig = {}, calendarNumArg) {
    const calendars = getConfiguredCalendars(instConfig);
    const timezone = resolveTimezone(instConfig);

    // If no calendars configured, use default fallback instead of throwing error
    if (calendars.length === 0) {
        const defaultCalendar = createDefaultCalendar(timezone);
        console.warn(`[calendarResolver] Nenhum calendário configurado. Usando calendário padrão: ${DEFAULT_CALENDAR_ID}`);
        return {
            ok: true,
            calendar: defaultCalendar,
            calendars: [defaultCalendar],
            allowed_calendar_nums: [1],
            calendar_used: 1,
            requested_calendar_num: calendarNumArg || 1,
            is_default: true
        };
    }

    // Action 2: If only one calendar exists, validate the requested number or auto-select
    if (calendars.length === 1) {
        const singleCalendar = calendars[0];
        
        // If no calendar specified or 0 (legacy), auto-select
        if (!calendarNumArg || calendarNumArg === 0 || calendarNumArg === "calendar_id" || calendarNumArg === "" || calendarNumArg === null || calendarNumArg === undefined) {
            console.log(`[calendarResolver] Usando calendário configurado: ${singleCalendar.id} (único calendário)`);
            return {
                ok: true,
                calendar: singleCalendar,
                calendars,
                allowed_calendar_nums: calendars.map(c => c.num),
                calendar_used: singleCalendar.num,
                requested_calendar_num: singleCalendar.num
            };
        }
        
        // If a specific calendar_num is requested, validate it
        const parsedNum = typeof calendarNumArg === "number" ? calendarNumArg : Number(String(calendarNumArg).trim());
        if (Number.isFinite(parsedNum) && parsedNum === singleCalendar.num) {
            console.log(`[calendarResolver] Usando calendário configurado: ${singleCalendar.id} (calendar_num=${parsedNum})`);
            return {
                ok: true,
                calendar: singleCalendar,
                calendars,
                allowed_calendar_nums: calendars.map(c => c.num),
                calendar_used: singleCalendar.num,
                requested_calendar_num: singleCalendar.num
            };
        }
        
        // Invalid calendar_num for single calendar - fall through to error
    }

    const activeNum = Number(instConfig?.calendar_active_num || instConfig?.calendar_active || 1);
    const activeCalendar = calendars.find(cal => cal.num === activeNum) || calendars[0];

    // Action 3: Multiplicity rule - validate or fallback to first calendar
    if (!calendarNumArg || calendarNumArg === "calendar_id" || calendarNumArg === "" || calendarNumArg === null || calendarNumArg === undefined) {
        // Default to first calendar when none specified
        calendarNumArg = calendars[0].num;
    }

    if (looksLikeLegacyCalendarId(calendarNumArg)) {
        const trimmed = String(calendarNumArg).trim();
        const matching = calendars.find(cal => cal.id === trimmed);
        if (matching) {
            console.log(`[calendarResolver] Usando calendário configurado: ${matching.id} (legacy id)`);
            return {
                ok: true,
                calendar: matching,
                calendars,
                allowed_calendar_nums: calendars.map(c => c.num),
                calendar_used: matching.num,
                requested_calendar_num: matching.num,
                legacy: true
            };
        }
        return calendarError(
            "resolveCalendar",
            calendarNumArg,
            null,
            resolveTimezone(instConfig),
            calendars,
            { code: "ERR_CALENDAR_CONFIG", message: `Calendário "${calendarNumArg}" não encontrado entre os calendários configurados.` },
            { hint: `Use calendar_num=1..${calendars.length}. Verifique a configuração da instância.` }
        );
    }

    const parsedNum = (() => {
        if (typeof calendarNumArg === "number") {
            return calendarNumArg;
        }
        const trimmed = String(calendarNumArg).trim();
        return trimmed === "" ? NaN : Number(trimmed);
    })();

    if (!Number.isFinite(parsedNum) || !Number.isInteger(parsedNum)) {
        return calendarError(
            "resolveCalendar",
            calendarNumArg,
            null,
            resolveTimezone(instConfig),
            calendars,
            { code: "ERR_INVALID_ARGS", message: "calendar_num deve ser um número inteiro." },
            { hint: `Use calendar_num=1..${calendars.length}.` }
        );
    }

    let effectiveNum = parsedNum;
    if (effectiveNum === 0) {
        console.warn("[calendarResolver] calendar_num 0 está obsoleto; use calendar_num=1.");
        effectiveNum = 1;
    }

    const index = effectiveNum - 1;
    if (index < 0 || index >= calendars.length) {
        return calendarError(
            "resolveCalendar",
            effectiveNum,
            null,
            resolveTimezone(instConfig),
            calendars,
            { code: "ERR_CALENDAR_CONFIG", message: `calendar_num=${effectiveNum} fora do intervalo configurado.` },
            { hint: `Use calendar_num=1..${calendars.length}.` }
        );
    }

    // Action 4 & 5: Add allowed_calendar_nums and calendar_used to success response
    const calendar_num = effectiveNum;
    console.log(`[calendarResolver] Usando calendário configurado: ${calendars[index].id} (calendar_num=${calendar_num})`);
    return {
        ok: true,
        calendar: calendars[index],
        calendars,
        allowed_calendar_nums: calendars.map(c => c.num),
        calendar_used: calendar_num,
        requested_calendar_num: calendar_num
    };
}

function resolveCalendarIdByNum(instConfig, calendarNumArg) {
    const resolved = resolveCalendar(instConfig, calendarNumArg);
    if (!resolved.ok) {
        return resolved;
    }
    return {
        ok: true,
        calendar_id: resolved.calendar.id,
        calendar_num: String(resolved.calendar.num),
        calendar: resolved.calendar,
        calendars: resolved.calendars
    };
}

function parseCalendarArgs(args, instConfig, calendarArgIndex = 4, timezoneArgIndex = 5) {
    const calendarNumArg = args[calendarArgIndex];
    const timezoneArg = args[timezoneArgIndex];
    const timezone = resolveTimezone(instConfig, timezoneArg);
    const resolved = resolveCalendar(instConfig, calendarNumArg);
    return {
        resolved,
        timeZone: timezone,
        error: resolved.ok ? null : resolved
    };
}

module.exports = {
    APP_TIMEZONE_DEFAULT,
    DEFAULT_CALENDAR_ID,
    isValidTimeZone,
    resolveTimezone,
    normalizeCalendars,
    getConfiguredCalendars,
    createDefaultCalendar,
    maskCalendarId,
    buildCalendarMap,
    looksLikeLegacyCalendarId,
    isCalendarNum,
    resolveCalendar,
    resolveCalendarIdByNum,
    calendarError: buildCalendarErrorPayload,
    parseCalendarArgs
};
