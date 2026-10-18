<?php

namespace App\Modules\Finance\Accounting\Services;

use App\Modules\Finance\Accounting\Mail\FinanceDocumentMail;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinRecurringInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Recurring / subscription invoicing: templates + schedule, turned into real
 * invoices (posted through the shared engine) by a daily job.
 */
class RecurringInvoiceService
{
    public function __construct(
        private ArApService $arap,
        private FinanceDocumentService $docs,
    ) {}

    public function save(array $data, ?FinRecurringInvoice $recurring = null): FinRecurringInvoice
    {
        $party = FinParty::findOrFail($data['party_id']);
        $start = Carbon::parse($data['start_date'] ?? now());

        return DB::transaction(function () use ($data, $recurring, $party, $start) {
            $recurring ??= new FinRecurringInvoice(['company_id' => $party->company_id, 'created_by' => Auth::id()]);
            $recurring->fill([
                'party_id' => $party->id, 'name' => $data['name'] ?? null,
                'currency' => $data['currency'] ?? config('finance.base_currency', 'ZAR'),
                'frequency' => $data['frequency'], 'interval' => (int) ($data['interval'] ?? 1),
                'start_date' => $start,
                'next_run_date' => $recurring->exists ? $recurring->next_run_date : $start,
                'end_date' => isset($data['end_date']) && $data['end_date'] ? Carbon::parse($data['end_date']) : null,
                'status' => $data['status'] ?? ($recurring->status ?? 'active'),
                'auto_email' => (bool) ($data['auto_email'] ?? false),
                'description' => $data['description'] ?? null,
            ])->save();

            $recurring->lines()->delete();
            foreach ($data['lines'] as $l) {
                $recurring->lines()->create([
                    'account_id' => (int) $l['account_id'], 'item_id' => $l['item_id'] ?? null,
                    'description' => $l['description'] ?? '', 'quantity' => round((float) ($l['quantity'] ?? 1), 4),
                    'unit_price' => round((float) ($l['unit_price'] ?? 0), 4), 'tax_rate' => round((float) ($l['tax_rate'] ?? 0), 3),
                ]);
            }

            return $recurring->fresh('lines');
        });
    }

    /**
     * Generate all invoices due today (catching up any missed cycles). Returns count.
     *
     * $companyId scopes the sweep to one tenant, and the daily command passes it for every tenant
     * in turn. It matters because CompanyScope is inactive in the console: an unscoped call reads
     * every customer's schedules at once and then creates invoices for them with no tenant applied,
     * so whatever the invoice engine resolves by lookup — party, account, numbering sequence — is
     * resolved against the whole estate rather than against the customer being billed.
     */
    public function generateDue(?int $companyId = null): int
    {
        $today = Carbon::today();
        $count = 0;

        FinRecurringInvoice::withoutGlobalScopes()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->with('lines', 'party')->where('status', 'active')
            ->whereDate('next_run_date', '<=', $today)->get()
            ->each(function (FinRecurringInvoice $r) use ($today, &$count) {
                $guard = 0;
                while ($r->status === 'active' && Carbon::parse($r->next_run_date)->lte($today) && $guard++ < 60) {
                    $this->generateOne($r, Carbon::parse($r->next_run_date));
                    $r->refresh();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Generate one invoice for the schedule (dated $on), advance the next run.
     *
     * Pinned to the business the schedule belongs to, for everything inside: the invoice, its
     * lines, the journal it posts and the number it takes. The cron has no session to read one
     * from, and where the tenant context does supply one it is that tenant's default — so a
     * schedule set up under Mkhaya Move would quietly bill under Mkhaya MK, on Mkhaya MK's
     * numbering and letterhead, every month, without anybody being told.
     */
    public function generateOne(FinRecurringInvoice $recurring, ?Carbon $on = null): FinInvoice
    {
        return app(CurrentEntity::class)->run(
            $recurring->entity_id,
            fn () => $this->generateOneForItsEntity($recurring, $on)
        );
    }

    private function generateOneForItsEntity(FinRecurringInvoice $recurring, ?Carbon $on): FinInvoice
    {
        $on ??= Carbon::today();

        $invoice = $this->arap->createInvoice([
            'party_id' => $recurring->party_id, 'direction' => 'receivable', 'currency' => $recurring->currency,
            'invoice_date' => $on, 'description' => $recurring->description ?: ('Recurring: '.($recurring->name ?: $recurring->party->name)),
            'lines' => $recurring->lines->map(fn ($l) => [
                'account_id' => $l->account_id, 'item_id' => $l->item_id, 'description' => $l->description,
                'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate,
            ])->all(),
        ]);

        $next = $recurring->advance(Carbon::parse($recurring->next_run_date));
        $ended = $recurring->end_date && $next->gt(Carbon::parse($recurring->end_date));
        $recurring->update([
            'next_run_date' => $next,
            'generated_count' => $recurring->generated_count + 1,
            'status' => $ended ? 'ended' : 'active',
        ]);

        if ($recurring->auto_email && $recurring->party->email) {
            try {
                Mail::to($recurring->party->email)->send(new FinanceDocumentMail(
                    "Invoice {$invoice->number}", $recurring->party->name,
                    "<p>Please find attached invoice <strong>{$invoice->number}</strong>.</p>",
                    $this->docs->invoicePdf($invoice)->output(), "{$invoice->number}.pdf"
                ));
            } catch (\Throwable $e) {
                Log::warning('Recurring invoice email failed', ['recurring' => $recurring->id, 'error' => $e->getMessage()]);
            }
        }

        return $invoice;
    }
}
