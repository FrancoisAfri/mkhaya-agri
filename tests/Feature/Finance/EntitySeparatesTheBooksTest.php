<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinBankAccount;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinExchangeRate;
use App\Modules\Finance\Accounting\Models\FinFiscalYear;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinNumberSeries;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinQuote;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2b — one business's books are one business's books.
 *
 * 2a separated what a business is set up with. This is about what it does: the quotes it sends,
 * the invoices it raises, the journals those post and the periods it closes. The failure it
 * prevents is quiet and expensive — Mkhaya Move's revenue sitting inside Mkhaya MK's income
 * statement, found by whoever reconciles to the bank, weeks later, or by SARS.
 *
 * Three rules, and each has its own section below:
 *
 *   Separate   A document raised in one business is not readable in another.
 *   Its own    Each business numbers its documents from 1, and closes its own periods.
 *   Coherent   A line cannot sit in a different business from the document it is on.
 */
class EntitySeparatesTheBooksTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private FinEntity $mk;

    private FinEntity $move;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $this->mk   = $this->entity('Mkhaya MK');
        $this->move = $this->entity('Mkhaya Move');
    }

    protected function tearDown(): void
    {
        app(CurrentEntity::class)->clear();
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function entity(string $name): FinEntity
    {
        return FinEntity::withoutGlobalScopes()->create([
            'company_id' => $this->companyId,
            'name'       => $name.' '.Str::random(4),
            'status'     => 'active',
        ]);
    }

    private function as(FinEntity $entity, callable $callback)
    {
        return app(CurrentEntity::class)->run($entity->id, $callback);
    }

    /** A postable account in whichever business is selected. */
    private function account(string $type): FinAccount
    {
        return FinAccount::create([
            'company_id' => $this->companyId,
            'code' => strtoupper(substr($type, 0, 1)).random_int(10000, 99999),
            'name' => 'Books fixture — '.$type,
            'type' => $type, 'is_postable' => true, 'is_active' => true,
        ]);
    }

    /** A posted journal with one balanced pair of lines. */
    private function postJournal(FinEntity $entity, float $amount): FinJournal
    {
        return $this->as($entity, function () use ($amount) {
            // Two accounts, as a real journal has: one debited, one credited. Both sides on one
            // account net to zero, and the trial balance would then show nothing at all.
            $debitAccount  = $this->account('asset');
            $creditAccount = $this->account('income');

            $journal = FinJournal::create([
                'company_id'    => $this->companyId,
                'journal_no'    => 'JB-'.Str::upper(Str::random(8)),
                'journal_date'  => now()->toDateString(),
                'source_module' => 'test',
                'currency'      => 'ZAR',
                'status'        => 'posted',
            ]);

            // base_debit/base_credit are what the reports sum — the base-currency columns.
            foreach ([[$debitAccount, $amount, 0.0], [$creditAccount, 0.0, $amount]] as [$account, $debit, $credit]) {
                FinJournalLine::create([
                    'journal_id'  => $journal->id,
                    'account_id'  => $account->id,
                    'company_id'  => $this->companyId,
                    'currency'    => 'ZAR',
                    'debit'       => $debit,       'credit'      => $credit,
                    'base_debit'  => $debit,       'base_credit' => $credit,
                ]);
            }

            return $journal;
        });
    }

    // ── Separate ─────────────────────────────────────────────────────────────

    public function test_an_invoice_raised_in_one_business_is_not_readable_in_the_other(): void
    {
        $invoice = $this->as($this->mk, function () {
            $party = FinParty::create(['kind' => 'customer', 'name' => 'Acme Mining']);

            return FinInvoice::create([
                'company_id'   => $this->companyId,
                'party_id'     => $party->id,
                'direction'    => 'ar',
                'number'       => 'INV-'.Str::upper(Str::random(6)),
                'invoice_date' => now()->toDateString(),
                'due_date'     => now()->addDays(30)->toDateString(),
                'currency'     => 'ZAR',
                'status'       => 'posted',
                'total'        => 1150.00,
            ]);
        });

        $this->as($this->move, function () use ($invoice) {
            $this->assertFalse(
                FinInvoice::whereKey($invoice->id)->exists(),
                "Mkhaya Move can read Mkhaya MK's invoice."
            );
            $this->assertSame(0, FinInvoice::count(), "Mkhaya Move's invoice list is not empty.");
        });
    }

    public function test_a_quote_raised_in_one_business_is_not_readable_in_the_other(): void
    {
        $quote = $this->as($this->mk, function () {
            $party = FinParty::create(['kind' => 'customer', 'name' => 'Acme Mining']);

            return FinQuote::create([
                'company_id' => $this->companyId,
                'party_id'   => $party->id,
                'number'     => 'QUO-'.Str::upper(Str::random(6)),
                'quote_date' => now()->toDateString(),
                'currency'   => 'ZAR',
                'status'     => 'draft',
                'total'      => 500.00,
            ]);
        });

        $this->as($this->move, fn () => $this->assertFalse(FinQuote::whereKey($quote->id)->exists()));
    }

    /**
     * The one this whole phase exists for.
     *
     * A trial balance that silently includes another business's journals is wrong in a way nobody
     * reading it can see.
     */
    public function test_one_businesss_journals_are_not_in_the_others_ledger(): void
    {
        $theirs = $this->postJournal($this->mk, 20000);

        $this->as($this->move, function () use ($theirs) {
            $this->assertFalse(
                FinJournal::whereKey($theirs->id)->exists(),
                "Mkhaya Move's ledger contains Mkhaya MK's journal."
            );

            $this->assertSame(
                0.0,
                (float) FinJournalLine::sum('debit'),
                "Mkhaya Move's trial balance is carrying Mkhaya MK's postings."
            );
        });

        // And the business that posted it still has it.
        $this->as($this->mk, function () use ($theirs) {
            $this->assertTrue(FinJournal::whereKey($theirs->id)->exists());
            $this->assertSame(20000.0, (float) FinJournalLine::sum('debit'));
        });
    }

    public function test_bank_accounts_and_fiscal_years_are_separated_too(): void
    {
        [$bank, $year] = $this->as($this->mk, fn () => [
            FinBankAccount::create([
                'company_id' => $this->companyId, 'name' => 'FNB Cheque',
                // A bank account is a window onto a GL account, and the column is required.
                'account_id' => FinAccount::create([
                    'company_id' => $this->companyId,
                    'code' => 'B'.random_int(10000, 99999), 'name' => 'Bank — fixture',
                    'type' => 'asset', 'is_postable' => true, 'is_active' => true,
                ])->id,
                'type' => 'bank', 'currency' => 'ZAR', 'is_active' => true,
            ]),
            FinFiscalYear::create([
                'company_id' => $this->companyId, 'label' => 'FY'.random_int(2000, 2999),
                'start_date' => '2026-03-01', 'end_date' => '2027-02-28', 'status' => 'open',
            ]),
        ]);

        $this->as($this->move, function () use ($bank, $year) {
            $this->assertFalse(FinBankAccount::whereKey($bank->id)->exists(), 'Bank accounts leak between businesses.');
            $this->assertFalse(FinFiscalYear::whereKey($year->id)->exists(), 'Fiscal years leak between businesses.');
        });
    }

    /** Market data is not a business's own. */
    public function test_exchange_rates_stay_shared_across_a_tenants_businesses(): void
    {
        $this->assertFalse(
            method_exists(FinExchangeRate::class, 'getEntityColumn'),
            'fin_exchange_rates was made per entity. A rand/dollar rate is the same number for '
            .'every business in the country, and a copy per entity is the same figure maintained '
            .'several times over.'
        );
    }

    // ── Its own ──────────────────────────────────────────────────────────────

    public function test_each_business_numbers_its_documents_from_one(): void
    {
        $mine  = FinNumberSeries::next($this->companyId, 'quote', 'QUO-', $this->mk->id);
        $yours = FinNumberSeries::next($this->companyId, 'quote', 'QUO-', $this->move->id);

        $this->assertSame($mine, $yours, 'Each business numbers its own quotes, so the first of each is QUO-000001.');

        // And within one business the sequence still advances.
        $second = FinNumberSeries::next($this->companyId, 'quote', 'QUO-', $this->mk->id);
        $this->assertNotSame($mine, $second, 'A business re-issued the same document number.');
    }

    public function test_the_numbering_follows_the_business_being_worked_in(): void
    {
        $mine = $this->as($this->mk, fn () => FinNumberSeries::next($this->companyId, 'invoice', 'INV-'));

        $series = FinNumberSeries::withoutGlobalScopes()
            ->where('company_id', $this->companyId)->where('doc_type', 'invoice')
            ->where('entity_id', $this->mk->id)->first();

        $this->assertNotNull($series, 'The series was not filed against the business it was issued for.');
        $this->assertStringStartsWith('INV-', $mine);
    }

    // ── Coherent ─────────────────────────────────────────────────────────────

    /**
     * The constraint the composite foreign key exists for.
     *
     * A journal whose lines sit in two businesses balances in neither, and every application check
     * that looks at one row at a time would let it through.
     */
    public function test_a_journal_line_cannot_be_moved_to_another_business(): void
    {
        $journal = $this->postJournal($this->mk, 500);

        $line = FinJournalLine::withoutGlobalScopes()->where('journal_id', $journal->id)->first();
        $this->assertNotNull($line);

        $this->expectException(QueryException::class);

        DB::table('fin_journal_lines')->where('id', $line->id)->update(['entity_id' => $this->move->id]);
    }

    public function test_moving_a_journal_carries_its_lines_with_it(): void
    {
        $journal = $this->postJournal($this->mk, 750);

        DB::table('fin_journals')->where('id', $journal->id)->update(['entity_id' => $this->move->id]);

        $strays = FinJournalLine::withoutGlobalScopes()
            ->where('journal_id', $journal->id)
            ->where('entity_id', '!=', $this->move->id)
            ->count();

        $this->assertSame(0, $strays, 'The journal moved business and left its lines behind.');
    }

    public function test_every_line_of_a_journal_is_stamped_with_its_journals_business(): void
    {
        $journal = $this->postJournal($this->move, 1200);

        $lines = FinJournalLine::withoutGlobalScopes()->where('journal_id', $journal->id)->get();

        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertSame($this->move->id, (int) $line->entity_id);
        }
    }

    /**
     * The trial balance is one business's trial balance.
     *
     * The models carry the entity scope, but a report does not read through the models — summing a
     * ledger through Eloquent would mean hydrating every line — so the totals come from the query
     * builder, which has never heard of a global scope. Everything else could be perfectly
     * separated and this one number would still be the two businesses added together, which is the
     * version somebody would actually believe: a trial balance is what the other reports get
     * checked against.
     */
    public function test_the_trial_balance_shows_one_business_at_a_time(): void
    {
        $this->postJournal($this->mk, 20000);
        $this->postJournal($this->move, 5000);

        $reports = app(FinanceReportService::class);

        $mine  = $this->as($this->mk, fn () => $reports->trialBalance($this->companyId));
        $yours = $this->as($this->move, fn () => $reports->trialBalance($this->companyId));

        $this->assertSame(20000.0, (float) $mine['total_debit'], "Mkhaya MK's trial balance is not its own.");
        $this->assertSame(5000.0, (float) $yours['total_debit'], "Mkhaya Move's trial balance is not its own.");

        $this->assertTrue($mine['balanced']);
        $this->assertTrue($yours['balanced']);
    }

    /**
     * A figure read straight from the table, with no model in the way.
     *
     * The trial balance above survives on its own, because its totals are keyed by account and the
     * account list is entity-scoped through the model. Plenty of figures have no such protection:
     * the executive overview sums `fin_invoices` directly, and so do the debtor metrics, the VAT
     * return and the statements. Those queries go through the query builder, which has never heard
     * of a global scope, so each needs the filter naming the business explicitly — and this is the
     * test that fails if somebody removes it.
     */
    public function test_a_figure_summed_straight_from_the_table_is_one_businesss_own(): void
    {
        foreach ([[$this->mk, 1000.00], [$this->move, 250.00]] as [$entity, $total]) {
            $this->as($entity, function () use ($total) {
                $party = FinParty::create(['kind' => 'customer', 'name' => 'Acme Mining']);

                FinInvoice::create([
                    'company_id'   => $this->companyId,
                    'party_id'     => $party->id,
                    'direction'    => 'ar',
                    'number'       => 'INV-'.Str::upper(Str::random(6)),
                    'invoice_date' => now()->toDateString(),
                    'due_date'     => now()->addDays(30)->toDateString(),
                    'currency'     => 'ZAR',
                    'status'       => 'open',
                    'total'        => $total,
                ]);
            });
        }

        $outstanding = fn (FinEntity $entity) => $this->as($entity, fn () => (float) DB::table('fin_invoices')
            ->tap(fn ($q) => FinanceReportService::scopeToEntity($q, 'entity_id'))
            ->where('company_id', $this->companyId)
            ->whereNotIn('status', ['paid', 'cancelled', 'void', 'draft'])
            ->sum('total'));

        $this->assertSame(1000.00, $outstanding($this->mk), "Mkhaya MK's outstanding total includes another business's invoices.");
        $this->assertSame(250.00, $outstanding($this->move), "Mkhaya Move's outstanding total includes another business's invoices.");
    }

    // ── The monitor sees each business ───────────────────────────────────────

    /**
     * Two businesses whose imbalances cancel are both reported.
     *
     * This is the reason `finance:parity` runs per company rather than over the whole estate,
     * repeated one level down. Debits and credits are signed: Mkhaya MK 20,000 debit-heavy and
     * Mkhaya Move 20,000 credit-heavy sum to exactly zero inside the one company, so a check that
     * looked at the company as a whole would report OK on two sets of books that neither of them
     * balances — and the monitor that exists to catch drift would be the thing hiding it.
     */
    public function test_two_businesses_whose_imbalances_cancel_are_both_reported(): void
    {
        $this->postImbalanced($this->mk, 20000);
        $this->postImbalanced($this->move, -20000);

        // The trap is really set: together they balance to the cent.
        $net = FinJournalLine::withoutGlobalScopes()
            ->whereIn('entity_id', [$this->mk->id, $this->move->id])
            ->selectRaw('COALESCE(SUM(base_debit), 0) - COALESCE(SUM(base_credit), 0) AS net')
            ->value('net');

        $this->assertSame(0.0, (float) $net, 'The fixture no longer cancels, so this would pass for the wrong reason.');

        $this->artisan('finance:parity', ['--company' => $this->companyId])
            ->expectsOutputToContain($this->mk->name)
            ->expectsOutputToContain($this->move->name)
            ->assertFailed();
    }

    /** A journal that does not balance, in one business. */
    private function postImbalanced(FinEntity $entity, float $amount): void
    {
        $this->as($entity, function () use ($amount) {
            $account = $this->account('asset');

            $journal = FinJournal::create([
                'company_id'    => $this->companyId,
                'journal_no'    => 'JP-'.Str::upper(Str::random(8)),
                'journal_date'  => now()->toDateString(),
                'source_module' => 'test',
                'currency'      => 'ZAR',
                'status'        => 'posted',
            ]);

            $debit  = $amount > 0 ? $amount : 0.0;
            $credit = $amount < 0 ? abs($amount) : 0.0;

            FinJournalLine::create([
                'journal_id'  => $journal->id,
                'account_id'  => $account->id,
                'company_id'  => $this->companyId,
                'currency'    => 'ZAR',
                'debit'       => $debit,  'credit'      => $credit,
                'base_debit'  => $debit,  'base_credit' => $credit,
            ]);
        });
    }

    // ── Nothing left behind ──────────────────────────────────────────────────

    public function test_the_migration_left_no_row_of_this_tenant_without_a_business(): void
    {
        $tables = [
            'fin_invoices', 'fin_quotes', 'fin_sales_orders', 'fin_journals', 'fin_payments',
            'fin_bank_accounts', 'fin_fiscal_years', 'fin_fiscal_periods', 'fin_number_series',
            'fin_stock_items', 'fin_assets', 'fin_budgets', 'fin_expenses', 'fin_contracts',
        ];

        foreach ($tables as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $orphans = DB::table($table)
                ->where('company_id', $this->companyId)
                ->whereNull('entity_id')
                ->count();

            $this->assertSame(0, $orphans, "{$table} has {$orphans} row(s) that belong to no business.");
        }
    }
}
