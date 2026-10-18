<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Loading a PPE catalogue from a spreadsheet.
 *
 * A catalogue arrives as a list — from the supplier, from the safety file, from whatever the last
 * system exported — and typing eighty items into a dialog one at a time is the reason a catalogue
 * stays half-populated and the issue register stays empty behind it.
 *
 * Two decisions worth pinning down, because both could reasonably have gone the other way: an
 * existing code is updated rather than refused, and a bad row is skipped rather than fatal.
 */
class PpeCatalogueImportTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $moduleId;

    /*
     * The same harness as PpeCatalogueTest, deliberately.
     *
     * The first version of this file made a platform admin and guarded every test with "skip on
     * 403" — and every one of them skipped, which proves nothing while reading like a pass. These
     * routes sit behind the module gate and `sheq.configure`, so the setup grants both and a 403
     * is then a failure rather than a shrug.
     */
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

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeUser(): User
    {
        $id = (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'ppe-import-' . Str::random(8) . '@example.test',
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

        $user = User::withoutGlobalScopes()->findOrFail($id);
        Permission::findOrCreate('sheq.configure', 'web');
        $user->syncPermissions(['sheq.configure']);

        return $user->fresh();
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ppe') . '.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'catalogue.csv', 'text/csv', null, true);
    }

    private function import(string $body)
    {
        return $this->post(route('sheq.ppe.catalogue.items.import'), ['file' => $this->csv($body)]);
    }

    private function item(string $code)
    {
        return DB::table('sheq_ppe_items')
            ->where('company_id', $this->companyId)->where('code', $code)
            ->whereNull('deleted_at')->first();
    }

    // ── Loading ──────────────────────────────────────────────────────────────

    public function test_a_catalogue_loads_from_a_csv(): void
    {
        $code = 'BOOT-' . Str::upper(Str::random(5));

        $this->import(
            "code,name,category,specification,lifespan_months,requires_size\n"
            . "{$code},Safety boot,FOOT,SANS 20345 S3,12,yes\n"
        );

        $item = $this->item($code);

        $this->assertNotNull($item, 'The row did not become an item.');
        $this->assertSame('Safety boot', $item->name);
        $this->assertSame('FOOT', $item->category);
        $this->assertSame(12, (int) $item->lifespan_months);
        $this->assertTrue((bool) $item->requires_size);
    }

    /**
     * The realistic use is the supplier list re-sent with a correction, not one clean import. A
     * load that failed on "code already exists" would make somebody diff two spreadsheets by hand.
     */
    public function test_an_existing_code_is_updated_rather_than_duplicated(): void
    {
        $code = 'HAT-' . Str::upper(Str::random(5));

        $this->import("code,name,category\n{$code},Hard hat,HEAD\n");

        $this->import("code,name,category,specification\n{$code},Hard hat,HEAD,SANS 1397\n");

        $rows = DB::table('sheq_ppe_items')
            ->where('company_id', $this->companyId)->where('code', $code)
            ->whereNull('deleted_at')->get();

        $this->assertCount(1, $rows, 'Re-importing the same code created a second item.');
        $this->assertSame('SANS 1397', $rows->first()->specification);
    }

    // ── Rows it cannot take ──────────────────────────────────────────────────

    /**
     * Eighty rows with one bad category should load seventy-nine. Refusing the file sends the whole
     * list back over a typo, and the person retries by deleting the row rather than fixing it — so
     * the item quietly never exists.
     */
    public function test_a_bad_row_is_skipped_and_the_rest_still_load(): void
    {
        $good = 'GLOVE-' . Str::upper(Str::random(5));
        $bad = 'MASK-' . Str::upper(Str::random(5));

        $this->import(
            "code,name,category\n"
            . "{$good},Rigger glove,HAND\n"
            . "{$bad},Dust mask,NOT_A_CATEGORY\n"
        );

        $this->assertNotNull($this->item($good), 'A good row was lost because another row was bad.');
        $this->assertNull($this->item($bad));

        // Named, not counted: "1 skipped" does not tell anybody which of eighty rows to go and fix.
        $skipped = session('ppeImportSkipped');
        $this->assertNotEmpty($skipped);
        $this->assertSame(3, $skipped[0]['line'], 'The line number does not match the file.');
    }

    public function test_a_row_with_no_code_is_skipped(): void
    {
        $this->import("code,name,category\n,Nameless thing,HAND\n");

        $this->assertNotEmpty(session('ppeImportSkipped'));
    }

    // ── Files as people actually save them ───────────────────────────────────

    /**
     * A spreadsheet export writes "Lifespan Months", and a file saved from Excel on Windows starts
     * with a byte-order mark that otherwise becomes part of the first header — making `code`
     * unfindable in a file that plainly has one.
     */
    public function test_headers_survive_capitals_spaces_and_a_byte_order_mark(): void
    {
        $code = 'VEST-' . Str::upper(Str::random(5));

        $this->import(
            "\xEF\xBB\xBFCode,Name,Category,Lifespan Months\n"
            . "{$code},Hi-vis vest,HIGH_VISIBILITY,24\n"
        );

        $item = $this->item($code);
        $this->assertNotNull($item, 'A file exported from a spreadsheet was not readable.');
        $this->assertSame(24, (int) $item->lifespan_months);
    }

    public function test_an_empty_file_is_refused_rather_than_reported_as_success(): void
    {
        $response = $this->import("code,name,category\n");

        $response->assertSessionHasErrors('file');
    }

    // ── The template ─────────────────────────────────────────────────────────

    /** The template is the file the importer accepts, so it has to round-trip through it. */
    public function test_the_template_is_a_file_the_importer_can_read(): void
    {
        $response = $this->get(route('sheq.ppe.catalogue.items.template'));

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('code,name,category', $csv);

        $back = $this->import($csv);
        $back->assertSessionHasNoErrors();
        $this->assertNotNull($this->item('BOOT-01'), 'The template does not load through the importer.');
    }
}
