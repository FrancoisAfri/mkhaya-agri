<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\DocumentBlob;
use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Support\CapabilityCatalogue;
use App\Modules\Operations\ProjectManagement\Support\Cities;
use App\Modules\Operations\ProjectManagement\Support\Providers\ModuleTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Three things that are easy to get subtly wrong and impossible to notice afterwards.
 *
 * Uploads go through the platform's own FileStorage trait, so PM's documents are validated,
 * compressed and shaped exactly like every other upload on this system rather than by a
 * PM-specific routine that drifts from it.
 *
 * The integrations page tells the truth about what is actually consuming a capability, instead of
 * printing "Available" beside eighteen keys of which three do anything.
 *
 * And a client's town comes from the platform's list, because a free-text town column fills up with
 * four spellings of Johannesburg and nothing downstream can put them back together.
 */
class IntegrationsAndStorageTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
        Storage::fake('local');
    }

    // ── Uploads go through the platform's trait ──────────────────────────────

    public function test_an_upload_is_stored_in_the_platforms_own_shape(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.documents.store', $this->project->uuid), [
                'file' => UploadedFile::fake()->createWithContent('spec.pdf', 'SPECIFICATION'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $blob = DocumentBlob::first();

        // uuid / original_name / mime / type / data / size — what App\Core\User\Trait\FileStorage
        // writes, so one convention on this system rather than two.
        $this->assertSame('spec.pdf', $blob->original_name);
        $this->assertSame('document', $blob->type);
        $this->assertSame(base64_encode('SPECIFICATION'), $blob->payload());
        $this->assertSame(13, $blob->size);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Nothing may be written to a disk.');
    }

    /**
     * The stored mime describes the stored bytes — which is the trait's job, and the reason callers
     * must read the stored size back rather than trusting the browser's.
     */
    public function test_the_recorded_type_describes_what_was_actually_stored(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.documents.store', $this->project->uuid), [
                'file' => UploadedFile::fake()->image('site-photo.png', 400, 300),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $blob = DocumentBlob::first();

        $this->assertSame('image', $blob->type);
        /*
         * The trait used to re-encode every image as JPEG and record image/jpeg for all of them.
         * That destroyed PNG transparency and flattened animated GIFs, so it now keeps the format
         * it was given and only resizes images wider than the limit — this one is 400px, so the
         * bytes are stored exactly as they arrived.
         *
         * The invariant this test exists for is unchanged and is the assertion below it: whatever
         * the trait decides to store, the recorded type and size describe those bytes and not the
         * ones the browser sent.
         */
        $this->assertSame('image/png', $blob->mime);
        $this->assertStringStartsWith("\x89PNG", $blob->bytes());
        $this->assertSame($blob->size, strlen($blob->bytes()), 'The recorded size is the stored size.');
    }

    /** A refusal is a field error, not a 500: the person picked the wrong file, not a broken page. */
    public function test_a_refused_file_type_comes_back_as_a_field_error(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.documents.store', $this->project->uuid), [
                'file' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, DocumentBlob::count());
        $this->assertSame(0, ExternalRef::where('source_entity', 'document')->count());
    }

    /**
     * PM raises the platform's 10 MB default to 50.
     *
     * Worth pinning: a general-arrangement drawing runs well past ten, and a store that refuses
     * them is one people work around by emailing files instead.
     */
    public function test_the_size_cap_is_pms_own_and_not_the_platform_default(): void
    {
        $store = app(\App\Modules\Operations\ProjectManagement\Providers\Local\LocalDocumentStore::class);

        $this->assertSame(50, (new \ReflectionProperty($store, 'maxFileSizeMb'))->getValue($store));

        // And the type list is the platform's, extended — not replaced.
        $mimes = (new \ReflectionProperty($store, 'allowedMimes'))->getValue($store);

        $this->assertContains('application/pdf', $mimes, "The platform's own list is the baseline.");
        $this->assertContains('image/vnd.dwg', $mimes, 'Drawings are what this module carries.');
    }

    // ── The integrations page tells the truth ────────────────────────────────

    public function test_state_distinguishes_wired_from_merely_licensed(): void
    {
        // The three states, on the function that decides them. `ready` is the one that matters:
        // a module the tenant has, feeding nothing. It is what the page said about fifteen
        // capabilities before they were built, and the distinction has to survive them being built.
        $this->assertSame('live', CapabilityCatalogue::state('crm.customers', available: true));
        $this->assertSame('off', CapabilityCatalogue::state('crm.customers', available: false));
        $this->assertSame('ready', CapabilityCatalogue::state('nothing.built', available: true));

        // And every capability PM advertises is now genuinely consumed by something.
        foreach (CapabilityCatalogue::all() as $capability => $entry) {
            $this->assertTrue(
                $entry['built'],
                "'{$capability}' is advertised but nothing in the module consumes it."
            );
            $this->assertNotEmpty(
                $entry['uses'],
                "'{$capability}' is flagged built but names no screen that uses it."
            );
        }
    }

    /**
     * The `built` flag cannot drift from reality.
     *
     * It is hand-maintained, and a stale `true` is the worst possible failure of this page: it
     * tells somebody a number they are reading is fed by a module that is not feeding it. So every
     * capability flagged built must resolve to a real integrated implementation through the
     * registry when its module is on — which is a fact the test can check and a person cannot
     * reliably remember.
     */
    public function test_every_built_capability_resolves_to_a_real_integrated_provider(): void
    {
        $registry = app(\App\Modules\Operations\ProjectManagement\Services\ProviderRegistry::class);

        foreach (CapabilityCatalogue::all() as $capability => $entry) {
            if (! $entry['built'] || $entry['seam'] === null) {
                continue;
            }

            $this->activateOtherModule($this->moduleFor($capability));

            $this->assertTrue(
                $registry->isIntegrated($entry['seam']),
                "'{$capability}' is flagged built, but {$entry['seam']} still resolves to the local "
                . 'implementation — the setup page would report it as live while nothing happens.'
            );
        }
    }

    /** The module code_name behind a capability, as ModuleRegistry maps it. */
    private function moduleFor(string $capability): string
    {
        return match (explode('.', $capability)[0]) {
            'hr'          => str_contains($capability, 'leave') ? 'leave_management'
                : (str_contains($capability, 'attendance') ? 'time_attendance' : 'payroll'),
            'crm'         => 'crm',
            'finance'     => str_contains($capability, 'budgets') ? 'budget_management' : 'accounting',
            'procurement' => str_contains($capability, 'suppliers') ? 'supplier_management' : 'procurement_requests',
            'contracts'   => 'contract_management',
            'assets'      => 'asset_management',
            'fleet'       => 'fleet_management',
            'helpdesk'    => 'help_desk',
            'lms'         => 'learning_management',
            default       => 'project_management',
        };
    }

    public function test_every_capability_the_registry_advertises_is_described(): void
    {
        $available = app(\App\Modules\Operations\ProjectManagement\Services\ModuleRegistry::class)->forResponse();

        foreach (array_keys($available) as $capability) {
            $this->assertArrayHasKey(
                $capability,
                CapabilityCatalogue::all(),
                "'{$capability}' is advertised on the setup page with nothing said about what it does."
            );
        }
    }

    public function test_the_page_says_where_each_capability_is_used(): void
    {
        // CRM switched on for this tenant, so the live path is actually exercised rather than
        // asserted against a page where everything happens to be off.
        $this->activateOtherModule('crm');

        $adminRole = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'pm integrations admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(['pm.projects.view', 'pm.settings.manage']);
        $admin = $this->makeUser('pm-integrations@example.test', $adminRole);
        $this->grantModuleTier($admin);

        $props = $this->actingAs($admin)
            ->get(route('project-management.setup.integrations'))
            ->assertOk()
            ->viewData('page')['props'];

        $rows = collect($props['catalogue'])->keyBy('key');

        // Live, with the screens that consume it named — a live capability that says nothing about
        // where it is used is the claim this page was rewritten to stop making.
        $this->assertSame('live', $rows['crm.customers']['state']);
        $this->assertNotEmpty($rows['crm.customers']['uses']);
        $this->assertSame('ClientProvider', $rows['crm.customers']['seam']);

        // The same module's second capability is live too — deals now show on the project page.
        $this->assertSame('live', $rows['crm.opportunities']['state']);

        // Anything from a module this tenant does not have is off, not "available".
        $this->assertSame('off', $rows['fleet.vehicles']['state']);

        // Not an exact figure: which other modules a migration happened to register as active is
        // not this test's business, and pinning it would make the assertion fail every time
        // somebody adds one. What matters is the shape.
        $this->assertGreaterThanOrEqual(2, $props['counts']['live'], 'Both CRM capabilities are live.');

        // Nothing is "ready" any more — every capability PM advertises is now consumed by
        // something, which was the whole point of building them.
        $this->assertSame(0, $props['counts']['ready']);
        $this->assertSame(count($props['capabilities']), $props['counts']['total']);
    }

    /**
     * Switch another module on for this tenant.
     *
     * Mirrors the parent's `activateModule()`: the registry reads `security_modules` and caches the
     * answer per company, so both the row and the cache have to move.
     */
    private function activateOtherModule(string $codeName): void
    {
        $categoryId = DB::table('security_categories')->value('id');

        DB::table('security_modules')->updateOrInsert(
            ['code_name' => $codeName],
            [
                'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => strtoupper($codeName),
                'category_id' => $categoryId, 'active' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );

        app(\App\Modules\Operations\ProjectManagement\Services\ModuleRegistry::class)->forget();
    }

    // ── The id-shape trap ────────────────────────────────────────────────────

    /**
     * A uuid must never reach a bigint column.
     *
     * This is the bug that 500'd the Delivery page: `budgets.scope_id` is a bigint, PM addresses
     * everything by uuid, and Postgres does not quietly fail to match — it raises 22P02 and
     * **aborts the transaction**. Every integrated provider reads another module's keys, so the
     * guard is worth a test of its own rather than trust.
     */
    public function test_a_uuid_never_reaches_a_bigint_column(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();

        // hd_tickets has an integer key and no uuid column at all — the hardest case.
        $builder = ModuleTable::query('hd_tickets', ['id']);
        $this->assertNotNull($builder);

        // No exception, and no match: "not found", which is the correct answer.
        $this->assertNull(ModuleTable::whereId($builder, 'hd_tickets', $uuid)->first());

        // And the reverse: a numeric id against a uuid-keyed table.
        $budgets = ModuleTable::query('budgets', ['id']);
        $this->assertNull(ModuleTable::whereId($budgets, 'budgets', '999999')->first());

        // Neither shape matches anything, and must not fall through to an unconstrained query that
        // returns somebody else's first row as if it were the record asked for.
        $again = ModuleTable::query('budgets', ['id']);
        $this->assertNull(ModuleTable::whereId($again, 'budgets', 'not-an-id')->first());
    }

    /**
     * The Delivery page renders with Finance on.
     *
     * The regression test for the 500: with `budget_management` active, CostService asks Finance for
     * a budget scoped to the project, and that lookup used to send a uuid to a bigint.
     */
    public function test_the_delivery_page_renders_with_finance_installed(): void
    {
        $this->activateOtherModule('budget_management');
        $this->activateOtherModule('accounting');

        $this->actingAs($this->manager)
            ->get(route('project-management.delivery', $this->project->uuid))
            ->assertOk();
    }

    // ── A town is picked, not typed ──────────────────────────────────────────

    public function test_the_client_form_offers_the_platforms_town_list(): void
    {
        $this->seedTown('Polokwane');

        $payload = $this->actingAs($this->manager)
            ->getJson(route('project-management.clients.search'))
            ->assertOk()
            ->json();

        $this->assertContains('Polokwane', $payload['cities']);
    }

    public function test_a_town_that_is_not_on_the_list_is_refused(): void
    {
        $this->seedTown('Polokwane');

        $this->actingAs($this->manager)
            ->post(route('project-management.clients.store'), ['name' => 'Acme', 'city' => 'Jhb'])
            ->assertSessionHasErrors('city');

        // Blank stays fine: a place genuinely not on the list is better left empty than recorded
        // as the wrong one.
        $this->actingAs($this->manager)
            ->post(route('project-management.clients.store'), ['name' => 'Acme', 'city' => null])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post(route('project-management.clients.store'), ['name' => 'Beta', 'city' => 'Polokwane'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Polokwane', ExternalRef::where('display_name', 'Beta')->first()->snapshot['city']);
    }

    /**
     * A town, with the country it needs.
     *
     * RefreshDatabase leaves the reference tables empty, and `cities.country_id` is a real foreign
     * key — so the row has to bring its country with it rather than assume a seeded one.
     */
    private function seedTown(string $name): void
    {
        $countryId = DB::table('countries')->value('id') ?? DB::table('countries')->insertGetId([
            'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('cities')->insert([
            'name' => $name, 'country_id' => $countryId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Cities::forget();
    }

    /** A slimmed deployment with no cities table must not 500 the client form. */
    public function test_a_missing_city_table_degrades_to_no_list(): void
    {
        Cities::forget();
        DB::statement('ALTER TABLE cities RENAME TO cities_hidden');

        try {
            Cities::forget();
            $this->assertSame([], Cities::options());

            $this->actingAs($this->manager)
                ->getJson(route('project-management.clients.search'))
                ->assertOk();
        } finally {
            DB::statement('ALTER TABLE cities_hidden RENAME TO cities');
            Cities::forget();
        }
    }
}
