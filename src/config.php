<?php
declare(strict_types=1);

/*
 * Public-safe configuration.
 *
 * Production/local secrets should be supplied through environment
 * variables or src/config.local.php.
 */

$localConfigFile = __DIR__ . '/config.local.php';

$localConfig = [];

if (is_file($localConfigFile)) {
    $localConfig = require $localConfigFile;
}

return array_merge([
    'db_host' => getenv('OIPULSE_DB_HOST') ?: '127.0.0.1',
    'db_name' => getenv('OIPULSE_DB_NAME') ?: 'oi_pulse',
    'db_user' => getenv('OIPULSE_DB_USER') ?: 'root',
    'db_pass' => getenv('OIPULSE_DB_PASS') ?: '',
    'oipulse_secret' => getenv('OIPULSE_SECRET') ?: '',

    'symbol' => 'NIFTY',

    'lot_size' => 75,

    'pcr_window_strikes' => 8,

    'near_atm_strikes' => 10,

    'risk_free_rate' => 0.065,
], $localConfig);
