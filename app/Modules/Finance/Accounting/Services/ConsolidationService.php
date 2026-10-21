<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Models\FinGroup;
use Illuminate\Support\Carbon;

/**
 * Group consolidation (Phase 6.4). Aggregates member companies' trial balances,
 * translates each to the group's base currency by its FX rate, eliminates
 * intercompany accounts (with an audit), and derives a minority-interest note.
 */
class ConsolidationService
{
    public function __construct(private FinanceReportService $reports)
    {
    }

    public function consolidate(FinGroup $group, ?string $asOf = null): array
    {
        $start = microtime(true);
        $asOf = $asOf ?: Carbon::now()->toDateString();
        $intercompany = array_map('strval', $group->intercompany_codes ?? []);

        $consolidated = [];   // code => ['name','type','balance']
        $memberTotals = [];   // per-member translated debit/credit summary
        $nci = 0.0;

        foreach ($group->members as $member) {
            $rate = (float) $member->fx_rate;
            $ownership = (float) $member->ownership_pct / 100;
            // Each member's books are read inside that member's organisation: the ledger's rows are
            // kept to their organisation by the database, so another company's are not visible from here.
            $balances = collect(app(\App\Modules\Tenancy\Services\TenantContext::class)
                ->run((int) $member->company_id, fn () => $this->reports->accountBalances((int) $member->company_id, $asOf)));

            $memberNet = 0.0;
            foreach ($balances as $b) {
                $translated = round((float) $b['balance'] * $rate, 2);
                $code = $b['code'];
                $consolidated[$code] ??= ['code' => $code, 'name' => $b['name'], 'type' => $b['type'], 'balance' => 0.0];
                $consolidated[$code]['balance'] = round($consolidated[$code]['balance'] + $translated, 2);
                if (in_array($b['type'], ['asset', 'liability', 'equity'], true)) {
                    $memberNet += ($b['type'] === 'asset' ? $translated : -$translated);
                }
            }

            // Minority interest = outside shareholders' share of the sub's net assets.
            if ($ownership < 1) {
                $nci = round($nci + $memberNet * (1 - $ownership), 2);
            }
            $memberTotals[] = ['company_id' => (int) $member->company_id, 'name' => $member->name, 'ownership_pct' => (float) $member->ownership_pct, 'fx_rate' => $rate];
        }

        // Intercompany elimination.
        $eliminations = [];
        foreach ($intercompany as $code) {
            if (isset($consolidated[$code]) && abs($consolidated[$code]['balance']) > 0.005) {
                $eliminations[] = ['code' => $code, 'name' => $consolidated[$code]['name'], 'amount' => $consolidated[$code]['balance']];
                $consolidated[$code]['balance'] = 0.0;
            }
        }

        $rows = collect($consolidated)->filter(fn ($r) => abs($r['balance']) > 0.005)->sortBy('code')->values()->all();

        return [
            'as_of'        => $asOf,
            'rows'         => $rows,
            'eliminations' => $eliminations,
            'members'      => $memberTotals,
            'nci'          => $nci,
            'totals'       => [
                'assets'      => round(collect($rows)->where('type', 'asset')->sum('balance'), 2),
                'liabilities' => round(collect($rows)->where('type', 'liability')->sum('balance'), 2),
                'equity'      => round(collect($rows)->where('type', 'equity')->sum('balance'), 2),
            ],
            'elapsed_ms'   => round((microtime(true) - $start) * 1000, 1),
        ];
    }
}
