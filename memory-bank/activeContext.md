# Active Context

## Current Work Focus
- Removed "Meta Phone Number ID" field from Meta API configuration
- Kept only Meta Access Token, Waba ID, and Telephone ID
- Updated backend constants and UI accordingly
- Template sending functionality fully implemented
- Test and bulk sending capabilities added to UI
- Template management workflow completed
- Webhook 24h window rule enforcement implemented for Meta API

## Recent Changes
- Removed meta_phone_number_id from all configuration forms and backend
- Updated Meta API instance identification to use meta_access_token and meta_business_account_id
- Modified sendMetaTemplate to use telephone_id as phone_number_id for sending
- Updated UI to remove Meta Phone Number ID input fields
- Updated `index.php` to hide "Conectar WhatsApp" button for Meta API instances
- Hid Auto Pause, Transcrever audio, and Secretária virtual options for Meta API instances
- Modified alarms section to only show "Alarme de erros" with email field (no time slide)
- Added "Templates" tab to `index.php` to show approved, pending, and rejected statuses plus send controls for existing templates
- Added template sending functionality with test and bulk sending forms in Templates tab
- Added send buttons to approved template cards for quick sending
- Implemented dynamic variable input fields based on template content
- Updated `conversas.php` to display integration type (Baileys/Meta) in the instance header
- Added support for showing Meta API metadata (sent, read, delivered, failed) below messages
- Renomeamos os provedores no painel de IA para OpenAI, Gemini e OpenRouter e ativamos a sequência de fallbacks (incluindo o novo provider OpenRouter)
- Web instances now reuse the same Baileys automation panels (transcrição de áudio, secretária virtual e auto pause) and quick-config base URL fields.
- Removed the Add Template form/instructions so dashboard template creation now happens elsewhere.
- Modified `retorno.php` to enforce 24h window rule for free-text responses on Meta API webhooks

## Next Steps
- Test the template sending functionality with approved templates
- Verify bulk sending works correctly with multiple recipients
- Ensure error handling displays proper messages for failed sends
- Test the changes with both Baileys and Meta API instances
- Ensure all UI elements are properly hidden/showing based on instance type
- Verify that the Templates tab functionality works correctly
- Check that message metadata is properly displayed in conversas.php
- Validar o fluxo de fallbacks e o provedor OpenRouter no painel de IA
- Confirm Web integrations keep the Baileys automation workflows (secretária, transcrição e auto pause) while Meta-specific tabs stay isolated.
- Validate the 3-model fallback sequence for OpenAI, Gemini and OpenRouter across instances and log any discrepancies for follow-up.

## Active Decisions and Considerations
- Instances with `meta_access_token` and `meta_business_account_id` are identified as Meta API instances
- Different UI elements are shown/hidden based on the integration type
- Web integrations are treated like Baileys for automation features (secretária, transcrição de áudio, auto pause e alarmes) while Meta remains segregated.
- Templates are managed separately from the main chat interface
- Meta API messages show detailed delivery statuses
- Meta Phone Number ID field removed from configuration; Telephone ID used for sending

## Important Patterns and Preferences
- Conditional UI rendering based on instance metadata
- Clear separation of features between Baileys and Meta API instances
- Consistent styling using Tailwind CSS
- Metadata display for enhanced user experience

## Learnings and Project Insights
- Meta API has stricter requirements for message templates and delivery status tracking
- UI needs to adapt to different integration types to provide the right functionality
- Templates management is an important feature for Meta API compliance
