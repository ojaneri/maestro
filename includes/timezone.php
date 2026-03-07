<?php
declare(strict_types=1);

if (!defined('APPLICATION_TIMEZONE')) {
    define('APPLICATION_TIMEZONE', getenv('APP_TIMEZONE') ?: 'America/Sao_Paulo');
}

date_default_timezone_set(APPLICATION_TIMEZONE);

if (!function_exists('getApplicationTimezone')) {
    /**
     * @return string The configured application timezone (UTC-3).
     */
    function getApplicationTimezone(): string
    {
        return APPLICATION_TIMEZONE;
    }
}
