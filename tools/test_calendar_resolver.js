const assert = require("assert")
const calendarResolver = require("../src/utils/calendarResolver")

function runTest(name, fn) {
    try {
        fn()
        console.log(`PASS: ${name}`)
    } catch (err) {
        console.error(`FAIL: ${name}`)
        console.error(err)
        process.exit(1)
    }
}

runTest("Scenario A: resolveCalendar(1) and resolveCalendar(0) succeed, resolveCalendar(2) fails", () => {
    const configA = {
        google_calendars: [
            "primary@group.calendar.google.com"
        ],
        calendar_active_num: "1"
    }

    const resolvedOne = calendarResolver.resolveCalendar(configA, 1)
    assert.ok(resolvedOne.ok, "calendar_num=1 should resolve")
    assert.strictEqual(resolvedOne.calendar.num, 1)

    const resolvedZero = calendarResolver.resolveCalendar(configA, 0)
    assert.ok(resolvedZero.ok, "calendar_num=0 should still resolve for backward compatibility")
    assert.strictEqual(resolvedZero.calendar.num, 1)

    const resolvedTwo = calendarResolver.resolveCalendar(configA, 2)
    assert.strictEqual(resolvedTwo.ok, false, "calendar_num=2 should fail when only one calendar exists")
    assert.strictEqual(resolvedTwo.code, "ERR_CALENDAR_CONFIG")

    const tz = calendarResolver.resolveTimezone(configA, "")
    const error = calendarResolver.calendarError(
        "test",
        2,
        null,
        tz,
        resolvedTwo.calendars,
        resolvedTwo,
        { context: "scenario A" }
    )
    assert.deepStrictEqual(error.payload.data.allowed_calendar_nums, [1])
})

runTest("Scenario B: no calendars configured should fail with empty allowed list", () => {
    const configB = {}
    const resolved = calendarResolver.resolveCalendar(configB, 1)
    assert.strictEqual(resolved.ok, false)
    assert.strictEqual(resolved.code, "ERR_CALENDAR_CONFIG")

    const tz = calendarResolver.resolveTimezone(configB, "")
    const error = calendarResolver.calendarError(
        "test",
        1,
        null,
        tz,
        resolved.calendars,
        resolved,
        { context: "scenario B" }
    )
    assert.deepStrictEqual(error.payload.data.allowed_calendar_nums, [])
})

runTest("Scenario C: invalid timezone falls back to instance or default", () => {
    const configC = { timezone: "America/Sao_Paulo" }
    const tzRequested = "Invalid/Zone"
    const tzEffective = calendarResolver.resolveTimezone(configC, tzRequested)
    assert.strictEqual(tzEffective, "America/Sao_Paulo", "should use instance timezone when argument invalid")

    const fallback = calendarResolver.resolveTimezone({}, tzRequested)
    assert.strictEqual(fallback, calendarResolver.APP_TIMEZONE_DEFAULT, "should use default timezone when everything else invalid")
})

console.log("=== Calendar resolver smoke tests passed ===")
