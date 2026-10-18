<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauApprovalStep;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Services\Bureau\BureauApprovalService;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The approval chain, and who is on a run.
 *
 * Approval used to be one act with one rule. A client who wants their HR manager to check the
 * figures and then their finance director to release the money had nowhere to say so, and the
 * bureau had nothing to show for having done it.
 */
class BureauApprovalChainTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $officer;
    private BureauCompany $client;

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

        DB::table('sa_tax_years')->updateOrInsert(
            ['country' => 'ZA', 'tax_year' => 2027],
            [
                'starts_on' => '2026-03-01', 'ends_on' => '2027-02-28',
                'brackets' => json_encode([[0, 0.18, 0]]),
                'primary_rebate' => 17235, 'secondary_rebate' => 9444, 'tertiary_rebate' => 3145,
                'threshold_under_65' => 95750, 'threshold_65_to_74' => 148217, 'threshold_75_plus' => 165689,
                'medical_credit_main' => 364, 'medical_credit_first_dependant' => 364,
                'medical_credit_additional' => 246,
                'uif_rate' => 0.01, 'uif_monthly_ceiling' => 17712,
                'sdl_rate' => 0.01, 'sdl_annual_threshold' => 500000,
                'eti' => json_encode(['qualify_max' => 7500, 'lower' => 2000, 'upper' => 4500,
                    'benefit_cap' => 6500, 'min_age' => 18, 'max_age' => 29, 'max_months' => 24,
                    'first_year_months' => 12,
                    'p1' => ['pct' => 0.75, 'flat' => 1500, 'taper' => 0.75],
                    'p2' => ['pct' => 0.375, 'flat' => 750, 'taper' => 0.375]]),
                'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        $this->officer = $this->user();
        $this->actingAs($this->officer);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Chain Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->officer->id, 'created_by' => $this->officer->id,
            'pay_frequency' => 'monthly', 'bank' => 'fnb',
            'bank_account_number' => '62000000001', 'bank_branch_code' => '250655',
        ]);
    }

    private function user(?string $role = null): User
    {
        $u = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'chain+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        if ($role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $u->assignRole($role);
        }

        return $u;
    }

    private function step(int $sequence, string $name, array $extra = []): BureauApprovalStep
    {
        return BureauApprovalStep::create(array_merge([
            'sequence' => $sequence, 'name' => $name,
            'approver_type' => BureauApprovalStep::BY_ROLE, 'role_name' => null,
            'bureau_company_id' => $this->client->id, 'company_id' => $this->companyId,
            'is_active' => true,
        ], $extra));
    }

    private function hire(array $attributes = []): BureauEmployee
    {
        return app(BureauEmployeeService::class)->create($this->client, array_merge([
            'first_name' => 'Zanele', 'last_name' => 'Nkosi',
            'start_date' => '2025-01-01', 'basic_salary' => 18000,
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'tax_number' => (string) random_int(1000000000, 9999999999),
            'bank_name' => 'FNB', 'branch_code' => '250655',
            'account_number' => '62' . random_int(100000000, 999999999),
        ], $attributes));
    }

    private function runs(): BureauPayRunService
    {
        return app(BureauPayRunService::class);
    }

    private function makeRun(string $type = 'regular'): BureauPayRun
    {
        return $this->runs()->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'tax_year' => 2027, 'run_type' => $type,
        ]);
    }

    // ── The roster ───────────────────────────────────────────────────────────

    public function test_a_regular_run_starts_with_everybody_on_its_cycle(): void
    {
        $this->hire();
        $this->hire(['first_name' => 'Bongani']);

        $run = $this->makeRun();

        $this->assertSame(2, $run->roster()->count());
        $this->assertSame(['auto', 'auto'], $run->roster()->pluck('added_via')->all());
    }

    public function test_a_bonus_run_starts_empty(): void
    {
        $this->hire();
        $this->hire(['first_name' => 'Bongani']);

        // The whole point: a bonus for three people must not sweep in the entire payroll.
        $this->assertSame(0, $this->makeRun('bonus')->roster()->count());
    }

    public function test_somebody_added_to_a_bonus_run_is_the_only_one_paid(): void
    {
        $this->hire();
        $chosen = $this->hire(['first_name' => 'Bongani']);

        $run = $this->makeRun('bonus');
        $this->runs()->addToRoster($run, (int) $chosen->id);

        $processed = $this->runs()->process($run);

        $this->assertSame(1, $processed->payslips()->count());
        $this->assertSame((int) $chosen->id, (int) $processed->payslips()->first()->bureau_employee_id);
    }

    public function test_employees_on_another_cycle_are_left_off_a_regular_run(): void
    {
        $this->hire();
        $this->hire(['first_name' => 'Weekly', 'pay_frequency' => 'weekly']);

        $this->assertSame(1, $this->makeRun()->roster()->count());
    }

    public function test_adding_the_same_person_twice_does_not_pay_them_twice(): void
    {
        $employee = $this->hire();
        $run = $this->makeRun('bonus');

        $this->assertTrue($this->runs()->addToRoster($run, (int) $employee->id));
        $this->assertFalse($this->runs()->addToRoster($run, (int) $employee->id));
        $this->assertSame(1, $run->roster()->count());
    }

    public function test_somebody_from_another_client_cannot_be_added(): void
    {
        $other = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Other ' . Str::random(4),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->officer->id, 'created_by' => $this->officer->id,
            'pay_frequency' => 'monthly',
        ]);
        $theirs = app(BureauEmployeeService::class)->create($other, [
            'first_name' => 'Not', 'last_name' => 'Ours', 'start_date' => '2025-01-01', 'basic_salary' => 1000,
        ]);

        $this->expectException(ValidationException::class);
        $this->runs()->addToRoster($this->makeRun('bonus'), (int) $theirs->id);
    }

    public function test_removing_somebody_takes_their_payslip_with_them(): void
    {
        $keep = $this->hire();
        $drop = $this->hire(['first_name' => 'Bongani']);

        $run = $this->runs()->process($this->makeRun());
        $this->assertSame(2, $run->payslips()->count());

        $this->runs()->removeFromRoster($run, (int) $drop->id);

        // Otherwise the run keeps paying somebody who is no longer on it.
        $this->assertSame(1, $run->payslips()->count());
        $this->assertSame((int) $keep->id, (int) $run->payslips()->first()->bureau_employee_id);
    }

    public function test_a_once_off_input_puts_somebody_on_the_run(): void
    {
        $employee = $this->hire();
        $run = $this->makeRun('bonus');

        $this->runs()->addInput($run, $employee, [
            'kind' => 'bonus', 'description' => 'December bonus', 'amount' => 5000,
        ]);

        // Attaching a bonus to a person is saying they are on the run.
        $this->assertSame(1, $this->runs()->process($run)->payslips()->count());
    }

    // ── The chain ────────────────────────────────────────────────────────────

    public function test_a_run_waits_on_each_step_in_turn(): void
    {
        $hr = $this->user('hr signer');
        $fin = $this->user('finance signer');
        $this->step(1, 'HR review', ['role_name' => 'hr signer']);
        $this->step(2, 'Finance release', ['role_name' => 'finance signer']);

        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->assertSame(1, $run->current_step, 'A calculated run stands on the first step.');

        $this->actingAs($hr);
        $this->runs()->approve($run);

        // One signature is not approval when the chain has two steps.
        $this->assertSame(PayRunLifecycle::REVIEW, $run->fresh()->status);
        $this->assertSame(2, $run->fresh()->current_step);

        $this->actingAs($fin);
        $this->runs()->approve($run->fresh());

        $this->assertSame(PayRunLifecycle::APPROVED, $run->fresh()->status);
        $this->assertNull($run->fresh()->current_step);
    }

    public function test_the_wrong_role_cannot_sign_a_step(): void
    {
        $this->step(1, 'Finance release', ['role_name' => 'finance signer']);
        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->actingAs($this->user('hr signer'));

        $this->expectException(ValidationException::class);
        $this->runs()->approve($run);
    }

    public function test_a_step_can_name_one_particular_person(): void
    {
        $director = $this->user();
        $this->step(1, 'Director sign-off', [
            'approver_type' => BureauApprovalStep::BY_USER, 'approver_id' => $director->id,
        ]);

        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->actingAs($this->user('finance signer'));
        try {
            $this->runs()->approve($run);
            $this->fail('Somebody other than the named director signed the step.');
        } catch (ValidationException) {
            // expected
        }

        $this->actingAs($director);
        $this->runs()->approve($run->fresh());

        $this->assertSame(PayRunLifecycle::APPROVED, $run->fresh()->status);
    }

    public function test_whoever_calculated_the_run_cannot_sign_it(): void
    {
        $this->step(1, 'Review', ['role_name' => null]);
        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        // The officer who created it is still acting; a chain every step of which the preparer can
        // sign is a chain that approves nothing.
        $this->expectException(ValidationException::class);
        $this->runs()->approve($run);
    }

    public function test_a_step_may_allow_self_approval_when_a_client_asks_for_it(): void
    {
        $this->step(1, 'Review', ['role_name' => null, 'allow_self_approval' => true]);
        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->assertSame(PayRunLifecycle::APPROVED, $this->runs()->approve($run)->status);
    }

    public function test_rejecting_sends_it_back_and_restarts_the_chain(): void
    {
        $signer = $this->user('hr signer');
        $this->step(1, 'HR review', ['role_name' => 'hr signer']);
        $this->step(2, 'Finance release', ['role_name' => 'hr signer']);

        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->actingAs($signer);
        app(BureauApprovalService::class)->approve($run);
        $this->assertSame(2, $run->fresh()->current_step);

        app(BureauApprovalService::class)->reject($run->fresh(), 'Overtime hours are wrong for two staff.');

        $run->refresh();
        // A step signed before the figures changed is not a signature on the figures that come out
        // afterwards, so the chain starts again.
        $this->assertSame(PayRunLifecycle::REVIEW, $run->status);
        $this->assertSame(1, $run->current_step);
        $this->assertSame(2, $run->approval_round);
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $this->step(1, 'Review', ['role_name' => null, 'allow_self_approval' => true]);
        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->expectException(ValidationException::class);
        app(BureauApprovalService::class)->reject($run, 'no');
    }

    public function test_every_decision_is_kept_including_the_refusals(): void
    {
        $signer = $this->user('hr signer');
        $this->step(1, 'HR review', ['role_name' => 'hr signer']);

        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->actingAs($signer);
        app(BureauApprovalService::class)->reject($run, 'Bank details missing for one employee.');
        app(BureauApprovalService::class)->approve($run->fresh());

        $history = app(BureauApprovalService::class)->historyFor($run->fresh());

        // "It came back once before it was signed" is part of the history of a payroll.
        $this->assertCount(2, $history);
        $this->assertEqualsCanonicalizing(['approved', 'rejected'], $history->pluck('decision')->all());
    }

    public function test_a_client_chain_replaces_the_tenant_default(): void
    {
        BureauApprovalStep::create([
            'sequence' => 1, 'name' => 'Tenant default', 'approver_type' => 'role',
            'role_name' => null, 'bureau_company_id' => null, 'company_id' => $this->companyId,
            'is_active' => true,
        ]);
        $this->step(1, 'Client step', ['role_name' => null]);

        $steps = app(BureauApprovalService::class)->stepsFor($this->client);

        $this->assertCount(1, $steps);
        $this->assertSame('Client step', $steps->first()->name);
    }

    public function test_a_client_with_no_chain_uses_the_tenant_default(): void
    {
        BureauApprovalStep::create([
            'sequence' => 1, 'name' => 'Tenant default', 'approver_type' => 'role',
            'role_name' => null, 'bureau_company_id' => null, 'company_id' => $this->companyId,
            'is_active' => true,
        ]);

        $this->assertSame('Tenant default',
            app(BureauApprovalService::class)->stepsFor($this->client)->first()->name);
    }

    public function test_with_no_chain_configured_a_run_still_approves(): void
    {
        // A single-operator bureau that never set one up is not blocked; the run's own
        // separation-of-duties rule still applies.
        $this->hire();
        $run = $this->runs()->process($this->makeRun());

        $this->assertSame(PayRunLifecycle::APPROVED, $this->runs()->approve($run)->status);
    }

    public function test_the_defaults_install_once(): void
    {
        $service = app(BureauApprovalService::class);

        $this->assertSame(count(BureauApprovalStep::DEFAULTS), $service->installDefaults($this->client));
        $this->assertSame(0, $service->installDefaults($this->client));
    }
}
