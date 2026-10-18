<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An exchange rate effective from a date. Lookups take the most recent rate on or
 * before the requested date; the inverse pair is used automatically if only one
 * direction is captured.
 */
class FinExchangeRate extends Model
{
    protected $table = 'fin_exchange_rates';

    protected $fillable = [
        'base_currency', 'quote_currency', 'rate', 'rate_type', 'source', 'effective_date',
    ];

    protected $casts = [
        'rate'           => 'decimal:8',
        'effective_date' => 'date',
    ];

    /**
     * Rate to convert 1 unit of $from into $to on $date. Returns 1.0 for the same
     * currency, the inverse of a reverse pair if needed, or null if unknown.
     */
    public static function rateFor(string $from, string $to, $date, string $type = 'spot'): ?float
    {
        $from = strtoupper($from);
        $to   = strtoupper($to);
        if ($from === $to) {
            return 1.0;
        }

        $on = Carbon::parse($date)->toDateString();

        $direct = static::where('base_currency', $from)->where('quote_currency', $to)
            ->where('rate_type', $type)->where('effective_date', '<=', $on)
            ->orderByDesc('effective_date')->value('rate');
        if ($direct !== null) {
            return (float) $direct;
        }

        $inverse = static::where('base_currency', $to)->where('quote_currency', $from)
            ->where('rate_type', $type)->where('effective_date', '<=', $on)
            ->orderByDesc('effective_date')->value('rate');
        if ($inverse !== null && (float) $inverse !== 0.0) {
            return round(1 / (float) $inverse, 8);
        }

        return null;
    }
}
