<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\BankingGate;
use App\Modules\SupplierManagement\DueDiligence\Services\Blacklist;
use App\Modules\SupplierManagement\DueDiligence\Services\CaseWorkflow;
use App\Modules\SupplierManagement\DueDiligence\Services\HealthScore;
use App\Modules\SupplierManagement\DueDiligence\Services\OwnershipGraph;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\ReputationScore;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Services\SupplierComplianceService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Supplier Risk Passport: one screen that answers "should we be trading with this company?"
 *
 * Everything on it exists elsewhere in more detail. The point of gathering it here is that the
 * detail is where a decision goes to die — a procurement manager with four minutes does not open
 * five panels, so the answer has to be legible in one, with every tile carrying the two facts that
 * make it trustworthy: what the source said, and how long ago.
 *
 * The rule this screen is built around is that "not checked" must never look like "clear". Each tile
 * reports one of four states and says which — verified, a problem, awaiting review, or never
 * checked — and a supplier with nothing done shows twelve honest gaps rather than a clean sheet.
 */
class SupplierPassportController extends Controller
{
    public function __construct(
        private readonly OwnershipGraph $ownership,
        private readonly ReputationScore $reputation,
        private readonly BankingGate $banking,
        private readonly Blacklist $blacklist,
        private readonly CaseWorkflow $workflow,
        private readonly SupplierComplianceService $compliance,
        private readonly ProviderRegistry $registry,
    ) {}

    public function show(Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        $risk = SupplierRiskScore::latestFor($supplier->id);
        $case = DueDiligenceCase::currentFor($supplier->id);
        $reputation = $this->reputation->for($supplier);

        return Inertia::render('Modules/SupplierManagement/Passport', [
            'supplier' => [
                'id' => $supplier->id,
                // The page addresses this supplier — opening its record, opening a case, moving one
                // — and every one of those routes keys on the uuid. A hand-built payload has to say
                // so explicitly; the row id alone leaves three controls on this page throwing.
                'uuid' => $supplier->uuid,
                'name' => $supplier->company_name,
                'trading_name' => $supplier->trading_name,
                'registration_number' => $supplier->company_registration_number,
                'segment' => $supplier->segment,
                'is_active' => (int) $supplier->is_active,
            ],

            'risk' => $risk ? [
                'score' => (float) $risk->total,
                'band' => $risk->band,
                'band_label' => $risk->bandLabel(),
                'requires' => $risk->requires(),
                'movement' => $risk->movement(),
                'tier' => $risk->tier,
                'auto_approvable' => (bool) $risk->auto_approvable,
                'blockers' => $risk->blockers ?? [],
                'edd_triggers' => $risk->edd_triggers ?? [],
                'calculated_at' => $risk->calculated_at?->toDateTimeString(),
            ] : null,

            // `case` is a reserved word in JavaScript, so the prop cannot be called that.
            'ddCase' => $case ? [
                'id' => $case->id,
                'reference' => $case->reference,
                'state' => $case->state,
                'state_label' => $case->stateLabel(),
                'owner_slot' => $case->owner_slot,
                'due_at' => $case->due_at?->toDateString(),
                'overdue' => $case->isOverdue(),
                'decided_at' => $case->decided_at?->toDateTimeString(),
                'decision_reason' => $case->decision_reason,
                'conditions' => $case->conditions ?? [],
                'conditions_due' => $case->conditions_due?->toDateString(),
                'conditions_lapsed' => $case->conditionsLapsed(),
                'outstanding_edd' => $case->outstandingEdd(),
                'actions' => $this->workflow->availableTo($case),
                'history' => $case->transitions()->get()->map(fn ($t) => [
                    'at' => $t->occurred_at?->toDateTimeString(),
                    'summary' => $t->summary(),
                ]),
            ] : null,

            // The tiles. One per thing a buyer would ask about, each with its own answer and age.
            'tiles' => $this->tiles($supplier, $reputation),

            'reputation' => [
                'score' => $reputation['score'],
                'band' => $reputation['band'],
                'coverage' => $reputation['coverage'],
                'unreviewed' => $reputation['unreviewed'],
                'drivers' => $reputation['drivers'],
            ],

            'blacklist' => $this->blacklist->check($supplier),

            // Health sits beside risk rather than inside it: risk asks whether to engage at all,
            // health asks how the relationship is going, and they move independently.
            'health' => app(HealthScore::class)->for($supplier),

            'questionnaires' => \App\Modules\SupplierManagement\Models\DueDiligenceQuestionnaire::query()
                ->where('supplier_id', $supplier->id)
                ->get()
                ->map(fn ($q) => [
                    'id' => $q->id,
                    'pack' => $q->pack,
                    'title' => $q->title,
                    'status' => $q->status,
                    'completeness' => $q->completeness,
                    'risk_score' => $q->risk_score !== null ? (float) $q->risk_score : null,
                    'flags' => $q->flags ?? [],
                    'due_on' => $q->due_on?->toDateString(),
                    'overdue' => ! $q->isComplete() && $q->due_on !== null && $q->due_on->isPast(),
                ]),

            // Which packs this tier calls for but nobody has sent.
            'questionnairesExpected' => collect(\App\Modules\SupplierManagement\DueDiligence\Support\QuestionnaireTemplates::forTier($supplier->risk_tier))
                ->reject(fn ($pack) => \App\Modules\SupplierManagement\Models\DueDiligenceQuestionnaire::query()
                    ->where('supplier_id', $supplier->id)->where('pack', $pack)->exists())
                ->values(),

            'ownership' => [
                'ubos' => $this->ownership->forSupplier($supplier)['ubos'],
                'related' => $this->ownership->relatedSuppliers($supplier),
            ],
        ]);
    }

    /**
     * One tile per question a buyer actually asks.
     *
     * `state` is deliberately four-valued rather than a boolean. A boolean forces "not checked" into
     * either true or false, and both readings are wrong in a way that costs money.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tiles(Supplier $supplier, array $reputation): array
    {
        $checks = SupplierVerification::currentFor($supplier->id);
        $tiles = [];

        foreach ([
            CheckType::COMPANY_REGISTRY => 'Company',
            CheckType::COMPANY_DIRECTORS => 'Directors',
            CheckType::OWNERSHIP_BENEFICIAL => 'Beneficial ownership',
            CheckType::TAX_COMPLIANCE => 'Tax',
            CheckType::TAX_VAT_VENDOR => 'VAT',
            CheckType::BBBEE_CERTIFICATE => 'B-BBEE',
            CheckType::GOVERNMENT_CSD => 'Government (CSD)',
            CheckType::CYBER_POSTURE => 'Cybersecurity',
        ] as $check => $label) {
            $observation = $checks->get($check);

            $tiles[] = [
                'key' => $check,
                // The English label stays as a fallback for a check the screen has no wording for
                // yet; the key is what it translates by. A passport read in Zulu should not have
                // eight English words down the middle of it.
                'label' => $label,
                'label_key' => str_replace('.', '_', $check),
                'state' => $this->stateOf($observation),
                'detail' => $observation?->summary,
                'as_at' => $observation?->checked_at?->toDateString(),
                'age_days' => $observation?->checked_at ? (int) $observation->checked_at->diffInDays(now()) : null,
                'stale' => (bool) $observation?->isStale(),
                'source' => $observation?->provider_code,
                // Whether anybody COULD answer it automatically, so a gap reads as "buy a source" or
                // "somebody must look" rather than as a mystery.
                'has_source' => $this->registry->hasDataSourceFor($check),
            ];
        }

        // Banking gets its own treatment: the question is not "was it checked" but "may money move".
        $banking = $this->banking->verdict($supplier);
        $tiles[] = [
            'key' => 'banking',
            'label' => 'Banking',
            'label_key' => 'banking',
            'state' => match ($banking['state']) {
                BankingGate::OK => 'clear',
                BankingGate::BLOCKED => 'problem',
                default => 'unchecked',
            },
            'detail' => $banking['reason'] ?? 'Account holder matches the supplier.',
            'as_at' => $banking['checked_at'] ? substr((string) $banking['checked_at'], 0, 10) : null,
            'age_days' => null,
            'stale' => false,
            'source' => null,
            'has_source' => $this->registry->hasDataSourceFor(CheckType::BANK_ACCOUNT),
        ];

        // Screening, from the adjudicated position rather than the raw match count.
        $live = ScreeningHit::query()->where('supplier_id', $supplier->id)->live()->get();
        $sanctions = $live->where('list_type', ScreeningHit::TYPE_SANCTIONS);
        $peps = $live->where('list_type', ScreeningHit::TYPE_PEP);

        $tiles[] = [
            'key' => 'sanctions',
            'label' => 'Sanctions',
            'label_key' => 'sanctions',
            'state' => match (true) {
                $sanctions->where('status', ScreeningHit::STATUS_CONFIRMED)->isNotEmpty() => 'problem',
                $sanctions->where('status', ScreeningHit::STATUS_OPEN)->isNotEmpty() => 'review',
                $checks->get(CheckType::SCREENING_SANCTIONS)?->passed() => 'clear',
                default => 'unchecked',
            },
            'detail' => $sanctions->isEmpty() ? null : $sanctions->count().' match(es) on file',
            'as_at' => $checks->get(CheckType::SCREENING_SANCTIONS)?->checked_at?->toDateString(),
            'age_days' => null, 'stale' => false, 'source' => null,
            'has_source' => $this->registry->hasDataSourceFor(CheckType::SCREENING_SANCTIONS),
        ];

        $tiles[] = [
            'key' => 'pep',
            'label' => 'PEP',
            'label_key' => 'pep',
            // A confirmed PEP is not a problem to be fixed; it is a reason for more diligence, and
            // colouring it red would teach people to dismiss PEP hits on sight.
            'state' => match (true) {
                $peps->where('status', ScreeningHit::STATUS_OPEN)->isNotEmpty() => 'review',
                $peps->where('status', ScreeningHit::STATUS_CONFIRMED)->isNotEmpty() => 'review',
                $checks->get(CheckType::SCREENING_PEP)?->passed() => 'clear',
                default => 'unchecked',
            },
            'detail' => $peps->isEmpty() ? null : $peps->count().' politically exposed person(s) identified',
            'as_at' => $checks->get(CheckType::SCREENING_PEP)?->checked_at?->toDateString(),
            'age_days' => null, 'stale' => false, 'source' => null,
            'has_source' => $this->registry->hasDataSourceFor(CheckType::SCREENING_PEP),
        ];

        $credit = SupplierCreditReport::latestFor($supplier->id);
        $tiles[] = [
            'key' => 'credit',
            'label' => 'Credit',
            'label_key' => 'credit',
            'state' => match (true) {
                ! $credit => 'unchecked',
                $credit->insolvency || $credit->business_rescue => 'problem',
                ($credit->riskScore() ?? 0) >= 60 || $credit->judgments_count > 0 => 'review',
                default => 'clear',
            },
            'detail' => $credit ? trim(($credit->score !== null ? "Score {$credit->score}/{$credit->score_max}" : '')
                .($credit->judgments_count ? ", {$credit->judgments_count} judgment(s)" : '')) : null,
            'as_at' => $credit?->report_date?->toDateString(),
            'age_days' => $credit?->report_date ? (int) $credit->report_date->diffInDays(now()) : null,
            'stale' => $credit?->report_date?->lt(now()->subYear()) ?? false,
            'source' => $credit?->provider_code,
            'has_source' => $this->registry->hasDataSourceFor(CheckType::CREDIT_REPORT),
        ];

        $tiles[] = [
            'key' => 'reputation',
            'label' => 'Reputation',
            'label_key' => 'reputation',
            'state' => match (true) {
                ! $reputation['coverage'] => 'unchecked',
                $reputation['unreviewed'] > 0 => 'review',
                $reputation['score'] >= 61 => 'problem',
                default => 'clear',
            },
            'detail' => $reputation['coverage']
                ? sprintf('%d finding(s), score %.0f', $reputation['findings'], $reputation['score'])
                : null,
            'as_at' => null, 'age_days' => null, 'stale' => false, 'source' => null,
            'has_source' => $this->registry->hasDataSourceFor(CheckType::MEDIA_ADVERSE),
        ];

        $blockers = $this->compliance->blockers($supplier);
        $tiles[] = [
            'key' => 'documents',
            'label' => 'Documents',
            'label_key' => 'documents',
            'state' => $blockers->isEmpty() ? 'clear' : 'problem',
            'detail' => $blockers->isEmpty() ? 'All compulsory documents on file and in date.' : $blockers->implode(', '),
            'as_at' => null, 'age_days' => null, 'stale' => false, 'source' => null,
            'has_source' => true,
        ];

        return $tiles;
    }

    /** Four states, because a boolean cannot say "nobody has looked". */
    private function stateOf(?SupplierVerification $observation): string
    {
        if (! $observation) {
            return 'unchecked';
        }

        return match ($observation->status) {
            VerificationStatus::VERIFIED => $observation->isStale() ? 'review' : 'clear',
            VerificationStatus::MISMATCH, VerificationStatus::NOT_FOUND => 'problem',
            VerificationStatus::MANUAL_REVIEW => 'review',
            default => 'unchecked',
        };
    }
}
