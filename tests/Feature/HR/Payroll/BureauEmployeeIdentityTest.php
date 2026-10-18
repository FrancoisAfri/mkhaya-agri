<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Who an employee is, and whether they earn leave.
 *
 * Two defects that only look separate. The employee number is the key a client's own records are
 * kept on and the key the Leave Bureau importer matches people by — but it was a nullable column
 * with no index, missing from the import template entirely, and issued by a count()+1 loop. So a
 * client migrating four hundred staff across lost every real number, and duplicates were possible
 * both by collision and by re-uploading a corrected file.
 *
 * And nothing gave a new employee a leave profile. Accrual is gated on that profile existing, so
 * an employee added through the Payroll Bureau accrued nothing at all — with every screen reporting
 * success, which is why it could survive so long unnoticed.
 */
class BureauEmployeeIdentityTest extends TestCase
{
    use DatabaseTransactions;

    private User $agent;
    private BureauCompany $client;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        app(CurrentCompany::class)->set((int) $companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['leave_bureau' => 'Leave Bureau', 'payroll_bureau' => 'Payroll Bureau'] as $code => $name) {
            DB::table('security_modules')->updateOrInsert(['code_name' => $code],
                ['uuid' => (string) Str::uuid(), 'name' => $name, 'category_id' => $categoryId,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        Cache::flush();

        $this->agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ident+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => (int) $companyId,
        ]);
        $this->actingAs($this->agent);

        $this->client = BureauCompany::create([
            'agent_id' => $this->agent->id, 'name' => 'Identity Co', 'created_by' => $this->agent->id,
        ]);
    }

    private function service(): BureauEmployeeService
    {
        return app(BureauEmployeeService::class);
    }

    /** Leave Bureau does not ship in this product, so a client is payroll-only. */
    private function withLeave(?BureauCompany $client = null): BureauCompany
    {
        return $client ?? $this->client;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'start_date' => '2024-01-01',
        ], $overrides);
    }

    // ── The number the client already uses ───────────────────────────────────

    public function test_a_supplied_employee_number_is_kept_exactly(): void
    {
        // The whole point: a client coming off another system has these numbers on payslips, in
        // files and in their own spreadsheets. Re-issuing them is not a cosmetic difference.
        $employee = $this->service()->create($this->client, $this->row(['employee_number' => 'STAFF-042']));

        $this->assertSame('STAFF-042', $employee->employee_number);
    }

    public function test_a_missing_employee_number_is_generated(): void
    {
        $employee = $this->service()->create($this->client, $this->row());

        $this->assertSame('EMP0001', $employee->employee_number);
    }

    public function test_generation_continues_from_the_highest_issued_not_the_count(): void
    {
        // The old count()+1 generator handed out EMP0002 here, colliding with nothing yet — but the
        // moment an employee is terminated and removed from the count it reissues a live number.
        $this->service()->create($this->client, $this->row(['employee_number' => 'EMP0009']));

        $next = $this->service()->create($this->client, $this->row(['first_name' => 'Thandi']));

        $this->assertSame('EMP0010', $next->employee_number);
    }

    public function test_the_same_number_cannot_be_used_twice_at_one_client(): void
    {
        $this->service()->create($this->client, $this->row(['employee_number' => 'DUP-1']));

        $this->expectException(ValidationException::class);
        $this->service()->create($this->client, $this->row(['employee_number' => 'DUP-1', 'first_name' => 'Thandi']));
    }

    public function test_two_clients_may_each_have_their_own_emp0001(): void
    {
        $other = BureauCompany::create([
            'agent_id' => $this->agent->id, 'name' => 'Other Co', 'created_by' => $this->agent->id,
        ]);

        $mine = $this->service()->create($this->client, $this->row(['employee_number' => 'EMP0001']));
        $theirs = $this->service()->create($other, $this->row(['employee_number' => 'EMP0001']));

        // Uniqueness is per client, not per bureau: two clients are separate registers.
        $this->assertSame('EMP0001', $mine->employee_number);
        $this->assertSame('EMP0001', $theirs->employee_number);
    }

    // ── Importing a register ─────────────────────────────────────────────────

    public function test_an_import_row_matching_an_existing_number_updates_rather_than_duplicates(): void
    {
        $this->service()->create($this->client, $this->row(['employee_number' => 'E-1', 'occupation' => 'Clerk']));

        $result = $this->service()->import($this->client, [
            $this->row(['employee_number' => 'E-1', 'occupation' => 'Supervisor']),
        ]);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $this->client->employees()->count(), 'The same person must not be created twice.');
        $this->assertSame('Supervisor', $this->client->employees()->first()->occupation);
    }

    public function test_uploading_the_same_file_twice_creates_nobody_new(): void
    {
        // The behaviour a bureau actually relies on: fix three cells, upload the corrected file
        // again. Previously that produced a second copy of all four hundred people.
        $rows = [
            $this->row(['employee_number' => 'E-1']),
            $this->row(['employee_number' => 'E-2', 'first_name' => 'Thandi']),
        ];

        $this->service()->import($this->client, $rows);
        $second = $this->service()->import($this->client, $rows);

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['updated']);
        $this->assertSame(2, $this->client->employees()->count());
    }

    public function test_a_blank_cell_does_not_wipe_a_value_already_on_file(): void
    {
        $this->service()->create($this->client, $this->row([
            'employee_number' => 'E-1', 'bank_name' => 'FNB', 'account_number' => '62012345678',
        ]));

        // A sparse re-upload — the client filled in only the columns they were correcting.
        $this->service()->import($this->client, [
            ['employee_number' => 'E-1', 'first_name' => 'Sipho', 'last_name' => 'Ndlovu',
                'bank_name' => '', 'account_number' => '', 'occupation' => 'Driver'],
        ]);

        $employee = $this->client->employees()->first();
        $this->assertSame('FNB', $employee->bank_name, 'A blank cell must not erase banking details.');
        $this->assertSame('Driver', $employee->occupation);
    }

    public function test_rows_without_a_start_date_are_imported_but_counted(): void
    {
        $result = $this->service()->import($this->client, [
            ['employee_number' => 'E-1', 'first_name' => 'Sipho', 'last_name' => 'Ndlovu'],
        ]);

        // Imported, because refusing the row helps nobody — but reported, because this employee
        // accrues nothing until a start date arrives.
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['without_start_date']);
    }

    public function test_the_import_template_carries_the_number_and_start_date(): void
    {
        $this->withLeave();

        $csv = $this->get(route('payroll.bureau.employees.template', $this->client->uuid))
            ->assertOk()->getContent() ?: '';
        $header = strtok($csv, "\n");

        $this->assertStringContainsString('employee_number', $header,
            'Without this column every imported employee loses their real number.');
        $this->assertStringContainsString('start_date', $header,
            'Without this column every imported employee accrues nothing.');
    }

    // ── The date leave hangs off ─────────────────────────────────────────────

    public function test_adding_an_employee_without_a_start_date_is_refused(): void
    {
        $this->withLeave();

        $this->post(route('payroll.bureau.employees.store', $this->client->uuid), [
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu',
        ])->assertSessionHasErrors('start_date');

        $this->assertSame(0, $this->client->employees()->count());
    }

    public function test_the_add_form_refuses_a_number_another_employee_holds(): void
    {
        $this->withLeave();
        $this->service()->create($this->client, $this->row(['employee_number' => 'TAKEN']));

        $this->post(route('payroll.bureau.employees.store', $this->client->uuid), [
            'first_name' => 'Thandi', 'last_name' => 'Mokoena',
            'start_date' => '2026-01-01', 'employee_number' => 'TAKEN',
        ])->assertSessionHasErrors('employee_number');
    }
}
