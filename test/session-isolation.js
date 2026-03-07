const assert = require("assert")
const { createSessionContext } = require("../src/utils/session-context")

// Simulate two simultaneous sessions for the same remote JID and ensure
// our session keys stay isolated.
const instanceId = "inst-001"
const remoteJid = "5511987654321@s.whatsapp.net"

const sessionA = createSessionContext(instanceId, remoteJid, null, { sessionId: "session-A" })
const sessionB = createSessionContext(instanceId, remoteJid, null, { sessionId: "session-B" })

assert.notStrictEqual(sessionA.key, sessionB.key, "Different sessions must have different composite keys")
assert.strictEqual(sessionA.remoteJid, remoteJid)
assert.strictEqual(sessionB.remoteJid, remoteJid)

const stateMap = new Map()
stateMap.set(sessionA.key, { data: "user-A" })
stateMap.set(sessionB.key, { data: "user-B" })

assert.strictEqual(stateMap.get(sessionA.key).data, "user-A")
assert.strictEqual(stateMap.get(sessionB.key).data, "user-B")

const contextStore = new Map()

function contextEntryKey(session, key) {
    return `${session.key}|${key}`
}

function setContext(session, key, value) {
    contextStore.set(contextEntryKey(session, key), value)
}

function getContext(session, key) {
    return contextStore.get(contextEntryKey(session, key))
}

setContext(sessionA, "nome", "Alice")
setContext(sessionB, "nome", "Bruno")

assert.strictEqual(getContext(sessionA, "nome"), "Alice")
assert.strictEqual(getContext(sessionB, "nome"), "Bruno")
assert.strictEqual(getContext(sessionA, "cpf"), undefined)

console.log("Session isolation smoke test passed.")
