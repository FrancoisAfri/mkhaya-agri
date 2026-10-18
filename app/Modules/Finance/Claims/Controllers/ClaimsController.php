<?php

namespace App\Modules\Finance\Claims\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Claims\Models\Claim;
use App\Modules\Finance\Claims\Models\ClaimCategory;
use App\Modules\Finance\Claims\Models\ClaimTravelTrip;
use App\Modules\Finance\Claims\Services\ClaimsService;
use App\Modules\Finance\Claims\Traits\ClaimsAuthTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ClaimsController extends Controller
{
    use ClaimsAuthTrait;

    public function __construct(private readonly ClaimsService $service) {}

    // ── My Claims list ────────────────────────────────────────────────────────
    public function index(): Response
    {
        $claims = $this->scopedClaimQuery()
            ->with(['category', 'employee.person', 'policyViolations', 'riskScore'])
            ->latest()
            ->paginate(20)
            ->through(fn($c) => $this->formatClaim($c));

        return Inertia::render('Modules/Finance/Claims/MyClaims/Index', [
            'claims'     => $claims,
            'categories' => $this->categoryOptions(),
            'canCreate'  => true,
            'isAdmin'    => $this->isClaimsAdmin(),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────
    public function create(): Response
    {
        return Inertia::render('Modules/Finance/Claims/MyClaims/Create', [
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $isTravel = (bool) $request->input('is_travel');

        if ($isTravel) {
            $data = $request->validate([
                'title'       => 'required|string|max:200',
                'category_id' => 'required|exists:claim_categories,id',
                'claim_date'  => 'required|date|before_or_equal:today',
                'description' => 'nullable|string|max:1000',
                'currency'    => 'nullable|string|max:10',
                'trips'       => 'required|array|min:1',
                'trips.*.trip_date'                   => 'required|date',
                'trips.*.departure_time'              => 'nullable|string',
                'trips.*.origin'                      => 'required|string|max:500',
                'trips.*.destination'                 => 'required|string|max:500',
                'trips.*.distance_km'                 => 'required|numeric|min:0.01',
                'trips.*.duration_minutes'            => 'nullable|integer',
                'trips.*.duration_in_traffic_minutes' => 'nullable|integer',
                'trips.*.is_peak_hour'                => 'nullable|boolean',
                'trips.*.traffic_condition'           => 'nullable|string|max:20',
                'trips.*.km_rate'                     => 'required|numeric|min:0.01',
                'trips.*.base_amount'                 => 'required|numeric|min:0',
                'trips.*.peak_surcharge'              => 'nullable|numeric|min:0',
                'trips.*.total_amount'                => 'required|numeric|min:0.01',
                'trips.*.notes'                       => 'nullable|string|max:500',
                'trip_receipt_files'                  => 'nullable|array',
                'trip_receipt_files.*'                => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ]);

            $claim = $this->service->createClaim($data);

            $tripReceiptFiles = $request->file('trip_receipt_files') ?? [];

            foreach ($data['trips'] as $index => $trip) {
                $createdTrip = ClaimTravelTrip::create(array_merge($trip, ['claim_id' => $claim->id]));
                if (isset($tripReceiptFiles[$index]) && $tripReceiptFiles[$index] instanceof \Illuminate\Http\UploadedFile) {
                    $this->service->uploadClaimReceipt($claim, $tripReceiptFiles[$index], null, 'receipt', $createdTrip->id);
                }
            }

            $claim->recalculateTotal();

            $autoSubmit = (bool) $request->input('auto_submit');
            if ($autoSubmit && $claim->travelTrips()->count() > 0) {
                $this->service->submitClaim($claim);
            }
        } else {
            $data = $request->validate([
                'title'         => 'required|string|max:200',
                'category_id'   => 'nullable|exists:claim_categories,id',
                'claim_date'    => 'required|date|before_or_equal:today',
                'description'   => 'nullable|string|max:1000',
                'currency'      => 'nullable|string|max:10',
                'items'         => 'required|array|min:1',
                'items.*.item_date'        => 'nullable|date|before_or_equal:today',
                'items.*.description'      => 'required|string|max:300',
                'items.*.unit_price'       => 'required|numeric|min:0.01',
                'items.*.quantity'         => 'nullable|integer|min:1',
                'items.*.item_type'        => 'nullable|string|max:80',
                'items.*.receipt_required' => 'nullable|boolean',
                'receipt_files'            => 'nullable|array',
                'receipt_files.*'          => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            ]);

            $claim = $this->service->createClaim($data);

            $receiptFiles = $request->file('receipt_files') ?? [];

            foreach ($data['items'] as $index => $item) {
                $createdItem = $this->service->addClaimItem($claim, $item);
                if (isset($receiptFiles[$index]) && $receiptFiles[$index] instanceof \Illuminate\Http\UploadedFile) {
                    $this->service->uploadClaimReceipt($claim, $receiptFiles[$index], $createdItem->id, 'receipt');
                }
            }

            $autoSubmit = (bool) $request->input('auto_submit');
            if ($autoSubmit && $claim->items()->count() > 0) {
                $this->service->submitClaim($claim);
            }
        }

        return redirect()->route('claims.my.show', $claim)
            ->with('success', "Claim {$claim->reference_number} created.");
    }

    // ── Edit (draft only) ─────────────────────────────────────────────────────
    public function edit(Claim $claim): Response
    {
        abort_unless($claim->employee_id === Auth::id() && $claim->status === 'draft', 403);

        $claim->load(['items.attachments', 'travelTrips', 'category', 'employee.person']);

        $detail = $this->formatClaimDetail($claim);

        // Override display-format dates with form-input format (Y-m-d)
        $detail['claim_date'] = $claim->claim_date->format('Y-m-d');
        $detail['items'] = $claim->items->map(fn($i) => [
            'id'               => $i->id,
            'item_date'        => $i->item_date?->format('Y-m-d'),
            'description'      => $i->description,
            'unit_price'       => (float) $i->unit_price,
            'quantity'         => $i->quantity,
            'amount'           => (float) $i->amount,
            'item_type'        => $i->item_type,
            'receipt_required' => $i->receipt_required,
            'receipt_uploaded' => $i->receipt_uploaded,
            'attachments'      => $i->attachments->map(fn($a) => [
                'id'           => $a->id,
                'name'         => $a->original_name,
                'type'         => $a->attachment_type,
                'mime'         => $a->mime,
                'download_url' => $a->uuid ? route('claims.attachments.download', $a->uuid) : null,
            ]),
        ])->toArray();

        return Inertia::render('Modules/Finance/Claims/MyClaims/Edit', [
            'claim'      => $detail,
            'categories' => $this->categoryOptions(),
        ]);
    }

    // ── Show / edit ───────────────────────────────────────────────────────────
    public function show(Claim $claim): Response
    {
        abort_unless($this->canViewClaim($claim), 403);

        $claim->load(['items.attachments', 'travelTrips', 'attachments', 'approvals.approver.person', 'policyViolations', 'riskScore', 'category', 'employee.person']);

        return Inertia::render('Modules/Finance/Claims/MyClaims/Show', [
            'claim'      => $this->formatClaimDetail($claim),
            'isAdmin'    => $this->isClaimsAdmin(),
            'canApprove' => $this->canApproveClaim($claim),
        ]);
    }

    // ── Inline detail (JSON, used by expandable index row) ────────────────────
    public function detail(Claim $claim): \Illuminate\Http\JsonResponse
    {
        abort_unless($this->canViewClaim($claim), 403);

        $claim->load(['items.attachments', 'travelTrips', 'attachments', 'approvals.approver.person', 'policyViolations', 'riskScore', 'category', 'employee.person']);

        return response()->json([
            'claim'      => $this->formatClaimDetail($claim),
            'canApprove' => $this->canApproveClaim($claim),
            'isAdmin'    => $this->isClaimsAdmin(),
        ]);
    }

    public function update(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($claim->employee_id === Auth::id() && $claim->status === 'draft', 403);

        $data = $request->validate([
            'title'       => 'sometimes|string|max:200',
            'category_id' => 'nullable|exists:claim_categories,id',
            'claim_date'  => 'sometimes|date|before_or_equal:today',
            'description' => 'nullable|string|max:1000',
        ]);

        $claim->update($data);

        return back()->with('success', 'Claim updated.');
    }

    // ── Item management ───────────────────────────────────────────────────────
    public function addItem(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($claim->employee_id === Auth::id() && $claim->status === 'draft', 403);

        $data = $request->validate([
            'description'     => 'required|string|max:300',
            'unit_price'      => 'required|numeric|min:0.01',
            'quantity'        => 'nullable|integer|min:1',
            'item_type'       => 'nullable|string|max:80',
            'receipt_required'=> 'nullable|boolean',
            'notes'           => 'nullable|string|max:500',
        ]);

        $this->service->addClaimItem($claim, $data);
        return back()->with('success', 'Item added.');
    }

    public function removeItem(Claim $claim, \App\Modules\Finance\Claims\Models\ClaimItem $item): RedirectResponse
    {
        abort_unless($claim->employee_id === Auth::id() && $claim->status === 'draft', 403);
        $this->service->removeClaimItem($item);
        return back()->with('success', 'Item removed.');
    }

    // ── Receipt upload ────────────────────────────────────────────────────────
    public function uploadReceipt(Request $request, Claim $claim): RedirectResponse
    {
        abort_unless($claim->employee_id === Auth::id(), 403);

        $request->validate([
            'file'          => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'item_id'       => 'nullable|exists:claim_items,id',
            'attachment_type'=> 'nullable|in:receipt,invoice,supporting_doc,other',
        ]);

        $this->service->uploadClaimReceipt(
            $claim,
            $request->file('file'),
            $request->integer('item_id') ?: null,
            $request->input('attachment_type', 'receipt')
        );

        return back()->with('success', 'Receipt uploaded.');
    }

    // ── Submit ────────────────────────────────────────────────────────────────
    public function submit(Claim $claim): RedirectResponse
    {
        abort_unless($claim->employee_id === Auth::id(), 403);
        $this->service->submitClaim($claim);

        activity('Claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('submitted')
            ->withProperties(['reference_number' => $claim->reference_number, 'total_amount' => $claim->total_amount])
            ->log("Claim {$claim->reference_number} submitted for approval.");

        return redirect()->route('claims.my.index')->with('success', "Claim {$claim->reference_number} submitted for approval.");
    }

    // ── Cancel ────────────────────────────────────────────────────────────────
    public function cancel(Claim $claim): RedirectResponse
    {
        abort_unless($claim->employee_id === Auth::id() && $claim->status === 'draft', 403);
        $claim->update(['status' => 'cancelled']);

        activity('Claims')
            ->causedBy(Auth::user())
            ->performedOn($claim)
            ->event('cancelled')
            ->log("Claim {$claim->reference_number} cancelled.");

        return redirect()->route('claims.my.index')->with('success', 'Claim cancelled.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function resolvePersonName(?\App\Core\User\Models\User $user): string
    {
        if (! $user) return '—';
        $person = $user->relationLoaded('person') ? $user->getRelation('person') : $user->person;
        $name   = trim(($person?->first_name ?? '') . ' ' . ($person?->surname ?? ''));
        return $name !== '' ? $name : ($user->email ?? '—');
    }

    private function categoryOptions(): \Illuminate\Support\Collection
    {
        return ClaimCategory::where('active', true)->orderBy('name')
            ->get(['id', 'name', 'code', 'max_amount', 'requires_receipt', 'max_age_days', 'is_travel', 'km_rate']);
    }

    private function formatClaim(Claim $c): array
    {
        return [
            'id'               => $c->id,
            'uuid'             => $c->uuid,
            'reference_number' => $c->reference_number,
            'employee'         => $c->employeeName(),
            'title'            => $c->title,
            'category'         => $c->category?->name ?? '—',
            'requires_receipt' => ($c->category?->requires_receipt ?? false) && ! ($c->category?->is_travel ?? false),
            'total_amount'     => (float) $c->total_amount,
            'currency'         => $c->currency,
            'status'           => $c->status,
            'status_color'     => $c->status_color,
            'risk_level'       => $c->risk_level,
            'risk_score'       => $c->risk_score ? (float) $c->risk_score : null,
            'violations'       => $c->policyViolations->count(),
            'claim_date'       => $c->claim_date->format('d M Y'),
            'submitted_at'     => $c->submitted_at?->format('d M Y'),
            'age_days'         => $c->age_in_days,
        ];
    }

    private function formatClaimDetail(Claim $c): array
    {
        return array_merge($this->formatClaim($c), [
            'description'      => $c->description,
            'notes'            => $c->notes,
            'rejection_reason' => $c->rejection_reason,
            'category_id'      => $c->category_id,
            'approved_at'      => $c->approved_at?->format('d M Y'),
            'rejected_at'      => $c->rejected_at?->format('d M Y'),
            'paid_at'          => $c->paid_at?->format('d M Y'),
            'is_travel'        => (bool) ($c->category?->is_travel),
            'items'            => $c->items->map(fn($i) => [
                'id'               => $i->id,
                'item_date'        => $i->item_date?->format('d M Y'),
                'description'      => $i->description,
                'unit_price'       => (float) $i->unit_price,
                'quantity'         => $i->quantity,
                'amount'           => (float) $i->amount,
                'item_type'        => $i->item_type,
                'receipt_required' => $i->receipt_required,
                'receipt_uploaded' => $i->receipt_uploaded,
                'attachments'      => $i->attachments->map(fn($a) => [
                    'id'           => $a->id,
                    'name'         => $a->original_name,
                    'type'         => $a->attachment_type,
                    'download_url' => $a->uuid ? route('claims.attachments.download', $a->uuid) : null,
                ]),
            ]),
            'attachments'      => $c->attachments->map(fn($a) => [
                'id'             => $a->id,
                'original_name'  => $a->original_name,
                'attachment_type'=> $a->attachment_type,
                'size_formatted' => $a->file_size_formatted,
                'mime_type'      => $a->mime,
                'download_url'   => $a->uuid ? route('claims.attachments.download', $a->uuid) : null,
            ]),
            'approvals'        => $c->approvals->map(fn($a) => [
                'id'       => $a->id,
                'level'    => $a->level,
                'approver' => $this->resolvePersonName($a->approver),
                'action'   => $a->action,
                'color'    => $a->action_color,
                'notes'    => $a->notes,
                'reason'   => $a->rejection_reason,
                'actioned_at' => $a->actioned_at?->format('d M Y H:i'),
            ]),
            'violations'       => $c->policyViolations->map(fn($v) => [
                'id'          => $v->id,
                'rule'        => $v->policy_rule,
                'type'        => $v->violation_type,
                'description' => $v->description,
                'severity'    => $v->severity,
                'color'       => $v->severity_color,
                'exception_requested' => $v->is_exception_requested,
                'exception_approved'  => $v->exception_approved,
            ]),
            'travel_trips'     => $c->travelTrips->map(fn($t) => [
                'id'                           => $t->id,
                'trip_date'                    => $t->trip_date->format('d M Y'),
                'departure_time'               => $t->departure_time,
                'origin'                       => $t->origin,
                'destination'                  => $t->destination,
                'distance_km'                  => (float) $t->distance_km,
                'duration_minutes'             => $t->duration_minutes,
                'duration_in_traffic_minutes'  => $t->duration_in_traffic_minutes,
                'is_peak_hour'                 => $t->is_peak_hour,
                'traffic_condition'            => $t->traffic_condition,
                'km_rate'                      => (float) $t->km_rate,
                'base_amount'                  => (float) $t->base_amount,
                'peak_surcharge'               => (float) $t->peak_surcharge,
                'total_amount'                 => (float) $t->total_amount,
                'notes'                        => $t->notes,
            ]),
            'risk'             => $c->riskScore ? [
                'score'          => (float) $c->riskScore->risk_score,
                'level'          => $c->riskScore->risk_level,
                'color'          => $c->riskScore->risk_color,
                'flags'          => $c->riskScore->flags ?? [],
                'explanation'    => $c->riskScore->explanation,
                'is_duplicate'   => $c->riskScore->is_duplicate,
                'is_inflated'    => $c->riskScore->is_inflated,
                'is_splitting'   => $c->riskScore->is_splitting,
                'missing_receipt'=> $c->riskScore->missing_receipt,
                'out_of_policy'  => $c->riskScore->out_of_policy,
            ] : null,
        ]);
    }
}
