<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Operations\ProjectManagement\Models\Invoice;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Services\InvoicingService;
use App\Modules\Operations\ProjectManagement\Services\ProjectLedgerPosition;
use App\Modules\Tenancy\Models\Company;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Support\Str;

/**
 * What a project's clients actually owe.
 *
 * Project Management could always say what it had billed and never what had been paid, because an
 * issued invoice had no financial life after it left — no paid state, no receipt, no ageing. So
 * "outstanding" on a project screen and "outstanding" to the person chasing the money were two
 * different numbers, and only one of them was in the books.
 *
 * The distinction this must not lose: `professional_fee_outstanding` on the monthly report is fee
 * *left to bill*, a forecasting number. `outstanding` here is money *owed*, a debtors number. A job
 * can have nothing left to bill and a large balance outstanding, and a report that confuses the two
 * tells somebody the work is finished when the money is still out.
 */
class ProjectLedgerPositionTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '4100')->exists()) {
            (new FinanceGlSeeder())->run();
        }

        $this->company = Company::create(['name' => 'Aseda Position']);
        $this->entitleCompany($this->company);

        $this->project = $this->asCompany($this->company, fn () => $this->makeProject([
            'code' => 'MP24001', 'status' => 'active', 'currency' => 'ZAR',
        ]));
    }

    private function position(): array
    {
        return $this->asCompany($this->company, fn () => app(ProjectLedgerPosition::class)->forProject($this->project));
    }

    /** A PM account already linked to a receivable of the given shape. */
    private function billed(float $total, float $paid, ?string $dueDate = null, string $status = 'open'): FinInvoice
    {
        return $this->asCompany($this->company, function () use ($total, $paid, $dueDate, $status) {
            $party = FinParty::create([
                'kind' => 'customer', 'code' => 'C' . Str::random(6),
                'name' => 'Client ' . Str::random(4), 'credit_terms_days' => 30,
            ]);

            $receivable = FinInvoice::create([
                'party_id' => $party->id, 'direction' => 'receivable', 'doc_type' => 'invoice',
                'number' => 'INV-' . Str::random(6), 'invoice_date' => now()->subDays(60),
                // NOT NULL on the table. Future by default, so a test that is not about
                // ageing does not accidentally make everything overdue.
                'due_date' => $dueDate ?? now()->addDays(30)->toDateString(), 'currency' => 'ZAR', 'status' => $status,
                'subtotal' => $total, 'tax_total' => 0, 'total' => $total, 'amount_paid' => $paid,
            ]);

            $invoice = app(InvoicingService::class)->startNextAccount($this->project);
            $invoice->forceFill([
                'ledger_invoice_id' => $receivable->id,
                'ledger_reference'  => $receivable->number,
            ])->save();

            return $receivable;
        });
    }

    // ── The question that could not be answered before ───────────────────────

    public function test_it_reports_what_has_been_paid_and_what_is_still_owed(): void
    {
        $this->billed(total: 100000, paid: 40000);

        $position = $this->position();

        $this->assertTrue($position['available']);
        $this->assertEqualsWithDelta(100000.0, $position['invoiced'], 0.01);
        $this->assertEqualsWithDelta(40000.0, $position['paid'], 0.01);
        $this->assertEqualsWithDelta(60000.0, $position['outstanding'], 0.01);
    }

    public function test_it_adds_up_across_the_accounts_on_a_job(): void
    {
        $this->billed(total: 100000, paid: 100000);
        $this->billed(total: 50000, paid: 0);

        $position = $this->position();

        $this->assertSame(2, $position['invoices']);
        $this->assertEqualsWithDelta(150000.0, $position['invoiced'], 0.01);
        $this->assertEqualsWithDelta(50000.0, $position['outstanding'], 0.01);
    }

    // ── Ageing ───────────────────────────────────────────────────────────────

    /** Overdue is the number somebody acts on; outstanding alone does not say whether it is late. */
    public function test_it_separates_overdue_from_merely_unpaid(): void
    {
        $this->billed(total: 30000, paid: 0, dueDate: now()->subDays(45)->toDateString());
        $this->billed(total: 20000, paid: 0, dueDate: now()->addDays(15)->toDateString());

        $position = $this->position();

        $this->assertEqualsWithDelta(50000.0, $position['outstanding'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $position['overdue'], 0.01,
            'An invoice not yet due was counted as overdue.');
        $this->assertSame(45, $position['oldest_unpaid_days']);
    }

    /** A settled invoice is not overdue however long ago it was due. */
    public function test_a_paid_invoice_is_never_overdue(): void
    {
        $this->billed(total: 30000, paid: 30000, dueDate: now()->subDays(90)->toDateString());

        $position = $this->position();

        $this->assertEqualsWithDelta(0.0, $position['overdue'], 0.01);
        $this->assertNull($position['oldest_unpaid_days']);
    }

    // ── What must not be counted ─────────────────────────────────────────────

    /** A cancelled receivable is not a debt. */
    public function test_a_voided_receivable_is_not_counted(): void
    {
        $this->billed(total: 100000, paid: 0, status: 'void');

        $this->assertFalse($this->position()['available']);
    }

    /**
     * Scoped to this project's own invoices.
     *
     * The same client may be billed on four jobs and from other modules entirely. Attributing all
     * of it here would make every one of those projects look four times as exposed as it is.
     */
    public function test_another_projects_debt_is_not_counted_here(): void
    {
        $other = $this->asCompany($this->company, fn () => $this->makeProject(['code' => 'MP24002']));

        $this->billed(total: 100000, paid: 0);

        $position = $this->asCompany($this->company, fn () => app(ProjectLedgerPosition::class)->forProject($other));

        $this->assertFalse($position['available'], "Another job's debt appeared on this one.");
        $this->assertEqualsWithDelta(0.0, $position['outstanding'], 0.01);
    }

    /** A project billed only in PM, with Accounting never involved, has nothing to report. */
    public function test_a_project_with_no_receivables_reports_nothing_rather_than_zero_facts(): void
    {
        $this->asCompany($this->company, fn () => app(InvoicingService::class)->startNextAccount($this->project));

        $position = $this->position();

        $this->assertFalse($position['available'],
            'A project with no receivables claimed to know its debtor position.');
        $this->assertSame(0, $position['invoices']);
    }
}
