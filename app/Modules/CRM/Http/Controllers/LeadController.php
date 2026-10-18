<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmSavedView;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmCustomFieldService;
use App\Modules\CRM\Services\CrmEventBus;
use App\Modules\CRM\Services\CrmSegmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use App\Modules\CRM\Http\Controllers\Concerns\GuardsRecordOwnership;

class LeadController extends Controller
{
    use GuardsRecordOwnership;

    /**
     * How many leads the page hands the browser at once.
     *
     * The table filters and searches client-side, so this is a payload limit rather than a page
     * size — the count is shown alongside it so nobody is left thinking their import failed.
     */
    private const PAGE_LIMIT = 500;

    public function __construct(
        private CrmAiService $ai,
        private CrmEventBus $events,
        private CrmCustomFieldService $fields,
        private CrmSegmentService $segments,
    ) {
    }

    public function index(Request $request): Response
    {
        // Saved views (segments) the current user can see for leads.
        $views = CrmSavedView::visibleTo((int) Auth::id(), 'lead')->orderBy('name')->get();
        $activeView = $request->filled('view') ? $views->firstWhere('id', (int) $request->view) : null;

        $query = CrmLead::with('owner.person', 'product')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->q, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('first_name', 'ilike', "%{$term}%")
                ->orWhere('last_name', 'ilike', "%{$term}%")
                ->orWhere('company', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")));

        // Apply the active saved view's filters server-side.
        if ($activeView) {
            $this->segments->apply($query, 'lead', $activeView->filters ?? []);
        }

        // Row-level visibility: consultants see their own, managers their tree.
        app(\App\Modules\CRM\Services\CrmVisibilityService::class)->scopeOwner($query);

        /*
         * Capped, and the cap is reported.
         *
         * This page hands every lead to the browser and filters client-side, which was fine when a
         * CRM held a few hundred. After a 664-row CSV import the table passed seven thousand and
         * the page returned a 500 — the payload alone, before the per-row custom-field queries
         * that used to go with it. Newest first, because a lead list is worked from the top.
         *
         * Somebody following a notification is looking for one specific lead, which may be older
         * than the cap, so that one is fetched whatever position it holds.
         */
        $focus = $request->filled('lead') ? (int) $request->query('lead') : null;

        $rows = $query->latest()->limit(self::PAGE_LIMIT)->get();
        $total = $query->toBase()->getCountForPagination();

        if ($focus && ! $rows->contains('id', $focus)) {
            $wanted = (clone $query)->whereKey($focus)->first();
            if ($wanted) {
                $rows->prepend($wanted);
            }
        }

        // One query for the whole page instead of one per row.
        $custom = $this->fields->valuesFor('lead', $rows->pluck('id')->all());
        $leads = $rows->map(fn ($l) => $this->present($l, $custom[$l->id] ?? []));

        return Inertia::render('Modules/CRM/Leads', [
            'leads'        => $leads,
            'leadTotal'    => $total,
            'leadLimit'    => self::PAGE_LIMIT,
            'focusLead'    => $focus,
            'products'     => \App\Modules\CRM\Models\CrmProduct::active()->orderBy('name')->get(['id', 'name', 'type']),
            'statuses'     => CrmLead::STATUSES,
            'aiEnabled'    => $this->ai->isConfigured(),
            'showOwner'    => app(\App\Modules\CRM\Services\CrmVisibilityService::class)->seesOthers(),
            'assignableOwners' => app(\App\Modules\CRM\Services\CrmVisibilityService::class)->assignableOwners(),
            'customFields' => $this->fields->definitionsForUi('lead'),
            'savedViews'   => $views->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->name, 'filters' => $v->filters ?? [],
                'is_shared' => $v->is_shared, 'mine' => $v->user_id === Auth::id(),
            ]),
            'activeView'   => $activeView?->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['uuid']       = Str::uuid();
        $data['status']     = 'new';
        $data['owner_id']   = $data['owner_id'] ?? Auth::id();
        $data['created_by'] = Auth::id();

        $lead = CrmLead::create($data);
        $this->fields->saveValues('lead', $lead->id, (array) $request->input('custom', []));
        $this->events->fire('lead.created', $lead);

        return back()->with('success', 'Lead created.');
    }

    public function update(Request $request, CrmLead $lead): RedirectResponse
    {
        $this->guard($lead);
        $lead->update($this->validateData($request, true));
        $this->fields->saveValues('lead', $lead->id, (array) $request->input('custom', []));

        return back()->with('success', 'Lead updated.');
    }

    public function destroy(CrmLead $lead): RedirectResponse
    {
        $this->guard($lead);
        $lead->delete();

        return back()->with('success', 'Lead deleted.');
    }

    /**
     * AI lead scoring (spec section 4).
     */
    public function score(CrmLead $lead): RedirectResponse
    {
        if (! $this->ai->isConfigured()) {
            return back()->with('error', 'AI lead scoring is not configured.');
        }

        $result = $this->ai->scoreLead([
            'name'    => $lead->full_name,
            'company' => $lead->company,
            'email'   => $lead->email,
            'source'  => $lead->source,
            'status'  => $lead->status,
            'notes'   => $lead->notes,
        ]);

        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Scoring failed.');
        }

        $lead->update([
            'score'        => max(0, min(100, (int) ($result['score'] ?? 0))),
            'score_reason' => $result['reason'] ?? null,
        ]);

        return back()->with('success', "Lead scored: {$lead->score}/100.");
    }

    /**
     * Convert a lead into an account + contact + (optional) deal.
     */
    public function convert(Request $request, CrmLead $lead): RedirectResponse
    {
        abort_if($lead->status === 'converted', 422, 'Lead already converted.');

        $data = $request->validate([
            'create_deal' => 'boolean',
            'deal_title'  => 'nullable|string|max:200',
            'amount'      => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($lead, $data, $request) {
            $account = $lead->company
                ? CrmAccount::create([
                    'uuid' => Str::uuid(), 'name' => $lead->company,
                    'email' => $lead->email, 'phone' => $lead->phone,
                    'owner_id' => $lead->owner_id, 'created_by' => Auth::id(),
                ])
                : null;

            $contact = CrmContact::create([
                'uuid' => Str::uuid(), 'account_id' => $account?->id,
                'first_name' => $lead->first_name, 'last_name' => $lead->last_name,
                'email' => $lead->email, 'phone' => $lead->phone,
                'owner_id' => $lead->owner_id, 'created_by' => Auth::id(),
            ]);

            $deal = null;
            if ($request->boolean('create_deal')) {
                $pipeline = CrmPipeline::where('is_default', true)->with('stages')->first()
                    ?? CrmPipeline::with('stages')->first();
                $firstStage = $pipeline?->stages->first();

                if ($pipeline && $firstStage) {
                    $deal = CrmDeal::create([
                        'uuid' => Str::uuid(),
                        'title' => $data['deal_title'] ?? ($lead->company ?: $lead->full_name) . ' opportunity',
                        'account_id' => $account?->id, 'contact_id' => $contact->id,
                        'pipeline_id' => $pipeline->id, 'stage_id' => $firstStage->id,
                        'amount' => $data['amount'] ?? 0, 'status' => 'open',
                        'probability' => $firstStage->probability,
                        'stage_changed_at' => now(),
                        'owner_id' => $lead->owner_id, 'created_by' => Auth::id(),
                    ]);
                }
            }

            $lead->update([
                'status' => 'converted',
                'converted_account_id' => $account?->id,
                'converted_contact_id' => $contact->id,
                'converted_deal_id' => $deal?->id,
            ]);
        });

        return back()->with('success', 'Lead converted.');
    }

    private function present(CrmLead $l, ?array $custom = null): array
    {
        return [
            'id'         => $l->id,
            'uuid'       => $l->uuid,
            'name'       => $l->full_name,
            'company'    => $l->company,
            'email'      => $l->email,
            'phone'      => $l->phone,
            'source'     => $l->source,
            'status'     => $l->status,
            'status_label' => CrmLead::STATUSES[$l->status]['label'] ?? $l->status,
            'status_color' => CrmLead::STATUSES[$l->status]['color'] ?? 'blue-grey',
            'score'      => $l->score,
            'score_reason' => $l->score_reason,
            'owner'      => $l->owner?->person?->first_name,
            'product_id' => $l->product_id,
            'product'    => $l->product?->name,
            'created_at' => $l->created_at?->toDateString(),
            // Handed in by the list page, which loads them all in one query; a caller with a
            // single record can still let this fetch its own.
            'custom'     => $custom ?? $this->fields->values('lead', $l->id),
        ];
    }

    private function validateData(Request $request, bool $update = false): array
    {
        return $request->validate([
            'first_name' => ($update ? 'sometimes|' : '') . 'required|string|max:120',
            'last_name'  => 'nullable|string|max:120',
            'company'    => 'nullable|string|max:200',
            'email'      => 'nullable|email|max:200',
            'phone'      => 'nullable|string|max:50',
            'source'     => 'nullable|string|max:100',
            'status'     => 'nullable|in:new,contacted,qualified,unqualified,converted',
            'owner_id'   => 'nullable|exists:users,id',
            'product_id' => 'nullable|exists:crm_products,id',
            'notes'      => 'nullable|string',
        ]);
    }
}
