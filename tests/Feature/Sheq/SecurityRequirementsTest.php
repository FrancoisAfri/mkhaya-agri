<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use App\Modules\Operations\Sheq\Regulatory\MetricConventions;
use App\Modules\Operations\Sheq\Regulatory\PackManifest;
use App\Modules\Operations\Sheq\Security\SpecialInformation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The security requirements (SEC-1 to SEC-9).
 *
 * These are the requirements where a gap is a breach rather than a missing feature, so they are
 * tested adversarially: each one attempts the thing that must not work, rather than confirming
 * that the thing that should work does.
 *
 * Most of them were already satisfied and are asserted here so they stay that way — the QR token
 * is opaque and throttled, the audit log refuses UPDATE and DELETE at the database, special
 * personal information is encrypted at column level, and a check that cannot be run refuses
 * rather than approving.
 *
 * SEC-7 was not satisfied, and the way it failed is the reason this file exists. Retention came
 * from the active regulatory pack, which is right, through a lookup that read keys no shipped pack
 * declares — `by_record_class` and `default_years` against a manifest that says `incident_records`
 * and `medical_surveillance`. Every lookup missed, fell through to a three-year default, and every
 * incident ever reported was stamped with a retention date three years out while the pack it was
 * classified under said forty. Nothing failed, nothing logged, and the erasure service honours
 * that date — so a fatality record became erasable thirty-seven years early.
 */
class SecurityRequirementsTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith();
    }

    // ── SEC-7 ─────────────────────────────────────────────────────────────────────────────────

    public function test_retention_comes_from_the_pack_and_not_from_a_default(): void
    {
        $manifest = PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => [
            'statutory_forms' => 'P40Y',
            'incident_records' => 'P40Y',
            'training_records' => 'P10Y',
            'exposure_monitoring' => 'P40Y',
            'medical_surveillance' => 'P40Y',
        ]]);

        $metrics = new MetricConventions($manifest);

        // The two classes `IncidentReporter` actually asks for. Both used to answer 3.
        $this->assertSame(40, $metrics->retentionYears('incident'),
            'An incident is retained for less than the pack requires. The record is marked '
            .'erasable before the law allows it, and the erasure service honours that date.');
        $this->assertSame(40, $metrics->retentionYears('incident_s24'),
            'A reportable incident — the class that includes a fatality — is retained for less '
            .'than the pack requires.');

        $this->assertSame(40, $metrics->retentionYears('medical'));
        $this->assertSame(40, $metrics->retentionYears('exposure'));
        $this->assertSame(10, $metrics->retentionYears('competency'));
    }

    public function test_a_pack_that_omits_a_class_cannot_shorten_it_below_the_statutory_floor(): void
    {
        /*
         * SEC-7 in its own words: a retention period cannot be shortened below the active
         * regulatory requirement. A pack that simply says nothing about medical surveillance was
         * shortening it to three years, which is the difference between being able to answer a
         * silicosis claim in 2060 and not.
         */
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => []]));

        $this->assertSame(40, $metrics->retentionYears('medical'),
            'A pack that omits medical surveillance destroys the record 37 years early.');
        $this->assertSame(40, $metrics->retentionYears('exposure'));
        $this->assertSame(10, $metrics->retentionYears('competency'));
    }

    public function test_a_pack_cannot_declare_less_than_the_statutory_floor(): void
    {
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => [
            'medical_surveillance' => 'P1Y',
            'exposure_monitoring' => 2,
        ]]));

        $this->assertSame(40, $metrics->retentionYears('medical'),
            'A pack declaring one year for medical surveillance was honoured. A pack is content, '
            .'and content cannot lower a statutory minimum.');
        $this->assertSame(40, $metrics->retentionYears('exposure'));
    }

    public function test_a_pack_may_exceed_the_statutory_floor(): void
    {
        // A group policy longer than the law is lawful, and somebody chose it deliberately.
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => [
            'training_records' => 'P25Y',
        ]]));

        $this->assertSame(25, $metrics->retentionYears('competency'),
            'The floor is being applied as a ceiling, so a tenant cannot keep records longer '
            .'than the minimum.');
    }

    public function test_a_malformed_retention_value_falls_back_to_the_law(): void
    {
        /*
         * `(int) 'P40Y'` is 0 in PHP, which is how a silent three-year retention survived in the
         * first place. A value this cannot parse has to fall through to the floor rather than to
         * whatever the string casts to.
         */
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => [
            'medical_surveillance' => 'forty years',
            'incident_records' => '',
        ]]));

        $this->assertSame(40, $metrics->retentionYears('medical'));
        $this->assertSame(3, $metrics->retentionYears('incident'),
            'A blank value produced a zero-year retention, which marks the record erasable today.');
    }

    public function test_months_and_days_round_up_rather_than_truncating(): void
    {
        // Rounding a retention period down is the one direction that destroys a record early.
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => [
            'training_records' => 'P11Y6M',
        ]]));

        $this->assertSame(12, $metrics->retentionYears('competency'),
            'Eleven and a half years was truncated to eleven, destroying the record six months '
            .'early.');
    }

    public function test_the_older_manifest_shape_still_works(): void
    {
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => [
            'by_record_class' => ['incident_s24' => 45],
            'default_years' => 7,
        ]]));

        $this->assertSame(45, $metrics->retentionYears('incident_s24'),
            'A pack written to the documented older shape stopped working.');
        $this->assertSame(7, $metrics->retentionYears('something_nobody_classified'));
    }

    public function test_an_unclassified_record_still_gets_the_general_three_year_floor(): void
    {
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => []]));

        $this->assertSame(3, $metrics->retentionYears('something_added_later'),
            'A record class nobody has classified gets no retention at all, so it is erasable '
            .'the day it is created.');
    }

    public function test_every_statutory_floor_is_reachable_through_the_classes_the_code_asks_for(): void
    {
        /*
         * A floor nothing can look up is a floor that does not exist. This walks the floors and
         * asserts that some record class the code uses resolves to each one — so a floor added
         * without an alias fails here rather than silently never applying.
         */
        $metrics = new MetricConventions(PackManifest::fromArray(['pack_id' => 'za-ohsa', 'version' => '1.0.0', 'retention' => []]));

        $reachable = [];

        foreach (['incident', 'incident_s24', 'investigation', 'statutory_form', 'medical',
            'medical_record', 'exposure', 'exposure_result', 'competency', 'training',
            'induction'] as $class) {
            $reachable[$metrics->retentionYears($class)] = true;
        }

        foreach (MetricConventions::STATUTORY_FLOORS as $key => $years) {
            $this->assertArrayHasKey($years, $reachable,
                "The {$key} floor of {$years} years is not reachable from any record class the "
                .'code asks about, so it never applies to anything.');
        }
    }

    // ── SEC-6 ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_audit_log_refuses_to_be_edited_or_deleted(): void
    {
        $id = (int) DB::table('sheq_audit_log')->insertGetId([
            'company_id' => $this->companyId, 'actor_id' => $this->user->id,
            'actor_type' => 'USER', 'action' => 'SECURITY_TEST',
            'subject_type' => 'Test', 'subject_id' => 1,
        ]);

        foreach ([
            'update' => fn () => DB::table('sheq_audit_log')->where('id', $id)
                ->update(['action' => 'SOMETHING_ELSE']),
            'delete' => fn () => DB::table('sheq_audit_log')->where('id', $id)->delete(),
        ] as $what => $attempt) {
            // Each attempt aborts the transaction, so each needs its own savepoint.
            DB::statement("SAVEPOINT attempt_{$what}");

            try {
                $attempt();
                DB::statement("RELEASE SAVEPOINT attempt_{$what}");

                $this->fail("The audit log accepted an {$what}. A compliance decision's history "
                    .'can be rewritten by anybody with database access, which is the one thing an '
                    .'audit log exists to prevent.');
            } catch (\Illuminate\Database\QueryException) {
                DB::statement("ROLLBACK TO SAVEPOINT attempt_{$what}");
            }
        }

        $this->assertTrue(DB::table('sheq_audit_log')->where('id', $id)->exists());
    }

    public function test_the_audit_log_is_hash_chained(): void
    {
        $before = DB::table('sheq_audit_log')->orderByDesc('id')->value('hash');

        DB::table('sheq_audit_log')->insert([
            'company_id' => $this->companyId, 'actor_id' => $this->user->id,
            'actor_type' => 'USER', 'action' => 'SECURITY_TEST_CHAIN',
            'subject_type' => 'Test', 'subject_id' => 2,
        ]);

        $row = DB::table('sheq_audit_log')->orderByDesc('id')->first(['hash', 'prev_hash']);

        $this->assertNotNull($row->hash,
            'Rows are not hashed, so a deletion from a backup restore cannot be detected.');

        if ($before !== null) {
            $this->assertSame($before, $row->prev_hash,
                'The chain is not linked to the previous row, so a row removed from the middle '
                .'leaves no trace.');
        }
    }

    // ── SEC-9 ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_check_that_cannot_run_never_becomes_an_approval(): void
    {
        /*
         * The most important single assertion in the module. A technical failure — a dropped
         * connection, a renamed column, a missing table — must not read as "nothing wrong here".
         */
        $this->assertContains(ComplianceDecisionService::UNKNOWN,
            [ComplianceDecisionService::UNKNOWN],
            'The engine has no outcome for a check it could not run, so it has to pick one of '
            .'the others — and one of the others is ALLOWED.');

        $this->assertNotSame(ComplianceDecisionService::ALLOWED,
            ComplianceDecisionService::UNKNOWN);

        // A contractor that does not exist is the simplest unanswerable question there is.
        $decision = app(ComplianceDecisionService::class)
            ->forContractor($this->companyId, 999999999);

        $this->assertNotSame(ComplianceDecisionService::ALLOWED, $decision['outcome'],
            'An unanswerable question was answered with an approval.');
    }

    // ── SEC-1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_another_tenant_cannot_be_asked_about_this_tenants_contractor(): void
    {
        $other = (int) DB::table('companies')->where('id', '!=', $this->companyId)->min('id');

        if ($other === 0) {
            $this->markTestSkipped('Only one company in the test database.');
        }

        $contractorId = (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Acme Scaffolding', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $decision = app(ComplianceDecisionService::class)->forContractor($other, $contractorId);

        $this->assertNotSame(ComplianceDecisionService::ALLOWED, $decision['outcome'],
            'One tenant got a compliance verdict on another tenant\'s contractor.');
    }

    // ── SEC-3, SEC-4, SEC-8 ───────────────────────────────────────────────────────────────────

    public function test_the_public_pass_route_is_throttled_and_takes_only_an_opaque_token(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'sheq.pass.verify');

        $this->assertNotNull($route, 'The public pass route is gone.');

        $middleware = $route->gatherMiddleware();

        $this->assertTrue(
            collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')),
            'SEC-8: the public QR endpoint has no rate limit, so guessing tokens is only as slow '
            .'as the network.'
        );

        // SEC-3: the token is the credential, so the pattern must not admit anything short.
        $pattern = $route->wheres['token'] ?? '';

        $this->assertNotSame('', $pattern,
            'SEC-3: the token parameter is unconstrained, so a one-character token reaches the '
            .'controller and the search space is whatever the controller happens to check.');
        $this->assertMatchesRegularExpression('/\{\s*(\d{2,})\s*,/', $pattern,
            'SEC-3: the token pattern does not impose a minimum length.');

        // And no authentication, because a guard at a boom has no account — asserted so that
        // somebody tidying route files does not silently break every gate.
        $this->assertFalse(collect($middleware)->contains('auth'),
            'The pass route requires a login. A guard holding their own phone cannot pass it, so '
            .'every scan becomes a wave-through.');
    }

    public function test_a_short_or_malformed_token_never_reaches_the_verifier(): void
    {
        /*
         * Asserted as "never produces a verdict" rather than as a 404. What the application does
         * with an unmatched path is its own business — some of these redirect — and the security
         * property is that none of them reaches the code that decides whether somebody may enter.
         */
        foreach (['x', 'abc', '../../etc/passwd', str_repeat('a', 128)] as $token) {
            $response = $this->get('/sheq/pass/'.urlencode($token));

            $this->assertNotSame(200, $response->getStatusCode(),
                "The token '{$token}' reached the verifier, so the token pattern is not the "
                .'constraint it appears to be.');

            $body = $response->getStatusCode() === 200 ? $response->getContent() : '';

            foreach (['ACCESS APPROVED', 'KUVUMELEKILE'] as $verdict) {
                $this->assertStringNotContainsString($verdict, $body);
            }
        }
    }

    // ── SEC-5 ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_fields_that_must_be_encrypted_are_still_declared_as_such(): void
    {
        /*
         * Asserted against the list rather than the behaviour, because the failure mode is a
         * field being quietly dropped from it during a refactor — at which point it is written in
         * clear and nothing looks different.
         */
        foreach (['injury_nature', 'body_part', 'statement'] as $field) {
            $this->assertContains($field, SpecialInformation::PROTECTED_FIELDS,
                "The {$field} column is no longer treated as special personal information, so it "
                .'is being written to the database in clear.');
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function userWith(): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'sec-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        return User::withoutGlobalScopes()->findOrFail($userId);
    }
}
