<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Compliance\SubcontractorService;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Principal contractor, subcontractor, worker — and the boundary between them (CON-25).
 *
 * The register was flat: every firm answered directly to the host. So the question Construction
 * Regulation 7 makes a principal contractor answerable for — "are the firms I appointed in
 * order?" — could not be asked, and the host could only answer it by email.
 *
 * The half worth testing hardest is the boundary. Letting a principal see its subcontractors'
 * compliance is one line of SQL; stopping it from seeing its siblings, the firm above it, or
 * another company's workers' medical records is the part that has to hold.
 */
class SubcontractorTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $principal;

    private array $subcontractor;

    private array $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode([]), 'updated_at' => now()],
        );

        $contractors = app(ContractorService::class);

        $this->principal = $contractors->register($this->companyId,
            ['name' => 'Mabaso Construction', 'trade' => 'CIVILS'], $this->user->id);
        $this->subcontractor = $contractors->register($this->companyId,
            ['name' => 'Jozi Electrical', 'trade' => 'ELECTRICAL'], $this->user->id);
        $this->sibling = $contractors->register($this->companyId,
            ['name' => 'Unrelated Plumbing', 'trade' => 'PLUMBING'], $this->user->id);
    }

    private function subs(): SubcontractorService
    {
        return app(SubcontractorService::class);
    }

    // ── The chain ─────────────────────────────────────────────────────────────────────────────

    public function test_a_contractor_can_be_appointed_under_another(): void
    {
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $this->assertSame($this->principal['id'], (int) DB::table('sheq_contractors')
            ->where('id', $this->subcontractor['id'])->value('parent_contractor_id'));

        // On the record. Who appointed whom decides who is answerable after an incident.
        $this->assertTrue(DB::table('sheq_audit_log')->where('company_id', $this->companyId)
            ->where('action', 'contractor.subcontractor_appointed')->exists());
    }

    public function test_the_chain_can_run_more_than_two_deep(): void
    {
        /*
         * Two levels is the common case and not the only one: a principal appoints an electrical
         * subcontractor who appoints a specialist testing firm. Refusing to record that would
         * push the arrangement off the system altogether, which is worse than recording it.
         */
        $specialist = app(ContractorService::class)->register($this->companyId,
            ['name' => 'Test & Verify', 'trade' => 'TESTING'], $this->user->id);

        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);
        $this->subs()->appoint($this->companyId, $this->subcontractor['uuid'],
            $specialist['uuid'], $this->user->id);

        $descendants = $this->subs()->descendants($this->companyId, $this->principal['id']);

        $this->assertCount(2, $descendants);

        $depths = array_combine(array_column($descendants, 'name'),
            array_column($descendants, 'depth'));

        $this->assertSame(1, $depths['Jozi Electrical']);
        $this->assertSame(2, $depths['Test & Verify']);
    }

    public function test_a_loop_in_the_chain_is_refused_with_a_sentence(): void
    {
        /*
         * It happens from a two-step edit nobody thinks of as a step: A is given B as its parent,
         * and later somebody gives B the parent A. Every tree walk in the application then runs
         * until the request dies.
         */
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/loop/i');

        $this->subs()->appoint($this->companyId, $this->subcontractor['uuid'],
            $this->principal['uuid'], $this->user->id);
    }

    public function test_the_database_refuses_a_loop_even_when_the_service_is_bypassed(): void
    {
        /*
         * The register is written from a controller, an importer, a seeder and the supplier
         * bridge. A guard in the service is a guard three paths walk past, so the real one is a
         * trigger.
         */
        DB::table('sheq_contractors')->where('id', $this->subcontractor['id'])
            ->update(['parent_contractor_id' => $this->principal['id']]);

        DB::statement('SAVEPOINT loop_attempt');

        try {
            DB::table('sheq_contractors')->where('id', $this->principal['id'])
                ->update(['parent_contractor_id' => $this->subcontractor['id']]);

            $this->fail('The database allowed a loop in the contractor chain.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('loop', strtolower($e->getMessage()));
            DB::statement('ROLLBACK TO SAVEPOINT loop_attempt');
        }
    }

    public function test_a_contractor_cannot_be_its_own_principal(): void
    {
        DB::statement('SAVEPOINT self_attempt');

        try {
            DB::table('sheq_contractors')->where('id', $this->principal['id'])
                ->update(['parent_contractor_id' => $this->principal['id']]);

            $this->fail('A contractor was allowed to be its own principal contractor.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('its own', strtolower($e->getMessage()));
            DB::statement('ROLLBACK TO SAVEPOINT self_attempt');
        }
    }

    public function test_releasing_a_subcontractor_returns_it_to_the_host(): void
    {
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $this->subs()->release($this->companyId, $this->subcontractor['uuid'], $this->user->id);

        $this->assertNull(DB::table('sheq_contractors')
            ->where('id', $this->subcontractor['id'])->value('parent_contractor_id'));
    }

    public function test_removing_a_principal_does_not_remove_the_firms_beneath_it(): void
    {
        /*
         * `nullOnDelete`, not cascade. Those firms were on site, they filed documents, and there
         * may be an incident attached to one of them — deleting their records with their
         * principal's would destroy evidence about events that happened.
         */
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        DB::table('sheq_contractors')->where('id', $this->principal['id'])->delete();

        $survivor = DB::table('sheq_contractors')
            ->where('id', $this->subcontractor['id'])->first(['id', 'parent_contractor_id']);

        $this->assertNotNull($survivor);
        $this->assertNull($survivor->parent_contractor_id);
    }

    // ── The boundary ──────────────────────────────────────────────────────────────────────────

    public function test_a_principal_sees_the_compliance_of_the_firms_it_appointed(): void
    {
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $view = $this->subs()->visibleTo($this->companyId, $this->principal['id']);

        $this->assertCount(1, $view['subcontractors']);
        $this->assertSame('Jozi Electrical', $view['subcontractors'][0]['name']);
        $this->assertArrayHasKey('outcome', $view['subcontractors'][0]);
    }

    public function test_a_principal_does_not_see_its_siblings(): void
    {
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $names = array_column(
            $this->subs()->visibleTo($this->companyId, $this->principal['id'])['subcontractors'],
            'name');

        $this->assertNotContains('Unrelated Plumbing', $names,
            'A principal contractor could see a firm it has nothing to do with.');
    }

    public function test_a_subcontractor_cannot_see_upwards(): void
    {
        // Accountability runs downwards only. A subcontractor is not entitled to the compliance
        // record of the firm that appointed it.
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $view = $this->subs()->visibleTo($this->companyId, $this->subcontractor['id']);

        $this->assertSame([], $view['subcontractors']);
    }

    public function test_asking_about_a_firm_outside_the_chain_returns_nothing(): void
    {
        /*
         * Nothing, not an error naming the firm — an error that says "Unrelated Plumbing is not
         * yours" confirms that Unrelated Plumbing exists, which is itself information about
         * another company.
         */
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $this->assertNull($this->subs()->readableChild($this->companyId,
            $this->principal['id'], $this->sibling['uuid']));

        $this->assertNotNull($this->subs()->readableChild($this->companyId,
            $this->principal['id'], $this->subcontractor['uuid']));
    }

    public function test_the_host_decides_what_a_principal_is_shown(): void
    {
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        DB::table('sheq_contractor_workers')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $this->subcontractor['id'], 'name' => 'Someone Else\'s Employee',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Shipped: the summary and the safety file state. Nothing about individuals.
        $default = $this->subs()->visibleTo($this->companyId, $this->principal['id']);

        $this->assertArrayNotHasKey('workers', $default['subcontractors'][0],
            'A principal was shown another firm\'s roster without the host allowing it.');

        $this->subs()->setVisibility($this->companyId, $this->principal['uuid'],
            ['COMPLIANCE_SUMMARY', 'WORKER_COUNT'], $this->user->id);

        $widened = $this->subs()->visibleTo($this->companyId, $this->principal['id']);

        $this->assertSame(1, $widened['subcontractors'][0]['workers']);
    }

    public function test_the_summary_cannot_be_switched_off(): void
    {
        /*
         * A principal accountable for a firm's compliance and shown nothing about it is worse off
         * than one shown nothing at all: the screen exists, it is empty, and they reasonably
         * conclude there is nothing wrong.
         */
        $saved = $this->subs()->setVisibility($this->companyId, $this->principal['uuid'],
            [], $this->user->id);

        $this->assertContains('COMPLIANCE_SUMMARY', $saved);
    }

    public function test_an_unknown_visibility_item_is_dropped_rather_than_stored(): void
    {
        // A typo'd key would otherwise sit in the configuration for ever, looking like a switch
        // that does nothing.
        $saved = $this->subs()->setVisibility($this->companyId, $this->principal['uuid'],
            ['COMPLIANCE_SUMMARY', 'EVERYTHING_PLEASE'], $this->user->id);

        $this->assertNotContains('EVERYTHING_PLEASE', $saved);
    }

    public function test_the_rollup_never_exposes_internal_ids(): void
    {
        // This payload goes to a contractor over a public portal, and a sequential id is an
        // invitation to try the next one.
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $row = $this->subs()->visibleTo($this->companyId, $this->principal['id'])
            ['subcontractors'][0];

        $this->assertArrayNotHasKey('id', $row);
        $this->assertArrayHasKey('uuid', $row);
    }

    // ── The screen ────────────────────────────────────────────────────────────────────────────

    public function test_the_page_shows_the_chain_and_what_the_principal_may_see(): void
    {
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $props = $this->actingAs($this->user)
            ->get('/sheq/subcontractors?contractor='.$this->principal['uuid'])
            ->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['rollup']['subcontractors']);
        $this->assertCount(count(SubcontractorService::VISIBILITY), $props['visibilityOptions']);
        $this->assertSame(SubcontractorService::DEFAULT_VISIBILITY, $props['visibility']);
    }

    public function test_the_page_refuses_a_loop_with_an_error_not_a_crash(): void
    {
        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $this->actingAs($this->user)
            ->post('/sheq/subcontractors/appoint', [
                'principal' => $this->subcontractor['uuid'],
                'subcontractor' => $this->principal['uuid'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('subcontractor');
    }

    public function test_appointing_requires_the_entitlement(): void
    {
        $this->actingAs($this->userWith([]))
            ->post('/sheq/subcontractors/appoint', [
                'principal' => $this->principal['uuid'],
                'subcontractor' => $this->subcontractor['uuid'],
            ])
            ->assertForbidden();
    }

    public function test_a_contractor_cannot_be_appointed_under_itself_through_the_page(): void
    {
        $this->actingAs($this->user)
            ->post('/sheq/subcontractors/appoint', [
                'principal' => $this->principal['uuid'],
                'subcontractor' => $this->principal['uuid'],
            ])
            ->assertSessionHasErrors('subcontractor');
    }

    // ── The principal's own portal ────────────────────────────────────────────────────────────

    public function test_a_principal_sees_the_firms_it_appointed_on_its_own_portal(): void
    {
        /*
         * The point of the whole requirement. A principal contractor accountable for its
         * subcontractors had the rollup computed for it and nothing rendered it, so the only way
         * to learn a subcontractor was blocked was to wait for the host to send an email.
         */
        $supplierId = $this->linkSupplier($this->principal['id']);

        $this->subs()->appoint($this->companyId, $this->principal['uuid'],
            $this->subcontractor['uuid'], $this->user->id);

        $payload = app(\App\Modules\Operations\Sheq\Services\ContractorPortalService::class)
            ->forSupplier($this->companyId, $supplierId);

        $this->assertNotNull($payload['subcontractors'],
            'A principal contractor was shown nothing about the firms it appointed.');
        $this->assertSame('Jozi Electrical',
            $payload['subcontractors']['subcontractors'][0]['name']);
    }

    public function test_a_contractor_with_no_subcontractors_is_shown_no_such_section(): void
    {
        // Null rather than an empty list, so the portal shows the section only to somebody it
        // concerns — an empty panel headed "firms you appointed" is a question, not information.
        $supplierId = $this->linkSupplier($this->sibling['id']);

        $payload = app(\App\Modules\Operations\Sheq\Services\ContractorPortalService::class)
            ->forSupplier($this->companyId, $supplierId);

        $this->assertNull($payload['subcontractors']);
    }

    /** Give a contractor a supplier record, which is how the portal finds them. */
    private function linkSupplier(int $contractorId): int
    {
        $supplierId = (int) DB::table('suppliers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'company_name' => 'Portal supplier '.Str::random(5),
            'payment_terms' => '30 days',
            'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true,
            'health_safety_compliance' => true,
            'is_active' => true, 'is_preferred' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_contractors')->where('id', $contractorId)
            ->update(['supplier_id' => $supplierId]);

        return $supplierId;
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'sub-'.Str::random(8).'@example.test',
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

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }
}
