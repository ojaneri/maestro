const { SCHEDULE_CHECK_INTERVAL_MS, SCHEDULE_FETCH_LIMIT, INSTANCE_ID, log } = require("../config/config")
const { db } = require("../infra/database-service")
const whatsappService = require("../infra/whatsapp-service")

let scheduleProcessorHandle = null
let scheduleProcessorRunning = false

async function processScheduledMessages() {
    if (scheduleProcessorRunning) {
        return
    }
    const sock = whatsappService.sock
    const whatsappConnected = whatsappService.whatsappConnected
    if (!db || !sock || !whatsappConnected) {
        return
    }

    scheduleProcessorRunning = true

    try {
        const dueMessages = await db.fetchDueScheduledMessages(INSTANCE_ID, SCHEDULE_FETCH_LIMIT)
        const dueGroupMessages = await db.fetchDueGroupScheduledMessages(INSTANCE_ID, SCHEDULE_FETCH_LIMIT)
        if (!dueMessages.length && !dueGroupMessages.length) {
            return
        }

        for (const job of dueMessages) {
            try {
                await whatsappService.sendWhatsAppMessage(job.remote_jid, { text: job.message })
                await db.saveMessage(INSTANCE_ID, job.remote_jid, "assistant", job.message, "outbound")
                await db.updateScheduledMessageStatus(job.id, "sent")
                log("Mensagem agendada enviada para", job.remote_jid, job.scheduled_at)
            } catch (err) {
                await db.updateScheduledMessageStatus(job.id, "failed", err.message)
                log("Erro ao enviar mensagem agendada", job.id, err.message)
            }
        }

        for (const job of dueGroupMessages) {
            try {
                await whatsappService.sendWhatsAppMessage(job.group_jid, { text: job.message })
                const selfJid = sock?.user?.id || sock?.user?.jid || "self"
                await db.saveGroupMessage(INSTANCE_ID, job.group_jid, selfJid, "outbound", job.message, JSON.stringify({ scheduled: true }))
                await db.updateGroupScheduledMessageStatus(job.id, "sent")
                log("Mensagem agendada enviada para grupo", job.group_jid, job.scheduled_at)
            } catch (err) {
                await db.updateGroupScheduledMessageStatus(job.id, "failed", err.message)
                log("Erro ao enviar mensagem agendada de grupo", job.id, err.message)
            }
        }
    } catch (err) {
        log("Erro ao processar agendamentos:", err.message)
    }
    finally {
        scheduleProcessorRunning = false
    }
}

function startScheduleProcessor() {
    if (scheduleProcessorHandle) return
    processScheduledMessages().catch(err => log("Erro inicial no scheduler:", err.message))
    scheduleProcessorHandle = setInterval(() => {
        processScheduledMessages().catch(err => log("Erro no scheduler:", err.message))
    }, SCHEDULE_CHECK_INTERVAL_MS)
}

module.exports = {
    startScheduleProcessor,
    processScheduledMessages
}
