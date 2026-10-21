<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinDispute;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Customer disputes: open ones first, each against its invoice; resolved with a credit note or rejected. */
class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Finance/Accounting/Disputes', [
            'disputes' => FinDispute::query()->with(['party:id,name', 'invoice:id,number,total,amount_paid'])
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(300)->get()
                ->map(fn (FinDispute $d) => ['id' => $d->id, 'party' => $d->party?->name, 'invoice' => $d->invoice?->number, 'kind' => $d->kind,
                    'amount' => $d->amount !== null ? (float) $d->amount : null, 'description' => $d->description, 'status' => $d->status,
                    'resolution' => $d->resolution, 'subject_type' => $d->subject_type, 'credit_note' => $d->credit_note_id
                        ? FinInvoice::query()->whereKey($d->credit_note_id)->value('number') : null,
                    'raised_at' => $d->created_at?->toDateString(), 'resolved_at' => $d->resolved_at?->toDateString()]),
            'customers' => FinParty::customers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['value' => $p->id, 'title' => $p->name]),
            'invoices' => FinInvoice::query()->where('direction', 'receivable')->where('doc_type', 'invoice')->where('status', '!=', 'void')
                ->orderByDesc('invoice_date')->limit(500)->get(['id', 'number', 'party_id', 'total'])
                ->map(fn ($i) => ['value' => $i->id, 'title' => "{$i->number} · ".number_format((float) $i->total, 2), 'party_id' => $i->party_id]),
            'kinds' => FinDispute::KINDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['party_id' => 'required|integer|exists:fin_parties,id', 'invoice_id' => 'nullable|integer',
            'kind' => ['required', Rule::in(FinDispute::KINDS)], 'amount' => 'nullable|numeric|gte:0', 'description' => 'required|string|max:1000']);

        try {
            $this->disputes->raise(FinParty::query()->findOrFail($data['party_id']), $data, $request->user()->id);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['kind' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Dispute recorded; the invoice is held from reminders.');
    }

    public function resolve(Request $request, FinDispute $dispute): RedirectResponse
    {
        $data = $request->validate(['outcome' => ['required', Rule::in(['resolved', 'rejected'])], 'resolution' => 'required|string|max:1000',
            'credit' => 'nullable|numeric|gt:0']);

        try {
            $this->disputes->resolve($dispute, $data['outcome'], $data['resolution'], isset($data['credit']) ? (float) $data['credit'] : null, $request->user()->id);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['credit' => [$e->getMessage()]]);
        }

        return back()->with('success', 'Dispute closed.');
    }
}
