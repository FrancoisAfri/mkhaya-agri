<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\HR\Payroll\Models\Beneficiary;
use App\Modules\HR\Payroll\Models\DeductionReference;
use App\Modules\HR\Payroll\Models\FileSpecification;
use App\Modules\HR\Payroll\Models\FileSpecificationField;
use App\Modules\HR\Payroll\Models\Remittance;
use App\Modules\HR\Payroll\Services\DeductionFileService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The deduction file a third party actually reads.
 *
 * The layout engine, the renderer and the per-employee remittance schedule were all already here
 * and unconnected. What was missing was the reference each person is known by at the fund — without
 * it the receiving system has no account to apply the money to, which is why the Q Link and PIPEDEL
 * exports could not be written even though everything around them existed.
 *
 * These tests are mostly about refusals. A deduction file is read by a machine on the other end, so
 * every way of producing a wrong one has to stop rather than approximate: no layout, no reference,
 * a required field left empty. A file that looks right and is applied to the wrong accounts is a
 * far worse outcome than a download that refuses with a reason.
 */
class DeductionFileTest extends PayrollTestCase
{
    private int $companyId;
    private Beneficiary $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (int) (DB::table('companies')->min('id')
            ?? Company::create(['name' => 'Deduction Test Co'])->id);

        DB::table('users')->where('id', $this->hrManager->id)->update(['company_id' => $this->companyId]);
        $this->hrManager->refresh();

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        $this->fund = Beneficiary::create([
            'company_id' => $this->companyId,
            'name' => 'Q Link', 'type' => 'other', 'reference' => 'EMP001',
            'employer_code' => 'E12345', 'file_spec_code' => 'qlink_deduction',
            'line_types' => ['loan'], 'active' => true,
        ]);
    }

    private function remittance(array $schedule): Remittance
    {
        return Remittance::create([
            'company_id'       => $this->companyId,
            'beneficiary_id'   => $this->fund->id,
            'period_start'     => '2026-08-01',
            'period_end'       => '2026-08-31',
            'employee_portion' => 1500.00,
            'employer_portion' => 0,
            'total'            => 1500.00,
            'employee_count'   => count($schedule),
            'status'           => 'due',
            'schedule'         => $schedule,
        ]);
    }

    /** A minimal fixed-width layout — one detail line, the way a vendor document describes one. */
    private function layout(): FileSpecification
    {
        $spec = FileSpecification::create([
            'company_id' => $this->companyId,
            'code' => 'qlink_deduction', 'name' => 'Q Link deduction', 'vendor' => 'Q Link',
            'version' => 1, 'effective_from' => '2026-01-01', 'format' => 'fixed',
            'is_active' => true, 'pad_to_length' => true,
        ]);

        foreach ([
            ['sequence' => 1, 'name' => 'reference', 'source' => 'employee.reference', 'position' => 1, 'length' => 12, 'type' => 'text', 'required' => true],
            ['sequence' => 2, 'name' => 'employee_number', 'source' => 'employee.employee_number', 'position' => 13, 'length' => 8, 'type' => 'text'],
            ['sequence' => 3, 'name' => 'amount', 'source' => 'employee.amount', 'position' => 21, 'length' => 10, 'type' => 'cents'],
        ] as $field) {
            FileSpecificationField::create($field + [
                'company_id' => $this->companyId,
                'file_specification_id' => $spec->id,
                'record_type' => 'detail',
            ]);
        }

        return $spec->load('fields');
    }

    private function reference(int $employeeId, string $reference, array $extra = []): DeductionReference
    {
        return DeductionReference::create([
            'company_id'     => $this->companyId,
            'employee_type'  => DeductionReference::HR,
            'employee_id'    => $employeeId,
            'beneficiary_id' => $this->fund->id,
            'reference'      => $reference,
        ] + $extra);
    }

    private function service(): DeductionFileService
    {
        return app(DeductionFileService::class);
    }

    // ── The file ─────────────────────────────────────────────────────────────

    public function test_it_writes_a_line_per_employee_using_the_funds_own_reference(): void
    {
        $this->layout();
        $this->reference(101, 'QL0000123');

        $file = $this->service()->build($this->remittance([
            ['employee_id' => 101, 'name' => 'Thabo Mokoena', 'employee_number' => 'E101', 'id_number' => '9001015800083', 'employee' => 1500.00, 'employer' => 0],
        ]));

        $this->assertSame(1, $file['rows']);
        // The reference is what the fund matches on, so it has to be the first thing on the line.
        $this->assertStringStartsWith('QL0000123', $file['contents']);
        // Amounts go out in whole cents: R1 500.00 is 150000, not "1500.00".
        $this->assertStringContainsString('0000150000', $file['contents']);
    }

    public function test_the_filename_names_the_layout_the_fund_and_the_period(): void
    {
        $this->layout();
        $this->reference(101, 'QL0000123');

        $file = $this->service()->build($this->remittance([
            ['employee_id' => 101, 'name' => 'Thabo Mokoena', 'employee_number' => 'E101', 'employee' => 1500.00, 'employer' => 0],
        ]));

        $this->assertSame('qlink_deduction-q-link-202608.txt', $file['filename']);
    }

    // ── Every way of producing a wrong file stops ────────────────────────────

    public function test_an_employee_without_a_reference_stops_the_file_and_is_named(): void
    {
        // Not skipped and not sent blank: either is money the fund holds and cannot apply.
        $this->layout();
        $this->reference(101, 'QL0000123');

        $remittance = $this->remittance([
            ['employee_id' => 101, 'name' => 'Thabo Mokoena', 'employee_number' => 'E101', 'employee' => 1500.00, 'employer' => 0],
            ['employee_id' => 202, 'name' => 'Nomsa Dlamini', 'employee_number' => 'E202', 'employee' => 900.00, 'employer' => 0],
        ]);

        try {
            $this->service()->build($remittance);
            $this->fail('A missing reference should stop the file.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Nomsa Dlamini', $e->errors()['file'][0]);
        }
    }

    public function test_a_reference_that_had_ended_by_the_period_does_not_count(): void
    {
        /*
         * A settled loan number sent this month is a payment applied to a closed account — worse
         * than sending nothing, because the money leaves and the employee still owes it.
         */
        $this->layout();
        $this->reference(101, 'QL0000123', ['effective_to' => '2026-07-31']);

        $this->expectException(ValidationException::class);

        $this->service()->build($this->remittance([
            ['employee_id' => 101, 'name' => 'Thabo Mokoena', 'employee_number' => 'E101', 'employee' => 1500.00, 'employer' => 0],
        ]));
    }

    public function test_a_beneficiary_with_no_layout_says_so_rather_than_guessing_one(): void
    {
        // No Q Link or PIPEDEL layout is shipped: the byte positions come from a vendor document,
        // and one guessed from memory produces a file that is either rejected or misapplied.
        $this->fund->update(['file_spec_code' => null]);

        try {
            $this->service()->build($this->remittance([
                ['employee_id' => 101, 'name' => 'Thabo Mokoena', 'employee' => 1500.00, 'employer' => 0],
            ]));
            $this->fail('A beneficiary with no layout should refuse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('File layouts', $e->errors()['file'][0]);
        }
    }

    public function test_a_layout_that_does_not_cover_the_period_is_refused_by_name(): void
    {
        // The file for August is built with August's layout. A layout that only starts in
        // September cannot produce it, and silently using it would send the wrong format.
        FileSpecification::create([
            'company_id' => $this->companyId,
            'code' => 'qlink_deduction', 'name' => 'Q Link deduction', 'version' => 2,
            'effective_from' => '2026-09-01', 'format' => 'fixed', 'is_active' => true,
        ]);

        try {
            $this->service()->build($this->remittance([
                ['employee_id' => 101, 'name' => 'Thabo Mokoena', 'employee' => 1500.00, 'employer' => 0],
            ]));
            $this->fail('A layout outside the period should refuse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('August 2026', $e->errors()['file'][0]);
        }
    }

    public function test_an_empty_remittance_produces_nothing_rather_than_an_empty_file(): void
    {
        $this->layout();

        $this->expectException(ValidationException::class);
        $this->service()->build($this->remittance([]));
    }

    // ── The route ────────────────────────────────────────────────────────────

    public function test_the_download_returns_the_rendered_file(): void
    {
        $this->layout();
        $this->reference(101, 'QL0000123');

        $remittance = $this->remittance([
            ['employee_id' => 101, 'name' => 'Thabo Mokoena', 'employee_number' => 'E101', 'employee' => 1500.00, 'employer' => 0],
        ]);

        $response = $this->actingAs($this->hrManager)
            ->get(route('payroll.remittances.deduction-file', $remittance->uuid))
            ->assertOk();

        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('QL0000123', $response->getContent());
    }
}
