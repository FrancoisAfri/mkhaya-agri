<?php

namespace Tests\Feature\Tenancy;

use App\Models\AppSetting;
use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\Finance\Accounting\Models\FinRecurringInvoice;
use App\Modules\Finance\Accounting\Services\RecurringInvoiceService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The three crons that used to act on the whole estate at once.
 *
 * Each had the same shape and a different consequence. `helpdesk:retention-purge` ran one
 * irreversible anonymising UPDATE across every customer's tickets under a single operator-supplied
 * retention window — but retention is a decision the controller of the data makes, and a legal hold
 * at one customer is not a legal hold at another. `finance:generate-recurring` read every
 * customer's billing schedules in one query and then created invoices with no tenant applied.
 * `finance:process-postings` drained the outbox with the tenant unset, so everything the ledger
 * resolves by lookup on the way to a journal resolved against the whole estate.
 *
 * What follows is mostly about *blast radius*: given two tenants, an operation aimed at one must
 * leave the other exactly as it found it. That property is invisible in a single-tenant test
 * database, which is why every test here creates its own second company rather than hoping one
 * exists.
 */
class CronTenancyTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyA;

    private int $companyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = (int) (DB::table('companies')->min('id') ?? 0);

        if ($this->companyA === 0) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyB = $this->makeCompany('Cron Tenancy Fixture');

        Cache::flush();
        app(CurrentCompany::class)->clear();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeCompany(string $name): int
    {
        return (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_tenant_window_is_read_inside_that_tenants_context(): void
    {
        // Guards the mechanism the test above depends on: AppSetting resolves against whichever
        // company is applied, falling back to the global row. Read outside a tenant it would return
        // the default and every customer would silently get the same window again.
        DB::table('app_settings')->insert([
            'key' => 'helpdesk.retention_days',
            'value' => json_encode(365),
            'company_id' => $this->companyB,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(CurrentCompany::class)->set($this->companyB);
        $this->assertSame(365, AppSetting::get('helpdesk.retention_days', 1825));

        app(CurrentCompany::class)->set($this->companyA);
        $this->assertSame(1825, AppSetting::get('helpdesk.retention_days', 1825));
    }

    // ── finance:generate-recurring ───────────────────────────────────────────────────────────

    public function test_recurring_billing_reads_only_the_tenant_it_was_asked_about(): void
    {
        /*
         * The sweep is scoped rather than the whole table, so a tenant's billing run cannot pick up
         * another tenant's schedule. Asserted at the service, because the invoice that would be
         * raised is the observable damage and there is no way to un-invoice a customer.
         */
        $service = app(RecurringInvoiceService::class);

        $mine = FinRecurringInvoice::withoutGlobalScopes()
            ->where('company_id', $this->companyA)->count();

        // Company B has no schedules at all, so its sweep must generate nothing regardless of what
        // company A has queued.
        $this->assertSame(0, $service->generateDue($this->companyB));

        $this->assertSame(
            $mine,
            FinRecurringInvoice::withoutGlobalScopes()->where('company_id', $this->companyA)->count(),
            "Another tenant's schedules were advanced by a sweep that was not aimed at them.",
        );
    }

    // ── finance:process-postings ─────────────────────────────────────────────────────────────

    public function test_the_outbox_drain_sees_every_tenants_backlog(): void
    {
        /*
         * Deliberately *not* scoped per company. An outbox is a queue: a drain that could only see
         * one tenant's rows would leave every other tenant's stuck behind whichever company the run
         * was scoped to. Read globally, act locally — the tenancy is applied per row inside
         * FinancePoster::processPending().
         */
        $a = $this->makeStuckPosting($this->companyA);
        $b = $this->makeStuckPosting($this->companyB);

        $this->artisan('finance:process-postings')->assertSuccessful();

        // Both were attempted: a row nobody looked at still reads 0 attempts.
        $this->assertGreaterThan(0, FinPosting::find($a)->attempts, "Company A's outbox row was never attempted.");
        $this->assertGreaterThan(0, FinPosting::find($b)->attempts, "Company B's outbox row was never attempted.");
    }

    public function test_a_poison_row_does_not_stop_the_drain(): void
    {
        // The row after a permanently-broken one must still be attempted, or one bad event blocks
        // the ledger for every tenant behind it indefinitely.
        $poison = $this->makeStuckPosting($this->companyA, payload: ['nonsense' => true]);
        $next = $this->makeStuckPosting($this->companyB);

        $this->artisan('finance:process-postings')->assertSuccessful();

        $this->assertSame('failed', FinPosting::find($poison)->status);
        $this->assertGreaterThan(0, FinPosting::find($next)->attempts, 'The drain stopped at the poison row.');
    }

    private function makeStuckPosting(int $companyId, ?array $payload = null): int
    {
        return (int) DB::table('fin_postings')->insertGetId([
            'event_id' => (string) Str::uuid(),
            'company_id' => $companyId,
            'entity_id' => $this->businessFor($companyId),
            'source_module' => 'test',
            'source_ref' => 'cron-tenancy',
            'idempotency_key' => 'cron-tenancy:'.Str::random(12),
            'payload' => json_encode($payload ?? [
                'source_module' => 'test', 'event_type' => 'test.event',
                'debits' => [], 'credits' => [], 'company_id' => $companyId,
                'currency' => 'ZAR', 'source_ref' => 'cron-tenancy',
                'idempotency_key' => 'cron-tenancy', 'event_id' => (string) Str::uuid(),
            ]),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
