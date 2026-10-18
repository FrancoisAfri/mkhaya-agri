<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinPaymentBatch;
use App\Modules\Finance\Accounting\Services\PaymentBatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PaymentBatchController extends Controller
{
    public function __construct(private PaymentBatchService $batches)
    {
    }

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Modules/Finance/Accounting/PaymentBatches', [
            'batches' => FinPaymentBatch::with('lines.party', 'maker.person', 'checker.person')
                ->orderByDesc('id')->limit(100)->get()
                ->map(fn (FinPaymentBatch $b) => [
                    'id' => $b->id, 'reference' => $b->reference, 'status' => $b->status,
                    'total' => (float) $b->total, 'payment_date' => $b->payment_date?->toDateString(),
                    'bank_code' => $b->bank_code, 'notes' => $b->notes,
                    'maker' => $this->name($b->maker), 'checker' => $this->name($b->checker),
                    'approved_at' => $b->approved_at?->toDateTimeString(), 'paid_at' => $b->paid_at?->toDateTimeString(),
                    'lines' => $b->lines->map(fn ($l) => ['party' => $l->party->name, 'invoice_id' => $l->invoice_id, 'amount' => (float) $l->amount]),
                ]),
            'openBills' => FinInvoice::with('party')->payable()->open()->whereRaw('total - amount_paid > 0.001')
                ->orderBy('due_date')->get()
                ->map(fn (FinInvoice $i) => [
                    'id' => $i->id, 'number' => $i->number, 'party' => $i->party->name,
                    'due' => $i->due_date?->toDateString(), 'outstanding' => $i->outstanding(),
                    'has_bank' => (bool) $i->party->bank_account,
                ]),
            'banks' => FinAccount::postable()->where('type', 'asset')->orderBy('code')->get(['code', 'name'])
                ->map(fn ($a) => ['code' => $a->code, 'label' => "{$a->code} · {$a->name}"]),
            'can_approve' => (bool) $request->user()?->can(PaymentBatchService::APPROVE_PERMISSION),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bill_ids'     => 'required|array|min:1',
            'bill_ids.*'   => 'integer',
            'bank_code'    => 'nullable|string|max:40',
            'payment_date' => 'nullable|date',
            'notes'        => 'nullable|string|max:500',
        ]);

        try {
            $this->batches->create($data['bill_ids'], $data['bank_code'] ?? null, $data['payment_date'] ?? null, $data['notes'] ?? null);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['batch' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Payment batch created.');
    }

    public function approve(Request $request, FinPaymentBatch $batch): RedirectResponse
    {
        try {
            $this->batches->approve($batch, $request->user());
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['batch' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Batch approved.');
    }

    public function reject(Request $request, FinPaymentBatch $batch): RedirectResponse
    {
        $reason = $request->validate(['reason' => 'required|string|max:300'])['reason'];
        try {
            $this->batches->reject($batch, $reason);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['batch' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Batch rejected.');
    }

    public function pay(FinPaymentBatch $batch): RedirectResponse
    {
        try {
            $this->batches->pay($batch);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['batch' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Batch released — payments posted to the GL.');
    }

    public function pain001(FinPaymentBatch $batch)
    {
        return Response::make($this->batches->pain001($batch), 200, [
            'Content-Type'        => 'application/xml',
            'Content-Disposition' => 'attachment; filename="' . $batch->reference . '-pain001.xml"',
        ]);
    }

    public function csv(FinPaymentBatch $batch)
    {
        return Response::make($this->batches->csv($batch), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $batch->reference . '.csv"',
        ]);
    }

    private function name($user): ?string
    {
        if (! $user) {
            return null;
        }

        return optional($user->person)->first_name ? trim($user->person->first_name . ' ' . $user->person->surname) : $user->email;
    }
}
