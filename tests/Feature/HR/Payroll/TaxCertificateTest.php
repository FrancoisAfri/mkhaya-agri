<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\TaxCertificate;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Services\TaxCertificateService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tax certificates as records of what was issued.
 *
 * An IRP5 used to be a query: press download and the totals were recomputed from whatever the
 * payroll said at that moment. Right the first time, and wrong every time after — an employee whose
 * February payslip is corrected in June receives a different certificate from the one they filed
 * their return with, and nothing anywhere records that the two differ.
 */
class TaxCertificateTest extends TestCase
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

        $this->agent = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'cert+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
            'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->agent);

        $this->client = BureauCompany::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Certificate Client ' . Str::random(5),
            'is_active' => true, 'company_id' => $this->companyId,
            'agent_id' => $this->agent->id, 'created_by' => $this->agent->id,
            'pay_frequency' => 'monthly', 'paye_reference' => '7100000001',
            'bank' => 'fnb', 'bank_account_number' => '62000000001', 'bank_branch_code' => '250655',
        ]);
    }

    private function hire(array $attributes = []): BureauEmployee
    {
        return app(BureauEmployeeService::class)->create($this->client, array_merge([
            'first_name' => 'Naledi', 'last_name' => 'Mahlangu',
            'start_date' => '2026-03-01', 'basic_salary' => 20000,
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'tax_number' => (string) random_int(1000000000, 9999999999),
            'bank_name' => 'FNB', 'branch_code' => '250655', 'account_number' => '62' . random_int(100000000, 999999999),
        ], $attributes));
    }

    /** A month, run all the way to finalised so its figures are settled. */
    private function finalisedRun(string $start = '2026-03-01', string $end = '2026-03-31'): BureauPayRun
    {
        $runs = app(BureauPayRunService::class);
        $run = $runs->create($this->client, ['period_start' => $start, 'period_end' => $end, 'tax_year' => 2027]);
        $runs->process($run);
        $runs->approve($run->fresh());

        return $runs->finalise($run->fresh());
    }

    private function issue(?string $reason = null)
    {
        return app(TaxCertificateService::class)->issueForClient($this->client, 2027, TaxCertificateService::FULL_YEAR, $reason);
    }

    public function test_a_certificate_is_issued_from_a_finalised_year(): void
    {
        $employee = $this->hire();
        $this->finalisedRun();

        $issued = $this->issue();

        $this->assertCount(1, $issued);
        $certificate = $issued->first();
        $this->assertSame((int) $employee->id, (int) $certificate->employee_id);
        $this->assertSame(2027, $certificate->tax_year);
        $this->assertSame('202702', $certificate->period);
        $this->assertGreaterThan(0, (float) $certificate->gross);
    }

    public function test_the_figures_are_kept_rather_than_recomputed(): void
    {
        $this->hire();
        $this->finalisedRun();

        $issued = $this->issue()->first();
        $gross = (float) $issued->gross;

        // The payroll moves afterwards — a payslip corrected, a run reprocessed, anything.
        DB::table('bureau_payslips')->where('bureau_pay_run_id', BureauPayRun::query()->value('id'))->update(['gross' => 1]);

        // The certificate does not. That is the whole point: an employee asking for a copy in July
        // must receive the document they filed their return with.
        $this->assertSame($gross, (float) $issued->fresh()->gross);
    }

    public function test_nothing_changed_means_nothing_reissued(): void
    {
        $this->hire();
        $this->finalisedRun();

        $first = $this->issue()->first();
        $second = $this->issue()->first();

        // Otherwise pressing generate twice hands every employee a version 2 that says exactly what
        // version 1 said, and the history stops meaning anything.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->version);
    }

    public function test_a_changed_figure_issues_a_new_version_that_supersedes_the_old(): void
    {
        $employee = $this->hire();
        $this->finalisedRun();
        $first = $this->issue()->first();

        // A second month, so the year's totals genuinely change.
        $this->finalisedRun('2026-04-01', '2026-04-30');
        $second = $this->issue('Second month processed.')->first();

        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame('Second month processed.', $second->reissue_reason);
        $this->assertGreaterThan((float) $first->gross, (float) $second->gross);

        // Both survive. SARS works in a five-year window and "what did we give them before that"
        // has to be answerable for all of it.
        $this->assertSame(2, TaxCertificate::query()
            ->forEmployee(TaxCertificate::BUREAU, (int) $employee->id)->count());
    }

    public function test_only_the_newest_version_is_current(): void
    {
        $employee = $this->hire();
        $this->finalisedRun();
        $this->issue();
        $this->finalisedRun('2026-04-01', '2026-04-30');
        $second = $this->issue()->first();

        $current = TaxCertificate::query()
            ->forEmployee(TaxCertificate::BUREAU, (int) $employee->id)
            ->current()
            ->get();

        $this->assertCount(1, $current);
        $this->assertSame($second->id, $current->first()->id);
    }

    public function test_somebody_who_paid_no_tax_gets_an_it3a(): void
    {
        // Under the threshold, so no PAYE. SARS treats the two as different documents and rejects
        // an IRP5 sent in place of an IT3(a).
        $this->hire(['basic_salary' => 1500]);
        $this->finalisedRun();

        $this->assertSame(TaxCertificate::IT3A, $this->issue()->first()->type);
    }

    public function test_somebody_who_paid_tax_gets_an_irp5(): void
    {
        $this->hire(['basic_salary' => 45000]);
        $this->finalisedRun();

        $this->assertSame(TaxCertificate::IRP5, $this->issue()->first()->type);
    }

    public function test_the_certificate_carries_the_sars_source_codes(): void
    {
        $this->hire(['basic_salary' => 45000]);
        $this->finalisedRun();

        $codes = $this->issue()->first()->source_codes;

        // Kept as a map rather than as columns, because the code set is version-specific and a code
        // SARS adds next year must not need a migration.
        $this->assertArrayHasKey('3699', $codes);   // gross remuneration
        $this->assertArrayHasKey('4102', $codes);   // PAYE
        $this->assertArrayHasKey('4141', $codes);   // UIF
    }

    public function test_the_employee_is_captured_as_they_were(): void
    {
        $employee = $this->hire();
        $this->finalisedRun();
        $certificate = $this->issue()->first();

        $originalTaxNumber = $employee->tax_number;
        $employee->forceFill(['tax_number' => '9999999999'])->save();

        // A tax number corrected next year must not silently rewrite a certificate already filed.
        $this->assertSame($originalTaxNumber, $certificate->fresh()->employee_snapshot['tax_number']);
    }

    public function test_a_year_with_no_finalised_run_certifies_nothing(): void
    {
        $this->hire();
        // Created and calculated, but never approved or finalised.
        $runs = app(BureauPayRunService::class);
        $runs->process($runs->create($this->client, [
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'tax_year' => 2027,
        ]));

        // A certificate built from a run still in review states a number that is about to change,
        // and it is the employee who finds out.
        $this->expectException(ValidationException::class);
        $this->issue();
    }

    public function test_the_certificate_number_is_stable_and_carries_the_paye_reference(): void
    {
        $this->hire();
        $this->finalisedRun();

        $number = $this->issue()->first()->certificate_number;

        $this->assertStringStartsWith('7100000001', $number);
        $this->assertSame($number, $this->issue()->first()->certificate_number);
    }

    // ── The screens ──────────────────────────────────────────────────────────

    public function test_certificates_are_searchable_by_what_somebody_actually_has(): void
    {
        $this->withModule();
        $employee = $this->hire();
        $this->finalisedRun();
        $this->issue();

        // The things somebody has in front of them when they ring in July: a name, a number, a
        // tax number. Not a uuid.
        foreach ([$employee->employee_number, $employee->tax_number, 'Naledi'] as $term) {
            $this->get(route('payroll.tax-certificates.index', ['search' => $term]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->has('certificates.data', 1));
        }
    }

    public function test_a_superseded_version_is_out_of_the_worklist_but_still_reachable(): void
    {
        $this->withModule();
        $this->hire();
        $this->finalisedRun();
        $this->issue();
        $this->finalisedRun('2026-04-01', '2026-04-30');
        $this->issue();

        $this->get(route('payroll.tax-certificates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('certificates.data', 1));

        $this->get(route('payroll.tax-certificates.index', ['include_superseded' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('certificates.data', 2));
    }

    public function test_downloading_reads_back_what_was_issued(): void
    {
        $this->withModule();
        $this->hire(['basic_salary' => 45000]);
        $this->finalisedRun();
        $certificate = $this->issue()->first();

        $body = $this->get(route('payroll.tax-certificates.download', $certificate->uuid))
            ->assertOk()->getContent();

        $this->assertStringContainsString($certificate->certificate_number, $body);
        $this->assertStringContainsString('4102,', $body);
    }

    public function test_generating_from_the_screen_records_it_on_the_audit_trail(): void
    {
        $this->withModule();
        $this->hire();
        $this->finalisedRun();

        $this->post(route('payroll.tax-certificates.generate'), [
            'client' => $this->client->uuid, 'tax_year' => 2027,
        ])->assertRedirect();

        // A tax certificate carries a person's whole year of earnings and their tax number. Who
        // took a copy is what a client asks after one turns up somewhere it should not have.
        $this->assertDatabaseHas('payroll_audit_logs', [
            'bureau_company_id' => $this->client->id,
            'action' => 'bureau.certificates.generated',
        ]);
    }

    private function withModule(): void
    {
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'payroll'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Payroll', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();
    }
}
