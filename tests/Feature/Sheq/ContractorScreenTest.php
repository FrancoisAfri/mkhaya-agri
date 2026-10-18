<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The contractor profile, and the gate it answers.
 *
 * Registering a contractor set them BLOCKED — correctly, because access is refused until the
 * documents this host demands are on file — and there was no screen on which to add one, so the
 * block could never be lifted. A register that can only produce permanently blocked contractors is
 * how a site ends up with people signed in under somebody else's name.
 *
 * What a host demands was also unexpressible: `complianceFile()` took a required-types list and
 * every caller passed none, so "required" was a concept the code understood and the installation
 * could not state.
 */
class ContractorScreenTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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

        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'con-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->user = User::withoutGlobalScopes()->findOrFail($userId);

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

        $this->user->givePermissionTo('sheq.contractor.manage');
        $this->user->forgetCachedPermissions();

        $this->requireDocuments(['LETTER_OF_GOOD_STANDING']);
    }

    /** Say what this host demands, the way the settings screen will. */
    private function requireDocuments(array $types): void
    {
        $existing = DB::table('sheq_settings')->where('company_id', $this->companyId)->exists();

        $existing
            ? DB::table('sheq_settings')->where('company_id', $this->companyId)
                ->update(['contractor_required_documents' => json_encode($types)])
            : DB::table('sheq_settings')->insert([
                'company_id' => $this->companyId,
                'contractor_required_documents' => json_encode($types),
                'created_at' => now(), 'updated_at' => now(),
            ]);
    }

    private function contractor(): array
    {
        return app(ContractorService::class)->register($this->companyId, [
            'name' => 'Scaffold Co', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);
    }

    public function test_the_profile_says_which_document_is_missing_rather_than_only_blocked(): void
    {
        $contractor = $this->contractor();

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props'];

        $this->assertSame('BLOCKED', $props['compliance']['status']);

        $missing = array_column($props['compliance']['blocking'], 'type');
        $this->assertContains('LETTER_OF_GOOD_STANDING', $missing,
            'A gatehouse needs to know which piece of paper is missing, not only that one is.');

        $this->assertContains('LETTER_OF_GOOD_STANDING', $props['requiredTypes']);
    }

    public function test_adding_the_missing_document_lifts_the_block(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'LETTER_OF_GOOD_STANDING',
                'issuing_body' => 'Compensation Fund',
                'issued_on' => Carbon::today()->subMonth()->toDateString(),
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props'];

        $this->assertSame('COMPLIANT', $props['compliance']['status'],
            'The block could never be lifted before, because nothing could add a document.');
    }

    public function test_an_expired_document_blocks_again(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/documents", [
                'document_type' => 'LETTER_OF_GOOD_STANDING',
                'expires_on' => Carbon::today()->subDay()->toDateString(),
            ]);

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props'];

        // Computed on view: a certificate that lapsed yesterday must not still read as compliant.
        $this->assertSame('BLOCKED', $props['compliance']['status']);
        $this->assertSame('EXPIRED', $props['compliance']['blocking'][0]['reason']);
    }

    public function test_every_caller_answers_the_gate_the_same_way(): void
    {
        $contractor = $this->contractor();

        // attachDocument recomputes the status internally. It used to do so with an empty required
        // list, so attaching any document at all read as compliant — a different answer from the
        // one the profile gave.
        app(ContractorService::class)->attachDocument($this->companyId, $contractor['uuid'], [
            'document_type' => 'SOMETHING_ELSE',
        ], $this->user->id);

        $this->assertSame('BLOCKED',
            DB::table('sheq_contractors')->where('uuid', $contractor['uuid'])->value('access_status'));
    }

    public function test_a_worker_can_be_added_and_a_lapsed_induction_is_shown_as_none(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/workers", [
                'name' => 'S. Dlamini', 'trade' => 'Scaffolder',
                'inducted_on' => Carbon::today()->subYears(2)->toDateString(),
                'induction_expires' => Carbon::today()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $workers = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$contractor['uuid']}")
            ->viewData('page')['props']['workers'];

        $this->assertSame('S. Dlamini', $workers[0]['name']);
        $this->assertFalse($workers[0]['induction_valid'],
            'A lapsed induction counts as none — it is the commonest reason somebody is turned away.');
    }

    public function test_an_override_needs_a_reason_and_an_end_date(): void
    {
        $contractor = $this->contractor();

        // An override with no end becomes permanent by accident.
        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/override", ['reason' => 'needed on site'])
            ->assertSessionHasErrors('until');

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/override", [
                'reason' => 'short', 'until' => Carbon::tomorrow()->toDateString(),
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_a_valid_override_is_recorded_against_a_name(): void
    {
        $contractor = $this->contractor();

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$contractor['uuid']}/override", [
                'reason' => 'Letter of good standing is in the post, verified by telephone today.',
                'until' => Carbon::today()->addDays(7)->toDateString(),
            ])
            ->assertRedirect();

        /*
         * Against the override register rather than four columns on the contractor. The columns
         * had no start, no scope, no conditions and no history — one override per contractor,
         * each overwriting the last — and nothing that ever switched them off.
         */
        $row = DB::table('sheq_access_overrides')
            ->where('company_id', $this->companyId)
            ->where('subject_type', 'CONTRACTOR')->where('subject_id', $contractor['id'])
            ->first(['issued_by', 'approved_by', 'starts_at', 'ends_at', 'reason']);

        $this->assertNotNull($row, 'The override was not written to the register.');
        $this->assertSame($this->user->id, (int) $row->issued_by);
        // Defaults to whoever issued it, which on a small site is the truth — recorded honestly
        // rather than left blank.
        $this->assertSame($this->user->id, (int) $row->approved_by);
        $this->assertNotNull($row->starts_at);
        $this->assertNotNull($row->ends_at);
        $this->assertStringContainsString('Letter of good standing', $row->reason);
    }

    public function test_managing_a_contractor_requires_the_entitlement(): void
    {
        $contractor = $this->contractor();

        $outsiderId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'con-no-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_module_access')->insert([
            'module_id' => DB::table('security_modules')->where('code_name', 'sheq')->value('id'),
            'user_id' => $outsiderId, 'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::withoutGlobalScopes()->findOrFail($outsiderId))
            ->post("/sheq/contractors/{$contractor['uuid']}/workers", ['name' => 'Nobody'])
            ->assertForbidden();
    }
}
