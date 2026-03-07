const express = require("express")

// Import debug functions from whatsapp-service
let whatsappService = null
try {
    whatsappService = require("../infra/whatsapp-service")
} catch (e) {
    // whatsapp-service may not be loaded yet
}

module.exports = ({ db, INSTANCE_ID, sock }) => {
    const router = express.Router()

    // Helper function to detect if LID looks like a phone number
    // NOTE: This is NOT used for resolution - PN must come from message metadata
    function lidLooksLikePhone(lid) {
        if (!lid || !lid.includes('@lid')) return false
        const digits = lid.replace(/\D/g, '')
        return digits.length >= 10 && digits.length <= 15
    }

    // Helper function to format Brazilian phone number
    function formatBrazilianPhone(pn) {
        if (!pn) return null
        
        // Extract digits only
        const digits = String(pn).replace(/\D/g, '')
        if (!digits || digits.length < 10) return pn
        
        // Brazilian format with parentheses: +55 (XX) X.XXXX-XXXX
        if (digits.startsWith('55') && digits.length >= 12) {
            const country = '55'
            const area = digits.slice(2, 4)
            const prefix = digits.slice(4, -4)
            const suffix = digits.slice(-4)
            // Format: +55 (XX) X.XXXX-XXXX
            return `+${country} (${area}) ${prefix.charAt(0)}.${prefix.slice(1)}-${suffix}`
        }
        
        // Generic format for other numbers
        if (digits.length >= 10) {
            const area = digits.slice(0, 2)
            const prefix = digits.slice(2, -4)
            const suffix = digits.slice(-4)
            return `${area} ${prefix}-${suffix}`
        }
        
        return pn
    }
    
    // Helper function to check if JID is in phone number format
    function isPNFormat(jid) {
        if (!jid) return false
        return /^\d{10,15}$/.test(String(jid).replace(/\D/g, ''))
    }
    
    // Helper function to normalize PN from various formats
    function normalizePN(jid) {
        if (!jid) return null
        
        // Handle LID format: lid:123456789@lid
        if (jid.includes('@lid')) {
            const match = jid.match(/(\d+)/)
            if (match) return match[1]
            return jid
        }
        
        // Handle phone format: 1234567890@s.whatsapp.net
        if (jid.includes('@s.whatsapp.net') || jid.includes('@c.us')) {
            return jid.split('@')[0]
        }
        
        // Handle regular JID
        const match = jid.match(/(\d+)/)
        if (match) return match[1]
        
        return jid
    }

    // Helper function to resolve LID to PN
// PRIORITY: 1. signalRepository.getPNForLID()  2. Database cache  3. Not resolved
async function resolveLIDtoPN(lid) {
        if (!lid || !lid.includes('@lid')) {
            return { pn: null, lid: null, source: 'invalid_format' }
        }
        
        console.log(`[LID-DEBUG] Resolving LID: ${lid}`)
        
        // PRIMARY: Try signalRepository.getPNForLID() first (most reliable in Baileys v7)
        if (sock?.signalRepository?.lidMapping?.getPNForLID) {
            try {
                console.log('[LID-DEBUG] Trying signalRepository.getPNForLID()')
                const pn = await sock.signalRepository.lidMapping.getPNForLID(lid)
                if (pn) {
                    console.log(`[LID-DEBUG] Resolved via signalRepository: ${pn}`)
                    // Save to database
                    try {
                        await db.saveLIDPNMapping(lid, pn)
                    } catch (saveErr) {
                        console.error('[LID-DEBUG] Error saving mapping:', saveErr.message)
                    }
                    return { pn, lid, source: 'signalRepository' }
                }
            } catch (err) {
                console.error('[LID-DEBUG] signalRepository error:', err.message)
            }
        } else {
            console.log('[LID-DEBUG] signalRepository.lidMapping.getPNForLID not available')
        }
        
        // SECONDARY: Check database cache
        try {
            const cachedPN = await db.getPNFromLID(lid)
            if (cachedPN) {
                console.log(`[LID-DEBUG] Found cached PN: ${cachedPN}`)
                return { pn: cachedPN, lid, source: 'database' }
            }
        } catch (err) {
            console.error('[LID-DEBUG] Database lookup error:', err.message)
        }
        
        console.log('[LID-DEBUG] Could not resolve LID to PN')
        return { pn: null, lid, source: 'not_resolved' }
    }

    // Get contact identity info
    router.get("/:instanceId/contact/:remoteJid", async (req, res) => {
        try {
            const { instanceId, remoteJid } = req.params
            const decodedRemoteJid = decodeURIComponent(remoteJid)
            
            // First, try to get from database
            let identity = await db.getContactIdentity(instanceId, decodedRemoteJid)
            
            // If it's a LID and we don't have PN, try to resolve
            if (decodedRemoteJid.includes('@lid') && (!identity?.pn)) {
                const { pn } = await resolveLIDtoPN(decodedRemoteJid)
                if (pn) {
                    // Update the identity with resolved PN
                    const formattedPhone = formatBrazilianPhone(pn)
                    await db.updateContactIdentity(instanceId, decodedRemoteJid, {
                        pn: pn,
                        formattedPhone: formattedPhone
                    })
                    
                    // Refresh identity from DB
                    identity = await db.getContactIdentity(instanceId, decodedRemoteJid)
                }
            }
            
            // Add formatted phone if we have PN
            if (identity?.pn) {
                identity.formatted_phone = formatBrazilianPhone(identity.pn)
            }
            
            res.json({ ok: true, identity })
        } catch (err) {
            console.error('Error fetching contact identity:', err.message)
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    // Update contact identity info
    router.post("/:instanceId/contact/:remoteJid", async (req, res) => {
        try {
            const { instanceId, remoteJid } = req.params
            const { pushName, formattedPhone, statusBio, contactName, pn, lid } = req.body
            const decodedRemoteJid = decodeURIComponent(remoteJid)
            
            // If LID is provided and PN is not, try to resolve
            let resolvedPN = pn || formattedPhone
            if (decodedRemoteJid.includes('@lid') && !resolvedPN) {
                const { pn: resolved } = await resolveLIDtoPN(decodedRemoteJid)
                resolvedPN = resolved
            }
            
            await db.updateContactIdentity(instanceId, decodedRemoteJid, {
                pushName: pushName || null,
                formattedPhone: resolvedPN ? formatBrazilianPhone(resolvedPN) : (formattedPhone || null),
                statusBio: statusBio || null,
                contactName: contactName || null,
                pn: resolvedPN || pn || null
            })
            
            res.json({ ok: true, message: "Contato atualizado com sucesso" })
        } catch (err) {
            console.error('Error updating contact identity:', err.message)
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    // Get LID resolution status
    router.get("/:instanceId/lid-resolve/:lid", async (req, res) => {
        try {
            const { lid } = req.params
            
            if (!lid || !lid.includes('@lid')) {
                return res.status(400).json({ ok: false, error: 'Invalid LID format' })
            }
            
            const { pn } = await resolveLIDtoPN(lid)
            
            res.json({ 
                ok: true, 
                lid, 
                pn, 
                formattedPhone: pn ? formatBrazilianPhone(pn) : null 
            })
        } catch (err) {
            console.error('Error resolving LID:', err.message)
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    // DEBUG ENDPOINT: Get all logged metadata for a specific LID
    router.get("/:instanceId/debug/lid/:lid", async (req, res) => {
        try {
            const { lid } = req.params
            const { clear } = req.query
            
            if (!lid || !lid.includes('@lid')) {
                return res.status(400).json({ ok: false, error: 'Invalid LID format' })
            }
            
            // Get debug logs from whatsapp-service
            let debugLogs = []
            if (whatsappService?.getDebugLogs) {
                debugLogs = whatsappService.getDebugLogs(lid)
            }
            
            // Clear logs if requested
            if (clear === 'true' && whatsappService?.clearDebugLogs) {
                whatsappService.clearDebugLogs(lid)
            }
            
            // Also check database for stored metadata
            let dbMetadata = []
            try {
                dbMetadata = await db.db.all(
                    `SELECT * FROM messages 
                     WHERE remote_jid = ? OR participant = ? OR remote_jid_alt = ? OR participant_alt = ? 
                     ORDER BY timestamp DESC LIMIT 50`,
                    [lid, lid, lid, lid]
                )
            } catch (dbErr) {
                console.error('Error fetching db metadata:', dbErr.message)
            }
            
            res.json({
                ok: true,
                lid,
                debugEnabled: process.env.VERBOSE_LID_DEBUG === 'true',
                logCount: debugLogs.length,
                logs: debugLogs,
                dbMetadataCount: dbMetadata.length,
                dbMetadata
            })
        } catch (err) {
            console.error('Error fetching debug logs:', err.message)
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    // DEBUG ENDPOINT: List all LIDs with debug logs
    router.get("/:instanceId/debug/lids", async (req, res) => {
        try {
            // This would require access to the internal Map, which we don't have directly
            // For now, return info about the debug feature
            res.json({
                ok: true,
                debugEnabled: process.env.VERBOSE_LID_DEBUG === 'true',
                message: 'Use /debug/lid/:lid to get logs for a specific LID',
                usage: {
                    getLogs: 'GET /:instanceId/debug/lid/<lid>',
                    clearLogs: 'GET /:instanceId/debug/lid/<lid>?clear=true',
                    enableVerbose: 'Set VERBOSE_LID_DEBUG=true environment variable'
                }
            })
        } catch (err) {
            console.error('Error listing debug LIDs:', err.message)
            res.status(500).json({ ok: false, error: err.message })
        }
    })

    return router
}
