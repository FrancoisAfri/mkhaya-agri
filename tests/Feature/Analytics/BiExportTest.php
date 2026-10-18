<?php

namespace Tests\Feature\Analytics;

use App\Core\User\Models\User;
use App\Modules\Analytics\Jobs\ExportToWarehouseJob;
use App\Modules\Analytics\Models\BiConnection;
use App\Modules\Analytics\Services\DatabricksExporter;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pushing a tenant's data into their own warehouse, so Power BI can read it.
 *
 * The model is replaced by a faked HTTP client throughout, because what is worth testing is not
 * whether Databricks works. It is the three things that are ours to get wrong: the token never
 * leaves the server, the SQL is built so a project called "O'Brien's Yard" does not break it, and
 * one tenant's rows cannot end up in another tenant's warehouse.
 */
class BiExportTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

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

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'bi+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'),
            'status' => 1,
            'company_id' => $this->companyId,
            'is_platform_admin' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function connection(array $attributes = []): BiConnection
    {
        return BiConnection::create(array_merge([
            'company_id'  => $this->companyId,
            'provider'    => 'databricks',
            'host'        => 'https://example.cloud.databricks.com',
            'http_path'   => '/sql/1.0/warehouses/abc123',
            'catalog'     => 'main',
            'schema_name' => 'mkhaya',
            'credentials' => ['token' => 'dapi-secret-token'],
            'is_active'   => true,
        ], $attributes));
    }

    /** Every statement the exporter sent, in order. */
    private function statements(): array
    {
        $sent = [];

        foreach (Http::recorded() as [$request]) {
            $sent[] = $request->data()['statement'] ?? '';
        }

        return $sent;
    }

    // ── The token ────────────────────────────────────────────────────────────

    public function test_the_token_is_encrypted_at_rest(): void
    {
        $connection = $this->connection();

        $raw = DB::table('bi_connections')->where('id', $connection->id)->value('credentials');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('dapi-secret-token', $raw,
            'A warehouse token is sitting in the database in the clear.');
        // And it still reads back, so the encryption is real rather than a mangled column.
        $this->assertSame('dapi-secret-token', $connection->fresh()->token());
    }

    public function test_the_token_never_reaches_the_page(): void
    {
        $this->connection();

        $response = $this->actingAs($this->user)->get(route('settings.bi-export'));
        $response->assertOk();

        $payload = json_encode($response->viewData('page')['props']);

        $this->assertStringNotContainsString('dapi-secret-token', $payload,
            'The token was serialised into the page props.');
        // What somebody configuring this actually needs: that a credential is set.
        $this->assertTrue($response->viewData('page')['props']['connection']['has_token']);
    }

    /**
     * Somebody correcting a catalog name should not have to paste their token again — asking them
     * to is how a credential ends up somewhere convenient to copy from.
     */
    public function test_saving_without_a_token_keeps_the_one_already_stored(): void
    {
        $this->connection();

        $this->actingAs($this->user)->post(route('settings.bi-export.save'), [
            'provider' => 'databricks',
            'host' => 'https://example.cloud.databricks.com',
            'http_path' => '/sql/1.0/warehouses/abc123',
            'catalog' => 'renamed',
            'schema' => 'mkhaya',
        ])->assertRedirect();

        $connection = BiConnection::where('company_id', $this->companyId)->first();

        $this->assertSame('renamed', $connection->catalog);
        $this->assertSame('dapi-secret-token', $connection->token(), 'The stored token was wiped.');
    }

    // ── The SQL ──────────────────────────────────────────────────────────────

    public function test_the_table_is_replaced_so_a_repeated_export_does_not_double_the_rows(): void
    {
        Http::fake(['*' => Http::response(['status' => ['state' => 'SUCCEEDED']])]);

        app(DatabricksExporter::class)->export($this->connection(), 'project_management.projects', [
            ['code' => 'PRJ-1', 'name' => 'A project', 'status' => 'active'],
        ]);

        $statements = $this->statements();

        // Appending would silently double every figure in a Power BI report built on it, which is
        // the worst kind of wrong because it still looks like a report.
        $this->assertStringContainsString('CREATE OR REPLACE TABLE', $statements[0]);
        $this->assertStringContainsString('`main`.`mkhaya`.`mkhaya_projects`', $statements[0]);
        $this->assertStringContainsString('INSERT INTO', $statements[1]);
    }

    /** Not an attack — just a yard in Gauteng. It still has to survive being turned into SQL. */
    public function test_a_quote_in_a_project_name_does_not_break_the_statement(): void
    {
        Http::fake(['*' => Http::response(['status' => ['state' => 'SUCCEEDED']])]);

        app(DatabricksExporter::class)->export($this->connection(), 'project_management.projects', [
            ['code' => 'PRJ-1', 'name' => "O'Brien's Yard", 'status' => 'active'],
        ]);

        $insert = $this->statements()[1];

        $this->assertStringContainsString("'O''Brien''s Yard'", $insert);
        $this->assertStringNotContainsString("'O'Brien", $insert);
    }

    /**
     * Databricks treats a backslash as an escape inside a string literal, so a Windows path at the
     * end of a value would otherwise eat the closing quote and run the statement into the next one.
     */
    public function test_a_backslash_is_escaped(): void
    {
        Http::fake(['*' => Http::response(['status' => ['state' => 'SUCCEEDED']])]);

        app(DatabricksExporter::class)->export($this->connection(), 'project_management.projects', [
            ['code' => 'PRJ-1', 'name' => 'Site C:\\', 'status' => 'active'],
        ]);

        $this->assertStringContainsString("'Site C:\\\\'", $this->statements()[1]);
    }

    public function test_an_empty_value_becomes_null_rather_than_an_empty_string(): void
    {
        Http::fake(['*' => Http::response(['status' => ['state' => 'SUCCEEDED']])]);

        app(DatabricksExporter::class)->export($this->connection(), 'project_management.projects', [
            ['code' => 'PRJ-1', 'name' => 'A project', 'status' => null, 'stage' => ''],
        ]);

        // A DATE column given '' is a cast error in Databricks; NULL is the honest value anyway.
        $this->assertStringContainsString('NULL', $this->statements()[1]);
    }

    public function test_the_warehouse_id_is_taken_from_the_path_the_ui_gives_people(): void
    {
        Http::fake(['*' => Http::response(['status' => ['state' => 'SUCCEEDED']])]);

        app(DatabricksExporter::class)->test($this->connection());

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/api/2.0/sql/statements')
                && $request->data()['warehouse_id'] === 'abc123'
                && $request->hasHeader('Authorization', 'Bearer dapi-secret-token');
        });
    }

    // ── When it goes wrong ───────────────────────────────────────────────────

    /** The API reports bad SQL as a 200 with a FAILED state, so the status code is not the answer. */
    public function test_a_failed_state_in_a_successful_response_is_still_a_failure(): void
    {
        Http::fake(['*' => Http::response([
            'status' => ['state' => 'FAILED', 'error' => ['message' => 'TABLE_OR_VIEW_NOT_FOUND']],
        ])]);

        $result = app(DatabricksExporter::class)->test($this->connection());

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('TABLE_OR_VIEW_NOT_FOUND', $result['error']);
    }

    public function test_a_connection_with_no_token_fails_before_any_request_is_made(): void
    {
        Http::fake();

        $result = app(DatabricksExporter::class)->test($this->connection(['credentials' => null]));

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_a_test_records_what_went_wrong_so_the_screen_can_say(): void
    {
        Http::fake(['*' => Http::response('nope', 401)]);
        $this->connection();

        $this->actingAs($this->user)->post(route('settings.bi-export.test'))->assertRedirect();

        $connection = BiConnection::where('company_id', $this->companyId)->first();

        $this->assertNotNull($connection->last_tested_at);
        $this->assertNotNull($connection->last_test_error,
            'A failed test left nothing on the screen to explain it.');
    }

    // ── The tenant boundary ──────────────────────────────────────────────────

    /**
     * A queue has no session, so a job resolving its own tenant would resolve none — and the
     * failure mode is one customer's rows written into another customer's warehouse, permanently
     * and outside this platform's reach.
     */
    public function test_the_export_job_carries_the_company_rather_than_resolving_one(): void
    {
        Queue::fake();
        $connection = $this->connection();

        $this->actingAs($this->user)
            ->post(route('settings.bi-export.run'), ['datasets' => ['project_management.projects']])
            ->assertRedirect();

        Queue::assertPushed(ExportToWarehouseJob::class, function (ExportToWarehouseJob $job) use ($connection) {
            return $job->companyId === $this->companyId
                && $job->connectionUuid === $connection->uuid
                && $job->datasets === ['project_management.projects'];
        });
    }

    public function test_an_unknown_dataset_is_refused(): void
    {
        $this->connection();

        $this->actingAs($this->user)
            ->post(route('settings.bi-export.run'), ['datasets' => ['payroll_secrets']])
            ->assertSessionHasErrors('datasets.0');
    }

    public function test_somebody_who_cannot_manage_settings_cannot_reach_it(): void
    {
        $ordinary = User::forceCreate([
            'uuid' => (string) Str::uuid(),
            'email' => 'plain+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'),
            'status' => 1,
            'company_id' => $this->companyId,
        ]);

        // A warehouse token plus the whole company's data is an administrator's decision.
        $this->actingAs($ordinary)->get(route('settings.bi-export'))->assertForbidden();
    }

    // ── What is on offer ─────────────────────────────────────────────────────

    /**
     * The page offers modules, not a flat list of tables.
     *
     * "What can I get out of CRM?" is the question somebody actually has. Listing modules a tenant
     * has not bought would make this a sales page, and listing a dataset whose table has never been
     * migrated makes an absent module look like a broken export.
     */
    public function test_only_modules_this_tenant_runs_are_offered(): void
    {
        $offered = \App\Modules\Analytics\Services\BiDatasets::forTenant();

        $this->assertNotEmpty($offered, 'Nothing at all was offered to export.');

        $active = \Illuminate\Support\Facades\DB::table('security_modules')
            ->where('active', true)->pluck('code_name');

        foreach ($offered as $group) {
            $this->assertTrue($active->contains($group['module']),
                "[{$group['module']}] is offered but is not switched on for this tenant.");
            $this->assertNotEmpty($group['datasets'],
                "[{$group['module']}] is offered with nothing in it.");
        }
    }

    /** Every dataset on offer must actually run, or the export fails halfway through. */
    public function test_every_offered_dataset_can_be_read(): void
    {
        $companyId = $this->companyId;

        foreach (\App\Modules\Analytics\Services\BiDatasets::forTenant() as $group) {
            foreach ($group['datasets'] as $dataset) {
                $rows = \App\Modules\Analytics\Services\BiDatasets::rows($dataset['key'], $companyId, 5);

                $this->assertIsArray($rows, "[{$dataset['key']}] could not be read.");
            }
        }
    }

    /**
     * A dataset's declared columns and the columns its query returns have to agree: the export
     * writes CREATE TABLE from the first and INSERT from the second, so a mismatch is an insert
     * against the wrong column list — silently misaligned data in a customer's warehouse.
     */
    public function test_a_datasets_columns_match_what_its_query_returns(): void
    {
        /*
         * Its own row, rather than whatever the database happens to hold.
         *
         * The first version of this leaned on ambient data, found none, and reported success while
         * checking nothing — the exact failure mode it exists to catch.
         */
        $calendarId = DB::table('pm_calendars')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'name' => 'Shape check calendar',
            'timezone' => 'Africa/Johannesburg',
            'work_week' => json_encode(['mon' => [['08:00', '17:00']]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pm_projects')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'calendar_id' => $calendarId,
            'code' => 'BI-' . Str::upper(Str::random(5)),
            'name' => 'A project to shape-check against',
            'status' => 'active',
            'stage' => 'execution',
            'currency' => 'ZAR',
            'timezone' => 'Africa/Johannesburg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $checked = 0;

        foreach (\App\Modules\Analytics\Services\BiDatasets::forTenant() as $group) {
            foreach ($group['datasets'] as $dataset) {
                $rows = \App\Modules\Analytics\Services\BiDatasets::rows($dataset['key'], $this->companyId, 1);

                if ($rows === []) {
                    continue;
                }

                $checked++;
                $this->assertSame(
                    $dataset['columns'],
                    array_keys($rows[0]),
                    "[{$dataset['key']}] returns different columns from the ones it declares.",
                );
            }
        }

        // Otherwise an empty database makes this report success while checking nothing at all.
        $this->assertGreaterThan(0, $checked, 'No dataset had a row to check the columns against.');
    }

    /** Namespaced keys, so two modules can both have a dataset called "invoices". */
    public function test_dataset_keys_name_their_module(): void
    {
        foreach (\App\Modules\Analytics\Services\BiDatasets::keys() as $key) {
            $this->assertStringContainsString('.', $key,
                "[{$key}] is not namespaced by its module.");
            $this->assertNotNull(\App\Modules\Analytics\Services\BiDatasets::definition($key));
        }
    }
}
