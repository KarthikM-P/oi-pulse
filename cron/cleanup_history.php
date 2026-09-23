<?php
declare(strict_types=1);

/**
 * Deletes oipulse_history rows older than 30 days.
 *
 * Run this daily (or weekly) via cron to prevent the history table
 * from growing indefinitely.
 *
 * Example Hostinger cron entry (once per day at 00:30):
 *   30 0 * * * /usr/bin/php /path/to/cron/cleanup_history.php
 */

require_once __DIR__ . '/../src/db.php';

$config = require __DIR__ . '/../src/config.php';

function oipulse_log(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] {$msg}\n");
}

try {
    $pdo = oipulse_pdo($config);

    $stmt = $pdo->prepare(
        'DELETE FROM oipulse_history WHERE trading_day < DATE_SUB(CURDATE(), INTERVAL 30 DAY)',
    );
    $stmt->execute();

    $deleted = $stmt->rowCount();

    oipulse_log("Cleanup OK — deleted {$deleted} old history rows.");
} catch (Throwable $e) {
    oipulse_log('CLEANUP FAILED: ' . $e->getMessage());
    exit(1);
}
