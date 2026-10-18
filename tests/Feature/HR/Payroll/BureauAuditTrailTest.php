<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\PayrollAuditLog;
use App\Modules\HR\Payroll\Services\Bureau\BureauAuditService;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The bureau engine's audit trail.
 *
 * It recorded nothing. The engine that runs other companies' payrolls, files their EMP201s and
 * releases their money had no answer to who processed a run or who changed an employee's bank
 * account the day before payment — which for a service bureau is the first thing a client's auditor
 * asks for, and the only defence when a payment is disputed.
 */
class BureauAuditTrailTest extends TestCase
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

        // The routes sit behind module.active, so the module has to be switched on for this
        // installation before any of its screens answer.
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'payroll_bureau'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Payroll Bureau', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();

        $this->agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'audit+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->agent);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Audited Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->agent->id, 'created_by' => $this->agent->id,
            'pay_frequency' => 'monthly',
        ]);
    }

    private function hire(array $attributes = []): BureauEmployee
    {
        return app(BureauEmployeeService::class)->create($this->client, array_merge([
            'first_name' => 'Nomsa', 'last_name' => 'Khumalo',
            'start_date' => '2026-01-01', 'basic_salary' => 12000,
            'bank_name' => 'FNB', 'branch_code' => '250655', 'account_number' => '62123456789',
        ], $attributes));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, PayrollAuditLog> */
    private function trail(?string $action = null)
    {
        return PayrollAuditLog::query()
            ->where('bureau_company_id', $this->client->id)
            ->when($action, fn ($q) => $q->where('action', $action))
            ->get();
    }

    public function test_creating_a_run_is_recorded_against_the_client(): void
    {
        app(BureauPayRunService::class)->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        ]);

        $entry = $this->trail(BureauAuditService::RUN_CREATED)->first();

        $this->assertNotNull($entry, 'The bureau engine used to record nothing at all.');
        $this->assertSame($this->agent->id, $entry->user_id);
        $this->assertSame('2026-03-31', substr((string) $entry->meta['period_end'], 0, 10));
    }

    public function test_a_changed_bank_account_is_recorded_with_who_changed_it(): void
    {
        $employee = $this->hire();

        app(BureauEmployeeService::class)->update($employee, ['account_number' => '62999888777']);

        $entry = $this->trail(BureauAuditService::BANKING_CHANGED)->first();

        $this->assertNotNull($entry, '"Who changed this account number" was unanswerable.');
        $this->assertSame($this->agent->id, $entry->user_id);
        $this->assertSame($employee->employee_number, $entry->meta['employee_number']);
    }

    public function test_the_account_number_is_masked_in_the_trail(): void
    {
        $employee = $this->hire();

        app(BureauEmployeeService::class)->update($employee, ['account_number' => '62999888777']);

        $entry = $this->trail(BureauAuditService::BANKING_CHANGED)->first();

        // The trail records *that* banking changed and by whom. Reprinting the full number in a log
        // more people can read than can open the employee record widens the exposure, not the audit.
        $this->assertSame('*******8777', $entry->new_values['account_number']);
        $this->assertStringNotContainsString('62999888777', json_encode($entry->new_values));
    }

    public function test_a_field_nobody_needs_audited_is_left_out(): void
    {
        $employee = $this->hire();

        app(BureauEmployeeService::class)->update($employee, ['last_name' => 'Khumalo-Dlamini']);

        // A corrected surname is noise, and a trail full of noise is one nobody reads.
        $this->assertCount(0, $this->trail(BureauAuditService::BANKING_CHANGED));
        $this->assertCount(0, $this->trail(BureauAuditService::TAX_DETAILS_CHANGED));
    }

    public function test_saving_the_same_value_is_not_a_change(): void
    {
        $employee = $this->hire();

        app(BureauEmployeeService::class)->update($employee, ['account_number' => '62123456789']);

        $this->assertCount(0, $this->trail(BureauAuditService::BANKING_CHANGED));
    }

    public function test_incentive_eligibility_changes_are_recorded(): void
    {
        $employee = $this->hire();

        // Moving somebody into the intern category opts them into the incentive automatically —
        // which is money claimed from SARS, so it belongs on the trail.
        app(BureauEmployeeService::class)->update($employee, [
            'employment_category' => EmploymentCategory::INTERN,
        ]);

        $entry = $this->trail(BureauAuditService::ETI_CHANGED)->first();

        $this->assertNotNull($entry);
        $this->assertSame(EmploymentCategory::INTERN, $entry->new_values['employment_category']);
    }

    public function test_a_pay_frequency_change_is_recorded(): void
    {
        $employee = $this->hire();

        // It decides which run somebody lands in. Moved quietly, an employee is simply not paid.
        app(BureauEmployeeService::class)->update($employee, ['pay_frequency' => 'weekly']);

        $entry = $this->trail(BureauAuditService::PAY_FREQUENCY_CHANGED)->first();

        $this->assertNotNull($entry);
        $this->assertSame('weekly', $entry->new_values['pay_frequency']);
    }

    public function test_banking_changed_together_reads_as_one_act(): void
    {
        $employee = $this->hire();

        app(BureauEmployeeService::class)->update($employee, [
            'bank_name' => 'Capitec', 'branch_code' => '470010', 'account_number' => '17123456789',
        ]);

        // Three columns, one act. Three rows describing it makes the trail harder to read, not
        // more complete.
        $entries = $this->trail(BureauAuditService::BANKING_CHANGED);

        $this->assertCount(1, $entries);
        $this->assertSame(['bank_name', 'branch_code', 'account_number'], array_keys($entries->first()->new_values));
    }

    public function test_adding_an_employee_is_recorded(): void
    {
        $employee = $this->hire();

        $entry = $this->trail(BureauAuditService::EMPLOYEE_CREATED)->first();

        $this->assertNotNull($entry);
        $this->assertSame($employee->id, $entry->meta['employee_id']);
    }

    public function test_terminating_an_employee_is_recorded(): void
    {
        $employee = $this->hire();

        app(BureauEmployeeService::class)->terminate($employee, '2026-06-30');

        $this->assertCount(1, $this->trail(BureauAuditService::EMPLOYEE_TERMINATED));
    }

    public function test_the_screen_renders_the_clients_trail(): void
    {
        $employee = $this->hire();
        app(BureauEmployeeService::class)->update($employee, ['account_number' => '62999888777']);

        $this->get(route('payroll.bureau.audit.index', $this->client->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/HR/Payroll/Bureau/AuditTrail')
                ->where('company.name', $this->client->name)
                // The trail stores a user id; a page of numbers is a page nobody can read.
                ->where('entries.data.0.user_name', $this->agent->email)
                ->where('entries.data.0.action', BureauAuditService::BANKING_CHANGED));
    }

    public function test_the_history_reads_back_newest_first(): void
    {
        $employee = $this->hire();
        app(BureauEmployeeService::class)->update($employee, ['account_number' => '62999888777']);

        $history = app(BureauAuditService::class)->historyFor($this->client);

        $this->assertGreaterThanOrEqual(2, $history->count());
        $this->assertSame(BureauAuditService::BANKING_CHANGED, $history->first()->action);
    }
}
