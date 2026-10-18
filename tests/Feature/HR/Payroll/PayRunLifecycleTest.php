<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Services\Bureau\BureauAuditService;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The states a client's payroll passes through.
 *
 * There were three, and finalising did four jobs at once: approving the figures, locking the
 * payslips, and implicitly clearing the run for payment. So whoever calculated a run could also
 * approve it and release the money, with nothing recorded but a timestamp and no name against the
 * decision. For a bureau paying other people's staff, that is the whole control missing.
 */
class PayRunLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $agent;
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
                'eti' => json_encode([
                    'qualify_max' => 7500, 'lower' => 2000, 'upper' => 4500, 'benefit_cap' => 6500,
                    'min_age' => 18, 'max_age' => 29, 'max_months' => 24, 'first_year_months' => 12,
                    'p1' => ['pct' => 0.75, 'flat' => 1500, 'taper' => 0.75],
                    'p2' => ['pct' => 0.375, 'flat' => 750, 'taper' => 0.375],
                ]),
                'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
            ],
        );

        $this->agent = $this->makeUser();
        $this->actingAs($this->agent);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Lifecycle Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->agent->id, 'created_by' => $this->agent->id,
            'pay_frequency' => 'monthly', 'bank' => 'fnb',
            'bank_account_number' => '62000000001', 'bank_branch_code' => '250655',
        ]);
    }

    private function makeUser(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'life+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
    }

    private function hire(): BureauEmployee
    {
        return app(BureauEmployeeService::class)->create($this->client, [
            'first_name' => 'Kagiso', 'last_name' => 'Molefe',
            'start_date' => '2025-01-01', 'basic_salary' => 15000,
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'tax_number' => (string) random_int(1000000000, 9999999999),
            'bank_name' => 'FNB', 'branch_code' => '250655', 'account_number' => '62' . random_int(100000000, 999999999),
        ]);
    }

    private function runs(): BureauPayRunService
    {
        return app(BureauPayRunService::class);
    }

    private function processedRun(): BureauPayRun
    {
        $this->hire();

        return $this->runs()->process($this->runs()->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]));
    }

    public function test_a_run_walks_the_whole_sequence(): void
    {
        $run = $this->processedRun();

        $this->assertSame(PayRunLifecycle::REVIEW, $run->status);
        $this->assertSame(PayRunLifecycle::APPROVED, $this->runs()->approve($run)->status);
        $this->assertSame(PayRunLifecycle::FINALISED, $this->runs()->finalise($run->fresh())->status);
        $this->assertSame(PayRunLifecycle::PAID, $this->runs()->markPaid($run->fresh())->status);
        $this->assertSame(PayRunLifecycle::COMPLETED, $this->runs()->complete($run->fresh())->status);
    }

    public function test_approval_records_who_signed_it_off(): void
    {
        $run = $this->processedRun();

        $approved = $this->runs()->approve($run);

        // The answer to the only question anybody asks about a disputed payment.
        $this->assertSame($this->agent->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_a_run_cannot_be_finalised_without_being_approved(): void
    {
        $run = $this->processedRun();

        // Finalising used to do the approving too, which is how one person came to calculate,
        // approve and release a payment on their own.
        $this->expectException(ValidationException::class);
        $this->runs()->finalise($run);
    }

    public function test_a_finalised_run_cannot_be_paid_twice(): void
    {
        $run = $this->processedRun();
        $this->runs()->approve($run);
        $this->runs()->finalise($run->fresh());
        $this->runs()->markPaid($run->fresh());

        $this->expectException(ValidationException::class);
        $this->runs()->markPaid($run->fresh());
    }

    public function test_an_approved_run_cannot_be_recalculated(): void
    {
        $run = $this->processedRun();
        $this->runs()->approve($run);

        // Recalculating past approval would move figures that already carry somebody's name.
        $this->expectException(ValidationException::class);
        $this->runs()->process($run->fresh());
    }

    public function test_an_approved_run_refuses_a_new_input(): void
    {
        $employee = $this->hire();
        $run = $this->processedRun();
        $this->runs()->approve($run);

        $this->expectException(ValidationException::class);
        $this->runs()->addInput($run->fresh(), $employee, [
            'kind' => 'bonus', 'description' => 'Slipped in afterwards', 'amount' => 5000,
        ]);
    }

    public function test_a_run_in_review_can_still_be_recalculated(): void
    {
        $run = $this->processedRun();

        // Fixing an input and pressing calculate again is the normal way to work.
        $this->assertSame(PayRunLifecycle::REVIEW, $this->runs()->process($run)->status);
    }

    // ── The authorised amendment ─────────────────────────────────────────────

    public function test_reopening_needs_a_reason(): void
    {
        $run = $this->processedRun();
        $this->runs()->approve($run);

        $this->expectException(ValidationException::class);
        $this->runs()->reopen($run->fresh(), 'oops');
    }

    public function test_reopening_clears_the_approval_and_keeps_the_reason(): void
    {
        $run = $this->processedRun();
        $this->runs()->approve($run);
        $this->runs()->finalise($run->fresh());

        $reopened = $this->runs()->reopen($run->fresh(), 'Wrong overtime hours captured for two staff.');

        $this->assertSame(PayRunLifecycle::REVIEW, $reopened->status);
        // An approval that survives the figures it approved is worse than no approval.
        $this->assertNull($reopened->approved_by);
        $this->assertNull($reopened->finalised_at);
        $this->assertSame('Wrong overtime hours captured for two staff.', $reopened->reopen_reason);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertSame($this->agent->id, $reopened->reopened_by);
    }

    public function test_reopening_unlocks_the_payslips(): void
    {
        $run = $this->processedRun();
        $this->runs()->approve($run);
        $this->runs()->finalise($run->fresh());

        $this->assertTrue((bool) $run->payslips()->first()->is_locked);

        $this->runs()->reopen($run->fresh(), 'Recalculating after a corrected salary.');

        $this->assertFalse((bool) $run->payslips()->first()->fresh()->is_locked);
    }

    public function test_the_reason_reaches_the_audit_trail(): void
    {
        $run = $this->processedRun();
        $this->runs()->approve($run);
        $this->runs()->reopen($run->fresh(), 'Client sent a corrected input file.');

        $entry = DB::table('payroll_audit_logs')
            ->where('bureau_pay_run_id', $run->id)
            ->where('action', BureauAuditService::RUN_REOPENED)
            ->first();

        $this->assertNotNull($entry);
        $this->assertStringContainsString('corrected input file', $entry->description);
    }

    public function test_a_run_still_in_review_has_nothing_to_reopen(): void
    {
        $run = $this->processedRun();

        $this->expectException(ValidationException::class);
        $this->runs()->reopen($run, 'Nothing has been signed off yet.');
    }

    // ── Separation of duties ─────────────────────────────────────────────────

    public function test_the_person_who_calculated_a_run_cannot_approve_it_when_somebody_else_could(): void
    {
        $colleague = $this->makeUser();
        $this->client->members()->attach($colleague->id, ['role' => 'agent']);

        $run = $this->processedRun();

        $this->expectException(ValidationException::class);
        $this->runs()->approve($run);
    }

    public function test_a_colleague_can_approve_what_somebody_else_calculated(): void
    {
        $colleague = $this->makeUser();
        $this->client->members()->attach($colleague->id, ['role' => 'agent']);

        $run = $this->processedRun();

        $this->actingAs($colleague);

        $this->assertSame(PayRunLifecycle::APPROVED, $this->runs()->approve($run)->status);
        $this->assertSame($colleague->id, $run->fresh()->approved_by);
    }

    public function test_a_one_person_bureau_can_still_approve_its_own_work(): void
    {
        // Nobody to hand it to. Refusing here would stop a real payroll for a rule that cannot be
        // satisfied — the separation applies from the moment a second person has access.
        $run = $this->processedRun();

        $this->assertSame(PayRunLifecycle::APPROVED, $this->runs()->approve($run)->status);
    }
}
