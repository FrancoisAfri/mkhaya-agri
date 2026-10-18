<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmComment;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\Finance\Accounting\Services\PartySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use App\Modules\CRM\Http\Controllers\Concerns\GuardsRecordOwnership;

class AccountController extends Controller
{
    use GuardsRecordOwnership;

    /** Standard industry options for the account dropdown. */
    public const INDUSTRIES = [
        'Agriculture', 'Automotive', 'Banking', 'Construction', 'Consulting', 'Education',
        'Energy & Utilities', 'Financial Services', 'Government', 'Healthcare', 'Hospitality',
        'Insurance', 'Legal', 'Logistics & Transport', 'Manufacturing', 'Media & Entertainment',
        'Mining', 'Non-Profit', 'Pharmaceuticals', 'Real Estate', 'Retail', 'Technology',
        'Telecommunications', 'Tourism & Travel', 'Wholesale', 'Other',
    ];

    public function index(Request $request): Response
    {
        $accountQuery = CrmAccount::withCount(['contacts', 'deals'])->with('owner.person', 'product')
            ->when($request->q, fn ($q, $term) => $q->where('name', 'ilike', "%{$term}%"))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->orderBy('name');

        app(\App\Modules\CRM\Services\CrmVisibilityService::class)->scopeOwner($accountQuery);

        $accounts = $accountQuery->get()
            ->map(fn ($a) => [
                'id'             => $a->id,
                'uuid'           => $a->uuid,
                'name'           => $a->name,
                'industry'       => $a->industry,
                'website'        => $a->website,
                'email'          => $a->email,
                'phone'          => $a->phone,
                // Sent so the edit dialog can load them; the list itself shows neither.
                'address'        => $a->address,
                'city'           => $a->city,
                'country'        => $a->country,
                'notes'          => $a->notes,
                'owner_id'       => $a->owner_id,
                'annual_revenue' => $a->annual_revenue !== null ? (float) $a->annual_revenue : null,
                'employee_count' => $a->employee_count,
                'contacts'       => $a->contacts_count,
                'deals'          => $a->deals_count,
                'owner'          => $a->owner?->person?->first_name,
                'product_id'     => $a->product_id,
                'product'        => $a->product?->name,
            ]);

        // Deduplicate countries by name (the table can hold duplicate names),
        // keeping the canonical lowest id so it matches the seeded cities.
        $countries = [];
        foreach (DB::table('countries')->orderBy('id')->get(['id', 'name']) as $row) {
            if (! isset($countries[$row->name])) {
                $countries[$row->name] = ['id' => $row->id, 'name' => $row->name];
            }
        }
        ksort($countries);

        return Inertia::render('Modules/CRM/Accounts', [
            'accounts'   => $accounts,
            'products'   => \App\Modules\CRM\Models\CrmProduct::active()->orderBy('name')->get(['id', 'name', 'type']),
            'countries'  => array_values($countries),
            'industries' => self::INDUSTRIES,
            // Customers captured (or imported) in Accounting that have no CRM account yet.
            'pendingFromAccounting' => app(PartySyncService::class)->customersNotInCrm(),
            'showOwner'        => app(\App\Modules\CRM\Services\CrmVisibilityService::class)->seesOthers(),
            'assignableOwners' => app(\App\Modules\CRM\Services\CrmVisibilityService::class)->assignableOwners(),
        ]);
    }

    /**
     * Pull every Accounting customer that isn't in CRM yet across as an account — for a client
     * who set Accounting up first, or migrated their customer book in from Sage. Each one is
     * matched against the existing accounts before anything is created, so running it twice
     * costs nothing and it never duplicates what CRM already has.
     */
    public function syncFromAccounting(PartySyncService $sync): RedirectResponse
    {
        abort_unless($sync->accountingActive(), 404, 'Accounting is not active for this company.');

        $result = $sync->pushCustomersToCrm();

        return back()->with('success', $result['created'] || $result['linked']
            ? "{$result['created']} account(s) created from Accounting, {$result['linked']} linked to accounts you already had."
            : 'Every Accounting customer is already in CRM.');
    }

    /**
     * Account 360° view — everything about one account: contacts, deals, the full
     * activity timeline (calls / notes / emails / meetings across the account, its
     * deals and its contacts), notes/discussion, and revenue for a chosen period.
     */
    public function show(Request $request, CrmAccount $account): Response
    {
        // Enforce row-level visibility — consultants can't open others' accounts.
        $visible = app(\App\Modules\CRM\Services\CrmVisibilityService::class)->visibleOwnerIds();
        abort_if($visible !== null && ! in_array($account->owner_id, $visible, true), 403, 'You do not have access to this account.');

        // Period filter for revenue & activity metrics.
        $period = $request->query('period', '365'); // 30 | 90 | 365 | all
        $from   = match ($period) {
            '30'  => now()->subDays(30),
            '90'  => now()->subDays(90),
            '365' => now()->subYear(),
            default => null,
        };

        $account->load(['owner.person']);

        $contacts = $account->contacts()->latest()->get();
        $deals    = $account->deals()->with(['stage', 'owner.person'])->latest()->get();

        $contactIds = $contacts->pluck('id')->all();
        $dealIds    = $deals->pluck('id')->all();

        // Activity timeline across the account + its deals + its contacts.
        $activities = \App\Modules\CRM\Models\CrmActivity::with('owner.person')
            ->where(function ($q) use ($account, $dealIds, $contactIds) {
                $q->where(fn ($w) => $w->where('related_type', 'account')->where('related_id', $account->id));
                if ($dealIds)    $q->orWhere(fn ($w) => $w->where('related_type', 'deal')->whereIn('related_id', $dealIds));
                if ($contactIds) $q->orWhere(fn ($w) => $w->where('related_type', 'contact')->whereIn('related_id', $contactIds));
            })
            ->latest()
            ->limit(200)
            ->get();

        $periodActivities = $from ? $activities->filter(fn ($a) => $a->created_at >= $from) : $activities;

        // Discussion / notes on the account.
        $notes = \App\Modules\CRM\Models\CrmComment::with('author.person')
            ->where('entity_type', 'account')->where('entity_id', $account->id)
            ->whereNull('parent_id')->latest()->get();

        $wonDeals = $deals->where('status', 'won');
        $wonInPeriod = $from
            ? $wonDeals->filter(fn ($d) => $d->closed_at && $d->closed_at >= $from)
            : $wonDeals;

        return Inertia::render('Modules/CRM/AccountDetail', [
            'account' => [
                'id'             => $account->id,
                'uuid'           => $account->uuid,
                'name'           => $account->name,
                'industry'       => $account->industry,
                'website'        => $account->website,
                'email'          => $account->email,
                'phone'          => $account->phone,
                'city'           => $account->city,
                'country'        => $account->country,
                'annual_revenue' => $account->annual_revenue !== null ? (float) $account->annual_revenue : null,
                'employee_count' => $account->employee_count,
                'owner'          => $account->owner?->person
                    ? trim($account->owner->person->first_name.' '.$account->owner->person->surname) : null,
            ],
            'period'  => $period,
            'metrics' => [
                'contacts'       => $contacts->count(),
                'open_deals'     => $deals->where('status', 'open')->count(),
                'pipeline_value' => (float) $deals->where('status', 'open')->sum('amount'),
                'won_revenue'    => (float) $wonInPeriod->sum('amount'),
                'won_count'      => $wonInPeriod->count(),
                'lost_count'     => $deals->where('status', 'lost')->count(),
                'activity_counts'=> [
                    'call'    => $periodActivities->where('type', 'call')->count(),
                    'email'   => $periodActivities->where('type', 'email')->count(),
                    'meeting' => $periodActivities->where('type', 'meeting')->count(),
                    'note'    => $periodActivities->where('type', 'note')->count(),
                    'total'   => $periodActivities->count(),
                ],
            ],
            'contacts' => $contacts->map(fn ($c) => [
                'id'        => $c->id,
                'name'      => trim($c->first_name.' '.$c->last_name),
                'job_title' => $c->job_title,
                'email'     => $c->email,
                'phone'     => $c->phone ?: $c->mobile,
            ]),
            'deals' => $deals->map(fn ($d) => [
                'id'           => $d->id,
                'title'        => $d->title,
                'amount'       => (float) $d->amount,
                'currency'     => $d->currency,
                'status'       => $d->status,
                'status_label' => CrmDeal::STATUSES[$d->status]['label'] ?? $d->status,
                'status_color' => CrmDeal::STATUSES[$d->status]['color'] ?? 'grey',
                'stage'        => $d->stage?->name,
                'owner'        => $d->owner?->person?->first_name,
                'closed_at'    => $d->closed_at?->toDateString(),
            ]),
            'activities' => $activities->map(fn ($a) => [
                'id'            => $a->id,
                'type'         => $a->type,
                'type_label'   => CrmActivity::TYPES[$a->type]['label'] ?? $a->type,
                'type_icon'    => CrmActivity::TYPES[$a->type]['icon'] ?? 'point',
                'subject'      => $a->subject,
                'description'  => $a->readableDescription(),
                'related_type' => $a->related_type,
                'status'       => $a->status,
                'owner'        => $a->owner?->person?->first_name,
                'created_at'   => $a->created_at?->diffForHumans(),
            ]),
            'notes' => $notes->map(fn ($n) => [
                'id'     => $n->id,
                'body'   => $n->body,
                'author' => trim(($n->author?->person?->first_name ?? '').' '.($n->author?->person?->surname ?? '')) ?: 'User',
                'at'     => $n->created_at?->diffForHumans(),
            ]),
            // Everything the "add deal / contact / activity" dialogs on this page need, so a
            // deal can be raised against the account you're looking at without leaving it.
            'pipelines' => \App\Modules\CRM\Models\CrmPipeline::where('is_active', true)->with('stages')
                ->orderBy('sort_order')->get()
                ->map(fn ($p) => [
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'is_default' => (bool) $p->is_default,
                    'stages'     => $p->stages->sortBy('sort_order')
                        ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
                ])->values(),
            'activityTypes' => collect(CrmActivity::TYPES)
                ->map(fn ($meta, $key) => ['value' => $key, 'title' => $meta['label'] ?? $key])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['uuid']       = Str::uuid();
        $data['owner_id']   = $data['owner_id'] ?? Auth::id();
        $data['created_by'] = Auth::id();

        CrmAccount::create($data);

        return back()->with('success', 'Account created.');
    }

    public function update(Request $request, CrmAccount $account): RedirectResponse
    {
        $this->guard($account);
        $account->update($this->validateData($request));

        return back()->with('success', 'Account updated.');
    }

    public function destroy(CrmAccount $account): RedirectResponse
    {
        $this->guard($account);
        $account->delete();

        return back()->with('success', 'Account deleted.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'           => 'required|string|max:200',
            'industry'       => 'nullable|string|max:120',
            'website'        => 'nullable|string|max:200',
            'email'          => 'nullable|email|max:200',
            'phone'          => 'nullable|string|max:50',
            'address'        => 'nullable|string|max:255',
            'city'           => 'nullable|string|max:120',
            'country'        => 'nullable|string|max:120',
            'annual_revenue' => 'nullable|numeric|min:0',
            'employee_count' => 'nullable|integer|min:0',
            'parent_id'      => 'nullable|exists:crm_accounts,id',
            'owner_id'       => 'nullable|exists:users,id',
            'product_id'     => 'nullable|exists:crm_products,id',
            'notes'          => 'nullable|string',
        ]);
    }
}
