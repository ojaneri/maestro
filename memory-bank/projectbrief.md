# Project Brief - Maestro Janeri WhatsApp Platform

## Project Overview
Multi-tenant WhatsApp messaging platform with instances, QR code connection, and campaign management.

## Technology Stack
- **Frontend**: PHP (native), JavaScript
- **Backend**: Node.js (whatsapp-server-intelligent.js)
- **Database**: SQLite (instance_data.php)
- **Protocol**: WhatsApp Web (Baileys library)

## Architecture
- **Master Server**: Node.js process manager (master-server.js) managing multiple instance processes
- **Instance**: Each WhatsApp instance runs on a unique port (e.g., 3000, 3011, etc.)
- **QR Proxy**: PHP script (qr-proxy.php) bridging frontend requests to instance API endpoints

## Critical Endpoints (VERIFIED)
| Service | Port | Endpoint | Purpose |
|---------|------|----------|---------|
| WhatsApp Instance | :3011 | `/qr` | QR code retrieval |
| WhatsApp Instance | :3011 | `/status` | Connection status |
| QR Proxy | HTTP | `/api/envio/wpp/qr-proxy.php` | Frontend QR bridge |

## Known Issues Fixed
- **2026-02-15**: QR code not displaying - Fixed endpoint path in qr-proxy.php (was calling `/api/qr`, should be `/qr`)

## Security Notes
- Token-based authentication for QR proxy
- Session timeout: 24h window for WhatsApp connection
- No global variables in JS - use dependency injection
