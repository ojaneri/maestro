const express = require("express")
const healthRoutes = require("./health.routes")
const statusRoutes = require("./status.routes")
const contactsRoutes = require("./contacts.routes")

module.exports = (context) => {
    const router = express.Router()
    router.use(healthRoutes(context))
    router.use(statusRoutes(context))
    router.use(contactsRoutes(context))
    return router
}
