<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\Models\DueDiligenceQuestionnaire;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierScar;
use App\Modules\SupplierManagement\Services\SupplierComplianceService;

/**
 * Supplier health: how this relationship is going, as a percentage where higher is better.
 *
 * Deliberately the inverse of the risk score, and deliberately a different question. Risk asks
 * "should we engage with them at all" and is dominated by the worst thing known; health asks "how is
 * this working out" and is an average across the things that make a supplier good to deal with —
 * they deliver, they invoice correctly, their paperwork is current, nothing has blown up.
 *
 * Keeping them apart matters because they move independently and get used by different people. A
 * supplier can be low risk and a nightmare to work with (late, disputed invoices, endless corrective
 * actions), and another can carry real risk and deliver flawlessly. Collapsing both into one number
 * would hide whichever half the reader was not asking about.
 *
 * Expressed as a percentage because that is how an executive reads a health figure, and inverted
 * from risk for the same reason — 82% good is legible in a way that 18/100 bad is not.
 */
class HealthScore
{
    /**
     * What health is made of. Weights sum to 100.
     *
     * Delivery weighs most because it is the thing the buyer actually bought.
     */
    private const WEIGHTS = [
        'delivery' => 25.0,
        'compliance' => 20.0,
        'risk' => 20.0,
        'financial' => 15.0,
        'documentation' => 10.0,
        'responsiveness' => 10.0,
    ];

    public function __construct(
        private readonly SupplierComplianceService $compliance,
        private readonly ReputationScore $reputation,
    ) {}

    /**
     * @return array{score:float, band:string, components:array<string, array<string,mixed>>, covered:bool}
     */
    public function for(Supplier $supplier): array
    {
        $components = [
            'delivery' => $this->delivery($supplier),
            'compliance' => $this->compliance($supplier),
            'risk' => $this->risk($supplier),
            'financial' => $this->financial($supplier),
            'documentation' => $this->documentation($supplier),
            'responsiveness' => $this->responsiveness($supplier),
        ];

        // Only components with something behind them vote. A supplier three weeks old has no
        // delivery record, and scoring that as poor health would tell you about their age rather
        // than their performance.
        $weighted = 0.0;
        $weight = 0.0;

        foreach ($components as $key => $component) {
            if (! $component['covered']) {
                continue;
            }

            $weighted += $component['score'] * self::WEIGHTS[$key];
            $weight += self::WEIGHTS[$key];
        }

        $score = $weight > 0 ? round($weighted / $weight, 1) : 0.0;

        return [
            'score' => $score,
            'band' => $this->band($score, $weight > 0),
            'components' => collect($components)->map(fn ($c, $k) => $c + [
                'weight' => self::WEIGHTS[$k],
                'label' => $this->label($k),
            ])->all(),
            'covered' => $weight > 0,
        ];
    }

    public function score(Supplier $supplier): float
    {
        return $this->for($supplier)['score'];
    }

    /** Do they deliver? Corrective actions are the record of when they did not. */
    private function delivery(Supplier $supplier): array
    {
        $scars = SupplierScar::query()->where('supplier_id', $supplier->id)->get();
        $appraisals = $supplier->appraisals()->get();

        if ($scars->isEmpty() && $appraisals->isEmpty()) {
            return ['score' => 100.0, 'covered' => false, 'detail' => 'No delivery record yet.'];
        }

        $open = $scars->whereIn('status', ['open', 'Open', 'in_progress'])->count();
        $score = max(0.0, 100.0 - ($open * 20) - (max(0, $scars->count() - $open) * 5));

        return [
            'score' => $score,
            'covered' => true,
            'detail' => $scars->isEmpty()
                ? 'No corrective actions raised.'
                : "{$scars->count()} corrective action(s), {$open} still open.",
        ];
    }

    /**
     * Is their paperwork current?
     *
     * Only counts where the tenant has actually defined compulsory documents. "All compulsory
     * documents on file" is vacuously true when none are required, and letting that vote 100 gives a
     * supplier nobody knows anything about a clean bill of health — the same flattering-empty-record
     * failure the risk engine had to be corrected for.
     */
    private function compliance(Supplier $supplier): array
    {
        $required = \App\Modules\SupplierManagement\Models\SupplierDocumentType::query()
            ->where('active', true)->where('compulsory', true)->count();

        if ($required === 0) {
            return ['score' => 100.0, 'covered' => false, 'detail' => 'No compulsory documents are configured.'];
        }

        $blockers = $this->compliance->blockers($supplier);

        return [
            'score' => $blockers->isEmpty() ? 100.0 : max(0.0, 100.0 - $blockers->count() * 25),
            'covered' => true,
            'detail' => $blockers->isEmpty()
                ? 'All compulsory documents on file and in date.'
                : $blockers->count().' document(s) missing or expired.',
        ];
    }

    /** The risk score, inverted — health includes it without being it. */
    private function risk(Supplier $supplier): array
    {
        $snapshot = SupplierRiskScore::latestFor($supplier->id);

        if (! $snapshot) {
            return ['score' => 100.0, 'covered' => false, 'detail' => 'Never assessed.'];
        }

        return [
            'score' => max(0.0, 100.0 - (float) $snapshot->total),
            'covered' => true,
            'detail' => sprintf('Risk %.0f/100 (%s).', $snapshot->total, $snapshot->bandLabel()),
        ];
    }

    private function financial(Supplier $supplier): array
    {
        $report = SupplierCreditReport::latestFor($supplier->id);

        if (! $report) {
            return ['score' => 100.0, 'covered' => false, 'detail' => 'No credit report on file.'];
        }

        $risk = $report->riskScore() ?? 50.0;

        return [
            'score' => max(0.0, 100.0 - $risk),
            'covered' => true,
            'detail' => $report->insolvency
                ? 'Insolvency recorded.'
                : sprintf('Bureau score %s, %d judgment(s).', $report->score ?? '—', $report->judgments_count),
        ];
    }

    private function documentation(Supplier $supplier): array
    {
        $documents = $supplier->documents()->count();

        return [
            'score' => $documents > 0 ? 100.0 : 50.0,
            'covered' => $documents > 0,
            'detail' => "{$documents} document(s) on file.",
        ];
    }

    /** How quickly they come back on what is asked of them. */
    private function responsiveness(Supplier $supplier): array
    {
        $questionnaires = DueDiligenceQuestionnaire::query()->where('supplier_id', $supplier->id)->get();

        if ($questionnaires->isEmpty()) {
            return ['score' => 100.0, 'covered' => false, 'detail' => 'Nothing has been asked of them yet.'];
        }

        $overdue = $questionnaires->filter(fn ($q) => ! $q->isComplete()
            && $q->due_on !== null && $q->due_on->isPast())->count();

        $completeness = (float) $questionnaires->avg('completeness');

        return [
            'score' => max(0.0, $completeness - ($overdue * 20)),
            'covered' => true,
            'detail' => $overdue > 0
                ? "{$overdue} questionnaire(s) overdue."
                : sprintf('%.0f%% of questions answered.', $completeness),
        ];
    }

    /** Health bands read the other way round from risk, which is the point of having both. */
    private function band(float $score, bool $covered): string
    {
        if (! $covered) {
            return 'unknown';
        }

        return match (true) {
            $score >= 85 => 'excellent',
            $score >= 70 => 'good',
            $score >= 50 => 'fair',
            $score >= 30 => 'poor',
            default => 'critical',
        };
    }

    private function label(string $key): string
    {
        return match ($key) {
            'delivery' => 'Delivery & corrective actions',
            'compliance' => 'Compliance documents',
            'risk' => 'Risk position',
            'financial' => 'Financial standing',
            'documentation' => 'Documentation',
            'responsiveness' => 'Responsiveness',
            default => $key,
        };
    }
}
