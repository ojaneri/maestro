# WhatsApp Meta - API & Integration Rules

## WhatsApp 24h Session Window
- **Critical Rule**: After receiving the first user message, you have 24 hours to reply with a template.
- If `lastMessage > 24h` -> MUST use `triggerTemplate()` 
- Never send free-form text after 24h window closes

## QR Code Connection
- Endpoint for QR: `/qr` (NOT `/api/qr`)
- Endpoint for status: `/status`
- Instance port retrieved from instance_data.php
- QR proxy: qr-proxy.php (bridges frontend to instance API)

## Webhook Events
- connection.update: QR code generation, disconnection
- messages.upsert: Incoming messages
- messages.update: Message status updates

## Rate Limits
- Messages per second: 20 (safe limit)
- Campaign bulk sends: Implement delay between messages

## Baileys Library Notes
- Deprecated: printQRInTerminal option
- Listen to 'connection.update' for QR events
- Handle 'QR refs attempts ended' - this is normal after ~20 seconds without scan
