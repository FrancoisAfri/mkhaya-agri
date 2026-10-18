<?php

namespace App\Modules\Finance\Tax\Http\Controllers;

use App\Modules\Finance\Tax\Models\TaxLiability;
use App\Modules\Finance\Tax\Models\TaxPaymentAllocation;
use App\Modules\Finance\Tax\Models\TaxType;
use App\Modules\Finance\Tax\Services\TaxPaymentService;
use App\Modules\Finance\Tax\Support\TaxPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** §13 Tax liabilities and payments. */
class TaxLiabilityController extends TaxController
{
    public function __construct(private readonly TaxPaymentService $payments)
    {
    }

    public function index(Request $request): Response
    {
        $this->allow(TaxPermissions::VIEW);
        $business = $this->businessFilter();
        $status = $request->query('status');

        $rows = TaxLiability::query()->with(['taxType:id,code', 'period:id,uuid,label', 'taxReturn:id,uuid,reference', 'allocations'])
            ->forBusiness($business)
            ->when($status === 'outstanding', fn ($q) => $q->whereIn('status', ['open', 'part_paid']))
            ->when($status && $status !== 'outstanding', fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN status IN ('open','part_paid') THEN 0 ELSE 1 END")->orderBy('due_date')->limit(300)->get();
        $payments = DB::table('fin_payments')->whereIn('id', $rows->flatMap->allocations->pluck('fin_payment_id')->unique())
            ->get(['id', 'number', 'payment_date', 'reference'])->keyBy('id');

        return Inertia::render('Modules/Finance/Tax/Liabilities', [
            'liabilities' => $rows->map(fn (TaxLiability $l) => [
                'uuid' => $l->uuid, 'tax_type' => $l->taxType?->code, 'entity' => $this->entityName($l->entity_id),
                'period' => $l->period?->label, 'return' => $l->taxReturn ? ['uuid' => $l->taxReturn->uuid, 'reference' => $l->taxReturn->reference] : null,
                'source' => $l->source, 'description' => $l->description, 'original' => (string) $l->original_amount,
                'adjustments' => (string) $l->adjustments, 'paid' => (string) $l->paid_amount, 'outstanding' => $l->outstanding(),
                'due_date' => $l->due_date?->toDateString(), 'status' => $l->status, 'evidence' => TaxEvidenceController::listFor($l),
                'overdue' => $l->due_date && $l->due_date->lt(today()) && in_array($l->status, ['open', 'part_paid'], true),
                'allocations' => $l->allocations->map(fn (TaxPaymentAllocation $a) => [
                    'id' => $a->id, 'amount' => (string) $a->amount, 'note' => $a->note, 'reversed' => (bool) $a->reversed_at,
                    'reversal_reason' => $a->reversal_reason, 'at' => $a->allocated_at?->toDateString(),
                    'payment' => ($p = $payments[$a->fin_payment_id] ?? null) ? ['number' => $p->number, 'date' => $p->payment_date, 'reference' => $p->reference] : null,
                ])->values(),
            ]),
            'filters' => ['status' => $status],
            'taxTypes' => TaxType::where('has_payment', true)->orderBy('id')->get(['id', 'code', 'name']),
            'can' => ['allocate' => $request->user()->can(TaxPermissions::RECONCILE), 'record' => $request->user()->can(TaxPermissions::APPROVE)],
        ] + $this->businessProps($business));
    }

    /** Payments recorded in Finance that still have money to allocate (JSON, for the allocate dialog). */
    public function candidates(TaxLiability $liability)
    {
        $this->allow(TaxPermissions::RECONCILE, 'payment.candidates');

        return response()->json($this->payments->candidates($liability)->map(fn ($p) => [
            'id' => $p->id, 'number' => $p->number, 'date' => $p->payment_date, 'amount' => (string) $p->amount,
            'available' => (string) $p->available, 'reference' => $p->reference, 'party' => $p->party,
        ]));
    }

    public function allocate(Request $request, TaxLiability $liability): RedirectResponse
    {
        $this->allow(TaxPermissions::RECONCILE, 'payment.allocate');
        $d = $request->validate(['payment_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->payments->allocate($liability, (int) $d['payment_id'], (string) $d['amount'], $d['note'] ?? null), 'Payment allocated.');
    }

    public function reverse(Request $request, TaxPaymentAllocation $allocation): RedirectResponse
    {
        $this->allow(TaxPermissions::RECONCILE, 'payment.reverse');
        $d = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->attempt(fn () => $this->payments->reverse($allocation, $d['reason']), 'Allocation reversed.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow(TaxPermissions::APPROVE, 'liability.record');
        $d = $request->validate([
            'tax_type_id' => ['required', 'integer', 'exists:tax_types,id'], 'entity_id' => ['nullable', 'integer', $this->businessRule()],
            'source' => ['required', 'in:assessment,interest,penalty'], 'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric'], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->attempt(fn () => $this->payments->recordAssessment($d + ['amount' => (string) $d['amount']]), 'Recorded. Attach the SARS notice to the liability.');
    }

    public function export(): StreamedResponse
    {
        $this->allow(TaxPermissions::VIEW);
        $rows = (function () {
            foreach (TaxLiability::query()->with(['taxType:id,code', 'period:id,label'])->forBusiness($this->businessFilter())->orderBy('due_date')->cursor() as $l) {
                yield [$l->taxType?->code, $this->entityName($l->entity_id), $l->period?->label, $l->description, $l->source,
                    (string) $l->original_amount, (string) $l->adjustments, (string) $l->paid_amount, $l->outstanding(), $l->due_date?->toDateString(), $l->status];
            }
        })();

        return $this->csv('tax-liabilities-' . now()->format('Ymd-His') . '.csv',
            ['Tax', 'Business', 'Period', 'Description', 'Source', 'Original', 'Adjustments', 'Paid', 'Outstanding', 'Due', 'Status'],
            $rows, ['Report' => 'Tax liability register', 'Generated' => now()->toDateTimeString()]);
    }
}
