# Active Context

## Current Work Focus
- Implementing UI changes for Meta API instances
- Hiding unnecessary UI elements for Meta API instances
- Adding Templates tab to manage WhatsApp templates
- Updating conversas.php to show integration type and Meta metadata

## Recent Changes
- Updated `index.php` to hide "Conectar WhatsApp" button for Meta API instances
- Hid Auto Pause, Transcrever audio, and Secretária virtual options for Meta API instances
- Modified alarms section to only show "Alarme de erros" with email field (no time slide)
- Added "Templates" tab to `index.php` to show approved, pending, and allow adding new templates
- Updated `conversas.php` to display integration type (Baileys/Meta) in the instance header
- Added support for showing Meta API metadata (sent, read, delivered, failed) below messages

## Next Steps
- Test the changes with both Baileys and Meta API instances
- Ensure all UI elements are properly hidden/showing based on instance type
- Verify that the Templates tab functionality works correctly
- Check that message metadata is properly displayed in conversas.php

## Active Decisions and Considerations
- Instances with `meta_access_token` and `meta_phone_number_id` are identified as Meta API instances
- Different UI elements are shown/hidden based on the integration type
- Templates are managed separately from the main chat interface
- Meta API messages show detailed delivery statuses

## Important Patterns and Preferences
- Conditional UI rendering based on instance metadata
- Clear separation of features between Baileys and Meta API instances
- Consistent styling using Tailwind CSS
- Metadata display for enhanced user experience

## Learnings and Project Insights
- Meta API has stricter requirements for message templates and delivery status tracking
- UI needs to adapt to different integration types to provide the right functionality
- Templates management is an important feature for Meta API compliance