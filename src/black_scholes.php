<?php
declare(strict_types=1);

/**
 * Mirrors frontend/src/lib/black-scholes.ts exactly. If you change one,
 * change the other — this is the same "estimate this trade" math that
 * powers the click-to-estimate modal, just computed here from live NSE IV
 * instead of mock IV.
 */

function oipulse_norm_cdf(float $x): float
{
    $t = 1 / (1 + 0.2316419 * abs($x));
    $d = 0.3989423 * exp(-$x * $x / 2);
    $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));
    return $x > 0 ? 1 - $p : $p;
}

/**
 * @param float  $spot          Underlying (Nifty) spot price
 * @param float  $strike        Option strike
 * @param float  $ivPercent     Implied volatility as a percentage, e.g. 14.2
 * @param float  $daysToExpiry  Calendar days to expiry (fractional allowed)
 * @param string $optionType    'call' | 'put'
 * @param float  $riskFreeRate  e.g. 0.065
 */
function oipulse_bs_delta(
    float $spot,
    float $strike,
    float $ivPercent,
    float $daysToExpiry,
    string $optionType,
    float $riskFreeRate,
): float {
    $sigma = max(0.01, $ivPercent / 100);
    $T = max($daysToExpiry, 0.25) / 365; // floor avoids divide-by-~0 on expiry day
    $d1 = (log($spot / $strike) + ($riskFreeRate + ($sigma * $sigma) / 2) * $T) / ($sigma * sqrt($T));

    if ($optionType === 'call') {
        return round(oipulse_norm_cdf($d1), 4);
    }
    return round(oipulse_norm_cdf($d1) - 1, 4);
}

function oipulse_moneyness(string $optionType, float $strike, float $spot, float $bandPoints = 25.0): string
{
    if (abs($strike - $spot) <= $bandPoints) {
        return 'ATM';
    }
    if ($optionType === 'call') {
        return $strike < $spot ? 'ITM' : 'OTM';
    }
    return $strike > $spot ? 'ITM' : 'OTM';
}
