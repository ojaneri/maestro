// test-scheduler-interno.js - Sanity checks for scheduling internal mode

function assert(condition, message) {
    if (!condition) {
        throw new Error(message || "Assertion failed")
    }
}

function normalizeInternoFlag(value) {
    if (value === true) return true
    if (value === false || value === null || value === undefined) return false
    const normalized = String(value).trim().toLowerCase()
    return normalized === "true" || normalized === "1" || normalized === "yes"
}

function parseRelativeToken(value) {
    const trimmed = (value || "").trim()
    const match = /^([+-])\s*(\d+)\s*([mhd])$/i.exec(trimmed)
    if (!match) {
        return null
    }
    const [, sign, amount, unit] = match
    const multiplier = {
        m: 60 * 1000,
        h: 60 * 60 * 1000,
        d: 24 * 60 * 60 * 1000
    }[unit.toLowerCase()]
    if (!multiplier) {
        return null
    }
    const offset = Number(amount) * multiplier * (sign === "-" ? -1 : 1)
    return { offset, unit }
}

function parseScheduleDateTimeExact(timestampStr) {
    const raw = (timestampStr || "").trim()
    if (!raw) {
        return null
    }
    const match = /^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})$/.exec(raw)
    if (!match) {
        return null
    }
    const year = Number(match[1])
    const month = Number(match[2])
    const day = Number(match[3])
    const hour = Number(match[4])
    const minute = Number(match[5])
    const second = Number(match[6])
    if (![year, month, day, hour, minute, second].every(Number.isFinite)) {
        return null
    }
    if (month < 1 || month > 12) {
        return null
    }
    const maxDay = new Date(year, month, 0).getDate()
    if (day < 1 || day > maxDay) {
        return null
    }
    if (hour < 0 || hour > 23 || minute < 0 || minute > 59 || second < 0 || second > 59) {
        return null
    }
    return {
        year,
        month,
        day,
        hour,
        minute,
        second
    }
}

function runTests() {
    console.log("Running scheduler internal mode tests...")

    assert(normalizeInternoFlag(true) === true, "normalizeInternoFlag(true)")
    assert(normalizeInternoFlag("true") === true, "normalizeInternoFlag('true')")
    assert(normalizeInternoFlag("1") === true, "normalizeInternoFlag('1')")
    assert(normalizeInternoFlag("yes") === true, "normalizeInternoFlag('yes')")
    assert(normalizeInternoFlag(undefined) === false, "normalizeInternoFlag(undefined)")

    assert(parseRelativeToken("+5m")?.unit === "m", "relative +5m")
    assert(parseRelativeToken("+2h")?.unit === "h", "relative +2h")
    assert(parseRelativeToken("+1d")?.unit === "d", "relative +1d")
    assert(parseRelativeToken("5m") === null, "relative invalid missing sign")

    assert(parseScheduleDateTimeExact("2026-01-26 14:30:00") !== null, "valid exact timestamp")
    assert(parseScheduleDateTimeExact("2026-13-26 14:30:00") === null, "invalid month")
    assert(parseScheduleDateTimeExact("2026-01-32 14:30:00") === null, "invalid day")
    assert(parseScheduleDateTimeExact("2026-01-26 25:30:00") === null, "invalid hour")

    console.log("All scheduler internal mode tests passed.")
}

if (require.main === module) {
    try {
        runTests()
    } catch (err) {
        console.error("Tests failed:", err.message)
        process.exit(1)
    }
}

module.exports = { runTests }
