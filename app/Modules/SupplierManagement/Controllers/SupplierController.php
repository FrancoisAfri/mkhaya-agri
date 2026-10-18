<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\DueDiligence\Services\BankingGate;
use App\Modules\SupplierManagement\DueDiligence\Services\OwnershipGraph;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\ReputationScore;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskTiering;
use App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon;
use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierCategory;
use App\Modules\SupplierManagement\Models\SupplierContact;
use App\Modules\SupplierManagement\Models\SupplierDirector;
use App\Modules\SupplierManagement\Models\SupplierDocument;
use App\Modules\SupplierManagement\Models\SupplierDocumentType;
use App\Modules\SupplierManagement\Models\SupplierIndustryType;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use App\Modules\SupplierManagement\Models\SupplierFinding;
use App\Modules\SupplierManagement\Models\SupplierHandle;
use App\Modules\SupplierManagement\Models\SupplierMonitoringRun;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Services\SupplierRiskService;
use App\Modules\SupplierManagement\Services\SupplierService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    use FileStorage;

    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $request->only('status', 'company_name', 'registration', 'risk_band');

        return Inertia::render('Modules/SupplierManagement/Index', [
            'suppliers' => $this->suppliers->getSuppliers($filters, 20),
            'filters'   => $filters,
            /*
             * The directory was a list and nothing else: a page you could read for a minute without
             * learning whether anything on it needed doing. These three answer the questions somebody
             * actually opens it with — how many suppliers are waiting on us, how much of the book is
             * risky, and whether the base is growing.
             */
            'stats'     => $this->directoryStats(),
            'riskMix'   => $this->riskMix(),
            'growth'    => $this->growth(),
        ]);
    }

    /** @return array<string, int|null> */
    private function directoryStats(): array
    {
        $byStatus = Supplier::query()
            ->selectRaw('is_active, count(*) as n')
            ->groupBy('is_active')
            ->pluck('n', 'is_active');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus[1] ?? 0),
            // Pending and Pending-KYC are one queue to whoever has to clear them, however the
            // database distinguishes them.
            'pending' => (int) ($byStatus[0] ?? 0) + (int) ($byStatus[3] ?? 0),
            'refused' => (int) ($byStatus[2] ?? 0),
            'elevated_risk' => Supplier::query()->whereIn('risk_band', ['high', 'critical'])->count(),
            /*
             * Never checked, among those we are actually buying from. A pending supplier nobody has
             * verified is expected; an active one is the number worth seeing, because it is money
             * already going out against evidence nobody collected.
             */
            'unverified_active' => Supplier::query()->where('is_active', 1)->whereNull('last_monitored_at')->count(),
        ];
    }

    /**
     * How the book is spread across risk bands.
     *
     * Unrated is carried as its own band rather than folded into low. "We have not assessed them"
     * and "we assessed them and they are fine" are the same empty space on most charts and opposite
     * facts, and the first is the one that should make somebody act.
     *
     * @return list<array{band: string, count: int}>
     */
    private function riskMix(): array
    {
        $counts = Supplier::query()
            ->selectRaw("coalesce(risk_band, 'unrated') as band, count(*) as n")
            ->groupBy('band')
            ->pluck('n', 'band');

        return collect(['critical', 'high', 'moderate', 'low', 'unrated'])
            ->map(fn (string $band) => ['band' => $band, 'count' => (int) ($counts[$band] ?? 0)])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * Suppliers added per month, for the last twelve.
     *
     * Months with none are filled in as zero here, unlike the appraisal trend — a month in which
     * nobody was onboarded is a real zero, not a gap in the record, and leaving it out would
     * compress the axis and flatter a quiet year.
     *
     * @return list<array{month: string, count: int}>
     */
    private function growth(): array
    {
        $counts = Supplier::query()
            ->selectRaw("to_char(created_at, 'YYYY-MM') as month, count(*) as n")
            ->where('created_at', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('month')
            ->pluck('n', 'month');

        return collect(range(11, 0))
            ->map(function (int $back) use ($counts) {
                $month = now()->subMonths($back)->format('Y-m');

                return ['month' => $month, 'count' => (int) ($counts[$month] ?? 0)];
            })
            ->all();
    }

    public function show(Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        $supplier->load(['contacts', 'directors', 'products', 'industries', 'categories',
            'documents.documentType', 'bbbee', 'screening', 'scars', 'parent', 'children']);

        $dueDiligence = DueDiligenceAddon::active();

        $riskFlags = SupplierRiskFlag::where('supplier_id', $supplier->id)
            ->open()
            ->latest()
            ->get();

        return Inertia::render('Modules/SupplierManagement/Show', [
            'supplier'      => $supplier,
            'riskFlags'     => $riskFlags,
            'industryTypes' => SupplierIndustryType::where('active', true)->get(['id', 'industry_type_name']),
            // `guidance` and `default_validity_months` come with the type, because for a statutory
            // document there is nothing to verify against — the expiry date is the verification, and
            // where to obtain one is the whole of the help we can offer.
            'documentTypes' => SupplierDocumentType::where('active', true)
                ->orderByDesc('compulsory')->orderBy('document_type_name')
                ->get(['id', 'document_type_name', 'compulsory', 'authority', 'default_validity_months', 'guidance']),
            'parentOptions' => Supplier::where('id', '!=', $supplier->id)
                ->orderBy('company_name')->get(['id', 'company_name']),

            // Due diligence — the paid add-on. Absent rather than empty for a tenant without it:
            // an empty risk panel invites somebody to conclude the supplier is clean.
            ...($dueDiligence ? [
                // Where this supplier stands on each Layer 1 check, and how they got there —
                // including the checks nobody has done.
                'dueDiligence'  => $this->dueDiligenceState($supplier),
                'hasTcsPin'     => filled($supplier->tcs_pin),
                // Who actually owns this supplier, resolved through the chain, plus the other
                // suppliers it turns out to share a person or a parent with.
                'ownership'     => app(OwnershipGraph::class)->forSupplier($supplier),
                'relatedSuppliers' => app(OwnershipGraph::class)->relatedSuppliers($supplier),
                // The assessment: where the number came from, what it authorises, and what is
                // holding it up.
                'risk'          => $this->riskState($supplier),
                // What the world says, and whether anybody has read it.
                'reputation'    => $this->reputationState($supplier),
            ] : []),
        ]);
    }

    /**
     * The due-diligence position of one supplier: every Layer 1 check, its newest answer, and whether
     * anyone can even run it.
     *
     * Checks with no observation are included rather than omitted. A screen that lists only the checks
     * that happened reads as a complete file, which is the impression that gets an order raised
     * against a supplier nobody verified.
     */
    private function dueDiligenceState(Supplier $supplier): array
    {
        $current = SupplierVerification::currentFor($supplier->id);
        $registry = app(ProviderRegistry::class);

        $checks = collect(CheckType::verificationChecks())->map(function (string $check) use ($current, $registry) {
            $observation = $current->get($check);

            return [
                'key'          => $check,
                'label'        => CheckType::label($check),
                'status'       => $observation->status ?? 'never_checked',
                'severity'     => $observation ? VerificationStatus::severity($observation->status) : 'unknown',
                'summary'      => $observation->summary ?? null,
                'provider'     => $observation->provider_code ?? null,
                'checked_at'   => $observation?->checked_at?->toDateTimeString(),
                'checked_days' => $observation?->checked_at ? (int) $observation->checked_at->diffInDays(now()) : null,
                'stale'        => (bool) $observation?->isStale(),
                'mismatched'   => $observation?->mismatchedFields() ?? [],
                'has_source'   => $registry->hasDataSourceFor($check),
            ];
        })->values();

        return [
            'checks'  => $checks,
            'banking' => app(BankingGate::class)->verdict($supplier),
            // Full history, so "what did the registry say in March" is answerable on the screen.
            'history' => SupplierVerification::query()
                ->where('supplier_id', $supplier->id)
                ->orderByDesc('checked_at')->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SupplierVerification $v) => [
                    'id'         => $v->id,
                    'check'      => $v->checkLabel(),
                    'status'     => $v->status,
                    'severity'   => VerificationStatus::severity($v->status),
                    'provider'   => $v->provider_code,
                    'subject'    => $v->subject_label,
                    'summary'    => $v->summary,
                    'note'       => $v->note,
                    'reference'  => $v->reference,
                    'checked_at' => $v->checked_at?->toDateTimeString(),
                    'by'         => $v->checked_by ? ($v->checker?->person
                        ? trim(($v->checker->person->first_name ?? '').' '.($v->checker->person->surname ?? ''))
                        : $v->checker?->email) : null,
                ]),
            'people' => SupplierPerson::query()
                ->where('supplier_id', $supplier->id)
                ->orderBy('surname')
                ->get()
                ->map(fn (SupplierPerson $p) => [
                    'id'          => $p->id,
                    'name'        => $p->name(),
                    'role'        => $p->role,
                    'position'    => $p->position,
                    'nationality' => $p->nationality,
                    // Masked, never the value: enough to confirm the right record, not enough for a
                    // screenshot to be a data breach.
                    'id_number'   => $p->maskedIdNumber(),
                    'has_id'      => $p->hasIdentifier(),
                    'appointed_at' => $p->appointed_at?->toDateString(),
                    'resigned_at' => $p->resigned_at?->toDateString(),
                    'source'      => $p->source,
                ]),
        ];
    }

    /**
     * The supplier's risk position: the newest snapshot with its breakdown, the gates, the screening
     * matches awaiting a decision, and the credit history.
     *
     * The gates are sent even when they pass, because "nothing is blocking this" is a different
     * statement from "we did not check", and a screen that only lists problems cannot make the first
     * one.
     */
    private function riskState(Supplier $supplier): array
    {
        $latest = SupplierRiskScore::latestFor($supplier->id);
        $tiering = app(RiskTiering::class);

        return [
            'score' => $latest ? [
                'total'         => (float) $latest->total,
                'band'          => $latest->band,
                'band_label'    => $latest->bandLabel(),
                'requires'      => $latest->requires(),
                'movement'      => $latest->movement(),
                'components'    => $latest->components,
                'auto_approvable' => (bool) $latest->auto_approvable,
                'blockers'      => $latest->blockers ?? [],
                'edd_triggers'  => $latest->edd_triggers ?? [],
                'calculated_at' => $latest->calculated_at?->toDateTimeString(),
                'trigger'       => $latest->trigger,
            ] : null,

            // The trend, oldest first, so a sparkline reads left to right.
            'history' => SupplierRiskScore::query()
                ->where('supplier_id', $supplier->id)
                ->orderBy('calculated_at')
                ->limit(24)
                ->get()
                ->map(fn (SupplierRiskScore $s) => [
                    'total' => (float) $s->total,
                    'band'  => $s->band,
                    'at'    => $s->calculated_at?->toDateString(),
                    'trigger' => $s->trigger,
                ]),

            'tier' => [
                'current'     => $supplier->risk_tier,
                'label'       => RiskModel::tiers()[$supplier->risk_tier ?? 0]['label'] ?? null,
                'factors'     => $supplier->tier_factors ?? [],
                'available'   => collect(RiskModel::tierFactors())
                    ->map(fn ($f, $key) => ['key' => $key, 'label' => $f['label'], 'tier' => $f['tier']])->values(),
                'outstanding' => collect($tiering->outstandingChecks($supplier))
                    ->map(fn ($check) => ['key' => $check, 'label' => CheckType::label($check)])->values(),
            ],

            'hits' => ScreeningHit::query()
                ->where('supplier_id', $supplier->id)
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->orderByDesc('match_score')
                ->get()
                ->map(fn (ScreeningHit $hit) => [
                    'id'           => $hit->id,
                    'subject'      => $hit->subject_name,
                    'subject_type' => $hit->subject_type,
                    'list_type'    => $hit->list_type,
                    'list_name'    => $hit->list_name,
                    'matched_name' => $hit->matched_name,
                    'match_score'  => $hit->match_score,
                    'match_basis'  => $hit->match_basis ?? [],
                    'status'       => $hit->status,
                    'note'         => $hit->adjudication_note,
                    'times_seen'   => $hit->times_seen,
                    'first_seen'   => $hit->first_seen_at?->toDateString(),
                    'last_seen'    => $hit->last_seen_at?->toDateString(),
                ]),

            'credit' => SupplierCreditReport::query()
                ->where('supplier_id', $supplier->id)
                ->orderByDesc('report_date')
                ->limit(12)
                ->get()
                ->map(fn (SupplierCreditReport $r) => [
                    'id'                => $r->id,
                    'provider'          => $r->provider_code,
                    'score'             => $r->score,
                    'score_max'         => $r->score_max,
                    'risk_score'        => $r->riskScore(),
                    'payment_behaviour' => $r->payment_behaviour,
                    'defaults'          => $r->defaults_count,
                    'judgments'         => $r->judgments_count,
                    'insolvency'        => (bool) $r->insolvency,
                    'business_rescue'   => (bool) $r->business_rescue,
                    'report_date'       => $r->report_date?->toDateString(),
                ]),
        ];
    }

    /**
     * The reputation position: the score with what is driving it, the findings as a timeline, what is
     * being watched, and the monitoring passes themselves.
     *
     * The runs are included deliberately, including the ones that found nothing. "We monitor
     * continuously" is an assertion; a list of dated passes is the evidence, and the day somebody asks
     * why a collapse was a surprise, that is the difference.
     */
    private function reputationState(Supplier $supplier): array
    {
        $reputation = app(ReputationScore::class)->for($supplier);

        return [
            'score' => $reputation,

            'findings' => SupplierFinding::query()
                ->where('supplier_id', $supplier->id)
                ->orderByDesc('published_at')
                ->orderByDesc('first_seen_at')
                ->limit(100)
                ->get()
                ->map(fn (SupplierFinding $f) => [
                    'id'            => $f->id,
                    'channel'       => $f->channel,
                    'platform'      => $f->platform,
                    'source'        => $f->source_name,
                    'url'           => $f->url,
                    'title'         => $f->title,
                    'excerpt'       => \Illuminate\Support\Str::limit((string) $f->excerpt, 300),
                    'published_at'  => $f->published_at?->toDateString(),
                    'category'      => $f->category,
                    'category_label' => $f->categoryLabel(),
                    'severity'      => $f->severity,
                    'sentiment'     => $f->sentiment,
                    'is_positive'   => (bool) $f->is_positive,
                    // Whether a machine or a person decided this, said plainly on every row.
                    'ai_generated'  => (bool) $f->ai_generated,
                    'analysis_confidence' => $f->analysis_confidence,
                    'analysis_note' => $f->analysis_note,
                    'entity_confidence' => $f->entity_confidence,
                    'entity_basis'  => $f->entity_basis ?? [],
                    'corroborations' => $f->corroborations,
                    'status'        => $f->status,
                    'note'          => $f->adjudication_note,
                ]),

            'handles' => SupplierHandle::query()
                ->where('supplier_id', $supplier->id)
                ->active()
                ->get()
                ->map(fn (SupplierHandle $h) => [
                    'id' => $h->id, 'kind' => $h->kind, 'platform' => $h->platform,
                    'value' => $h->value, 'source' => $h->added_by_source,
                ]),

            'runs' => SupplierMonitoringRun::query()
                ->where('supplier_id', $supplier->id)
                ->orderByDesc('started_at')
                ->limit(12)
                ->get()
                ->map(fn (SupplierMonitoringRun $r) => [
                    'id' => $r->id,
                    'scope' => $r->scope,
                    'trigger' => $r->trigger,
                    'at' => $r->started_at?->toDateTimeString(),
                    'sources' => $r->sources_queried,
                    'unavailable' => $r->sources_unavailable,
                    'new' => $r->findings_new,
                    'updated' => $r->findings_updated,
                    'needs_review' => $r->below_confidence_floor,
                    'movement' => $r->reputationMovement(),
                ]),

            // Which platforms this tenant can actually search, so the screen offers what is possible
            // rather than what would fail.
            'sources_available' => app(ProviderRegistry::class)->hasDataSourceFor(CheckType::MEDIA_ADVERSE)
                || app(ProviderRegistry::class)->hasDataSourceFor(CheckType::SOCIAL_REPUTATION),
        ];
    }

    public function resolveRiskFlag(Request $request, SupplierRiskFlag $flag): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $status = $request->input('status', 'resolved');

        app(SupplierRiskService::class)->resolve($flag, (int) Auth::id(), $status);

        return back()->with('success', 'Risk flag updated.');
    }

    public function create(): Response
    {
        $this->authorize('create', Supplier::class);

        return Inertia::render('Modules/SupplierManagement/Create', [
            'industryTypes' => SupplierIndustryType::where('active', true)->get(['id', 'industry_type_name']),
            'categories'    => SupplierCategory::where('status', 'active')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $data = $request->validate([
            'company_name'                 => 'required|string|max:255',
            'trading_name'                 => 'nullable|string|max:255',
            'company_registration_number'  => 'nullable|string|max:255',
            'tax_reference_number'         => 'nullable|string|max:255',
            'vat_registration_number'      => 'nullable|string|max:255',
            'bbbee_status_level'           => 'nullable|string|max:255',
            'business_entity_type'         => 'nullable|string|max:255',
            'physical_address'             => 'nullable|string',
            'postal_address'               => 'nullable|string',
            'phone_number'                 => 'nullable|string|max:255',
            'email'                        => 'nullable|email|max:255',
            'website_url'                  => 'nullable|string|max:255',
            'bank_name'                    => 'nullable|string|max:255',
            'account_holder_name'          => 'nullable|string|max:255',
            'account_type'                 => 'nullable|string|max:255',
            'bank_account_number'          => 'nullable|string|max:255',
            'branch_code'                  => 'nullable|string|max:255',
            'payment_terms'                => 'nullable|string|max:255',
            'years_in_operation'           => 'nullable|integer',
            'notes'                        => 'nullable|string',
            'industry_ids'                 => 'nullable|array',
            'industry_ids.*'               => 'integer',
            'category_ids'                 => 'nullable|array',
            'category_ids.*'               => 'integer',
            'contacts'                     => 'nullable|array',
            'directors'                    => 'nullable|array',
            'products'                     => 'nullable|array',
        ]);

        $supplier = $this->suppliers->createSupplier($data, Auth::id());

        return redirect()->route('suppliers.show', $supplier->id)
            ->with('success', 'Supplier created.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'company_name'                 => 'sometimes|required|string|max:255',
            'trading_name'                 => 'nullable|string|max:255',
            'company_registration_number'  => 'nullable|string|max:255',
            'tax_reference_number'         => 'nullable|string|max:255',
            'vat_registration_number'      => 'nullable|string|max:255',
            'bbbee_status_level'           => 'nullable|string|max:255',
            'business_entity_type'         => 'nullable|string|max:255',
            'physical_address'             => 'nullable|string',
            'postal_address'               => 'nullable|string',
            'phone_number'                 => 'nullable|string|max:255',
            'email'                        => 'nullable|email|max:255',
            'website_url'                  => 'nullable|string|max:255',
            'bank_name'                    => 'nullable|string|max:255',
            'account_holder_name'          => 'nullable|string|max:255',
            'account_type'                 => 'nullable|string|max:255',
            'bank_account_number'          => 'nullable|string|max:255',
            'branch_code'                  => 'nullable|string|max:255',
            'payment_terms'                => 'nullable|string|max:255',
            'years_in_operation'           => 'nullable|integer',
            'conflict_of_interest_declaration'  => 'nullable|string',
            'fraud_corruption_policy_agreement' => 'nullable|boolean',
            'public_liability_insurance'        => 'nullable|boolean',
            'health_safety_compliance'          => 'nullable|boolean',
            'references_or_client_list'         => 'nullable|string',
            'notes'                        => 'nullable|string',
        ]);

        $this->suppliers->updateSupplier($supplier, $data);

        return back()->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        $supplier->delete();

        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted.');
    }

    public function trash(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $suppliers = Supplier::onlyTrashed()->latest('deleted_at')->paginate(20);

        return Inertia::render('Modules/SupplierManagement/Trash', [
            'suppliers' => $suppliers,
        ]);
    }

    public function restore(int $id): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        Supplier::onlyTrashed()->findOrFail($id)->restore();

        return back()->with('success', 'Supplier restored.');
    }

    public function forceDelete(int $id): RedirectResponse
    {
        $this->authorize('delete', Supplier::class);

        Supplier::onlyTrashed()->findOrFail($id)->forceDelete();

        return back()->with('success', 'Supplier permanently deleted.');
    }

    public function activate(Supplier $supplier): RedirectResponse
    {
        $this->authorize('approve', $supplier);

        $this->suppliers->activate($supplier);

        return back()->with('success', __('supplier_management.suppliers.activated'));
    }

    public function refuse(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('approve', $supplier);

        $data = $request->validate(['reason' => 'nullable|string|max:2000']);

        $this->suppliers->refuse($supplier, $data['reason'] ?? null);

        return back()->with('success', __('supplier_management.suppliers.refused'));
    }

    public function requestInfo(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate(['note' => 'nullable|string|max:2000']);

        $this->suppliers->requestInfo($supplier, $data['note'] ?? null);

        return back()->with('success', __('supplier_management.suppliers.info_requested'));
    }

    /**
     * Issue a fresh portal link and invalidate the old one.
     *
     * The portal token is the supplier's only credential and had no expiry and no way to replace it,
     * so a contact who left the supplier kept a working link to that company's banking details for
     * as long as the record existed. This is the revoke.
     */
    public function rotatePortalToken(Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $this->suppliers->rotatePortalToken($supplier);

        return back()->with('success', __('supplier_management.suppliers.portal_link_rotated'));
    }

    // ── Contacts ───────────────────────────────────────────────────────────────

    public function contactStore(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'surname'    => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'tel'        => 'nullable|string|max:255',
        ]);

        $supplier->contacts()->create($data);

        return back()->with('success', 'Contact added.');
    }

    public function contactUpdate(Request $request, Supplier $supplier, SupplierContact $contact): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'surname'    => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'tel'        => 'nullable|string|max:255',
        ]);

        $contact->update($data);

        return back()->with('success', 'Contact updated.');
    }

    public function contactDestroy(Supplier $supplier, SupplierContact $contact): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $contact->delete();

        return back()->with('success', 'Contact removed.');
    }

    // ── Directors ────────────────────────────────────────────────────────────────

    public function directorStore(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'surname'    => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'tel'        => 'nullable|string|max:255',
        ]);

        $supplier->directors()->create($data);

        return back()->with('success', 'Director added.');
    }

    public function directorUpdate(Request $request, Supplier $supplier, SupplierDirector $director): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'surname'    => 'nullable|string|max:255',
            'email'      => 'nullable|email|max:255',
            'tel'        => 'nullable|string|max:255',
        ]);

        $director->update($data);

        return back()->with('success', 'Director updated.');
    }

    public function directorDestroy(Supplier $supplier, SupplierDirector $director): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $director->delete();

        return back()->with('success', 'Director removed.');
    }

    // ── Products & industries (sync) ─────────────────────────────────────────────

    public function productsSync(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'products'              => 'present|array',
            'products.*.item_name'  => 'required|string|max:255',
            'products.*.item_price' => 'nullable|numeric',
            'products.*.active'     => 'nullable|boolean',
        ]);

        $supplier->products()->delete();
        foreach ($data['products'] as $product) {
            $supplier->products()->create($product);
        }

        return back()->with('success', 'Products updated.');
    }

    public function industriesSync(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'industry_ids'   => 'present|array',
            'industry_ids.*' => 'integer',
        ]);

        $supplier->industries()->sync($data['industry_ids']);

        return back()->with('success', 'Industries updated.');
    }

    // ── Documents ────────────────────────────────────────────────────────────────

    public function documentStore(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'document_type_id' => 'nullable|integer',
            'expiry_date'      => 'nullable|date',
            'file'             => 'required|file|max:10240',
        ]);

        // The document binary is stored IN the database via the FileStorage trait.
        $blob = $this->saveFileToDatabase($request->file('file'));

        $document = $supplier->documents()->make([
            'document_type_id' => $data['document_type_id'] ?? null,
            'expiry_date'      => $data['expiry_date'] ?? null,
            'file_name'        => $blob['original_name'],
            'file_mime'        => $blob['mime'],
            'file_size'        => $blob['size'],
        ]);

        $document->writeBytes(base64_decode($blob['data']), pathinfo((string) $blob['original_name'], PATHINFO_EXTENSION));
        $document->save();

        // If no expiry was supplied, let AI read the document and detect it.
        $detected = null;
        if (empty($data['expiry_date'])) {
            $detected = app(\App\Modules\SupplierManagement\Services\SupplierDocumentAiService::class)
                ->extractExpiry($document);
        }

        return back()->with('success', $detected
            ? "Document stored. Expiry auto-detected: {$detected}."
            : 'Document uploaded and stored.');
    }

    /** Manually (re)run AI expiry detection on a stored document. */
    public function documentDetectExpiry(SupplierDocument $document): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $detected = app(\App\Modules\SupplierManagement\Services\SupplierDocumentAiService::class)
            ->extractExpiry($document, true);

        return back()->with('success', $detected
            ? "Expiry detected: {$detected}."
            : 'No expiry date could be detected in this document.');
    }

    /** Stream a supplier document stored in the database. */
    public function documentDownload(SupplierDocument $document): \Illuminate\Http\Response
    {
        $this->authorize('view', Supplier::class);
        abort_unless($document->hasDocument(), 404, 'No file stored for this document.');

        return $document->documentResponse();
    }

    /** Link this supplier to a parent company (entity hierarchy). */
    public function setParent(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'different:'.$supplier->id, 'exists:suppliers,id'],
        ]);

        $supplier->update(['parent_id' => $data['parent_id'] ?? null]);

        return back()->with('success', 'Parent company updated.');
    }
}
