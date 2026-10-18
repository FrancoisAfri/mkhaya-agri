<?php

namespace App\Modules\Procurement\Governance\Support\Integration;

use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converting bids into one currency so they can be ranked against each other (§1.6).
 *
 * A tender that reaches bidders in South Africa, Eswatini and Botswana receives offers in ZAR, SZL
 * and BWP. The scoring engine ranks on the lowest bid, so without conversion 900,000 BWP beats
 * 1,000,000 ZAR on the arithmetic and the wrong bidder wins — quietly, with a complete audit trail
 * of the wrong answer. That is the failure this class exists to prevent.
 *
 * It converts through the Finance module's fin_exchange_rates rather than keeping its own table,
 * for the same reason BudgetBridge does not re-derive available budget: two exchange rates for the
 * same day in one product is a defect waiting to be found by an auditor comparing a tender award to
 * the ledger that paid it.
 *
 * Where BudgetBridge answers null and lets the caller show "not available", this class refuses.
 * The difference is what the number is for. A budget figure that cannot be shown is a gap in a
 * report; a bid that cannot be converted and is ranked anyway is an award made on a false
 * comparison. So a missing rate stops the ranking and says which rate is missing.
 */
class CurrencyBridge
{
    /**
     * Currencies of the Common Monetary Area, which are pegged to the rand at par by treaty.
     *
     * Not an optimisation. Eswatini's lilangeni and Lesotho's loti are fixed 1:1 to the rand, and a
     * tenant would otherwise have to load a table of 1.0 rates daily to run a tender that crosses
     * the Eswatini border — and every day they forgot, evaluation would stop. Botswana's pula
     * floats and is deliberately not in this list: it needs a real rate.
     */
    private const PARITY_WITH_ZAR = ['ZAR', 'SZL', 'LSL', 'NAD'];

    public function isAvailable(): bool
    {
        return Schema::hasTable('fin_exchange_rates');
    }

    /**
     * The date whose rate applies, given the tenant's basis.
     *
     * CLOSING is the defensible default: every bid in a tender is then measured at one instant.
     * Converting each bid at its own submission date would rank two identical offers differently for
     * having arrived on different days.
     */
    public function rateDate(object $review, string $basis = 'CLOSING'): Carbon
    {
        $date = match (strtoupper($basis)) {
            'SUBMISSION' => null, // resolved per bid by the caller; falls back to closing
            'EVALUATION' => $review->evaluation_start_date ?? $review->tender_closing_at ?? null,
            default => $review->tender_closing_at ?? null,
        };

        return $date ? Carbon::parse($date)->startOfDay() : Carbon::now()->startOfDay();
    }

    /**
     * The rate to convert $from into $to on $on, or null when none is known.
     *
     * Looks for the most recent rate on or before the date rather than that exact day: markets close
     * at weekends, and a tender closing on a Saturday is not a reason to stop an evaluation. The
     * date actually used is returned so it can be recorded against the bid.
     *
     * @return array{rate: float, date: string, source: string}|null
     */
    public function rate(int $companyId, string $from, string $to, Carbon $on, string $rateType = 'spot'): ?array
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return ['rate' => 1.0, 'date' => $on->toDateString(), 'source' => 'identity'];
        }

        if (in_array($from, self::PARITY_WITH_ZAR, true) && in_array($to, self::PARITY_WITH_ZAR, true)) {
            return ['rate' => 1.0, 'date' => $on->toDateString(), 'source' => 'cma_parity'];
        }

        if (! $this->isAvailable()) {
            return null;
        }

        $direct = $this->lookup($companyId, $from, $to, $on, $rateType);

        if ($direct) {
            return ['rate' => (float) $direct->rate, 'date' => (string) $direct->effective_date, 'source' => (string) ($direct->source ?? 'fin_exchange_rates')];
        }

        // A table holding ZAR→BWP can answer BWP→ZAR. Inverting is safe for a spot rate and saves a
        // tenant maintaining both directions of every pair.
        $inverse = $this->lookup($companyId, $to, $from, $on, $rateType);

        if ($inverse && (float) $inverse->rate != 0.0) {
            return [
                'rate' => 1 / (float) $inverse->rate,
                'date' => (string) $inverse->effective_date,
                'source' => (string) ($inverse->source ?? 'fin_exchange_rates').' (inverted)',
            ];
        }

        return null;
    }

    private function lookup(int $companyId, string $base, string $quote, Carbon $on, string $rateType): ?object
    {
        return DB::table('fin_exchange_rates')
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('rate_type', $rateType)
            ->whereDate('effective_date', '<=', $on->toDateString())
            // Rates are tenant-scoped, but a platform-wide rate (no company) is a perfectly good
            // answer for a tenant who has not loaded their own.
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderByDesc('effective_date')
            ->orderByRaw('company_id IS NULL')
            ->first();
    }

    /**
     * Convert an amount, refusing rather than guessing.
     *
     * @return array{amount: float, rate: float, date: string, source: string}
     *
     * @throws DomainRuleException when no rate is known
     */
    public function convert(int $companyId, float $amount, string $from, string $to, Carbon $on, string $rateType = 'spot'): array
    {
        $rate = $this->rate($companyId, $from, $to, $on, $rateType);

        if ($rate === null) {
            throw DomainRuleException::forField(
                'currency',
                __('procurement_governance.rules.currency.no_rate', [
                    'from' => strtoupper($from),
                    'to' => strtoupper($to),
                    'date' => $on->toDateString(),
                ]),
            );
        }

        return [
            'amount' => round($amount * $rate['rate'], 2),
            'rate' => $rate['rate'],
            'date' => $rate['date'],
            'source' => $rate['source'],
        ];
    }

    /**
     * Currencies present among a tender's bids that cannot be converted into the tender's currency.
     *
     * Used to warn on the tender screen before evaluation opens, rather than at the moment someone
     * presses "recompute scores" and the ranking refuses.
     *
     * @return list<string>
     */
    public function unconvertible(int $companyId, iterable $bidders, string $to, Carbon $on, string $rateType = 'spot'): array
    {
        $missing = [];

        foreach ($bidders as $bidder) {
            $from = strtoupper((string) ($bidder->currency ?? $to));

            if ($from === '' || in_array($from, $missing, true)) {
                continue;
            }

            if ($this->rate($companyId, $from, $to, $on, $rateType) === null) {
                $missing[] = $from;
            }
        }

        return $missing;
    }
}
