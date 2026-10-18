<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\ViewAcrossBusinesses;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinEntity;
use App\Modules\Finance\Accounting\Models\FinInvoice;
use App\Modules\Finance\Accounting\Models\FinJournal;
use App\Modules\Finance\Accounting\Models\FinJournalLine;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Services\CurrentEntity;
use App\Modules\Finance\Accounting\Services\FinanceReportService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4 — the separation is absolute, and the group can still be read.
 *
 * Through Phases 2a and 2b a row with no business stayed visible in all of them. That was
 * deliberate: while the ledger was half converted, hiding a row that escaped stamping would have
 * meant a posted journal line quietly dropping out of a trial balance, and books that silently
 * stop adding up are worse than a row appearing twice.
 *
 * Both halves are converted now, so the case is removed rather than the rule. Every finance table
 * carries `CHECK (company_id IS NULL OR entity_id IS NOT NULL)`: a tenant's row cannot exist
 * without a business, so "no business" can only mean "belongs to nobody" — the system's own
 * accounts, which every tenant posts to.
 *
 * And the other side of an absolute boundary: the tenant who trades under three names eventually
 * needs the three added up.
 */
class BusinessIsolationIsAbsoluteTest extends TestCase
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

    private function postJournal(FinEntity $entity, float $amount): FinJournal
    {
        return $this->as($entity, function () use ($amount) {
            $journal = FinJournal::create([
                'company_id'    => $this->companyId,
                'journal_no'    => 'J4-'.Str::upper(Str::random(8)),
                'journal_date'  => now()->toDateString(),
                'source_module' => 'test',
                'currency'      => 'ZAR',
                'status'        => 'posted',
            ]);

            foreach ([['asset', $amount, 0.0], ['income', 0.0, $amount]] as [$type, $debit, $credit]) {
                FinJournalLine::create([
                    'journal_id'  => $journal->id,
                    'account_id'  => $this->account($type)->id,
                    'company_id'  => $this->companyId,
                    'currency'    => 'ZAR',
                    'debit'       => $debit, 'credit'      => $credit,
                    'base_debit'  => $debit, 'base_credit' => $credit,
                ]);
            }

            return $journal;
        });
    }

    private function account(string $type): FinAccount
    {
        return FinAccount::create([
            'company_id' => $this->companyId,
            'code' => strtoupper(substr($type, 0, 1)).random_int(100000, 999999),
            'name' => 'Isolation fixture — '.$type,
            'type' => $type, 'is_postable' => true, 'is_active' => true,
        ]);
    }

    // ── Absolute ─────────────────────────────────────────────────────────────

    /**
     * The rule the whole feature rests on, stated as the database states it.
     *
     * A row that belongs to a tenant belongs to one of that tenant's businesses. Without this, a
     * row written around the model — a raw import, a console script, a migration — would belong to
     * the tenant and to none of their businesses, and would be readable in every one of them.
     */
    public function test_a_tenants_row_cannot_exist_without_a_business(): void
    {
        $this->expectException(QueryException::class);

        DB::table('fin_invoices')->insert([
            'uuid'         => (string) Str::uuid(),
            'company_id'   => $this->companyId,
            'entity_id'    => null,
            'direction'    => 'ar',
            'number'       => 'INV-'.Str::upper(Str::random(6)),
            'invoice_date' => now()->toDateString(),
            'due_date'     => now()->addDays(30)->toDateString(),
            'currency'     => 'ZAR',
            'status'       => 'draft',
            'total'        => 100,
            'created_at'   => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The rule is on every table that carries a business, not just the one above.
     *
     * Found by the foreign key rather than the column name. "entity_id" is an ordinary name and
     * other modules use it for unrelated things — `crm_comments.entity_id` names whatever record a
     * comment is attached to, `ai_anomaly_flags.entity_id` is a uuid. Matching on the name alone
     * would demand this constraint on twenty tables that have no idea what a trading business is.
     */
    public function test_the_rule_is_on_every_finance_table(): void
    {
        $tables = DB::select("
            SELECT DISTINCT src.relname AS table_name
              FROM pg_constraint AS fk
              JOIN pg_class AS src ON src.oid = fk.conrelid
              JOIN pg_class AS ref ON ref.oid = fk.confrelid
              JOIN pg_attribute AS col ON col.attrelid = fk.conrelid AND col.attnum = fk.conkey[1]
             WHERE fk.contype = 'f' AND ref.relname = 'fin_entities' AND col.attname = 'entity_id'
               AND EXISTS (SELECT 1 FROM pg_attribute AS company
                            WHERE company.attrelid = fk.conrelid AND company.attname = 'company_id'
                              AND company.attnum > 0 AND NOT company.attisdropped)
             ORDER BY src.relname
        ");

        $this->assertNotEmpty($tables, 'No finance table carries a business at all.');

        $missing = [];

        foreach ($tables as $row) {
            $has = DB::selectOne(
                'SELECT 1 AS hit FROM pg_constraint WHERE conname = ?',
                [$row->table_name.'_tenant_row_has_a_business']
            );

            if (! $has) {
                $missing[] = $row->table_name;
            }
        }

        $this->assertSame([], $missing, 'These tables let a tenant keep a row in no business: '.implode(', ', $missing));
    }

    /** The system's own rows are the exception, and they still work. */
    public function test_a_row_that_belongs_to_nobody_is_still_allowed_and_still_visible(): void
    {
        $id = DB::table('fin_accounts')->insertGetId([
            'uuid'           => (string) Str::uuid(),
            'company_id'     => null,
            'entity_id'      => null,
            'code'           => 'SYS'.Str::random(5),
            'name'           => 'Bad Debts',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_postable'    => true, 'is_active' => true,
            'created_at'     => now(), 'updated_at' => now(),
        ]);

        foreach ([$this->mk, $this->move] as $entity) {
            $this->as($entity, fn () => $this->assertTrue(
                FinAccount::whereKey($id)->exists(),
                "A system account is invisible inside {$entity->name}."
            ));
        }
    }

    // ── And the group can still be read ──────────────────────────────────────

    /**
     * Three trading names, one set of figures for the accountant.
     *
     * Nothing special is needed for it: an unresolved business means no filter, in the scope and in
     * the report queries alike. This asserts that remains true, because it is the one behaviour
     * that a future tightening of the scope would silently take away.
     */
    public function test_the_group_view_adds_the_businesses_together(): void
    {
        $this->postJournal($this->mk, 20000);
        $this->postJournal($this->move, 5000);

        $reports = app(FinanceReportService::class);

        $mine  = $this->as($this->mk, fn () => $reports->trialBalance($this->companyId));
        $group = app(CurrentEntity::class)->run(null, fn () => $reports->trialBalance($this->companyId));

        $this->assertSame(20000.0, (float) $mine['total_debit']);
        $this->assertSame(25000.0, (float) $group['total_debit'], 'The group view is not the businesses added together.');
        $this->assertTrue($group['balanced']);
    }

    public function test_the_group_view_is_asked_for_on_the_url_and_not_remembered(): void
    {
        $this->as($this->mk, function () {
            $party = FinParty::create(['kind' => 'customer', 'name' => 'Acme Mining']);

            FinInvoice::create([
                'company_id' => $this->companyId, 'party_id' => $party->id, 'direction' => 'ar',
                'number' => 'INV-'.Str::upper(Str::random(6)),
                'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
                'currency' => 'ZAR', 'status' => 'open', 'total' => 1000,
            ]);
        });

        app(CurrentEntity::class)->run($this->move->id, function () {
            $middleware = app(ViewAcrossBusinesses::class);

            $inside = null;

            // The middleware hands a Response back, so what the test wants to look at is captured
            // on the way through rather than returned.
            $middleware->handle(
                \Illuminate\Http\Request::create('/finance/accounting/reports', 'GET', [ViewAcrossBusinesses::FLAG => 1]),
                function () use (&$inside) {
                    $inside = FinInvoice::count();

                    return new \Illuminate\Http\Response;
                }
            );

            $this->assertSame(1, $inside, 'The group view did not lift the filter.');

            // And the moment the request is over, the business is back.
            $this->assertSame(0, FinInvoice::count(), 'The group view leaked past the request that asked for it.');
        });
    }

    /**
     * Reading only.
     *
     * With no business resolved a write would be a row belonging to a tenant and to none of their
     * businesses, which the constraint above refuses outright — a database error where a person
     * needs a sentence. So the group view never applies to anything but a GET.
     */
    public function test_the_group_view_is_not_applied_to_a_write(): void
    {
        app(CurrentEntity::class)->run($this->mk->id, function () {
            $middleware = app(ViewAcrossBusinesses::class);

            $seen = null;

            $middleware->handle(
                \Illuminate\Http\Request::create('/finance/accounting/invoices', 'POST', [ViewAcrossBusinesses::FLAG => 1]),
                function () use (&$seen) {
                    $seen = app(CurrentEntity::class)->id();

                    return new \Illuminate\Http\Response;
                }
            );

            $this->assertSame($this->mk->id, $seen, 'A POST was allowed into the group view, where it has no business to write to.');
        });
    }
}
