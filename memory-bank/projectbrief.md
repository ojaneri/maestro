# Project Brief: Maestro - WhatsApp Orchestrator

## Overview
Maestro is a multi-instance WhatsApp management system that allows centralized orchestration of multiple WhatsApp API instances. Version 1.5 introduces multi-user management with granular access control.

## Core Requirements
- Multi-instance management via central SQLite database
- User roles: Admin, Manager, Operator
- QR code authentication
- AI-powered automated responses (OpenAI, Gemini)
- WhatsApp-style chat dashboard
- Google Calendar integration for scheduling

## Goals
- Provide a robust, scalable solution for managing multiple WhatsApp business accounts
- Enable efficient customer communication through AI automation
- Ensure secure, role-based access to instances
- Integrate with external services like Google Calendar

## Scope
- Backend: PHP 8.0+, Node.js 18+
- Frontend: HTML5, Tailwind CSS, JS
- Database: SQLite
- WhatsApp: Baileys library
- AI: OpenAI GPT, Gemini