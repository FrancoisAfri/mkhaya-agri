<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAsset;
use App\Modules\Finance\Accounting\Models\FinAssetDepreciation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-asset engine (Phase 5.1): registration + optional acquisition posting,
 * monthly depreciation (straight-line / reducing-balance), and disposal with an
 * auto-posted gain or loss. Everything posts through the shared Ledger.
 */
class FixedAssetService
{
    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * Register an asset. When $postAcquisition is set, capitalise it now:
     * Dr asset cost account / Cr the funding account (bank or AP control).
     */
    public function create(array $data, bool $postAcquisition = false, ?string $fundingContra = null): FinAsset
    {
        $data['asset_account_id']   ??= $this->accountId(config('finance.fixed_asset_account'));
        $data['accum_account_id']   ??= $this->accountId(config('finance.accum_depreciation_account'));
        $data['expense_account_id'] ??= $this->accountId(config('finance.depreciation_expense_account'));

        return DB::transaction(function () use ($data, $postAcquisition, $fundingContra) {
            $asset = FinAsset::create($data + ['created_by' => Auth::id()]);

            if ($postAcquisition && (float) $asset->cost > 0) {
                $contra = $fundingContra ?: config('finance.default_bank');
                $journal = $this->ledger->post(new FinancialEvent(
                    sourceModule: 'fixed_assets', eventType: 'asset.acquired',
                    debits:  [['account' => $asset->asset_account_id, 'amount' => (float) $asset->cost, 'narration' => "Acquired {$asset->name}"]],
                    credits: [['account' => $contra, 'amount' => (float) $asset->cost, 'narration' => "Acquired {$asset->name}"]],
                    eventDate: Carbon::parse($asset->acquisition_date), companyId: $asset->company_id,
                    sourceRef: "asset:{$asset->id}", description: "Acquisition of {$asset->name}",
                    idempotencyKey: "asset_acq:{$asset->id}",
                ));
                $asset->update(['acquisition_journal_id' => $journal->id]);
            }

            return $asset;
        });
    }

    /** The depreciation charge for one month (0 if fully depreciated or not yet in service). */
    public function monthlyDepreciation(FinAsset $asset): float
    {
        if (! $asset->isDepreciable()) {
            return 0.0;
        }
        $bookValue = $asset->bookValue();
        $floor     = (float) $asset->residual_value;

        $charge = match ($asset->method) {
            'reducing_balance' => $bookValue * ((float) $asset->annual_rate / 100 / 12),
            default            => ((float) $asset->cost - $floor) / max(1, (int) $asset->useful_life_months),
        };

        // Never depreciate below the residual value.
        return round(min($charge, $bookValue - $floor), 2);
    }

    /** Post one month of depreciation as at the given date (idempotent per asset+month). */
    public function runFor(FinAsset $asset, Carbon $asOf): ?FinAssetDepreciation
    {
        $period = $asOf->copy()->endOfMonth()->startOfDay();

        if (Carbon::parse($asset->acquisition_date)->gt($period)) {
            return null; // not yet in service
        }
        if (FinAssetDepreciation::where('asset_id', $asset->id)->whereDate('period_date', $period)->exists()) {
            return null; // already run for this month
        }

        $charge = $this->monthlyDepreciation($asset);
        if ($charge <= 0) {
            return null;
        }

        return DB::transaction(function () use ($asset, $period, $charge) {
            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'fixed_assets', eventType: 'asset.depreciation',
                debits:  [['account' => $asset->expense_account_id, 'amount' => $charge, 'narration' => "Depreciation — {$asset->name}"]],
                credits: [['account' => $asset->accum_account_id, 'amount' => $charge, 'narration' => "Depreciation — {$asset->name}"]],
                eventDate: $period, companyId: $asset->company_id,
                sourceRef: "asset:{$asset->id}", description: "Depreciation {$period->format('M Y')} — {$asset->name}",
                idempotencyKey: "asset_dep:{$asset->id}:{$period->toDateString()}",
            ));

            $asset->update([
                'accumulated_depreciation' => round((float) $asset->accumulated_depreciation + $charge, 2),
                'last_depreciated_on'      => $period,
            ]);

            return FinAssetDepreciation::create([
                'asset_id' => $asset->id, 'period_date' => $period, 'amount' => $charge,
                'book_value_after' => $asset->fresh()->bookValue(), 'journal_id' => $journal->id, 'created_by' => Auth::id(),
            ]);
        });
    }

    /** Depreciate every eligible asset for the month of $asOf. */
    public function run(Carbon $asOf, ?int $companyId = null): array
    {
        $count = 0;
        $total = 0.0;

        FinAsset::active()->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('acquisition_date', '<=', $asOf->copy()->endOfMonth())
            ->orderBy('id')->get()
            ->each(function (FinAsset $asset) use ($asOf, &$count, &$total) {
                $entry = $this->runFor($asset, $asOf);
                if ($entry) {
                    $count++;
                    $total += (float) $entry->amount;
                }
            });

        return ['count' => $count, 'total' => round($total, 2), 'period' => $asOf->copy()->endOfMonth()->toDateString()];
    }

    /**
     * Dispose of an asset. Removes cost and accumulated depreciation, recognises
     * the proceeds, and posts the balancing gain (credit) or loss (debit).
     */
    public function dispose(FinAsset $asset, Carbon $date, float $proceeds, ?string $proceedsContra = null): FinAsset
    {
        if ($asset->status !== 'active') {
            throw new PostingException('Only an active asset can be disposed.');
        }
        $proceeds  = round(max(0, $proceeds), 2);
        $cost      = round((float) $asset->cost, 2);
        $accum     = round((float) $asset->accumulated_depreciation, 2);
        $nbv       = round($cost - $accum, 2);
        $gain      = round($proceeds - $nbv, 2);
        $contra    = $proceedsContra ?: config('finance.default_bank');
        $disposal  = config('finance.asset_disposal_account');

        return DB::transaction(function () use ($asset, $date, $proceeds, $cost, $accum, $gain, $contra, $disposal) {
            $debits = [];
            $credits = [['account' => $asset->asset_account_id, 'amount' => $cost, 'narration' => "Disposal — {$asset->name}"]];

            if ($accum > 0) {
                $debits[] = ['account' => $asset->accum_account_id, 'amount' => $accum, 'narration' => 'Remove accumulated depreciation'];
            }
            if ($proceeds > 0) {
                $debits[] = ['account' => $contra, 'amount' => $proceeds, 'narration' => "Proceeds — {$asset->name}"];
            }
            if ($gain > 0) {
                $credits[] = ['account' => $disposal, 'amount' => $gain, 'narration' => 'Gain on disposal'];
            } elseif ($gain < 0) {
                $debits[] = ['account' => $disposal, 'amount' => abs($gain), 'narration' => 'Loss on disposal'];
            }

            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'fixed_assets', eventType: 'asset.disposed', debits: $debits, credits: $credits,
                eventDate: $date, companyId: $asset->company_id,
                sourceRef: "asset:{$asset->id}", description: "Disposal of {$asset->name}",
                idempotencyKey: "asset_disposal:{$asset->id}",
            ));

            $asset->update([
                'status' => 'disposed', 'disposal_date' => $date, 'disposal_proceeds' => $proceeds,
                'disposal_journal_id' => $journal->id,
            ]);

            return $asset;
        });
    }

    private function accountId(string $code): int
    {
        $id = \App\Modules\Finance\Accounting\Models\FinAccount::where('code', $code)->value('id');
        if (! $id) {
            throw new PostingException("Fixed-asset account [{$code}] is missing from the chart.");
        }

        return (int) $id;
    }
}
