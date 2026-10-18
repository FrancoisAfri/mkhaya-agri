<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Exceptions\DomainRuleException;
use App\Modules\Procurement\Governance\Http\Controllers\SettingsController;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §5.2: an approval chain must stay editable, and must never be saveable in a shape that cannot
 * approve anything.
 *
 * Both halves come from the same screen. A step naming one person while demanding several
 * signatures looks harmless when it is saved and is impossible to satisfy afterwards — the request
 * routed through it stops there permanently, and nothing errors, so the only symptom is an approval
 * that never arrives. Editing has the mirror problem: a chain is the record of how decisions were
 * routed, so an edit that rewrites its identity or its authorship destroys the very trail the
 * module exists to keep.
 */
class ChainEditingTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

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

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'chains-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->userId));
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** @param  array<int, array<string, mixed>>|null  $steps */
    private function save(array $overrides = [], ?array $steps = null): RedirectResponse
    {
        $payload = array_merge([
            'code' => 'TEST_'.Str::upper(Str::random(6)),
            'name' => 'Test chain',
            'applies_to' => 'deviation',
            'is_default' => false,
            'steps' => $steps ?? [[
                'name' => 'Management approval',
                'approver_type' => 'role',
                'approver_ref' => 'admin',
                'quorum' => 1,
                'sla_hours' => 120,
            ]],
        ], $overrides);

        $request = Request::create('/procurement-governance/settings/chains', 'POST', $payload);
        $request->setUserResolver(fn () => User::find($this->userId));

        return app(SettingsController::class)->storeChain($request);
    }

    private function chain(string $code): ?object
    {
        return DB::table('procgov_approval_chains')
            ->where('company_id', $this->companyId)
            ->where('code', $code)
            ->first();
    }

    // ── Steps that could never approve anything ─────────────────────────────────────────────────

    public function test_one_named_person_cannot_be_asked_for_several_signatures(): void
    {
        // The shape that produced "1. dev 1 — User · 11 signatures": saveable, and unapprovable.
        try {
            $this->save([], [[
                'name' => 'dev 1',
                'approver_type' => 'user',
                'approver_id' => $this->userId,
                'quorum' => 11,
                'sla_hours' => 122,
            ]]);

            $this->fail('A single named approver was accepted with a quorum of 11.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('never be met', $e->getMessage());
        }
    }

    public function test_a_named_approver_step_must_actually_name_someone(): void
    {
        try {
            $this->save([], [[
                'name' => 'Unassigned',
                'approver_type' => 'user',
                'approver_id' => null,
                'quorum' => 1,
            ]]);

            $this->fail('A user step with nobody chosen was accepted.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('person must be chosen', $e->getMessage());
        }
    }

    public function test_a_role_step_must_actually_name_a_role(): void
    {
        try {
            $this->save([], [[
                'name' => 'Unassigned',
                'approver_type' => 'role',
                'approver_ref' => '',
                'quorum' => 1,
            ]]);

            $this->fail('A role step with no role was accepted.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('role must be chosen', $e->getMessage());
        }
    }

    public function test_a_role_step_may_require_several_signatures(): void
    {
        // The rule is about one *person*, not about quorums. A role can hold many people.
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code], [[
            'name' => 'Committee sign-off',
            'approver_type' => 'role',
            'approver_ref' => 'admin',
            'quorum' => 3,
            'sla_hours' => 48,
        ]]);

        $chain = $this->chain($code);
        $step = DB::table('procgov_approval_chain_steps')->where('chain_id', $chain->id)->first();

        $this->assertSame(3, (int) $step->quorum);
    }

    // ── Editing ─────────────────────────────────────────────────────────────────────────────────

    public function test_saving_an_existing_code_edits_that_chain_rather_than_adding_another(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code, 'name' => 'First name']);
        $this->save(['code' => $code, 'name' => 'Second name']);

        $this->assertSame(1, DB::table('procgov_approval_chains')
            ->where('company_id', $this->companyId)->where('code', $code)->count());

        $this->assertSame('Second name', $this->chain($code)->name);
    }

    public function test_editing_preserves_the_chains_identity_and_authorship(): void
    {
        // Rewriting the uuid would orphan anything already pointing at this chain, and rewriting
        // created_by would reassign authorship of an approval rule to whoever last edited an SLA.
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code, 'name' => 'Original']);
        $before = $this->chain($code);

        // Attribute it to someone other than the editor, so "authorship survived" is a real
        // assertion rather than one the editor's own id would satisfy by accident.
        $author = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'author-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('procgov_approval_chains')->where('id', $before->id)->update(['created_by' => $author]);

        $this->save(['code' => $code, 'name' => 'Edited']);
        $after = $this->chain($code);

        $this->assertSame($before->uuid, $after->uuid, 'The chain uuid changed on edit.');
        $this->assertSame($author, (int) $after->created_by, 'The chain creator was overwritten on edit.');
        $this->assertSame($before->created_at, $after->created_at, 'The creation time was overwritten on edit.');
        $this->assertSame('Edited', $after->name);
    }

    public function test_editing_replaces_the_steps_and_renumbers_them(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));

        $this->save(['code' => $code], [
            ['name' => 'One', 'approver_type' => 'role', 'approver_ref' => 'admin', 'quorum' => 1],
            ['name' => 'Two', 'approver_type' => 'role', 'approver_ref' => 'admin', 'quorum' => 1],
        ]);

        // Reordered and shortened, which is what the arrows and the remove button produce.
        $this->save(['code' => $code], [
            ['name' => 'Two', 'approver_type' => 'role', 'approver_ref' => 'admin', 'quorum' => 1],
        ]);

        $steps = DB::table('procgov_approval_chain_steps')
            ->where('chain_id', $this->chain($code)->id)
            ->orderBy('step_no')
            ->get();

        $this->assertCount(1, $steps);
        $this->assertSame('Two', $steps->first()->name);
        $this->assertSame(1, (int) $steps->first()->step_no, 'Steps were not renumbered from 1.');
    }

    // ── Retiring ────────────────────────────────────────────────────────────────────────────────

    private function retire(int $chainId): RedirectResponse
    {
        $request = Request::create("/procurement-governance/settings/chains/{$chainId}", 'DELETE');
        $request->setUserResolver(fn () => User::find($this->userId));

        return app(SettingsController::class)->destroyChain($request, $chainId);
    }

    public function test_a_chain_can_be_retired(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));
        $this->save(['code' => $code]);

        $this->retire((int) $this->chain($code)->id);

        $this->assertNotNull($this->chain($code)->deleted_at);
        $this->assertFalse((bool) $this->chain($code)->is_active);
    }

    public function test_a_chain_with_decisions_still_travelling_through_it_is_not_retired(): void
    {
        $code = 'TEST_'.Str::upper(Str::random(6));
        $this->save(['code' => $code]);
        $chainId = (int) $this->chain($code)->id;

        DB::table('procgov_approval_requests')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'chain_id' => $chainId,
            'chain_code' => $code,
            'subject_type' => 'deviation',
            'subject_id' => 1,
            'status' => 'PENDING',
            'current_step' => 1,
            'total_steps' => 1,
            'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->retire($chainId);
            $this->fail('A chain carrying a pending decision was retired.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('in flight', $e->getMessage());
        }

        $this->assertNull($this->chain($code)->deleted_at);
    }

    public function test_the_last_default_for_a_subject_is_not_retired(): void
    {
        // Removing it would leave the next request of that kind with nowhere to route.
        $code = 'TEST_'.Str::upper(Str::random(6));

        DB::table('procgov_approval_chains')
            ->where('company_id', $this->companyId)
            ->where('applies_to', 'deviation')
            ->update(['is_default' => false]);

        $this->save(['code' => $code, 'applies_to' => 'deviation', 'is_default' => true]);

        try {
            $this->retire((int) $this->chain($code)->id);
            $this->fail('The only default chain for a subject was retired.');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('only default', $e->getMessage());
        }
    }
}
