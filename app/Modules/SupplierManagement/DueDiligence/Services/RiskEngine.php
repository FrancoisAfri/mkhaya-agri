<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Services\SupplierComplianceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Turns everything known about a supplier into one number, a band, and a decision.
 *
 * Two rules govern the whole calculation.
 *
 * The first is that absence is not innocence. A supplier nobody has checked has no failed checks, so
 * any model that scores components from what it finds gives them zero and calls them excellent —
 * which is how every waved-through fraudulent supplier got waved through. Components declare what
 * missing data means for them, and for identity, ownership, tax, compliance, financial and screening
 * it means risk.
 *
 * The second is that the score is not the decision. A low number with an unadjudicated sanctions hit,
 * a lapsed tax verification or a mismatched bank account is not an approval — the gates are a
 * conjunction, and any one of them failing removes auto-approval regardless of how good the total
 * looks. The number tells you how much attention to pay; the gates tell you what you may do.
 *
 * The engine only calculates and records. What happens next — approve, escalate, open a case — is
 * the workflow's business, which is what keeps this reusable by the monitoring engine later.
 */
class RiskEngine
{
    public function __construct(
        private readonly SupplierComplianceService $compliance,
        private readonly OwnershipGraph $ownership,
    ) {}

    /**
     * Assess a supplier and store the snapshot.
     *
     * @param  string  $trigger  manual | onboarding | monitoring | event — kept so a spike can be
     *                           traced back to what caused the reassessment
     */
    public function assess(Supplier $supplier, string $trigger = 'manual'): SupplierRiskScore
    {
        $weights = $this->weights();
        $components = $this->components($supplier);
        $total = $this->weightedTotal($components, $weights);
        $band = RiskModel::bandFor($total, $this->bands());

        $gates = $this->gates($supplier, $components, $total);
        $previous = SupplierRiskScore::latestFor($supplier->id);

        $score = SupplierRiskScore::create([
            'supplier_id' => $supplier->id,
            'total' => $total,
            'band' => $band,
            'tier' => $supplier->risk_tier,
            'components' => $components,
            'weights' => $weights,
            'auto_approvable' => $gates['auto_approvable'],
            'blockers' => $gates['blockers'],
            'edd_triggers' => $gates['edd_triggers'],
            'previous_total' => $previous?->total,
            'trigger' => $trigger,
            'calculated_by' => Auth::id(),
            'calculated_at' => Carbon::now(),
        ]);

        // The directory needs to sort and filter two thousand suppliers by their current position
        // without writing this query twice. Snapshots stay authoritative; this is a cache of the
        // newest one.
        $supplier->forceFill([
            'risk_score' => $total,
            'risk_band' => $band,
            'risk_calculated_at' => $score->calculated_at,
        ])->saveQuietly();

        return $score;
    }

    /**
     * Score every component, each 0-100 where higher is worse.
     *
     * @return array<string, array{score:float, weight:float, label:string, covered:bool, reasons:array<int,string>}>
     */
    public function components(Supplier $supplier): array
    {
        $verifications = SupplierVerification::currentFor($supplier->id);
        $weights = $this->weights();

        $scored = [
            RiskModel::COMPANY_IDENTITY => $this->identity($verifications),
            RiskModel::OWNERSHIP => $this->ownershipRisk($supplier, $verifications),
            RiskModel::TAX => $this->tax($verifications),
            RiskModel::FINANCIAL => $this->financial($supplier),
            RiskModel::COMPLIANCE => $this->complianceRisk($supplier),
            RiskModel::SANCTIONS_PEP => $this->screening($supplier),
            RiskModel::REPUTATION => $this->reputation($supplier),
            RiskModel::OPERATIONAL => $this->operational($supplier),
            RiskModel::CYBER => $this->cyber($verifications),
            RiskModel::ESG => $this->esg($supplier),
        ];

        $definitions = RiskModel::components();
        $out = [];

        foreach ($scored as $key => $result) {
            $out[$key] = [
                'label' => $definitions[$key]['label'] ?? $key,
                'score' => round($result['score'], 2),
                'weight' => (float) ($weights[$key] ?? $definitions[$key]['weight'] ?? 0),
                'covered' => $result['covered'],
                'reasons' => $result['reasons'],
            ];
        }

        return $out;
    }

    // ── Components ──────────────────────────────────────────────────────────

    /** Is this company who it says it is? */
    private function identity($verifications): array
    {
        return $this->fromChecks($verifications, [
            CheckType::COMPANY_REGISTRY => 1.0,
            CheckType::COMPANY_DIRECTORS => 0.5,
            CheckType::BANK_ACCOUNT => 1.0,
        ], RiskModel::COMPANY_IDENTITY);
    }

    /**
     * Ownership: is the chain resolved, does it add up, and does it agree with the registry?
     *
     * An unresolved chain scores as risk rather than as nothing, because "we could not find out who
     * owns this" is the finding — it is not the same as "nobody owns a controlling stake".
     */
    private function ownershipRisk(Supplier $supplier, $verifications): array
    {
        $graph = $this->ownership->forSupplier($supplier);
        $reasons = [];
        $score = 0.0;
        $covered = $graph['edges'] !== [];

        if (! $covered) {
            return [
                'score' => RiskModel::missingScore(RiskModel::OWNERSHIP),
                'covered' => false,
                'reasons' => ['No ownership has been captured, so the people behind this supplier are unknown.'],
            ];
        }

        if ($graph['ubos'] === []) {
            $score += 45;
            $reasons[] = 'The chain does not resolve to any natural person.';
        }

        foreach ($graph['unallocated'] as $gap) {
            $score += min(25, $gap['missing'] / 2);
            $reasons[] = "{$gap['entity']}: {$gap['missing']}% of the shareholding is unaccounted for.";
        }

        if ($graph['cycles'] !== []) {
            $score += 30;
            $reasons[] = 'Circular ownership detected.';
        }

        if ($graph['dead_ends'] !== []) {
            $score += 20;
            $reasons[] = 'The chain stops at a company with no declared owners.';
        }

        // A registry discrepancy is the strongest ownership signal there is.
        $check = $verifications->get(CheckType::OWNERSHIP_BENEFICIAL);

        if ($check && $check->status === VerificationStatus::MISMATCH) {
            $score += 40;
            $reasons[] = 'Declared ownership does not agree with the registry.';
        }

        return ['score' => min(100, $score), 'covered' => true, 'reasons' => $reasons];
    }

    private function tax($verifications): array
    {
        return $this->fromChecks($verifications, [
            CheckType::TAX_COMPLIANCE => 1.0,
            CheckType::TAX_VAT_VENDOR => 0.4,
        ], RiskModel::TAX);
    }

    /** Credit standing, from the newest bureau pull. */
    private function financial(Supplier $supplier): array
    {
        $report = SupplierCreditReport::latestFor($supplier->id);

        if (! $report) {
            return [
                'score' => RiskModel::missingScore(RiskModel::FINANCIAL),
                'covered' => false,
                'reasons' => ['No credit report on file.'],
            ];
        }

        $score = $report->riskScore() ?? 40.0;
        $reasons = [];

        if ($report->insolvency) {
            $score = max($score, 95);
            $reasons[] = 'Insolvency recorded.';
        }
        if ($report->business_rescue) {
            $score = max($score, 85);
            $reasons[] = 'In business rescue.';
        }
        if ($report->judgments_count > 0) {
            $score += min(25, $report->judgments_count * 8);
            $reasons[] = "{$report->judgments_count} judgment(s) on record.";
        }
        if ($report->defaults_count > 0) {
            $score += min(20, $report->defaults_count * 5);
            $reasons[] = "{$report->defaults_count} default(s) on record.";
        }
        if ($report->payment_behaviour === 'delinquent') {
            $score += 15;
            $reasons[] = 'Payment behaviour is delinquent.';
        } elseif ($report->payment_behaviour === 'slow') {
            $score += 7;
            $reasons[] = 'Pays slowly.';
        }

        // A report old enough to be about a different company is not evidence about this one.
        if ($report->report_date->lt(now()->subMonths(12))) {
            $reasons[] = 'The credit report is over a year old.';
            $score += 10;
        }

        return ['score' => min(100, $score), 'covered' => true, 'reasons' => $reasons];
    }

    /** Mandatory paperwork, answered by the service that already enforces it at activation. */
    private function complianceRisk(Supplier $supplier): array
    {
        $missing = $this->compliance->missingDocuments($supplier);
        $lapsed = $this->compliance->lapsedDocuments($supplier);
        $reasons = [];
        $score = 0.0;

        if ($missing->isNotEmpty()) {
            $score += min(60, $missing->count() * 20);
            $reasons[] = 'Missing: '.$missing->implode(', ');
        }

        if ($lapsed->isNotEmpty()) {
            $score += min(60, $lapsed->count() * 20);
            $reasons[] = 'Expired: '.$lapsed->implode(', ');
        }

        return ['score' => min(100, $score), 'covered' => true, 'reasons' => $reasons];
    }

    /**
     * Sanctions and PEP.
     *
     * A confirmed sanctions hit is the top of the scale and nothing else in the model can pull it
     * down. A PEP is not: being politically exposed is a reason for more diligence, not a finding of
     * wrongdoing, and scoring it as though it were would teach people to dismiss PEP hits on sight.
     */
    private function screening(Supplier $supplier): array
    {
        $hits = ScreeningHit::query()->where('supplier_id', $supplier->id)->live()->get();
        $screened = SupplierVerification::query()
            ->where('supplier_id', $supplier->id)
            ->whereIn('check_type', [CheckType::SCREENING_SANCTIONS, CheckType::SCREENING_PEP])
            ->exists();

        if (! $screened && $hits->isEmpty()) {
            return [
                'score' => RiskModel::missingScore(RiskModel::SANCTIONS_PEP),
                'covered' => false,
                'reasons' => ['Nobody connected to this supplier has been screened.'],
            ];
        }

        $score = 0.0;
        $reasons = [];

        $sanctions = $hits->where('list_type', ScreeningHit::TYPE_SANCTIONS);
        $peps = $hits->where('list_type', ScreeningHit::TYPE_PEP);

        if ($sanctions->where('status', ScreeningHit::STATUS_CONFIRMED)->isNotEmpty()) {
            return [
                'score' => 100.0,
                'covered' => true,
                'reasons' => ['A sanctions match has been confirmed against this supplier or its people.'],
            ];
        }

        if ($open = $sanctions->where('status', ScreeningHit::STATUS_OPEN)->count()) {
            $score += 60;
            $reasons[] = "{$open} sanctions match(es) awaiting adjudication.";
        }

        if ($confirmedPeps = $peps->where('status', ScreeningHit::STATUS_CONFIRMED)->count()) {
            $score += 25;
            $reasons[] = "{$confirmedPeps} confirmed politically exposed person(s) — requires enhanced diligence, not rejection.";
        }

        if ($openPeps = $peps->where('status', ScreeningHit::STATUS_OPEN)->count()) {
            $score += 15;
            $reasons[] = "{$openPeps} possible PEP match(es) awaiting adjudication.";
        }

        return ['score' => min(100, $score), 'covered' => true, 'reasons' => $reasons];
    }

    /**
     * Reputation, from the monitoring findings.
     *
     * Delegated to ReputationScore rather than counted here, because the weighing is not a count:
     * severity, category, recency, source credibility, corroboration, confirmation and how sure we
     * are the story is even about this supplier all bear on it. Fifteen customers complaining about
     * delivery must not outweigh one regulator opening an investigation.
     *
     * Thin coverage is reported as thin coverage. A supplier nobody has searched for has no findings,
     * and reading that as a clean record would be the system inventing good news.
     */
    private function reputation(Supplier $supplier): array
    {
        $reputation = app(ReputationScore::class)->for($supplier);

        if (! $reputation['coverage']) {
            return ['score' => 0.0, 'covered' => false, 'reasons' => ['No reputation monitoring on file.']];
        }

        $reasons = [];

        if ($reputation['negative'] > 0) {
            $reasons[] = "{$reputation['negative']} negative finding(s).";
        }

        if ($reputation['unreviewed'] > 0) {
            $reasons[] = "{$reputation['unreviewed']} awaiting review.";
        }

        foreach (array_slice($reputation['drivers'], 0, 2) as $driver) {
            $reasons[] = "{$driver['category']} ({$driver['severity']}): ".\Illuminate\Support\Str::limit((string) $driver['title'], 120, '');
        }

        if ($reasons === []) {
            $reasons[] = "{$reputation['positive']} finding(s), none negative.";
        }

        return ['score' => $reputation['score'], 'covered' => true, 'reasons' => $reasons];
    }

    /** Delivery performance, from the appraisal and corrective-action data the module already holds. */
    private function operational(Supplier $supplier): array
    {
        $openScars = $supplier->scars()->whereIn('status', ['open', 'Open', 'in_progress'])->count();
        $reasons = [];
        $score = 0.0;

        if ($openScars > 0) {
            $score += min(60, $openScars * 20);
            $reasons[] = "{$openScars} open corrective action(s).";
        }

        $flags = $supplier->riskFlags()->open()->get();

        if ($flags->isNotEmpty()) {
            // Verification failures are already scored through their own components; counting them
            // again here would double-charge the same fact.
            $others = $flags->reject(fn ($flag) => str_starts_with((string) $flag->type, 'verification_'));

            if ($others->isNotEmpty()) {
                $score += min(40, $others->count() * 15);
                $reasons[] = $others->count().' open risk flag(s): '.$others->pluck('type')->implode(', ');
            }
        }

        return [
            'score' => min(100, $score),
            'covered' => $openScars > 0 || $flags->isNotEmpty(),
            'reasons' => $reasons,
        ];
    }

    /**
     * Cybersecurity: an external rating where one is configured, the questionnaire otherwise.
     *
     * Most customers will never buy a security-ratings feed, and the questionnaire answers the
     * questions that matter for a supplier who would touch your data — so the pack is a real source
     * here rather than a fallback of last resort.
     */
    private function cyber($verifications): array
    {
        $check = $verifications->get(CheckType::CYBER_POSTURE);

        if ($check) {
            return [
                'score' => $check->passed() ? 0.0 : 55.0,
                'covered' => true,
                'reasons' => $check->passed() ? [] : [$check->summary ?: 'Cybersecurity assessment did not pass.'],
            ];
        }

        return ['score' => 0.0, 'covered' => false, 'reasons' => ['No cybersecurity assessment on file.']];
    }

    /**
     * ESG, from the questionnaire pack.
     *
     * Nothing external answers "do you have controls against forced labour in your supply chain" —
     * it comes from the supplier in writing, and an unanswered pack is not a clean one.
     */
    private function esg(Supplier $supplier): array
    {
        $position = app(QuestionnaireService::class)->position($supplier);

        return [
            'score' => $position['covered'] || $position['reasons'] !== [] ? $position['score'] : 0.0,
            'covered' => $position['covered'],
            'reasons' => $position['reasons'],
        ];
    }

    // ── Scoring machinery ───────────────────────────────────────────────────

    /**
     * Score a component from a set of checks, weighted among themselves.
     *
     * A check that was never run and one that came back unavailable are the same thing here: no
     * answer. Both take the component's missing-data score rather than passing.
     */
    private function fromChecks($verifications, array $checks, string $component): array
    {
        $totalWeight = array_sum($checks);
        $score = 0.0;
        $reasons = [];
        $covered = false;

        foreach ($checks as $checkType => $weight) {
            $check = $verifications->get($checkType);
            $share = $weight / $totalWeight;

            if (! $check || $check->status === VerificationStatus::UNAVAILABLE || $check->status === VerificationStatus::ERROR) {
                $score += RiskModel::missingScore($component) * $share;
                $reasons[] = CheckType::label($checkType).' has not been verified.';

                continue;
            }

            $covered = true;

            $checkScore = match ($check->status) {
                VerificationStatus::VERIFIED => $check->isStale() ? 35.0 : 0.0,
                VerificationStatus::MISMATCH => 90.0,
                VerificationStatus::NOT_FOUND => 85.0,
                VerificationStatus::MANUAL_REVIEW => 45.0,
                default => RiskModel::missingScore($component),
            };

            if ($checkScore > 0) {
                $reasons[] = CheckType::label($checkType).': '.($check->isStale() && $check->passed()
                    ? 'verified, but the answer is out of date'
                    : $check->status);
            }

            $score += $checkScore * $share;
        }

        return ['score' => min(100, $score), 'covered' => $covered, 'reasons' => $reasons];
    }

    /**
     * The weighted average — over the components that actually have a say.
     *
     * A component whose policy is `neutral` and which has no data is excluded from both halves of the
     * average rather than scored zero. The difference is not academic: reputation, operational, cyber
     * and ESG are 30% of the model between them, and an entirely unchecked supplier that scores zero
     * on all four comes out at 39 — "low risk" — purely because nobody has looked. Excluding them
     * puts the same supplier at 56, which is what "we know almost nothing about this company" should
     * read as.
     *
     * Components whose policy is `risk` stay in even when uncovered. Not knowing who a company is IS
     * the risk, and letting that abstain would restore the same flattering arithmetic by another door.
     */
    private function weightedTotal(array $components, array $weights): float
    {
        $definitions = RiskModel::components();
        $weighted = 0.0;
        $totalWeight = 0.0;

        foreach ($components as $key => $component) {
            $abstains = ! $component['covered']
                && ($definitions[$key]['on_missing'] ?? 'neutral') === 'neutral';

            if ($abstains) {
                continue;
            }

            $weight = (float) ($weights[$key] ?? 0);
            $weighted += $component['score'] * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight > 0 ? round($weighted / $totalWeight, 2) : 0.0;
    }

    /**
     * The approval gates — a conjunction, not a threshold.
     *
     * Every one of these has to pass for a supplier to be approved without a person weighing in, and
     * a check that could not be run fails the gate rather than skipping it. That is the difference
     * between "we verified this supplier" and "nothing stopped us".
     *
     * @return array{auto_approvable:bool, blockers:array<int,string>, edd_triggers:array<int,string>}
     */
    public function gates(Supplier $supplier, array $components, float $total): array
    {
        $blockers = [];
        $eddTriggers = [];
        $verifications = SupplierVerification::currentFor($supplier->id);

        $threshold = (float) (SupplierSetting::query()->first()->auto_approve_below ?? RiskModel::AUTO_APPROVE_BELOW);

        if ($total >= $threshold) {
            $blockers[] = sprintf('Risk score is %.2f, at or above the %.0f auto-approval threshold.', $total, $threshold);
        }

        foreach (RiskModel::components() as $key => $definition) {
            if (! ($definition['blocks_approval'] ?? false)) {
                continue;
            }

            $component = $components[$key] ?? null;

            if ($component && ! $component['covered']) {
                $blockers[] = $definition['label'].' has not been assessed.';
            }
        }

        // Named gates, each one a specific thing somebody would ask about in an audit.
        $registry = $verifications->get(CheckType::COMPANY_REGISTRY);
        if (! $registry || ! $registry->passed()) {
            $blockers[] = 'The company has not been verified against a registry.';
        }

        $tax = $verifications->get(CheckType::TAX_COMPLIANCE);
        if (! $tax || ! $tax->passed()) {
            $blockers[] = 'Tax compliance has not been verified.';
        } elseif ($tax->isStale()) {
            $blockers[] = 'The tax compliance verification is out of date.';
        }

        $bank = $verifications->get(CheckType::BANK_ACCOUNT);
        if (! $bank || ! $bank->passed()) {
            $blockers[] = 'The bank account has not been verified against the supplier.';
        }

        if ($this->compliance->blockers($supplier)->isNotEmpty()) {
            $blockers[] = 'Compulsory documents are missing or expired.';
        }

        $unadjudicated = ScreeningHit::query()->where('supplier_id', $supplier->id)->unadjudicated()->count();
        if ($unadjudicated > 0) {
            $blockers[] = "{$unadjudicated} screening match(es) have not been adjudicated.";
        }

        // Adjudicated is not the same as cleared. A hit somebody looked at and CONFIRMED is the
        // strongest possible reason to withhold approval — checking only for unadjudicated hits would
        // mean the act of confirming a sanctions match quietly unblocked the supplier.
        if (ScreeningHit::query()->where('supplier_id', $supplier->id)->confirmed()->type(ScreeningHit::TYPE_SANCTIONS)->exists()) {
            $blockers[] = 'A sanctions match has been confirmed.';
        }

        // ── What forces enhanced due diligence regardless of the number ──────
        if ($total >= 61) {
            $eddTriggers[] = 'Risk score is in the high or critical band.';
        }

        if ($bank && in_array($bank->status, [VerificationStatus::MISMATCH, VerificationStatus::MANUAL_REVIEW], true)) {
            $eddTriggers[] = 'The bank account holder does not match the supplier.';
        }

        $ownership = $verifications->get(CheckType::OWNERSHIP_BENEFICIAL);
        if ($ownership && $ownership->status === VerificationStatus::MISMATCH) {
            $eddTriggers[] = 'Declared ownership does not agree with the registry.';
        }

        if (ScreeningHit::query()->where('supplier_id', $supplier->id)->live()->type(ScreeningHit::TYPE_SANCTIONS)->exists()) {
            $eddTriggers[] = 'There is a live sanctions match.';
        }

        if (ScreeningHit::query()->where('supplier_id', $supplier->id)->confirmed()->type(ScreeningHit::TYPE_PEP)->exists()) {
            $eddTriggers[] = 'A politically exposed person is involved.';
        }

        $credit = SupplierCreditReport::latestFor($supplier->id);
        if ($credit && ($credit->insolvency || $credit->business_rescue || $credit->judgments_count > 0)) {
            $eddTriggers[] = 'The credit report shows insolvency, business rescue or judgments.';
        }

        if (($supplier->risk_tier ?? 0) >= 4) {
            $eddTriggers[] = 'The engagement is tier 4, which requires enhanced due diligence.';
        }

        return [
            'auto_approvable' => $blockers === [],
            'blockers' => array_values(array_unique($blockers)),
            'edd_triggers' => array_values(array_unique($eddTriggers)),
        ];
    }

    /** @return array<string,float> */
    public function weights(): array
    {
        $configured = SupplierSetting::query()->first()->risk_weights ?? null;
        $defaults = collect(RiskModel::components())->map(fn ($c) => (float) $c['weight'])->all();

        if (! is_array($configured) || $configured === []) {
            return $defaults;
        }

        // Unknown keys are dropped and missing ones fall back, so a partial or stale configuration
        // cannot silently remove a component from the model.
        return collect($defaults)
            ->map(fn ($weight, $key) => isset($configured[$key]) ? max(0.0, (float) $configured[$key]) : $weight)
            ->all();
    }

    private function bands(): ?array
    {
        $configured = SupplierSetting::query()->first()->risk_bands ?? null;

        return is_array($configured) && $configured !== [] ? $configured : null;
    }
}
