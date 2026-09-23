<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
$config = require __DIR__ . '/../../src/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
// Loosen for local dev (frontend on a different port). On Hostinger, frontend
// and API share the same domain, so this header is harmless but unnecessary there.
header('Access-Control-Allow-Origin: *');

try {
    $pdo = oipulse_pdo($config);
    $row = $pdo->query('SELECT payload FROM oipulse_latest WHERE id = 1')->fetch();

    if (!$row) {
        http_response_code(503);
        echo json_encode(['error' => 'No snapshot yet — the cron job has not run successfully.']);
        exit;
    }

    $payload = json_decode($row['payload'], true);
    unset($payload['_signal_cache']); // internal only, not part of LatestPayload
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error reading latest snapshot.']);
    error_log('latest.php: ' . $e->getMessage());
}
