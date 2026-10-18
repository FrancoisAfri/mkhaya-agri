<?php

namespace Tests\Feature\LMS;

use App\Core\User\Models\User;
use App\Modules\HR\LMS\Services\LmsComplianceService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Two live defects found by running every per-tenant cron once and reading the exceptions.
 *
 * Both were invisible. `lms:compliance-checks` and `lms:send-registration-expiry-alerts` fan out
 * over companies in a plain loop, so an exception on the first company ended the pass — every
 * company behind it silently received no compliance checks and no expiry alerts, and the run
 * reported nothing wrong. The estate had been in that state since the commands were scheduled.
 *
 * The two triggers are worth naming because both look harmless in review:
 *
 *  - `reminder_days_before` is a jsonb column cast to array. jsonb accepts `14` quite happily, it
 *    casts back to an int, and `in_array($days, 14)` is a TypeError. The `?? [30,14,7,1]` guarding
 *    it only ever caught null.
 *  - Spatie's `role()` scope throws RoleDoesNotExist for a name it cannot resolve, and matches
 *    exactly. The code asked for 'hr' and 'admin'; the roles are 'HR' and 'administrator'.
 */
class ComplianceCronResilienceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function service(): LmsComplianceService
    {
        return app(LmsComplianceService::class);
    }

    /** Reach the private coercion the way the sweep does, without needing a full assignment graph. */
    private function schedule(mixed $configured): array
    {
        $method = new \ReflectionMethod(LmsComplianceService::class, 'reminderSchedule');

        return $method->invoke($this->service(), $configured);
    }

    public function test_a_scalar_reminder_schedule_does_not_crash_the_sweep(): void
    {
        // The exact shape three seeded rules held, and the exact value that threw.
        $this->assertSame([14], $this->schedule(14));
        $this->assertSame([14], $this->schedule('14'));
    }

    public function test_a_normal_reminder_schedule_is_left_alone(): void
    {
        // Coercion must not quietly rewrite good configuration.
        $this->assertSame([30, 14, 7, 1], $this->schedule([30, 14, 7, 1]));
    }

    public function test_an_unusable_reminder_schedule_falls_back_to_the_default(): void
    {
        /*
         * Null, empty and junk all mean "nobody configured this", which is a reminder schedule of
         * the sensible default — not an empty list that silently sends nothing at all.
         */
        $this->assertSame([30, 14, 7, 1], $this->schedule(null));
        $this->assertSame([30, 14, 7, 1], $this->schedule([]));
        $this->assertSame([30, 14, 7, 1], $this->schedule('not a schedule'));
        $this->assertSame([30, 14, 7, 1], $this->schedule(['x', 'y']));
    }

    public function test_no_compliance_rule_in_the_database_holds_a_scalar_schedule(): void
    {
        // The coercion above is the belt; this is the braces. A scalar in the column is a bug
        // wherever it came from, and it should not survive a seed.
        $scalars = DB::table('lms_compliance_rules')
            ->get()
            ->reject(fn ($r) => $r->reminder_days_before === null
                || str_starts_with(trim((string) $r->reminder_days_before), '['))
            ->pluck('id')
            ->all();

        $this->assertSame([], $scalars, 'These rules hold a non-list reminder schedule: '.implode(', ', $scalars));
    }

    public function test_escalation_to_a_role_that_does_not_exist_returns_nobody_rather_than_throwing(): void
    {
        /*
         * `escalate_to_role` is free text a tenant configures. Any value that does not name a real
         * role used to throw straight out of the nightly sweep — so one tenant's typo stopped every
         * other tenant's compliance run.
         */
        $method = new \ReflectionMethod(LmsComplianceService::class, 'firstUserWithRole');

        $this->assertNull($method->invoke($this->service(), 'no-such-role-'.Str::random(6)));
    }

    public function test_a_role_is_matched_regardless_of_how_it_was_capitalised(): void
    {
        // The original bug: the code asked for 'hr' and 'admin' while the roles are 'HR' and
        // 'administrator'. Spatie matches exactly, so both missed and both threw.
        $name = 'HR-Case-'.Str::random(6);
        Role::findOrCreate($name, 'web');

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'lms-role-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        User::find($userId)->assignRole($name);

        $method = new \ReflectionMethod(LmsComplianceService::class, 'firstUserWithRole');

        $this->assertNotNull(
            $method->invoke($this->service(), mb_strtolower($name)),
            'A role that exists was missed because the caller used different capitalisation.',
        );
    }

    public function test_both_commands_isolate_a_failing_tenant(): void
    {
        // What makes the next bug of this kind visible instead of silent: the command reports the
        // company it failed for, carries on, and exits non-zero.
        foreach (['lms:compliance-checks', 'lms:send-registration-expiry-alerts'] as $signature) {
            $source = file_get_contents((new \ReflectionClass(
                app(Kernel::class)->all()[$signature]
            ))->getFileName());

            $this->assertStringContainsString(
                'RunsPerTenant',
                $source,
                "{$signature} fans out over tenants with no per-tenant error isolation.",
            );
        }
    }
}
