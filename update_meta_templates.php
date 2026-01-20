<?php
require_once __DIR__ . '/instance_data.php';

function updateAllMetaTemplates() {
    $instances = loadInstancesFromDatabase();
    $updated = 0;

    foreach ($instances as $instanceId => $instance) {
        if (($instance['integration_type'] ?? 'baileys') !== 'meta') {
            continue;
        }

        try {
            $result = checkAllMetaTemplateStatuses($instanceId);
            if ($result['ok']) {
                $updated++;
                debug_log("Updated templates for instance {$instanceId}: " . count($result['templates']) . " templates");
            } else {
                debug_log("Failed to update templates for instance {$instanceId}: " . ($result['error'] ?? 'Unknown error'));
            }
        } catch (Exception $e) {
            debug_log("Exception updating templates for instance {$instanceId}: " . $e->getMessage());
        }
    }

    debug_log("Template update completed. Updated {$updated} instances.");
    return $updated;
}

// Run the update
if (php_sapi_name() === 'cli') {
    updateAllMetaTemplates();
} else {
    // Prevent web access
    http_response_code(403);
    echo "Access denied";
}
?>