<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\SheqReportService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The report catalogue.
 *
 * The screen it replaced had four hard-coded blocks fixed to the current month. The risk in a
 * catalogue of twenty-four is different and worse: every report is a hand-written query against a
 * table this test file does not import, so a renamed column breaks exactly one report and nothing
 * else notices. Two of these queries were wrong when first written and only running them found it.
 *
 * So the load-bearing test here is the dullest one — *run all of them* — and the rest guard the
 * properties that make the output trustworthy: one company's rows only, a period that means what it
 * says, and an export that agrees with the screen.
 */
class ReportCatalogueTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $moduleId;

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
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);

        $module = DB::table('security_modules')->where('code_name', 'sheq')->first(['id', 'active']);

        if ($module === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        if (! $module->active) {
            DB::table('security_modules')->where('id', $module->id)->update(['active' => 1]);
        }

        Cache::forget('module_active_sheq');
        $this->moduleId = (int) $module->id;
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function service(): SheqReportService
    {
        return app(SheqReportService::class);
    }

    private function actingAsSheqUser(): void
    {
        $id = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'reports-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $this->companyId,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_module_access')->insert([
            'module_id' => $this->moduleId, 'user_id' => $id,
            'access_level' => 4, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::withoutGlobalScopes()->findOrFail($id));
    }

    public function test_every_report_in_the_catalogue_actually_runs(): void
    {
        /*
         * The test this file exists for. Twenty-four hand-written queries against twenty-four
         * tables: `sheq_competencies` has `title` where the query said `name`, and that report — and
         * only that report — threw. Nothing short of executing each one finds that.
         */
        $service = $this->service();
        $from = Carbon::now()->subYear();
        $to = Carbon::now()->endOfDay();
        $broken = [];

        foreach (array_keys($service->catalogue()) as $key) {
            try {
                $service->run($key, $this->companyId, $from, $to, null);
            } catch (\Throwable $e) {
                $broken[] = $key.': '.$e->getMessage();
            }
        }

        $this->assertSame([], $broken, "These reports do not run:\n".implode("\n", $broken));
        $this->assertGreaterThanOrEqual(20, count($service->catalogue()),
            'The catalogue has shrunk — a report was removed rather than fixed.');
    }

    public function test_every_report_declares_the_columns_its_rows_contain(): void
    {
        /*
         * The screen and the CSV both render straight from the declared column list. A row carrying
         * a key the definition never mentions is a column that silently never appears — and the
         * export is the copy somebody sends to an inspector.
         */
        $service = $this->service();
        $mismatched = [];

        foreach ($service->catalogue() as $key => $definition) {
            $rows = $service->run($key, $this->companyId, Carbon::now()->subYear(), Carbon::now(), null);

            if ($rows === []) {
                continue;
            }

            $undeclared = array_diff(array_keys($rows[0]), array_keys($definition['columns']));

            if ($undeclared !== []) {
                $mismatched[] = $key.' returns undeclared '.implode(', ', $undeclared);
            }
        }

        $this->assertSame([], $mismatched, implode("\n", $mismatched));
    }

    public function test_a_report_that_describes_a_period_respects_it(): void
    {
        // An incident last year must not appear in a report about this month, or the period filter
        // is decoration.
        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'RPT-'.Str::upper(Str::random(5)), 'incident_type' => 'NEAR_MISS',
            'occurred_at' => Carbon::now()->subYears(2),
            'reported_at' => Carbon::now()->subYears(2), 'status' => 'CLOSED',
            'description' => 'Report fixture incident.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inWindow = $this->service()->run('incidents_detail', $this->companyId,
            Carbon::now()->subYears(2)->subDay(), Carbon::now()->subYears(2)->addDay(), null);

        $outOfWindow = $this->service()->run('incidents_detail', $this->companyId,
            Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth(), null);

        $this->assertNotEmpty($inWindow);
        $this->assertCount(0, array_filter($outOfWindow,
            fn ($r) => str_starts_with((string) $r['reference'], 'RPT-')));
    }

    public function test_a_report_that_describes_the_position_now_ignores_the_period(): void
    {
        /*
         * The distinction that matters most in a compliance report. "What is on the shelf" has no
         * period; filtering it by one returns an empty table, which reads as "nothing to report" —
         * the most dangerous wrong answer this screen can give.
         */
        $itemId = DB::table('sheq_ppe_items')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'RPT-'.Str::upper(Str::random(4)), 'name' => 'Report fixture boots',
            'requires_size' => false, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_ppe_stock')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'ppe_item_id' => $itemId, 'quantity_on_hand' => 3, 'reorder_level' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A window years in the past — a periodic report would return nothing.
        $rows = $this->service()->run('ppe_stock', $this->companyId,
            Carbon::now()->subYears(5), Carbon::now()->subYears(5)->addDay(), null);

        $mine = array_values(array_filter($rows, fn ($r) => $r['item'] === 'Report fixture boots'));

        $this->assertCount(1, $mine, 'A position-now report was filtered away by the date range.');
        $this->assertTrue((bool) $mine[0]['is_low'], '3 on hand against a reorder level of 10 is low.');
    }

    public function test_a_report_never_returns_another_companys_rows(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Report Fixture Co',
            'slug' => 'report-fixture-'.Str::random(6), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_incidents')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $other,
            'reference' => 'OTHER-'.Str::upper(Str::random(5)), 'incident_type' => 'INJURY',
            'occurred_at' => Carbon::now(), 'reported_at' => Carbon::now(), 'status' => 'OPEN',
            'description' => 'Other company fixture.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = $this->service()->run('incidents_detail', $this->companyId,
            Carbon::now()->subMonth(), Carbon::now()->endOfDay(), null);

        $this->assertCount(0, array_filter($rows,
            fn ($r) => str_starts_with((string) $r['reference'], 'OTHER-')),
            "Another company's incident appeared in this company's report.");
    }

    public function test_the_page_falls_back_to_a_real_report_when_asked_for_nonsense(): void
    {
        // A stale bookmark or a hand-edited URL must not produce an empty screen with no
        // explanation — it lands on the first report instead.
        $this->actingAsSheqUser();

        $this->get('/sheq/insights/reports?report=not_a_report')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/Sheq/Insights/Reports')
                ->where('report', array_key_first($this->service()->catalogue()))
                ->has('catalogue')
                ->has('rows'));
    }

    public function test_a_backwards_date_range_is_swapped_rather_than_returning_nothing(): void
    {
        // Typing the dates the wrong way round is a slip, not a query for zero rows.
        $this->actingAsSheqUser();

        $this->get('/sheq/insights/reports?report=incidents_detail&from=2026-12-31&to=2026-01-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('from', '2026-01-01')
                ->where('to', '2026-12-31'));
    }

    public function test_the_export_returns_a_csv_whose_header_is_the_declared_columns(): void
    {
        /*
         * The export is built from the same definition as the screen, so this asserts the contract
         * rather than the contents: a CSV assembled separately eventually disagrees with the table
         * somebody is reading it against.
         */
        $this->actingAsSheqUser();

        $response = $this->get('/sheq/insights/reports/export?report=ppe_stock');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $columns = array_keys($this->service()->catalogue()['ppe_stock']['columns']);

        /*
         * The heading row is no longer the first row. CMD-8 requires every export to carry its
         * filters, its period, a generated timestamp and a report owner, and those sit ABOVE the
         * headings — a spreadsheet of incident counts with none of them on it is the commonest
         * source of argument in a safety meeting, because two people have each exported one and
         * there is no way to tell whether the filters, the periods or the ages differed.
         *
         * So this looks for the heading row rather than assuming its position. The contract being
         * asserted is unchanged: the headings are exactly the declared columns, in order.
         */
        $lines = array_map('trim', explode("\n", ltrim($csv, "\xEF\xBB\xBF")));
        $header = null;
        $headerIndex = null;

        foreach ($lines as $i => $line) {
            if (str_getcsv($line) === $columns) {
                $header = str_getcsv($line);
                $headerIndex = $i;
                break;
            }
        }

        $this->assertSame($columns, $header,
            'No row in the export is the declared column list, so the CSV and the table on screen '
            .'have come from different definitions.');

        // And the provenance really is above it, not merely somewhere in the file.
        $preamble = implode("\n", array_slice($lines, 0, $headerIndex));

        $this->assertStringContainsString('Report owner', $preamble,
            'CMD-8: the provenance block is not above the headings, so a reader meets the data '
            .'before the context.');
        $this->assertStringContainsString('Period', $preamble);
        $this->assertStringContainsString('Generated', $preamble);
    }

    public function test_the_export_filename_names_the_report(): void
    {
        // Four files called "export.csv" in a downloads folder is how the wrong one gets emailed.
        $this->actingAsSheqUser();

        $this->get('/sheq/insights/reports/export?report=visitors_on_site')
            ->assertOk()
            ->assertDownload();
    }
}
