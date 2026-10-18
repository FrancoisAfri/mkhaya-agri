<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Core\User\Models\User;
use App\Modules\Procurement\Governance\Services\ApprovalRouter;
use App\Modules\Procurement\Governance\Support\Rules\ConditionMatcher;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * §5.2 approval chains: selection, separation of duties and quorum.
 *
 * The two protections enforced in code rather than left to configuration are the ones tested
 * hardest here. No tenant would knowingly configure away separation of duties, and every tenant
 * would eventually be embarrassed by its absence — a two-level chain signed twice by one person is
 * one level of approval wearing a disguise.
 */
class ApprovalRouterTest extends TestCase
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

        // Isolate from the tenant's seeded starter chains, so selection is tested against exactly
        // the chains each case declares.
        DB::table('procgov_approval_chains')
            ->where('company_id', $this->companyId)
            ->update(['is_active' => false]);
    }

    private function router(): ApprovalRouter
    {
        return app(ApprovalRouter::class);
    }

    private function user(): int
    {
        return DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'approver-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<array<string,mixed>>  $steps */
    private function chain(string $code, ?array $conditions, array $steps, bool $isDefault = false): int
    {
        $chainId = DB::table('procgov_approval_chains')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'code' => $code.'-'.Str::random(4),
            'name' => $code,
            'applies_to' => 'deviation',
            'conditions' => $conditions ? json_encode($conditions) : null,
            'specificity' => app(ConditionMatcher::class)
                ->specificity($conditions),
            'is_active' => true,
            'is_default' => $isDefault,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($steps as $i => $step) {
            DB::table('procgov_approval_chain_steps')->insert(array_merge([
                'company_id' => $this->companyId,
                'chain_id' => $chainId,
                'step_no' => $i + 1,
                'quorum' => 1,
                'is_mandatory' => true,
                'can_delegate' => true,
                'created_at' => now(), 'updated_at' => now(),
            ], $step));
        }

        return $chainId;
    }

    private function subject(float $amount = 750_000): RuleSubject
    {
        $deviationId = DB::table('procgov_deviations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'reference' => 'DEV-TEST-'.Str::random(6),
            'title' => 'Emergency pump replacement',
            'procurement_value' => $amount,
            'currency' => 'ZAR',
            'risk_level' => 'HIGH',
            'status' => 'DRAFT',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return new RuleSubject(
            companyId: $this->companyId,
            type: 'procgov_deviations',
            id: $deviationId,
            reference: 'DEV-TEST',
            amount: $amount,
            riskLevel: 'HIGH',
            status: 'DRAFT',
        );
    }

    // ── Chain selection ─────────────────────────────────────────────────────────────────────────

    public function test_the_most_specific_matching_chain_wins(): void
    {
        $broad = $this->chain('broad', null, [
            ['name' => 'Manager', 'approver_type' => 'permission', 'approver_ref' => 'procgov.deviation.approve'],
        ], isDefault: true);

        $narrow = $this->chain('high-value', ['min_value' => 500_000], [
            ['name' => 'Manager', 'approver_type' => 'permission', 'approver_ref' => 'procgov.deviation.approve'],
            ['name' => 'Accounting officer', 'approver_type' => 'permission', 'approver_ref' => 'procgov.configure'],
        ]);

        $selected = $this->router()->selectChain('deviation', $this->subject(750_000));

        $this->assertSame($narrow, (int) $selected->id);
        $this->assertNotSame($broad, (int) $selected->id);
    }

    public function test_a_value_below_the_band_falls_to_the_default_chain(): void
    {
        $broad = $this->chain('broad', null, [
            ['name' => 'Manager', 'approver_type' => 'permission', 'approver_ref' => 'procgov.deviation.approve'],
        ], isDefault: true);

        $this->chain('high-value', ['min_value' => 500_000], [
            ['name' => 'Accounting officer', 'approver_type' => 'permission', 'approver_ref' => 'procgov.configure'],
        ]);

        $selected = $this->router()->selectChain('deviation', $this->subject(100_000));

        $this->assertSame($broad, (int) $selected->id);
    }

    public function test_selection_is_deterministic_across_repeated_calls(): void
    {
        // An approval route that varies between two identical requests is indefensible at audit.
        $this->chain('a', ['min_value' => 500_000], [
            ['name' => 'A', 'approver_type' => 'permission', 'approver_ref' => 'procgov.configure'],
        ]);
        $this->chain('b', ['min_value' => 500_000], [
            ['name' => 'B', 'approver_type' => 'permission', 'approver_ref' => 'procgov.configure'],
        ]);

        $first = $this->router()->selectChain('deviation', $this->subject());
        $second = $this->router()->selectChain('deviation', $this->subject());

        $this->assertSame((int) $first->id, (int) $second->id);
    }

    public function test_starting_without_any_configured_chain_is_refused_not_auto_approved(): void
    {
        // Silent auto-approval of the highest-risk transaction in procurement is the worst
        // available failure mode.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No approval chain is configured/');

        $this->router()->start('deviation', $this->subject());
    }

    public function test_a_chain_whose_steps_all_skip_is_refused(): void
    {
        $this->chain('conditional', null, [
            [
                'name' => 'Only for huge values',
                'approver_type' => 'permission',
                'approver_ref' => 'procgov.configure',
                'conditions' => json_encode(['min_value' => 50_000_000]),
            ],
        ], isDefault: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no steps that apply/');

        $this->router()->start('deviation', $this->subject(100_000));
    }

    // ── Running a chain ─────────────────────────────────────────────────────────────────────────

    public function test_steps_are_renumbered_so_the_user_never_sees_a_gap(): void
    {
        $this->chain('mixed', null, [
            [
                'name' => 'Skipped',
                'approver_type' => 'permission',
                'approver_ref' => 'procgov.configure',
                'conditions' => json_encode(['min_value' => 50_000_000]),
            ],
            ['name' => 'Applies', 'approver_type' => 'permission', 'approver_ref' => 'procgov.configure'],
            ['name' => 'Also applies', 'approver_type' => 'permission', 'approver_ref' => 'procgov.deviation.approve'],
        ], isDefault: true);

        $request = $this->router()->start('deviation', $this->subject(100_000));

        $steps = DB::table('procgov_approval_steps')
            ->where('request_id', $request->id)
            ->orderBy('step_no')
            ->pluck('step_no')
            ->all();

        $this->assertSame([1, 2], $steps);
        $this->assertSame(2, (int) $request->total_steps);
    }

    public function test_the_first_step_activates_and_later_steps_wait(): void
    {
        $this->chain('two-level', null, [
            ['name' => 'First', 'approver_type' => 'permission', 'approver_ref' => 'procgov.configure'],
            ['name' => 'Second', 'approver_type' => 'permission', 'approver_ref' => 'procgov.deviation.approve'],
        ], isDefault: true);

        $request = $this->router()->start('deviation', $this->subject());

        $statuses = DB::table('procgov_approval_steps')
            ->where('request_id', $request->id)
            ->orderBy('step_no')
            ->pluck('status')
            ->all();

        $this->assertSame(['ACTIVE', 'PENDING'], $statuses);
    }

    // ── Separation of duties ────────────────────────────────────────────────────────────────────

    public function test_the_requester_cannot_approve_their_own_request(): void
    {
        $requester = $this->user();

        $this->chain('single', null, [
            ['name' => 'Manager', 'approver_type' => 'user', 'approver_id' => $requester],
        ], isDefault: true);

        $this->actingAs(User::find($requester));

        $request = $this->router()->start('deviation', $this->subject());

        $this->assertFalse($this->router()->mayDecide((int) $request->id, $requester));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot also approve it/');

        $this->router()->decide((int) $request->id, $requester, 'APPROVED');
    }

    public function test_one_person_cannot_provide_two_levels_of_approval(): void
    {
        $requester = $this->user();
        $approver = $this->user();

        $this->chain('two-level', null, [
            ['name' => 'First', 'approver_type' => 'user', 'approver_id' => $approver],
            ['name' => 'Second', 'approver_type' => 'user', 'approver_id' => $approver],
        ], isDefault: true);

        $this->actingAs(User::find($requester));
        $request = $this->router()->start('deviation', $this->subject());

        $this->router()->decide((int) $request->id, $approver, 'APPROVED');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already approved this request at another level/');

        $this->router()->decide((int) $request->id, $approver, 'APPROVED');
    }

    public function test_someone_the_step_is_not_assigned_to_cannot_decide(): void
    {
        $requester = $this->user();
        $assigned = $this->user();
        $stranger = $this->user();

        $this->chain('single', null, [
            ['name' => 'Manager', 'approver_type' => 'user', 'approver_id' => $assigned],
        ], isDefault: true);

        $this->actingAs(User::find($requester));
        $request = $this->router()->start('deviation', $this->subject());

        $this->assertFalse($this->router()->mayDecide((int) $request->id, $stranger));
        $this->assertTrue($this->router()->mayDecide((int) $request->id, $assigned));
    }

    // ── Outcomes ────────────────────────────────────────────────────────────────────────────────

    public function test_approving_the_last_step_completes_the_request(): void
    {
        $requester = $this->user();
        $approver = $this->user();

        $this->chain('single', null, [
            ['name' => 'Manager', 'approver_type' => 'user', 'approver_id' => $approver],
        ], isDefault: true);

        $this->actingAs(User::find($requester));
        $request = $this->router()->start('deviation', $this->subject());

        $updated = $this->router()->decide((int) $request->id, $approver, 'APPROVED', 'Agreed.');

        $this->assertSame('APPROVED', $updated->status);
        $this->assertNotNull($updated->completed_at);
    }

    public function test_a_rejection_ends_the_chain_immediately(): void
    {
        $requester = $this->user();
        $first = $this->user();
        $second = $this->user();

        $this->chain('two-level', null, [
            ['name' => 'First', 'approver_type' => 'user', 'approver_id' => $first],
            ['name' => 'Second', 'approver_type' => 'user', 'approver_id' => $second],
        ], isDefault: true);

        $this->actingAs(User::find($requester));
        $request = $this->router()->start('deviation', $this->subject());

        $updated = $this->router()->decide((int) $request->id, $first, 'REJECTED', 'Not justified.');

        $this->assertSame('REJECTED', $updated->status);

        // The second level must never become active on a rejected request.
        $secondStep = DB::table('procgov_approval_steps')
            ->where('request_id', $request->id)->where('step_no', 2)->first();

        $this->assertSame('PENDING', $secondStep->status);
    }

    public function test_a_quorum_step_needs_every_signature_before_it_advances(): void
    {
        $requester = $this->user();
        $a = $this->user();
        $b = $this->user();

        $roleName = 'procgov-test-panel-'.Str::random(5);
        $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
        User::find($a)->assignRole($role);
        User::find($b)->assignRole($role);

        $this->chain('panel', null, [
            ['name' => 'Panel', 'approver_type' => 'role', 'approver_ref' => $roleName, 'quorum' => 2],
        ], isDefault: true);

        $this->actingAs(User::find($requester));
        $request = $this->router()->start('deviation', $this->subject());

        $afterFirst = $this->router()->decide((int) $request->id, $a, 'APPROVED');
        $this->assertSame('PENDING', $afterFirst->status);

        $afterSecond = $this->router()->decide((int) $request->id, $b, 'APPROVED');
        $this->assertSame('APPROVED', $afterSecond->status);
    }

    public function test_withdrawing_cancels_the_outstanding_steps(): void
    {
        $requester = $this->user();

        $this->chain('single', null, [
            ['name' => 'Manager', 'approver_type' => 'permission', 'approver_ref' => 'procgov.configure'],
        ], isDefault: true);

        $this->actingAs(User::find($requester));
        $request = $this->router()->start('deviation', $this->subject());

        $this->router()->withdraw((int) $request->id, 'Superseded by a new request.');

        $this->assertSame('WITHDRAWN', DB::table('procgov_approval_requests')->find($request->id)->status);
        $this->assertSame(0, DB::table('procgov_approval_steps')
            ->where('request_id', $request->id)
            ->whereIn('status', ['ACTIVE', 'PENDING'])
            ->count());
    }
}
