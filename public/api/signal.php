<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/calc.php';
$config = require __DIR__ . '/../../src/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = oipulse_pdo($config);
    $row = $pdo->query('SELECT payload FROM oipulse_latest WHERE id = 1')->fetch();

    if (!$row) {
        http_response_code(503);
        echo json_encode(['error' => 'No snapshot yet — the cron job has not run successfully.']);
        exit;
    }

    $latest = json_decode($row['payload'], true);
    // Recomputed fresh each request (cheap, pure function) rather than trusting
    // the cached copy, so signal.php always reflects the same thresholds as
    // the current code even if the cron job ran with an older version.
    echo json_encode(oipulse_build_signal($latest), JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error computing signal.']);
    error_log('signal.php: ' . $e->getMessage());
}
