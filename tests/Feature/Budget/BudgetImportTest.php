<?php

namespace Tests\Feature\Budget;

use App\Modules\Budget\Models\Budget;
use App\Modules\Budget\Models\FinancialYear;
use App\Modules\Budget\Services\BudgetPlanningService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The CSV importer, and the template it is written against.
 *
 * The rules defended here: the shipped template imports cleanly through the same parser that
 * produced it, the amount formats a real spreadsheet emits are read rather than rejected, a bad row
 * is skipped by line number without taking the file with it, and a value we quietly replaced with a
 * default is said out loud instead of silently applied.
 */
class BudgetImportTest extends TestCase
{
    use DatabaseTransactions;

    private FinancialYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);

        $this->year = FinancialYear::create([
            'name' => 'Import FY '.Str::random(4),
            'start_date' => '2026-03-01', 'end_date' => '2027-02-28',
        ]);
    }

    public function test_the_shipped_template_imports_without_an_error_or_a_warning(): void
    {
        // A template that warns the moment it is uploaded unchanged teaches people the warnings are
        // noise, and then they stop reading the ones that matter.
        $result = $this->planning()->import($this->templateAsRows(), $this->year);

        $this->assertSame(2, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_it_reads_the_amount_formats_a_spreadsheet_actually_writes(): void
    {
        $rows = [
            ['name' => 'Plain',     'allocated_amount' => '250000'],
            ['name' => 'Decimal',   'allocated_amount' => '250000.00'],
            ['name' => 'Comma dec', 'allocated_amount' => '250000,00'],
            ['name' => 'Thousands', 'allocated_amount' => '1,850,000.00'],
            ['name' => 'Symbol',    'allocated_amount' => 'R 1 850 000,00'],
            ['name' => 'Padded',    'allocated_amount' => '  42000  '],
        ];

        $result = $this->planning()->import($rows, $this->year);

        $this->assertSame(6, $result['created']);
        $this->assertSame(250_000.0, $this->amountOf('Plain'));
        $this->assertSame(250_000.0, $this->amountOf('Comma dec'));
        $this->assertSame(1_850_000.0, $this->amountOf('Thousands'));
        $this->assertSame(1_850_000.0, $this->amountOf('Symbol'));
        $this->assertSame(42_000.0, $this->amountOf('Padded'));
    }

    public function test_a_bad_row_is_skipped_by_line_number_and_the_rest_still_import(): void
    {
        $rows = [
            ['name' => 'Good one', 'allocated_amount' => '100'],
            ['name' => 'No amount', 'allocated_amount' => 'twelve'],
            ['name' => '', 'allocated_amount' => '100'],
            ['name' => 'Good two', 'allocated_amount' => '200'],
        ];

        $result = $this->planning()->import($rows, $this->year);

        $this->assertSame(2, $result['created'], 'An import that refuses everything over row 3 is one people give up on.');
        $this->assertCount(2, $result['errors']);
        // Line numbers count from the file the person is looking at: header is line 1.
        $this->assertStringContainsString('Line 3', $result['errors'][0]);
        $this->assertStringContainsString('Line 4', $result['errors'][1]);
    }

    public function test_an_unrecognised_value_warns_rather_than_failing_the_row(): void
    {
        $rows = [[
            'name' => 'Odd row', 'allocated_amount' => '100',
            'expenditure_type' => 'Opperating', 'scope_type' => 'branch',
            'category' => 'Nothing called this', 'currency' => 'Rands',
        ]];

        $result = $this->planning()->import($rows, $this->year);

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertCount(3, $result['warnings']);

        $budget = Budget::where('name', 'Odd row')->where('financial_year_id', $this->year->id)->firstOrFail();

        $this->assertSame('opex', $budget->expenditure_type);
        $this->assertSame('general', $budget->scope_type);
        $this->assertNull($budget->budget_category_id);
        // `currency` is NOT NULL with a database default; an unusable value must fall back to it
        // rather than being written as null and rejecting the insert.
        $this->assertSame('ZAR', $budget->currency);
    }

    public function test_a_category_matches_regardless_of_case(): void
    {
        $name = 'Facilities '.Str::random(4);
        $categoryId = DB::table('budget_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => $name, 'type' => 'opex',
            'status' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->planning()->import(
            [['name' => 'Cased', 'allocated_amount' => '100', 'category' => mb_strtoupper($name)]],
            $this->year,
        );

        $this->assertSame([], $result['warnings'], 'Nobody types a category the same way twice.');
        $this->assertSame(
            $categoryId,
            (int) Budget::where('name', 'Cased')->where('financial_year_id', $this->year->id)->value('budget_category_id'),
        );
    }

    public function test_everything_arrives_as_a_draft(): void
    {
        $this->planning()->import([['name' => 'Drafted', 'allocated_amount' => '100']], $this->year);

        // A budget that became spendable because somebody uploaded a file is one nobody approved.
        $this->assertSame(
            'draft',
            Budget::where('name', 'Drafted')->where('financial_year_id', $this->year->id)->value('status'),
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function planning(): BudgetPlanningService
    {
        return app(BudgetPlanningService::class);
    }

    /** The template, read back through the parser it was written for. */
    private function templateAsRows(): array
    {
        $rows = $this->planning()->templateRows();
        $header = array_shift($rows);

        return array_map(fn ($row) => array_combine($header, $row), $rows);
    }

    private function amountOf(string $name): float
    {
        return (float) Budget::where('name', $name)->where('financial_year_id', $this->year->id)->value('allocated_amount');
    }
}
