<?php
declare(strict_types=1);

/**
 * NSE option-chain test client.
 *
 * Flow:
 * 1. Visit NSE homepage to establish cookies.
 * 2. Request option-chain contract information.
 * 3. Determine nearest expiry.
 * 4. Request option-chain-v3 data.
 */

class NseFetchException extends RuntimeException {}

function oipulse_fetch_nse_option_chain(string $symbol = 'NIFTY'): array
{
    $cookieJar = sys_get_temp_dir()
        . '/oipulse_nse_cookies_'
        . md5($symbol)
        . '.txt';

    // Always start with a fresh NSE session.
    @unlink($cookieJar);

    $commonHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/151.0.0.0 Safari/537.36',

        'Accept-Language: en-US,en;q=0.9',

        'Accept-Encoding: gzip, deflate, br',

        'Cache-Control: no-cache',

        'Pragma: no-cache',

        'Connection: keep-alive',
    ];

    /*
     * Step 1:
     * Visit NSE homepage first so that NSE can establish
     * the required cookies/session.
     */
    $warmupHeaders = array_merge(
        $commonHeaders,
        [
            'Accept: text/html,application/xhtml+xml,'
                . 'application/xml;q=0.9,image/avif,image/webp,'
                . 'image/apng,*/*;q=0.8',
        ]
    );

    $warmup = oipulse_curl(
        'https://www.nseindia.com/',
        $cookieJar,
        $warmupHeaders
    );

    if ($warmup['status'] >= 400) {
        throw new NseFetchException(
            "Warm-up request failed with HTTP {$warmup['status']}"
        );
    }

    usleep(500_000);

    /*
     * Headers used for NSE API requests.
     */
    $apiHeaders = array_merge(
        $commonHeaders,
        [
            'Accept: application/json, text/plain, */*',
            'Referer: https://www.nseindia.com/',
            'Origin: https://www.nseindia.com',
        ]
    );

    /*
     * Step 2:
     * Get available expiry dates.
     */
    $contractUrl =
        'https://www.nseindia.com/api/option-chain-contract-info'
        . '?symbol='
        . urlencode($symbol);

    $contractInfo = oipulse_curl(
        $contractUrl,
        $cookieJar,
        $apiHeaders
    );

    oipulse_check_nse_status(
        $contractInfo['status'],
        'contract-info'
    );

    $contractDecoded = json_decode(
        $contractInfo['body'],
        true
    );

    if (!is_array($contractDecoded)) {
        throw new NseFetchException(
            'NSE contract-info response was not valid JSON.'
        );
    }

    $expiryDates = $contractDecoded['expiryDates'] ?? null;

    if (!is_array($expiryDates) || count($expiryDates) === 0) {
        throw new NseFetchException(
            'Could not read expiry dates from '
            . 'option-chain-contract-info.'
        );
    }

    $nearestExpiry = oipulse_nearest_expiry(
        $expiryDates
    );

    echo "Nearest expiry: {$nearestExpiry}" . PHP_EOL;

    usleep(400_000);

    /*
     * Step 3:
     * Request option-chain-v3 for the nearest expiry.
     */
    $apiUrl =
        'https://www.nseindia.com/api/option-chain-v3'
        . '?type=Indices'
        . '&symbol='
        . urlencode($symbol)
        . '&expiry='
        . urlencode($nearestExpiry);

    $api = oipulse_curl(
        $apiUrl,
        $cookieJar,
        $apiHeaders
    );

    oipulse_check_nse_status(
        $api['status'],
        'option-chain-v3'
    );

    $decoded = json_decode(
        $api['body'],
        true
    );

    if (!is_array($decoded)) {
        throw new NseFetchException(
            'NSE option-chain-v3 response was not valid JSON.'
        );
    }

    if (!isset($decoded['records'])) {
        throw new NseFetchException(
            'NSE response was missing the records object.'
        );
    }

    return $decoded;
}


/**
 * Validate NSE HTTP response.
 */
function oipulse_check_nse_status(
    int $status,
    string $step
): void {

    if ($status === 401 || $status === 403) {
        throw new NseFetchException(
            "NSE rejected the {$step} request "
            . "(HTTP {$status}). "
            . "NSE anti-bot protection is blocking the request."
        );
    }

    if ($status === 404) {
        throw new NseFetchException(
            "NSE returned 404 on {$step}. "
            . "The NSE endpoint may have changed."
        );
    }

    if ($status >= 400) {
        throw new NseFetchException(
            "NSE {$step} request failed with HTTP {$status}"
        );
    }
}


/**
 * Find the nearest valid expiry date.
 */
function oipulse_nearest_expiry(
    array $expiryDates
): string {

    $today = new DateTime('today');

    $best = null;
    $bestDate = null;

    foreach ($expiryDates as $raw) {

        if (!is_string($raw)) {
            continue;
        }

        $d = DateTime::createFromFormat(
            'd-M-Y',
            $raw
        );

        if ($d === false) {
            continue;
        }

        if ($d < $today) {
            continue;
        }

        if (
            $bestDate === null
            || $d < $bestDate
        ) {
            $bestDate = $d;
            $best = $raw;
        }
    }

    if ($best !== null) {
        return $best;
    }

    return (string) $expiryDates[0];
}


/**
 * Execute a cURL request.
 *
 * @return array{status:int, body:string}
 */
function oipulse_curl(
    string $url,
    string $cookieJar,
    array $headers
): array {

    $ch = curl_init($url);

    if ($ch === false) {
        throw new NseFetchException(
            'Unable to initialize cURL.'
        );
    }

    $caBundle = __DIR__ . '/cacert.pem';

    if (!is_file($caBundle)) {
        throw new NseFetchException(
            "CA certificate bundle not found: {$caBundle}"
        );
    }

    curl_setopt_array(
        $ch,
        [
            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_ENCODING => '',

            CURLOPT_HTTPHEADER => $headers,

            CURLOPT_COOKIEJAR => $cookieJar,

            CURLOPT_COOKIEFILE => $cookieJar,

            CURLOPT_TIMEOUT => 20,

            CURLOPT_CONNECTTIMEOUT => 10,

            CURLOPT_SSL_VERIFYPEER => true,

            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_CAINFO => $caBundle,

            CURLOPT_FOLLOWLOCATION => true,

            CURLOPT_MAXREDIRS => 5,
        ]
    );

    $body = curl_exec($ch);

    if ($body === false) {

        $error = curl_error($ch);

        throw new NseFetchException(
            "cURL error: {$error}"
        );
    }

    $status = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    /*
     * curl_close() is intentionally not called.
     * PHP 8.5 marks it as deprecated.
     */

    return [
        'status' => $status,
        'body' => $body,
    ];
}


/*
 * Execute the test when this file is run directly.
 */
try {
    $data = oipulse_fetch_nse_option_chain('NIFTY');

    echo "SUCCESS" . PHP_EOL;
    echo "Spot: " . ($data['records']['underlyingValue'] ?? 'N/A') . PHP_EOL;
    echo "Records received: " . count($data['records']['data'] ?? []) . PHP_EOL;

    echo PHP_EOL . "FIRST RECORD:" . PHP_EOL;
    print_r($data['records']['data'][0] ?? []);
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}