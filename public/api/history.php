<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';
$config = require __DIR__ . '/../../src/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

// Only "1d" (today) is implemented for now — the ?range= param is accepted
// for forward-compatibility but anything else currently falls back to today.
$range = $_GET['range'] ?? '1d';

try {
    $pdo = oipulse_pdo($config);
    $stmt = $pdo->prepare(
        'SELECT ts, spot, pcr FROM oipulse_history WHERE trading_day = CURDATE() ORDER BY ts ASC',
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $points = array_map(static fn($r) => [
        'time' => (new DateTime($r['ts']))->format(DateTime::ATOM),
        'pcr' => (float) $r['pcr'],
        'spot' => (float) $r['spot'],
    ], $rows);

    echo json_encode($points, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error reading history.']);
    error_log('history.php: ' . $e->getMessage());
}
