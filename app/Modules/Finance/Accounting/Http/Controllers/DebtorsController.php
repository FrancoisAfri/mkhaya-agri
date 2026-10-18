<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Jobs\SendDebtorReminderJob;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinNonpaymentReason;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSetting;
use App\Modules\Finance\Accounting\Services\ArApService;
use App\Modules\Finance\Accounting\Services\DebtorMetricsService;
use App\Modules\Finance\Accounting\Services\DebtorsService;
use App\Modules\Finance\Accounting\Services\DunningService;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DebtorsController extends Controller
{
    public function __construct(
        private DebtorsService $debtors,
        private DunningService $dunning,
        private DebtorMetricsService $metrics,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        return Inertia::render('Modules/Finance/Accounting/DebtorsManager', [
            'filters'    => $filters,
            'data'       => $this->debtors->outstanding($filters),
            'metrics'    => $this->metrics->metrics(),
            'reasons'    => FinNonpaymentReason::where('is_active', true)->orderBy('label')->get(['code', 'label']),
            'collectors' => $this->collectors(),
            'customers'  => $this->customers(),
            'config'     => [
                'channels'      => FinSetting::current(app(CurrentCompany::class)->id())->channels(),
                'base_currency' => config('finance.base_currency', 'ZAR'),
            ],
        ]);
    }

    /** Read + normalise the grid filters. */
    private function filters(Request $request): array
    {
        return [
            'party_id'     => $request->integer('party_id') ?: null,
            'bucket'       => $request->string('bucket')->toString() ?: 'all',
            'overdue_only' => $request->boolean('overdue_only'),
            'assigned_to'  => $request->string('assigned_to')->toString() ?: null,
            'status'       => $request->string('status')->toString() ?: null,
            'search'       => $request->string('search')->toString() ?: null,
            'sort'         => $request->string('sort')->toString() ?: 'priority',
            'dir'          => $request->string('dir')->toString() ?: 'desc',
            'per_page'     => $request->integer('per_page') ?: 25,
            'page'         => $request->integer('page') ?: 1,
        ];
    }

    public function timeline(FinInvoice $invoice): JsonResponse
    {
        return response()->json(['timeline' => $this->debtors->timeline($invoice)]);
    }

    public function storeActivity(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'type'             => 'required|in:note,promise,reason',
            'body'             => 'nullable|string|max:2000',
            'promised_date'    => 'nullable|date',
            'promised_amount'  => 'nullable|numeric|min:0',
            'reason_code'      => 'nullable|string|max:40',
            'next_action_date' => 'nullable|date',
        ]);
        $this->debtors->logActivity($invoice, $data);

        return back()->with('success', 'Activity logged.');
    }

    /** Queue a single reminder (channel optional; defaults to the invoice's next ladder step). */
    public function sendReminder(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $channel = $request->string('channel')->toString() ?: null;
        SendDebtorReminderJob::dispatch($invoice->id, $invoice->company_id, $channel);

        return back()->with('success', 'Reminder queued.');
    }

    /** Queue reminders for the selected invoices. */
    public function bulkRemind(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids'     => 'required|array|min:1',
            'ids.*'   => 'integer',
            'channel' => 'nullable|in:email,sms,whatsapp',
        ]);
        $invoices = FinInvoice::whereIn('id', $data['ids'])->get(['id', 'company_id']);
        foreach ($invoices as $inv) {
            SendDebtorReminderJob::dispatch($inv->id, $inv->company_id, $data['channel'] ?? null);
        }

        return back()->with('success', $invoices->count() . ' reminder(s) queued.');
    }

    /** Run the dunning ladder now for the current company. */
    public function runReminders(): RedirectResponse
    {
        $r = $this->dunning->runLadder(app(CurrentCompany::class)->id());

        return back()->with('success', "Dunning run complete: {$r['sent']} sent, {$r['skipped']} skipped.");
    }

    public function assign(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['user_id' => 'nullable|integer|exists:users,id']);
        $this->debtors->assign($invoice, $data['user_id'] ?? null);

        return back()->with('success', 'Assignment updated.');
    }

    public function dispute(Request $request, FinInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'disputed' => 'required|boolean',
            'reason'   => 'nullable|string|max:500',
        ]);
        $this->debtors->setDispute($invoice, (bool) $data['disputed'], $data['reason'] ?? null);

        return back()->with('success', $data['disputed'] ? 'Invoice marked as disputed.' : 'Dispute resolved.');
    }

    /** Record a (part) payment against the invoice — proper double-entry via ArApService. */
    public function recordPayment(Request $request, FinInvoice $invoice, ArApService $arap): RedirectResponse
    {
        $data = $request->validate([
            'amount'          => 'required|numeric|min:0.01',
            'payment_date'    => 'nullable|date',
            'bank_account_id' => 'nullable|string',
            'reference'       => 'nullable|string|max:100',
        ]);
        $amount = min((float) $data['amount'], $invoice->outstanding());

        $arap->recordPayment([
            'party_id'        => $invoice->party_id,
            'direction'       => 'receipt',
            'payment_date'    => $data['payment_date'] ?? now()->toDateString(),
            'bank_account_id' => $data['bank_account_id'] ?? null,
            'reference'       => $data['reference'] ?? $invoice->number,
            'allocations'     => [['invoice_id' => $invoice->id, 'amount' => round($amount, 2)]],
        ]);

        $this->debtors->logActivity($invoice->fresh(), [
            'type' => 'note', 'channel' => 'manual',
            'body' => 'Payment recorded: ' . $invoice->currency . ' ' . number_format($amount, 2) . '.',
        ]);

        return back()->with('success', 'Payment recorded.');
    }

    /** Stream a customer statement PDF. */
    public function statement(FinParty $party, FinanceDocumentService $docs): mixed
    {
        return $docs->statementPdf($party)->stream("statement-{$party->code}.pdf");
    }

    /** Email the customer their statement of account (PDF attached). */
    public function emailStatement(FinParty $party, FinanceDocumentService $docs): RedirectResponse
    {
        if (! $party->email) {
            return back()->with('error', 'This customer has no email address.');
        }

        $settings = FinSetting::current(app(CurrentCompany::class)->id());
        $body = '<p>Please find attached your current statement of account. Kindly settle any outstanding balance at your earliest convenience.</p>';

        \Illuminate\Support\Facades\Mail::to($party->email)->send(new \App\Modules\Finance\Accounting\Mail\FinanceDocumentMail(
            'Statement of account — ' . ($settings->company_name ?: config('app.name')),
            $party->name, $body, $docs->statementPdf($party)->output(), "statement-{$party->code}.pdf"
        ));

        if ($inv = FinInvoice::where('party_id', $party->id)->where('direction', 'receivable')->latest('id')->first()) {
            $this->debtors->logActivity($inv, ['type' => 'note', 'channel' => 'email', 'body' => 'Statement emailed to ' . $party->email . '.']);
        }

        return back()->with('success', 'Statement emailed to ' . $party->email . '.');
    }

    /** A single customer's outstanding roll-up (for the customer drawer). */
    public function customer(FinParty $party): JsonResponse
    {
        $invoices = FinInvoice::where('party_id', $party->id)
            ->where('direction', 'receivable')->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])->whereRaw('total - amount_paid > 0.001')
            ->orderBy('due_date')->get();

        return response()->json([
            'party' => [
                'id' => $party->id, 'name' => $party->name, 'email' => $party->email, 'phone' => $party->phone,
                'credit_limit' => (float) $party->credit_limit, 'do_not_contact' => (bool) $party->do_not_contact,
            ],
            'total' => round((float) $invoices->sum(fn ($i) => $i->outstanding()), 2),
            'invoices' => $invoices->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'due' => $i->due_date?->toDateString(),
                'currency' => $i->currency, 'outstanding' => $i->outstanding(),
                'days_overdue' => $i->due_date && $i->due_date->isPast() ? (int) $i->due_date->diffInDays(now()) : 0,
            ])->all(),
        ]);
    }

    /** CSV export of the current (filtered) debtors worklist. */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filters['per_page'] = 5000;
        $rows = $this->debtors->outstanding($filters)['rows'];

        $headers = ['Customer', 'Invoice', 'Invoice Date', 'Due Date', 'Currency', 'Total', 'Outstanding', 'Days Overdue', 'Bucket', 'Status', 'Disputed', 'Assignee', 'Reminders'];

        return ResponseFactory::streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['party'], $r['number'], $r['date'], $r['due'], $r['currency'], $r['total'], $r['outstanding'],
                    $r['days_overdue'], $r['bucket'], $r['status'], $r['disputed'] ? 'yes' : 'no', $r['assignee'], $r['reminders'],
                ]);
            }
            fclose($out);
        }, 'debtors-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function storeReason(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => 'required|string|max:40', 'label' => 'required|string|max:255']);
        FinNonpaymentReason::updateOrCreate(['code' => strtoupper($data['code'])], ['label' => $data['label'], 'is_active' => true]);

        return back()->with('success', 'Reason saved.');
    }

    /** Users assignable as collectors (current company; soft-deleted excluded by the model scope). */
    private function collectors(): array
    {
        return User::with('person')->orderBy('id')->get()
            ->map(fn ($u) => ['id' => $u->id, 'name' => optional($u->person)->first_name
                ? trim($u->person->first_name . ' ' . $u->person->surname) : ($u->email ?? ('User #' . $u->id))])
            ->values()->all();
    }

    /** Customers that currently have an outstanding balance (for the filter). */
    private function customers(): array
    {
        $ids = FinInvoice::query()->where('direction', 'receivable')->where('doc_type', 'invoice')
            ->whereIn('status', ['open', 'part_paid'])->whereRaw('total - amount_paid > 0.001')
            ->distinct()->pluck('party_id');

        return FinParty::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all();
    }
}
