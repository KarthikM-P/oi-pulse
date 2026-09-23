<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/nse_client.php';
require_once __DIR__ . '/../src/calc.php';

$config = require __DIR__ . '/../src/config.php';

const HOSTINGER_PUSH_URL =
    'https://mediumblue-cassowary-262239.hostingersite.com/api/push_snapshot.php';

$pushSecret = $config['oipulse_secret'];

function oipulse_log(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] {$msg}\n");
}

try {

    /*
     * 1. Fetch NSE
     */
    $raw = oipulse_fetch_nse_option_chain($config['symbol']);

    /*
     * 2. Perform all existing calculations
     */
    $latest = oipulse_build_latest($raw, $config);

    /*
     * 3. Build signal
     */
    $signal = oipulse_build_signal($latest);

    $latest['_signal_cache'] = $signal;

    /*
     * 4. Encode final calculated snapshot
     */
    $payloadJson = json_encode(
        $latest,
        JSON_UNESCAPED_SLASHES
    );

    if ($payloadJson === false) {
        throw new RuntimeException(
            'Failed to encode payload: ' . json_last_error_msg()
        );
    }

} catch (Throwable $e) {

    oipulse_log(
        'FETCH/PARSE FAILED: ' . $e->getMessage()
    );

    exit(1);
}

/*
 * Send calculated snapshot to Hostinger.
 */
$ch = curl_init(HOSTINGER_PUSH_URL);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payloadJson,

    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payloadJson),
        'X-OIPULSE-SECRET: ' . $pushSecret,
    ],

    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,

    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,

    CURLOPT_CAINFO => __DIR__ . '/../cacert.pem',
]);

$response = curl_exec($ch);

$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo(
    $ch,
    CURLINFO_HTTP_CODE
);

if ($response === false) {
    oipulse_log(
        'HOSTINGER PUSH FAILED: ' . $curlError
    );

    exit(1);
}

$responseData = json_decode($response, true);

if ($httpCode !== 200) {

    oipulse_log(
        "HOSTINGER PUSH FAILED: HTTP {$httpCode} RESPONSE: {$response}"
    );

    exit(1);
}

if (!is_array($responseData) || empty($responseData['success'])) {

    oipulse_log(
        "HOSTINGER PUSH FAILED: {$response}"
    );

    exit(1);
}

oipulse_log(
    "OK — spot={$latest['spot']} pcr={$latest['pcr']} | Hostinger updated"
);