<?php
declare(strict_types=1);

require_once __DIR__ . '/black_scholes.php';

class NseParseException extends RuntimeException {}

/**
 * Transforms NSE's raw option-chain-v3 response into our LatestPayload shape.
 */
function oipulse_build_latest(array $raw, array $config): array
{
    $records = $raw['records'] ?? null;

    if (!is_array($records)) {
        throw new NseParseException('Missing "records" in NSE response.');
    }

    $spot = (float) ($records['underlyingValue'] ?? 0);

    if ($spot <= 0) {
        throw new NseParseException(
            'Missing or invalid underlyingValue (spot price).'
        );
    }

    /*
     * NSE v3 response:
     *
     * records.expiryDates[0] = "08-Sep-2026"
     *
     * records.data[] contains:
     *   expiryDates => "08-Sep-2026"
     *   strikePrice => 22150
     *   CE => [...]
     *   PE => [...]
     */
    $expiryDates = $records['expiryDates'] ?? [];

    if (!is_array($expiryDates) || empty($expiryDates)) {
        throw new NseParseException(
            'No expiry dates found in NSE response.'
        );
    }

    $nearestExpiry = $expiryDates[0];

    if (!$nearestExpiry) {
        throw new NseParseException(
            'No nearest expiry found in NSE response.'
        );
    }

    /*
     * Example:
     * 08-Sep-2026
     */
    $expiryDateTime = DateTime::createFromFormat(
        'd-M-Y',
        $nearestExpiry
    );

    if ($expiryDateTime === false) {
        throw new NseParseException(
            "Could not parse expiry date '{$nearestExpiry}'."
        );
    }

    $now = new DateTime('now');

    $daysToExpiry = max(
        0.25,
        (
            $expiryDateTime->getTimestamp()
            - $now->getTimestamp()
        ) / 86400
    );

    /*
     * Build strike map.
     *
     * option-chain-v3 already returns the requested expiry.
     * Each record contains:
     *
     * [
     *     expiryDates => "08-Sep-2026",
     *     strikePrice => 22150,
     *     CE => [...],
     *     PE => [...]
     * ]
     *
     * Therefore we do NOT check:
     * $entry['expiryDate']
     *
     * because that field does not exist at the row level.
     */
    $rowsByStrike = [];

    foreach (($records['data'] ?? []) as $entry) {

        if (!is_array($entry)) {
            continue;
        }

        $strike = (float) ($entry['strikePrice'] ?? 0);

        if ($strike <= 0) {
            continue;
        }

        $rowsByStrike[$strike] = $entry;
    }

    if (!$rowsByStrike) {
        throw new NseParseException(
            'No strikes found for the nearest expiry.'
        );
    }

    ksort($rowsByStrike, SORT_NUMERIC);

    /*
     * Risk-free rate.
     */
    $r = (float) ($config['risk_free_rate'] ?? 0);

    /*
     * Build option chain.
     */
    $chain = [];

    foreach ($rowsByStrike as $strike => $entry) {

        $chain[] = [
            'strike' => (float) $strike,

            'call' => oipulse_build_leg(
                $entry['CE'] ?? null,
                $spot,
                (float) $strike,
                $daysToExpiry,
                'call',
                $r
            ),

            'put' => oipulse_build_leg(
                $entry['PE'] ?? null,
                $spot,
                (float) $strike,
                $daysToExpiry,
                'put',
                $r
            ),
        ];
    }

    /*
     * Find ATM strike.
     */
    $strikes = array_map(
        static fn($row) => $row['strike'],
        $chain
    );

    $atmIndex = 0;
    $bestDiff = INF;

    foreach ($strikes as $i => $strikePrice) {

        $diff = abs($strikePrice - $spot);

        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $atmIndex = $i;
        }
    }

    /*
     * PCR window.
     *
     * Example:
     * pcr_window_strikes = 5
     *
     * This takes 5 strikes below and 5 strikes above ATM.
     */
    $window = (int) ($config['pcr_window_strikes'] ?? 5);

    $lo = max(
        0,
        $atmIndex - $window
    );

    $hi = min(
        count($chain) - 1,
        $atmIndex + $window
    );

    $windowRows = array_slice(
        $chain,
        $lo,
        $hi - $lo + 1
    );

    /*
     * Calculate PCR.
     */
    $windowCallOI = array_sum(
        array_map(
            static fn($row) => $row['call']['oi'],
            $windowRows
        )
    );

    $windowPutOI = array_sum(
        array_map(
            static fn($row) => $row['put']['oi'],
            $windowRows
        )
    );

    $pcr = $windowCallOI > 0
        ? round($windowPutOI / $windowCallOI, 2)
        : 0.0;

    /*
     * Total Call OI.
     */
    $totalCallOI = array_sum(
        array_map(
            static fn($row) => $row['call']['oi'],
            $chain
        )
    );

    /*
     * Total Put OI.
     */
    $totalPutOI = array_sum(
        array_map(
            static fn($row) => $row['put']['oi'],
            $chain
        )
    );

    /*
     * Support = highest Put OI.
     * Resistance = highest Call OI.
     */
    $support = oipulse_top_levels(
        $chain,
        'put',
        3
    );

    $resistance = oipulse_top_levels(
        $chain,
        'call',
        3
    );

    /*
     * Return final payload.
     */
    return [
        'spot' => $spot,

        /*
         * fetch_snapshot.php calculates the actual change
         * against the previous snapshot.
         */
        'spotChng' => 0.0,

        'timestamp' => $now->format(DateTime::ATOM),

        'pcr' => $pcr,

        'pcrWindow' => $window,

        'totalCallOI' => (int) $totalCallOI,

        'totalPutOI' => (int) $totalPutOI,

        'support' => $support,

        'resistance' => $resistance,

        'chain' => $chain,

        'expiryDate' => $expiryDateTime->format(DateTime::ATOM),

        'daysToExpiry' => round(
            $daysToExpiry,
            2
        ),

        /*
         * Current NIFTY lot size from configuration.
         */
        'lotSize' => (int) (
            $config['lot_size'] ?? 75
        ),
    ];
}


/**
 * Build a Call/Put leg.
 */
function oipulse_build_leg(
    ?array $leg,
    float $spot,
    float $strike,
    float $daysToExpiry,
    string $type,
    float $r
): array {

    /*
     * NSE can return null when a quote is unavailable.
     */
    $leg = $leg ?? [];

    $iv = (float) (
        $leg['impliedVolatility'] ?? 0
    );

    return [

        'oi' => (int) (
            $leg['openInterest'] ?? 0
        ),

        'chngOi' => (int) (
            $leg['changeinOpenInterest'] ?? 0
        ),

        'volume' => (int) (
            $leg['totalTradedVolume'] ?? 0
        ),

        'iv' => round(
            $iv,
            2
        ),

        'ltp' => round(
            (float) ($leg['lastPrice'] ?? 0),
            2
        ),

        'chng' => round(
            (float) ($leg['change'] ?? 0),
            2
        ),

        'bidQty' => (int) (
            $leg['buyQuantity1']
            ?? $leg['bidQty']
            ?? 0
        ),

        'bid' => round(
            (float) (
                $leg['buyPrice1']
                ?? $leg['bidprice']
                ?? 0
            ),
            2
        ),

        'ask' => round(
            (float) (
                $leg['sellPrice1']
                ?? $leg['askPrice']
                ?? 0
            ),
            2
        ),

        'askQty' => (int) (
            $leg['sellQuantity1']
            ?? $leg['askQty']
            ?? 0
        ),

        /*
         * Calculate Black-Scholes delta only when
         * NSE provides a valid IV.
         */
        'delta' => $iv > 0
            ? oipulse_bs_delta(
                $spot,
                $strike,
                $iv,
                $daysToExpiry,
                $type,
                $r
            )
            : 0.0,

        'moneyness' => oipulse_moneyness(
            $type,
            $strike,
            $spot
        ),
    ];
}


/**
 * Get top support/resistance levels based on OI.
 */
function oipulse_top_levels(
    array $chain,
    string $side,
    int $n
): array {

    $levels = array_map(
        static fn($row) => [
            'strike' => $row['strike'],
            'oi' => $row[$side]['oi'],
        ],
        $chain
    );

    usort(
        $levels,
        static fn($a, $b) => $b['oi'] <=> $a['oi']
    );

    return array_slice(
        $levels,
        0,
        $n
    );
}


/**
 * Build trading signal.
 */
function oipulse_build_signal(
    array $latest
): array {

    $pcr = (float) $latest['pcr'];

    /*
     * PCR interpretation.
     */
    $bias = $pcr > 1.2
        ? 'Bullish'
        : (
            $pcr < 0.8
                ? 'Bearish'
                : 'Neutral'
        );

    /*
     * Top support.
     */
    $topSupport =
        $latest['support'][0]['strike']
        ?? (
            $latest['spot'] - 100
        );

    /*
     * Top resistance.
     */
    $topResistance =
        $latest['resistance'][0]['strike']
        ?? (
            $latest['spot'] + 100
        );

    /*
     * Confidence calculation.
     */
    $confidence =
        abs($pcr - 1) > 0.28
            ? 'High'
            : (
                abs($pcr - 1) > 0.12
                    ? 'Medium'
                    : 'Low'
            );

    return [

        'bias' => $bias,

        'confidence' => $confidence,

        'reasons' => [

            sprintf(
                'PCR at %.2f — %s skew in OI distribution',
                $pcr,
                strtolower($bias)
            ),

            "Heaviest put OI build-up at {$topSupport} (support)",

            "Call writers defending {$topResistance} (resistance)",
        ],

        'entryZone' => [
            $topSupport,
            $topSupport + 60,
        ],

        'stopLoss' => [
            $topSupport - 90,
            $topSupport - 50,
        ],

        'target' => [
            $topResistance - 40,
            $topResistance,
        ],
    ];
}