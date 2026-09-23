<?php
declare(strict_types=1);

/*
 * Receives a calculated snapshot from the Windows cron job and stores
 * it in MySQL.  The request is authenticated via the X-OIPULSE-SECRET
 * header — the expected value is read from the OIPULSE_SECRET
 * environment variable (or a server-side config file outside
 * public_html).
 *
 * Do NOT hardcode the secret here — this file is committed to Git.
 */

require_once __DIR__ . '/../../src/db.php';
$config = require __DIR__ . '/../../src/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

/*
 * Authenticate.
 */
$expectedSecret = $config['oipulse_secret'];

$providedSecret = $_SERVER['HTTP_X_OIPULSE_SECRET'] ?? '';

if ($expectedSecret === '' ||
    !hash_equals($expectedSecret, $providedSecret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

/*
 * Accept only POST.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

/*
 * Read and validate the JSON payload.
 */
$body = file_get_contents('php://input');

$latest = json_decode($body, true);

if (!is_array($latest) || empty($latest)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or empty JSON payload']);
    exit;
}

try {
    $pdo = oipulse_pdo($config);

    $today = (new DateTime('now'))->format('Y-m-d');

    /*
     * Reference point for "change since open" — first snapshot of
     * today, if any.
     */
    $stmt = $pdo->prepare(
        'SELECT spot FROM oipulse_history WHERE trading_day = :d ORDER BY ts ASC LIMIT 1',
    );
    $stmt->execute(['d' => $today]);
    $dayOpenSpot = $stmt->fetchColumn();
    $latest['spotChng'] = $dayOpenSpot !== false
        ? round($latest['spot'] - (float) $dayOpenSpot, 2)
        : 0.0;

    $payloadJson = json_encode($latest, JSON_UNESCAPED_SLASHES);

    if ($payloadJson === false) {
        throw new RuntimeException(
            'Failed to encode payload: ' . json_last_error_msg()
        );
    }

    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO oipulse_latest (id, payload, updated_at) VALUES (1, :p, :u)
         ON DUPLICATE KEY UPDATE payload = :p2, updated_at = :u2',
    )->execute([
        'p' => $payloadJson, 'u' => date('Y-m-d H:i:s'),
        'p2' => $payloadJson, 'u2' => date('Y-m-d H:i:s'),
    ]);

    $pdo->prepare(
        'INSERT INTO oipulse_history (ts, trading_day, spot, pcr) VALUES (:ts, :d, :spot, :pcr)',
    )->execute([
        'ts' => date('Y-m-d H:i:s'),
        'd' => $today,
        'spot' => $latest['spot'],
        'pcr' => $latest['pcr'],
    ]);

    $pdo->commit();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Server error storing snapshot.']);
    error_log('push_snapshot.php: ' . $e->getMessage());
}
