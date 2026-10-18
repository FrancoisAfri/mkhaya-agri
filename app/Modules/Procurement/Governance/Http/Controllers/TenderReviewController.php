<?php

namespace App\Modules\Procurement\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Procurement\Governance\Services\BidIntakeService;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Services\ConflictOfInterestService;
use App\Modules\Procurement\Governance\Services\CriteriaService;
use App\Modules\Procurement\Governance\Services\ModerationService;
use App\Modules\Procurement\Governance\Services\PreferenceCalculator;
use App\Modules\Procurement\Governance\Services\RecommendationService;
use App\Modules\Procurement\Governance\Services\ScoringEngine;
use App\Modules\Procurement\Governance\Services\SettingsService;
use App\Modules\Procurement\Governance\Services\TenderReviewService;
use App\Modules\Procurement\Governance\Support\Integration\BudgetBridge;
use App\Modules\Procurement\Governance\Support\Integration\CurrencyBridge;
use App\Modules\Procurement\Governance\Support\Integration\SupplierBridge;
use App\Modules\Procurement\Governance\Support\Presenters\OptionLists;
use App\Modules\Procurement\Governance\Support\ResolvesCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Tender review lifecycle (§1.1, §1.4, §1.7, §1.8) — the procurement officer's and chairperson's
 * view. The individual evaluator's private workspace is a separate controller, because the two
 * audiences must never see the same payload: this one carries the comparative picture.
 */
class TenderReviewController extends Controller
{
    use ResolvesCompany;

    public function __construct(
        private readonly TenderReviewService $reviews,
        private readonly CriteriaService $criteria,
        private readonly ScoringEngine $scoring,
        private readonly ModerationService $moderation,
        private readonly RecommendationService $recommendations,
        private readonly ConflictOfInterestService $coi,
        private readonly SettingsService $settings,
        private readonly OptionLists $options,
        private readonly SupplierBridge $suppliers,
        private readonly BudgetBridge $budgets,
        private readonly BidIntakeService $intake,
        private readonly CurrencyBridge $currency,
        private readonly PreferenceCalculator $preference,
        private readonly Capabilities $capabilities,
    ) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        $reviews = $companyId === null ? collect() : DB::table('procgov_tender_reviews as r')
            ->leftJoin('company_units as u', 'u.id', '=', 'r.company_unit_id')
            ->leftJoin('procgov_committees as c', 'c.id', '=', 'r.committee_id')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('r.status', $s))
            ->when($request->integer('officer_id'), fn ($q, $o) => $q->where('r.officer_id', $o))
            ->orderByDesc('r.created_at')
            ->limit(300)
            ->select([
                'r.id', 'r.uuid', 'r.reference', 'r.tender_number', 'r.title', 'r.status',
                'r.methodology', 'r.estimated_value', 'r.currency', 'r.evaluation_deadline',
                'r.tender_closing_at', 'r.awarded_at', 'r.criteria_frozen_at',
                'u.name as department', 'c.name as committee',
            ])
            ->selectSub(
                DB::table('procgov_bidders as b')
                    ->selectRaw('count(*)')
                    ->whereColumn('b.review_id', 'r.id')
                    ->whereNull('b.deleted_at'),
                'bidder_count'
            )
            ->get();

        return Inertia::render('Modules/ProcurementGovernance/Tenders/Index', [
            'reviews' => $reviews,
            'filters' => $request->only(['status', 'officer_id']),
            'statuses' => array_keys(TenderReviewService::TRANSITIONS),
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'noCompany' => $companyId === null,
            'can' => [
                'create' => (bool) $request->user()?->can('procgov.tender.create'),
                'moderate' => (bool) $request->user()?->can('procgov.tender.moderate'),
            ],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Modules/ProcurementGovernance/Tenders/Create', [
            'departments' => $companyId ? $this->options->departments($companyId) : [],
            'projects' => $companyId ? $this->options->projects($companyId) : [],
            'officers' => $companyId ? $this->options->officers($companyId) : [],
            'committees' => $companyId ? $this->options->committees($companyId) : [],
            'categories' => $companyId ? $this->options->procurementCategories($companyId) : [],
            'budgets' => $this->budgets->selectable(),
            'budgetModule' => $this->budgets->isAvailable(),
            'methodologies' => TenderReviewService::METHODOLOGIES,
            'planItems' => $companyId ? $this->availablePlanItems($companyId) : [],
            'jurisdictions' => OptionLists::JURISDICTIONS,
            'preferenceFrameworks' => $companyId ? $this->preferenceFrameworks($companyId) : [],
            'defaults' => [
                'currency' => $companyId ? $this->settings->get($companyId)->currency : 'ZAR',
                'jurisdiction' => $companyId ? ($this->settings->get($companyId)->jurisdiction ?? 'ZA') : 'ZA',
            ],
            'noCompany' => $companyId === null,
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    /**
     * Frameworks an officer may pick for a tender.
     *
     * Unverified ones are still offered — a tenant who has not yet had their preference table
     * confirmed must still be able to run a tender — but the flag travels with them so the form can
     * say so rather than presenting a seeded starting point as settled policy.
     *
     * @return list<array<string, mixed>>
     */
    private function preferenceFrameworks(int $companyId): array
    {
        if (! Schema::hasTable('procgov_preference_frameworks')) {
            return [];
        }

        return DB::table('procgov_preference_frameworks')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('jurisdiction')
            ->get(['code', 'name', 'jurisdiction', 'basis', 'price_weight', 'preference_weight', 'is_verified'])
            ->map(fn ($f) => (array) $f)
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $companyId = $this->requireCompany($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'tender_number' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:120'],
            'company_unit_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'officer_id' => ['nullable', 'integer', 'exists:users,id'],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'budget_id' => ['nullable', 'integer'],
            'tender_closing_at' => ['nullable', 'date'],
            'evaluation_start_date' => ['nullable', 'date'],
            'evaluation_deadline' => ['nullable', 'date', 'after_or_equal:evaluation_start_date'],
            'methodology' => ['required', Rule::in(TenderReviewService::METHODOLOGIES)],
            'price_weight' => ['nullable', 'integer', 'min:0', 'max:100'],
            'preference_weight' => ['nullable', 'integer', 'min:0', 'max:100'],
            'committee_id' => ['nullable', 'integer'],
            'required_approval_level' => ['nullable', 'string', 'max:60'],
            'plan_item_id' => ['nullable', 'integer'],
            'record_id' => ['nullable', 'integer'],

            // §1.6. Null jurisdiction inherits the tenant default, so nothing about existing
            // single-country behaviour changes.
            'jurisdiction' => ['nullable', 'string', 'size:2'],
            'preference_framework_code' => ['nullable', 'string', 'max:40'],
        ]);

        // The price/preference split must total the whole score, or the arithmetic silently loses
        // or invents points.
        if ($data['methodology'] === 'PRICE_PREFERENCE') {
            $total = (int) ($data['price_weight'] ?? 0) + (int) ($data['preference_weight'] ?? 0);

            if ($total !== 100) {
                return back()
                    ->withInput()
                    ->with('error', __('procurement_governance.flash.tenders.weights_total', ['total' => $total]));
            }
        }

        $review = $this->reviews->initiate($companyId, $data);

        return redirect()
            ->route('procgov.tenders.show', $review->uuid)
            ->with('success', __('procurement_governance.flash.tenders.initiated', ['reference' => $review->reference]));
    }

    public function show(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);
        $review = $this->findReview($companyId, $uuid);

        $criteria = $companyId ? $this->scoring->criteriaTree($companyId, (int) $review->id) : ['all' => collect()];

        $moderation = DB::table('procgov_moderations')
            ->where('review_id', $review->id)
            ->orderByDesc('id')
            ->first();

        $bidders = DB::table('procgov_bidders')
            ->where('review_id', $review->id)
            ->whereNull('deleted_at')
            ->orderByRaw('overall_rank nulls last')
            ->orderBy('supplier_name')
            ->get();

        return Inertia::render('Modules/ProcurementGovernance/Tenders/Show', [
            'review' => $review,
            'bidders' => $bidders,

            /*
             * §1.6. Currencies among these bids that cannot be converted into the tender's currency.
             *
             * Surfaced here, on the page, rather than left to the moment someone presses "recompute
             * scores" and the ranking refuses. A missing exchange rate is a five-minute fix in
             * Finance if you know about it on the day bids open, and a crisis if you discover it on
             * the afternoon the committee sits.
             */
            /*
             * §1.1 electronic intake. Invitations carry no token: it is returned once when the
             * invitation is created and never again, because a credential that stays on screen ends
             * up in a screenshot in a group chat.
             */
            'invitations' => $companyId ? DB::table('procgov_bid_invitations as i')
                ->leftJoin('procgov_bid_submissions as s', 's.invitation_id', '=', 'i.id')
                ->where('i.company_id', $companyId)
                ->where('i.review_id', $review->id)
                ->whereNull('i.deleted_at')
                ->orderBy('i.supplier_name')
                ->get([
                    'i.id', 'i.supplier_name', 'i.contact_person', 'i.contact_email',
                    'i.status', 'i.invited_at', 'i.last_seen_at',
                    's.submitted_at', 's.is_late', 's.opened_at',
                ]) : [],

            'seal' => $companyId ? (function () use ($companyId, $review) {
                $seal = $this->intake->sealRow($companyId, (int) $review->id);

                return [
                    'opened_at' => $seal->opened_at ?? null,
                    'submissions_opened' => (int) ($seal->submissions_opened ?? 0),
                    // Stated as a sentence rather than a disabled button with no explanation, which
                    // is how a committee ends up ringing IT while sitting in a room together.
                    'blocked_because' => $this->intake->openingPreconditionsBlockedBecause($companyId, $review),
                ];
            })() : null,

            // For the witness picker on the opening ceremony.
            'officers' => $companyId ? $this->options->officers($companyId) : [],

            'unconvertibleCurrencies' => $companyId
                ? $this->currency->unconvertible(
                    $companyId,
                    $bidders,
                    (string) ($review->currency ?: 'ZAR'),
                    $this->currency->rateDate($review, (string) ($this->settings->get($companyId)->fx_rate_basis ?? 'CLOSING')),
                    (string) ($this->settings->get($companyId)->fx_rate_type ?? 'spot'),
                )
                : [],
            'criteria' => $criteria['all']->map(function ($c) {
                unset($c->children);
                $c->scoring_bands = $c->scoring_bands ? json_decode((string) $c->scoring_bands, true) : null;

                return $c;
            })->values(),
            'weightProblems' => $companyId ? $this->scoring->validateWeights($companyId, (int) $review->id) : [],
            'evaluators' => $companyId ? $this->reviews->progress($companyId, (int) $review->id) : [],
            'declarations' => $companyId ? $this->coi->declarations($companyId, (int) $review->id) : [],
            'outstandingCoi' => $companyId ? $this->coi->outstanding($companyId, (int) $review->id) : [],
            'moderation' => $moderation,
            'moderationItems' => $moderation && $companyId
                ? $this->moderation->items($companyId, (int) $moderation->id)
                : [],
            'recommendations' => DB::table('procgov_recommendations')
                ->where('review_id', $review->id)
                ->whereNull('deleted_at')
                ->orderByDesc('version')
                ->get(['id', 'uuid', 'reference', 'version', 'recommended_supplier_name',
                    'recommended_amount', 'status', 'generated_at', 'decided_at', 'decision_comment']),
            'criteriaChanges' => DB::table('procgov_criteria_changes')
                ->where('review_id', $review->id)
                ->orderByDesc('requested_at')
                ->get(),
            'nextStatuses' => TenderReviewService::TRANSITIONS[$review->status] ?? [],
            'groups' => CriteriaService::GROUPS,
            'scoringMethods' => CriteriaService::SCORING_METHODS,
            'suppliers' => $companyId ? $this->suppliers->search($companyId, null, 200) : [],
            'supplierModule' => $this->suppliers->isAvailable(),
            'committees' => $companyId ? $this->options->committees($companyId) : [],
            'can' => [
                'edit' => (bool) $request->user()?->can('procgov.tender.edit'),
                'moderate' => (bool) $request->user()?->can('procgov.tender.moderate'),
                'recommend' => (bool) $request->user()?->can('procgov.tender.recommend'),
                'approve' => (bool) $request->user()?->can('procgov.tender.approve'),
            ],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    // ── §1.4 criteria ────────────────────────────────────────────────────────────────────────────

    public function storeCriterion(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'criterion_group' => ['required', Rule::in(CriteriaService::GROUPS)],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['required', 'numeric', 'min:1', 'max:100000'],
            'scoring_method' => ['required', Rule::in(CriteriaService::SCORING_METHODS)],
            'scoring_bands' => ['nullable', 'array'],
            'formula' => ['nullable', 'string', 'max:500'],
            'is_mandatory' => ['boolean'],
            'minimum_threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        try {
            $this->criteria->add($companyId, (int) $review->id, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.criterion_added'));
    }

    /**
     * Take the criteria from an earlier tender rather than retyping them.
     *
     * Most organisations evaluate the same categories the same way, and a twenty-line matrix typed
     * out again is where a weight lands in the wrong row. The service was written for this and had
     * nothing calling it.
     *
     * The source is only required to belong to this company — deliberately not to be finished or
     * recent, because the set worth copying is usually the one from the tender that went well.
     */
    public function copyCriteria(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate(['source_uuid' => ['required', 'string', 'max:64']]);

        $source = $this->findReview($companyId, $data['source_uuid']);

        if ((int) $source->id === (int) $review->id) {
            return back()->with('error', __('procurement_governance.flash.tenders.criteria_copy_same'));
        }

        try {
            $copied = $this->criteria->copyFrom($companyId, (int) $review->id, (int) $source->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $copied === 0
            ? back()->with('error', __('procurement_governance.flash.tenders.criteria_copy_empty'))
            : back()->with('success', __('procurement_governance.flash.tenders.criteria_copied', ['count' => $copied]));
    }

    public function updateCriterion(Request $request, string $uuid, int $criterionId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'criterion_group' => ['sometimes', Rule::in(CriteriaService::GROUPS)],
            'weight' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'max_score' => ['sometimes', 'numeric', 'min:1', 'max:100000'],
            'scoring_method' => ['sometimes', Rule::in(CriteriaService::SCORING_METHODS)],
            'minimum_threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_mandatory' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        try {
            $this->criteria->update($companyId, $criterionId, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.criterion_updated'));
    }

    public function destroyCriterion(Request $request, string $uuid, int $criterionId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        try {
            $this->criteria->remove($companyId, $criterionId);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.criterion_removed'));
    }

    public function requestCriteriaChange(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'criterion_id' => ['nullable', 'integer'],
            'change_type' => ['required', Rule::in(['ADDED', 'AMENDED', 'REMOVED'])],
            'changes' => ['required', 'array'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $this->criteria->requestChange(
                $companyId,
                (int) $review->id,
                $data['criterion_id'] ?? null,
                $data['change_type'],
                $data['changes'],
                $data['reason'],
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.criteria_change_requested'));
    }

    // ── Bidders ──────────────────────────────────────────────────────────────────────────────────

    // ── §1.1 electronic bid intake ───────────────────────────────────────────────────────────────

    /**
     * Invite a bidder to submit electronically.
     *
     * The response carries the token back once, so the officer can send it. It is not shown again
     * on any later page load: a credential that stays on screen is a credential that ends up in a
     * screenshot in a group chat.
     */
    public function inviteBidder(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['required', 'string', 'max:190'],
            'contact_person' => ['nullable', 'string', 'max:190'],
            'contact_email' => ['nullable', 'email', 'max:190'],
        ]);

        $invitation = $this->intake->invite($companyId, (int) $review->id, $data, $request->user()?->id);

        return back()->with('success', __('procurement_governance.flash.tenders.invitation_created'))
            ->with('bid_portal_link', url("/bid/{$invitation->token}"));
    }

    public function withdrawBid(Request $request, string $uuid, int $invitationId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        $this->intake->withdraw($companyId, $invitationId, $request->user()?->id);

        return back()->with('success', __('procurement_governance.flash.tenders.bid_withdrawn'));
    }

    /**
     * The opening ceremony: every sealed bid for this tender, opened once, in front of a witness.
     *
     * The witness is named on the request rather than inferred from whoever happens to be logged in,
     * because the point of a witness is that a second person was present and is willing to be
     * recorded as such.
     */
    /**
     * Read a bid document, after the tender has been opened.
     *
     * The sealed-bid design works like this: a bidder uploads, the bytes are sealed, and the opening
     * ceremony is the single irreversible moment that makes them readable. Everything up to that
     * point existed. What did not was any way for the committee to then read what it had opened —
     * the bidder's own portal lists its document names back to it and nothing else in the module
     * touched them, so a tender could be opened and still not evaluated on anything but the headline
     * figures somebody typed in.
     *
     * The refusal before opening lives in the service, where it belongs: a download that quietly
     * returns ciphertext teaches everyone to ignore it, and one that returns plaintext early is the
     * failure the whole design exists to prevent.
     */
    public function downloadBidDocument(Request $request, string $uuid, int $documentId): \Illuminate\Http\Response
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        /*
         * The document id comes off a link, so it is checked against this tender rather than trusted.
         * Without this, anybody who may view one tender may download the sealed bids of every other
         * tender in the company by changing a number.
         */
        $belongs = DB::table('procgov_bid_submission_documents as d')
            ->join('procgov_bid_submissions as s', 's.id', '=', 'd.submission_id')
            ->where('d.id', $documentId)
            ->where('d.company_id', $companyId)
            ->where('s.review_id', $review->id)
            ->exists();

        abort_unless($belongs, 404);

        try {
            $file = $this->intake->openDocument($companyId, $documentId);
        } catch (\Throwable $e) {
            abort(403, $e->getMessage());
        }

        return response($file['bytes'], 200, [
            'Content-Type' => $file['mime'],
            // Attachment, not inline: a bid document is evidence, and rendering somebody else's
            // upload in the browser on our origin is how an HTML "bid" reads the viewer's session.
            'Content-Disposition' => 'attachment; filename="'.addslashes($file['name']).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function openBids(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'witnessed_by' => ['required', 'integer', 'exists:users,id'],
        ]);

        $count = $this->intake->open(
            $companyId,
            (int) $review->id,
            (int) $request->user()->id,
            (int) $data['witnessed_by'],
        );

        return back()->with('success', __('procurement_governance.flash.tenders.bids_opened', ['count' => $count]));
    }

    public function storeBidder(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'supplier_name' => ['required', 'string', 'max:190'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'bid_reference' => ['nullable', 'string', 'max:60'],
            'bid_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'submitted_at' => ['nullable', 'date'],
            'bbbee_level' => ['nullable', 'integer', 'min:1', 'max:8'],
            'preference_points' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        try {
            $bidder = $this->reviews->addBidder($companyId, (int) $review->id, $data);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.bid_recorded', ['supplier' => $bidder->supplier_name]));
    }

    public function disqualifyBidder(Request $request, string $uuid, int $bidderId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'stage' => ['required', Rule::in(['ADMINISTRATIVE', 'TECHNICAL', 'FINANCIAL'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $this->reviews->disqualify($companyId, $bidderId, $data['stage'], $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.bidder_disqualified'));
    }

    public function reinstateBidder(Request $request, string $uuid, int $bidderId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);

        $this->reviews->reinstate($companyId, $bidderId, $data['reason']);

        return back()->with('success', __('procurement_governance.flash.tenders.bidder_reinstated'));
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────────────────────────

    public function assignCommittee(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate(['committee_id' => ['required', 'integer']]);

        $added = $this->reviews->syncEvaluatorsFromCommittee($companyId, (int) $review->id, (int) $data['committee_id']);

        return back()->with('success', __('procurement_governance.flash.tenders.committee_assigned', ['count' => $added]));
    }

    public function openEvaluation(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $problems = $this->reviews->openEvaluation($companyId, (int) $review->id);

        if ($problems !== []) {
            return back()->with('error', __('procurement_governance.flash.tenders.evaluation_blocked', ['problems' => implode(' ', $problems)]));
        }

        return back()->with('success', __('procurement_governance.flash.tenders.evaluation_open'));
    }

    public function transition(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(TenderReviewService::TRANSITIONS))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->reviews->transition($companyId, (int) $review->id, $data['status'], $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($data['status'] === 'MODERATION') {
            $this->moderation->open($companyId, (int) $review->id);
        }

        return back()->with('success', __('procurement_governance.flash.tenders.moved', ['status' => $data['status']]));
    }

    public function recompute(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $this->scoring->computeReview($companyId, (int) $review->id);

        return back()->with('success', __('procurement_governance.flash.tenders.scores_recomputed'));
    }

    public function complianceCheck(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $check = $this->reviews->complianceCheck($companyId, $review, 'manual');

        return back()->with(
            $check->result === 'FAIL' ? 'error' : 'success',
            "Compliance check: {$check->result} ({$check->failures} failure(s), {$check->warnings} warning(s))."
        );
    }

    // ── §1.7 moderation ──────────────────────────────────────────────────────────────────────────

    public function moderation(Request $request, string $uuid): Response
    {
        $companyId = $this->companyId($request);
        $review = $this->findReview($companyId, $uuid);

        $moderation = $companyId
            ? $this->moderation->open($companyId, (int) $review->id)
            : null;

        return Inertia::render('Modules/ProcurementGovernance/Tenders/Moderation', [
            'review' => $review,
            'moderation' => $moderation,
            'items' => $moderation ? $this->moderation->items($companyId, (int) $moderation->id) : [],
            'comparison' => $companyId ? $this->moderation->comparison($companyId, (int) $review->id) : null,
            'bidders' => DB::table('procgov_bidders')
                ->where('review_id', $review->id)
                ->whereNull('deleted_at')
                ->orderByRaw('overall_rank nulls last')
                ->get(),
            'varianceThreshold' => $companyId
                ? (float) $this->settings->get($companyId)->moderation_variance_threshold
                : null,
            'actions' => ModerationService::ACTIONS,
            'can' => ['moderate' => (bool) $request->user()?->can('procgov.tender.moderate')],
            'context' => $this->capabilities->forResponse(),
        ]);
    }

    public function moderateItem(Request $request, string $uuid, int $itemId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'action' => ['required', Rule::in(ModerationService::ACTIONS)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->moderation->act($companyId, $itemId, $data['action'], $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.moderation_recorded'));
    }

    public function approveScores(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $moderation = DB::table('procgov_moderations')
            ->where('review_id', $review->id)
            ->orderByDesc('id')
            ->first();

        if (! $moderation) {
            return back()->with('error', __('procurement_governance.flash.tenders.moderation_not_open'));
        }

        $problems = $this->moderation->approveScores($companyId, (int) $moderation->id);

        if ($problems !== []) {
            return back()->with('error', implode(' ', $problems));
        }

        return back()->with('success', __('procurement_governance.flash.tenders.scores_approved'));
    }

    // ── §1.8 recommendation ──────────────────────────────────────────────────────────────────────

    public function generateRecommendation(Request $request, string $uuid): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $review = $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'recommended_bidder_id' => ['nullable', 'integer'],
            'recommendation' => ['nullable', 'string', 'max:5000'],
            'committee_comments' => ['nullable', 'string', 'max:5000'],
            'award_conditions' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $recommendation = $this->recommendations->generate(
                $companyId,
                (int) $review->id,
                $data['recommended_bidder_id'] ?? null,
                $data,
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.recommendation_generated', ['reference' => $recommendation->reference, 'version' => $recommendation->version]));
    }

    public function submitRecommendation(Request $request, string $uuid, int $recommendationId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        try {
            $this->recommendations->submitForApproval($companyId, $recommendationId);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.recommendation_submitted'));
    }

    public function decideRecommendation(Request $request, string $uuid, int $recommendationId): RedirectResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->recommendations->decide($companyId, $recommendationId, $data['decision'], $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('procurement_governance.flash.tenders.recommendation_decided', ['decision' => $data['decision']]));
    }

    public function recommendationPdf(Request $request, string $uuid, int $recommendationId): HttpResponse
    {
        $companyId = $this->requireCompany($request);
        $this->findReview($companyId, $uuid);

        $result = $this->recommendations->pdf($companyId, $recommendationId);

        return $result['pdf']->download($result['filename']);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function findReview(?int $companyId, string $uuid): object
    {
        $review = DB::table('procgov_tender_reviews')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereNull('deleted_at')
            ->where('uuid', $uuid)
            ->first();

        abort_if(! $review, 404);

        return $review;
    }

    /** Approved plan lines not yet tendered — the §11 chain, offered rather than retyped. */
    private function availablePlanItems(int $companyId): array
    {
        return DB::table('procgov_plan_items as i')
            ->join('procgov_plans as p', 'p.id', '=', 'i.plan_id')
            ->where('i.company_id', $companyId)
            ->whereNull('i.deleted_at')
            ->whereIn('i.status', ['APPROVED', 'IN_PROGRESS'])
            ->whereIn('p.status', ['APPROVED', 'IN_PROGRESS'])
            ->orderBy('i.planned_tender_date')
            ->limit(200)
            ->get(['i.id', 'i.reference', 'i.description', 'i.estimated_value', 'i.category',
                'i.company_unit_id', 'i.project_id', 'i.responsible_officer_id', 'i.budget_id'])
            ->map(fn ($i) => (array) $i)
            ->all();
    }
}
