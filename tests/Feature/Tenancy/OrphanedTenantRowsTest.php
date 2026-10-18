<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Fleet\Models\FleetJobCardPartCategory;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rows saved with no company at all.
 *
 * The worst kind of bug this codebase produces, because every part of it reports success. The save
 * returns, the record is in the table, and row-level security — which matches
 * `company_id = <session>` — then hides it from every tenant that exists, including the one whose
 * user created it a second earlier. There is no error to find and nothing in the logs.
 *
 * It surfaced as "the blog is not posting". A content calendar of planned topics was invisible to
 * its own company, so the runner swept that tenant every five minutes, correctly found nothing
 * owed, and reported a healthy tick. Three thousand rows across a dozen modules were the same.
 *
 * Two halves are tested here: that the write path now finds an owner, and that the repair refuses
 * to guess one where guessing would move data between customers.
 */
class OrphanedTenantRowsTest extends TestCase
{
    use DatabaseTransactions;

    /** Any ordinary tenant-owned record will do; a parts category has no foreign keys to satisfy. */
    private function makePlan(): FleetJobCardPartCategory
    {
        return FleetJobCardPartCategory::create([
            'name' => 'Category ' . Str::random(5),
        ]);
    }

    public function test_a_single_tenant_install_stamps_its_one_company_rather_than_saving_an_orphan(): void
    {
        // The reported case: a self-hosted administrator with no CurrentCompany and no company on
        // their own user record. Before, this wrote a row nobody could ever see again.
        config(['edition.edition' => 'self_hosted']);
        app(CurrentCompany::class)->clear();

        $sole = Company::query()->orderBy('id')->firstOrFail();

        // Only meaningful on an installation shaped like the one being described.
        if (Company::query()->count() !== 1) {
            $this->markTestSkipped('Needs a single-company database to represent a self-hosted install.');
        }

        $plan = $this->makePlan();

        $this->assertSame(
            (int) $sole->id,
            (int) $plan->company_id,
            'On a single-tenant installation there is exactly one possible owner, so a row saved '
            .'without one is a defect rather than a shared record.',
        );
    }

    public function test_a_model_that_shares_records_globally_still_saves_without_a_company(): void
    {
        // The other side of the same coin: for a system-wide library, null is the intent. Stamping
        // one of those would hand a shared resource to one company and take it from everyone else.
        config(['edition.edition' => 'self_hosted']);

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            use \App\Modules\Tenancy\Traits\BelongsToCompany;

            public static function sharesGlobalRecords(): bool
            {
                return true;
            }
        };

        $this->assertTrue(
            $model::sharesGlobalRecords(),
            'A model declaring shared records must keep its null company_id.',
        );
    }

    public function test_the_repair_refuses_to_guess_an_owner_on_a_multi_tenant_install(): void
    {
        // The one outcome worse than a lost row is a lost row handed to the wrong customer.
        config(['edition.edition' => 'saas']);

        $this->artisan('tenancy:adopt-orphans')
            ->expectsOutputToContain('Cannot determine an owner')
            ->assertFailed();
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        config(['edition.edition' => 'saas']);

        $before = DB::table('fleet_job_card_part_categories')->whereNull('company_id')->count();

        $this->artisan('tenancy:adopt-orphans', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(
            $before,
            DB::table('fleet_job_card_part_categories')->whereNull('company_id')->count(),
            'A dry run must change nothing, or it is not a dry run.',
        );
    }

    public function test_a_real_run_does_not_touch_tables_that_permit_a_null_company(): void
    {
        // The near miss this test exists for. Two onboarding tables carry *two* policies each: one
        // permitting a null company_id and one not. Examined a policy at a time, whether their 853
        // shared template rows got adopted came down to which policy the query happened to return
        // last — and adopting them would hand a shared library to one company and take it from
        // every other tenant. That is the exact harm this command refuses elsewhere, arrived at by
        // accident.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-level security policies are PostgreSQL-specific.');
        }

        if (Company::query()->count() !== 1) {
            $this->markTestSkipped('Needs a single-company database to represent a self-hosted install.');
        }

        config(['edition.edition' => 'self_hosted']);

        $protected = collect(DB::select(
            "select c.table_name
               from information_schema.columns c
               join pg_policies p
                 on p.tablename = c.table_name
                and p.schemaname = c.table_schema
              where c.table_schema = current_schema()
                and c.column_name = 'company_id'
           group by c.table_name
             having bool_or(p.qual like '%company_id IS NULL%')"
        ))->pluck('table_name');

        $this->assertContains(
            'onboarding_templates',
            $protected->all(),
            'onboarding_templates is the table this test was written about — it carries both a '
            .'policy permitting a null company and one denying it.',
        );

        // Planted rather than assumed. This started out reading whatever shared rows the database
        // happened to hold, and on a freshly seeded test database that is none — so the assertion
        // below had nothing to check and the test skipped itself, silently, in the one place the
        // protection actually needed proving.
        DB::table('onboarding_templates')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'Shared induction ' . Str::random(5),
            'company_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $protected
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->whereNull('company_id')->count()])
            ->filter();

        $this->assertGreaterThan(
            0,
            $before['onboarding_templates'] ?? 0,
            'The planted shared row must be visible to this test, or it proves nothing.',
        );

        $this->artisan('tenancy:adopt-orphans')->assertSuccessful();

        foreach ($before as $table => $count) {
            $this->assertSame(
                $count,
                DB::table($table)->whereNull('company_id')->count(),
                "{$table} permits a null company_id, so its null rows are a library shared with "
                .'every tenant rather than orphans to be claimed by one.',
            );
        }
    }

    public function test_a_real_run_does_claim_the_rows_that_no_tenant_can_see(): void
    {
        // The other half: refusing to touch anything is also a way to pass the test above.
        if (Company::query()->count() !== 1) {
            $this->markTestSkipped('Needs a single-company database to represent a self-hosted install.');
        }

        config(['edition.edition' => 'self_hosted']);

        DB::table('fleet_job_card_part_categories')->insert([
            'uuid' => (string) Str::uuid(),
            'name' => 'Orphan ' . Str::random(5),
            'company_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('tenancy:adopt-orphans')->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('fleet_job_card_part_categories')->whereNull('company_id')->count(),
            'A record owned by nobody is invisible to the tenant that created it.',
        );
    }

    public function test_the_repair_leaves_shared_library_rows_alone(): void
    {
        // contracts_templates carries the system-wide contract library, and its row-level security
        // policy says so by permitting a null company_id. The repair reads that policy, so the two
        // cannot drift apart.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-level security policies are PostgreSQL-specific.');
        }

        $shared = DB::select(
            "select tablename from pg_policies
              where schemaname = current_schema()
                and qual like '%company_id IS NULL%'"
        );

        $this->assertNotEmpty(
            $shared,
            'At least one table is expected to treat a null company_id as "shared with every '
            .'tenant"; if none do, the exclusion in the repair is no longer being exercised.',
        );
    }

    public function test_the_collision_resolver_repairs_an_append_only_ledger_without_deleting_from_it(): void
    {
        // pm_evidence_events forbids DELETE with a trigger and permits no UPDATE except
        // superseded_by, because company_id is hashed into the chain. The resolver used to try to
        // delete the orphans outright, which could never have worked — and would have destroyed a
        // live project's evidence if it had, since an orphaned event still belongs to whoever owns
        // its project. It is invisible under row-level security, not ownerless.
        $companyId = (int) DB::table('companies')->min('id');
        if ($companyId === 0) {
            $this->markTestSkipped('No company in the test database.');
        }

        // No tenant context, which is how the real orphans came about: written from a console
        // command where row-level security had nothing to stamp. With a company set, the policy
        // correctly refuses to insert a row no tenant could see.
        DB::statement("SELECT set_config('app.company_id', '', false)");

        // Created rather than found, so the test proves something on an empty database too. A
        // skipped test guards nothing, and this one guards a command that used to throw.
        $calendarId = DB::table('pm_calendars')->value('id') ?? DB::table('pm_calendars')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => 'Orphan fixture calendar',
            'timezone' => 'Africa/Johannesburg', 'work_week' => json_encode([1, 2, 3, 4, 5]), 'is_default' => false,
        ]);
        $projectUuid = (string) Str::uuid();
        $projectId = DB::table('pm_projects')->insertGetId([
            'uuid' => $projectUuid, 'company_id' => $companyId,
            'code' => 'ORPH-' . Str::upper(Str::random(6)), 'name' => 'Orphan evidence fixture',
            'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'calendar_id' => $calendarId,
        ]);

        $uuid = (string) Str::uuid();
        $occurredAt = now();

        DB::table('pm_evidence_events')->insert([
            'uuid' => $uuid, 'company_id' => null, 'project_id' => $projectId,
            'subject_type' => 'project', 'subject_id' => $projectId,
            'subject_uuid' => $projectUuid,
            'event_type' => 'task.started', 'source_module' => 'pm',
            'occurred_at' => $occurredAt, 'occurred_tz' => 'Africa/Johannesburg',
            'recorded_at' => $occurredAt, 'actor_type' => 'system',
            'confidence' => '1.00', 'payload' => json_encode([]), 'attachments' => json_encode([]),
            'hash' => str_repeat('a', 64), 'hash_version' => 'v1',
        ]);

        $this->artisan('tenancy:resolve-collisions', [
            '--company' => $companyId, '--table' => ['pm_evidence_events'], '--execute' => true,
        ])->assertSuccessful();

        DB::statement("SELECT set_config('app.company_id', '', false)");

        // app.company_id is session-level, so an assertion failing below would otherwise leave the
        // rest of the suite running with no tenant context and take unrelated tests down with it.
        $this->beforeApplicationDestroyed(function () use ($companyId) {
            DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
        });

        $original = DB::table('pm_evidence_events')->where('uuid', $uuid)->first();

        $this->assertNotNull($original, 'The original event must survive: the ledger is append-only.');
        $this->assertNull($original->company_id, 'Stamping an owner on it would invalidate its hash.');
        $this->assertNotNull($original->superseded_by, 'It should point at the event that replaced it.');

        $replacement = DB::table('pm_evidence_events')->where('uuid', $original->superseded_by)->first();

        $this->assertNotNull($replacement, 'The replacement must exist.');
        $this->assertSame($companyId, (int) $replacement->company_id);
        $this->assertSame('task.started', $replacement->event_type, 'The replacement is the same event, not a note about it.');
    }
}
