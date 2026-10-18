<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierChangeEvent;
use App\Modules\SupplierManagement\Models\SupplierMonitoringRun;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Re-runs the checks that have gone stale, notices what moved, and reassesses when it matters.
 *
 * This is the half of the promise the rest of the module sets up. Verification establishes who a
 * supplier is on a Tuesday; without this, that answer is quietly treated as permanent, and the
 * system's confidence in a supplier grows stale at exactly the rate the world changes.
 *
 * What is due is worked out per check rather than per supplier, because the checks age at different
 * speeds and pretending otherwise wastes money on the slow ones and misses the fast ones. A tax
 * compliance status is a statement about a moment and goes stale in a month; a registered address
 * does not move twice a year. `CheckType::validDays()` already carries that, so due-ness is simply
 * "the last answer's freshness horizon has passed" — scaled by risk band, since a critical supplier
 * is worth looking at more often than the model's default.
 *
 * A reassessment is triggered by a change, not by the calendar. Rescoring a supplier nothing has
 * happened to produces an identical number, a new snapshot, and a monitoring report full of
 * movement that is not movement.
 */
class ContinuousMonitor
{
    /** How much sooner a supplier in each band is re-checked. 1.0 is the check's own horizon. */
    private const BAND_URGENCY = [
        'critical' => 0.25,
        'high' => 0.5,
        'moderate' => 0.75,
        'low' => 1.0,
        'excellent' => 1.0,
    ];

    /** Changes serious enough to be worth rescoring for. */
    private const REASSESS_ON = ['high', 'critical'];

    public function __construct(
        private readonly VerificationEngine $engine,
        private readonly ProviderRegistry $registry,
        private readonly ChangeDetector $changes,
        private readonly RiskEngine $risk,
    ) {}

    /**
     * Re-run whatever is due for one supplier.
     *
     * @param  bool  $force  ignore freshness and re-run everything a source can answer
     */
    public function sweep(Supplier $supplier, string $trigger = 'scheduled', bool $force = false): SupplierMonitoringRun
    {
        $riskBefore = $supplier->risk_score;

        $run = SupplierMonitoringRun::create([
            'supplier_id' => $supplier->id,
            'scope' => 'checks',
            'trigger' => $trigger,
            'run_by' => auth()->id(),
            'started_at' => Carbon::now(),
        ]);

        $due = $force ? $this->answerable($supplier) : $this->dueChecks($supplier);
        $queried = 0;
        $unavailable = 0;
        $detected = [];

        foreach ($due as $checkType) {
            if (! $this->registry->hasDataSourceFor($checkType)) {
                // Nothing can answer it. Recorded so the gap is visible rather than the check simply
                // never appearing to be due again.
                $this->engine->verify($supplier, $checkType);
                $unavailable++;

                continue;
            }

            $observation = $this->engine->verify($supplier, $checkType);
            $queried++;

            foreach ($this->changes->forVerification($observation) as $change) {
                $detected[] = $change;
            }
        }

        // One reassessment for the whole sweep, not one per change — a supplier with four changes
        // does not need four snapshots, and the report would read as four separate events.
        $serious = array_filter($detected, fn (SupplierChangeEvent $c) => in_array($c->severity, self::REASSESS_ON, true));
        $riskAfter = $riskBefore;

        if ($serious !== []) {
            $score = $this->risk->assess($supplier->fresh(), 'monitoring');
            $riskAfter = (float) $score->total;

            foreach ($serious as $change) {
                $change->forceFill([
                    'triggered_reassessment' => true,
                    'risk_before' => $riskBefore,
                    'risk_after' => $riskAfter,
                    'monitoring_run_id' => $run->id,
                ])->save();
            }

            $this->flag($supplier, $serious);

            // A flag sits on a screen somebody has to visit; routing reaches the person whose job it
            // is. One alert for the sweep, at the worst severity found, rather than one per change.
            $worst = collect($serious)->contains(fn ($c) => $c->severity === 'critical') ? 'critical' : 'high';

            app(AlertRouter::class)->dispatch(
                $supplier,
                'change',
                $worst,
                sprintf('%d change(s) detected on %s: %s', count($serious), $supplier->company_name,
                    collect($serious)->map(fn ($c) => $c->summary)->take(2)->implode(' ')),
                ['change_ids' => collect($serious)->pluck('id')->all()],
            );
        }

        foreach ($detected as $change) {
            if ($change->monitoring_run_id === null) {
                $change->forceFill(['monitoring_run_id' => $run->id])->save();
            }
        }

        $supplier->forceFill([
            'last_monitored_at' => Carbon::now(),
            'monitoring_due_at' => $this->nextDueAt($supplier),
        ])->saveQuietly();

        $run->forceFill([
            'sources_queried' => $queried,
            'sources_unavailable' => $unavailable,
            'findings_new' => count($detected),
            'alerts_raised' => count($serious),
            'reputation_before' => $riskBefore,
            'reputation_after' => $riskAfter,
            'completed_at' => Carbon::now(),
        ])->save();

        return $run->fresh();
    }

    /**
     * The checks whose last answer has gone stale.
     *
     * A check that has never been run is due immediately — an unanswered question does not become
     * less urgent by being ignored.
     *
     * @return array<int, string>
     */
    public function dueChecks(Supplier $supplier): array
    {
        $current = SupplierVerification::currentFor($supplier->id);
        $urgency = self::BAND_URGENCY[$supplier->risk_band ?? 'moderate'] ?? 0.75;
        $due = [];

        foreach ($this->answerable($supplier) as $checkType) {
            $observation = $current->get($checkType);

            if (! $observation) {
                $due[] = $checkType;

                continue;
            }

            $days = CheckType::validDays($checkType);

            if ($days === null) {
                // No natural horizon — re-run on the band's own rhythm rather than never.
                $days = 180;
            }

            $horizon = max(1, (int) round($days * $urgency));

            if ($observation->checked_at->addDays($horizon)->isPast()) {
                $due[] = $checkType;
            }
        }

        return $due;
    }

    /** Checks a source could answer, plus the ones already attempted so the gap keeps showing. */
    private function answerable(Supplier $supplier): array
    {
        return array_values(array_filter(
            [
                CheckType::COMPANY_REGISTRY,
                CheckType::COMPANY_DIRECTORS,
                CheckType::COMPANY_ADDRESS,
                CheckType::OWNERSHIP_BENEFICIAL,
                CheckType::TAX_COMPLIANCE,
                CheckType::TAX_VAT_VENDOR,
                CheckType::BANK_ACCOUNT,
                CheckType::CREDIT_REPORT,
                CheckType::SCREENING_SANCTIONS,
                CheckType::SCREENING_PEP,
            ],
            fn (string $check) => CheckType::exists($check)
        ));
    }

    /** When this supplier should next be swept. */
    public function nextDueAt(Supplier $supplier): Carbon
    {
        $urgency = self::BAND_URGENCY[$supplier->risk_band ?? 'moderate'] ?? 0.75;

        // The soonest any of its checks will go stale, floored at a week so a critical supplier is
        // not swept daily for a check that changes annually.
        return Carbon::now()->addDays(max(7, (int) round(30 * $urgency)));
    }

    /** Is this supplier due a sweep at all? */
    public function isDue(Supplier $supplier): bool
    {
        if ($supplier->monitoring_due_at === null) {
            return true;
        }

        return $supplier->monitoring_due_at->isPast() || $this->dueChecks($supplier) !== [];
    }

    /**
     * One flag for the sweep, naming the worst of it.
     *
     * A flag per change would put four rows on a supplier for one afternoon's news, and a queue of
     * four hundred is one nobody works.
     */
    private function flag(Supplier $supplier, array $changes): void
    {
        $worst = collect($changes)->contains(fn (SupplierChangeEvent $c) => $c->severity === 'critical') ? 'critical' : 'high';

        SupplierRiskFlag::query()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'type' => 'supplier_changed', 'status' => 'open'],
            [
                'severity' => $worst,
                'message' => sprintf(
                    '%d change(s) detected: %s',
                    count($changes),
                    Str::limit(collect($changes)->map(fn ($c) => $c->summary)->implode(' '), 400, '')
                ),
                'context' => [
                    'change_ids' => collect($changes)->pluck('id')->all(),
                    'categories' => collect($changes)->pluck('category')->unique()->values()->all(),
                ],
            ]
        );
    }
}
