<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentQuota;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Services\QuotaService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Storage limits below the level of the whole company.
 *
 * The company already has one, from its plan, and it is the wrong instrument for the problem it is
 * usually asked to solve. One department putting video into the repository fills the company's
 * allowance, and the first anybody hears about it is that nobody anywhere can upload anything. The
 * limit that would have helped is the one on the department.
 */
class StorageQuotasTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

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

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'qta+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        $user = $user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function uploader(): User
    {
        return $this->user('document.view', 'document.upload', 'document.replace');
    }

    /** A file of a known size, so a limit can be set just above or just below it. */
    private function pdfOf(int $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        $head = "%PDF-1.4\n";
        $tail = "\n%%EOF";
        file_put_contents($path, $head . str_repeat('x', max(1, $bytes - strlen($head) - strlen($tail))) . $tail);

        return new UploadedFile($path, 'file.pdf', 'application/pdf', null, true);
    }

    private function folder(string $name = 'Marketing'): DocumentFolder
    {
        return app(FolderService::class)->create(['name' => $name . ' ' . Str::random(4)]);
    }

    private function quota(string $scope, int $scopeId, int $bytes, int $warnAt = 85): DocumentQuota
    {
        return DocumentQuota::create([
            'uuid' => (string) Str::uuid(),
            'scope' => $scope,
            'scope_id' => $scopeId,
            'bytes_limit' => $bytes,
            'warn_percent' => $warnAt,
        ]);
    }

    private function quotas(): QuotaService
    {
        return app(QuotaService::class);
    }

    // ── Refusing ─────────────────────────────────────────────────────────────

    public function test_an_upload_that_would_breach_a_folder_limit_is_refused(): void
    {
        $uploader = $this->uploader();
        $folder = $this->folder();
        $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 4_000);

        $this->actingAs($uploader);
        $result = app(DocumentService::class)->store($this->pdfOf(8_000), ['folder_id' => $folder->id]);

        $this->assertNull($result['document']);
        $this->assertNotNull($result['error']);
    }

    public function test_the_refusal_names_what_is_full(): void
    {
        /*
         * "Storage limit reached" tells somebody nothing they can act on. "The Marketing folder is
         * full" tells them who to talk to — and the person refused is rarely the person who filled
         * it.
         */
        $uploader = $this->uploader();
        $folder = $this->folder('Marketing');
        $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 1_000);

        $this->actingAs($uploader);
        $result = app(DocumentService::class)->store($this->pdfOf(4_000), ['folder_id' => $folder->id]);

        $this->assertStringContainsString($folder->name, (string) $result['error']);
    }

    public function test_an_upload_within_the_limit_goes_through(): void
    {
        $uploader = $this->uploader();
        $folder = $this->folder();
        $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 1_000_000);

        $this->actingAs($uploader);
        $result = app(DocumentService::class)->store($this->pdfOf(4_000), ['folder_id' => $folder->id]);

        $this->assertNotNull($result['document'], $result['error'] ?? '');
    }

    public function test_nothing_changes_where_no_limit_is_set(): void
    {
        // The overwhelmingly common case, and it must cost nothing and refuse nothing.
        $uploader = $this->uploader();

        $this->actingAs($uploader);
        $result = app(DocumentService::class)->store($this->pdfOf(50_000), []);

        $this->assertNotNull($result['document'], $result['error'] ?? '');
    }

    public function test_a_persons_own_limit_applies_wherever_they_file(): void
    {
        $uploader = $this->uploader();
        $this->quota(DocumentQuota::SCOPE_USER, $uploader->id, 3_000);

        $this->actingAs($uploader);
        $result = app(DocumentService::class)->store($this->pdfOf(9_000), []);

        $this->assertNull($result['document']);
    }

    public function test_every_applicable_limit_is_checked_and_not_just_the_nearest(): void
    {
        /*
         * An upload into Finance's folder by somebody in HR is covered by the folder's limit and by
         * their own. A limit that only sometimes applies is one nobody can reason about.
         */
        $uploader = $this->uploader();
        $folder = $this->folder();

        // Generous on the folder, tight on the person: the person's has to be the one that bites.
        $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 10_000_000);
        $this->quota(DocumentQuota::SCOPE_USER, $uploader->id, 2_000);

        $this->actingAs($uploader);
        $result = app(DocumentService::class)->store($this->pdfOf(6_000), ['folder_id' => $folder->id]);

        $this->assertNull($result['document'], 'The tighter of the two has to apply.');
    }

    public function test_a_folder_limit_covers_the_folders_beneath_it(): void
    {
        /*
         * Otherwise making a subfolder is how somebody escapes the limit — and the person who does
         * it has no idea that is what they did.
         */
        $uploader = $this->uploader();
        $parent = $this->folder('Marketing');
        $child = app(FolderService::class)->create(['name' => 'Campaigns', 'parent_id' => $parent->uuid]);

        $this->quota(DocumentQuota::SCOPE_FOLDER, $parent->id, 3_000);

        $this->actingAs($uploader);
        $result = app(DocumentService::class)->store($this->pdfOf(9_000), ['folder_id' => $child->id]);

        $this->assertNull($result['document'], 'A subfolder cannot escape its parent\'s limit.');
    }

    public function test_a_new_version_counts_against_the_limit_too(): void
    {
        /*
         * Versions are kept, so replacing a small file with a large one adds the large one. A limit
         * that only looked at first uploads would be filled by revisions.
         */
        $uploader = $this->uploader();
        $folder = $this->folder();
        $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 10_000);

        $this->actingAs($uploader);
        $document = app(DocumentService::class)->store($this->pdfOf(4_000), ['folder_id' => $folder->id])['document'];

        $this->assertNotNull($document);

        $result = app(DocumentService::class)->replace($document->fresh(), $this->pdfOf(9_000));

        $this->assertNull($result['version'], 'The new version would take it past the limit.');
    }

    // ── Measuring ────────────────────────────────────────────────────────────

    public function test_usage_is_measured_rather_than_counted_up(): void
    {
        /*
         * A running total drifts: every deletion, purge and restore has to remember to adjust it,
         * and the one that forgets is discovered when a department cannot upload and nobody can say
         * why. Measured from the versions, a deletion is reflected without anything being told.
         */
        $uploader = $this->uploader();
        $folder = $this->folder();
        $quota = $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 1_000_000);

        $this->actingAs($uploader);
        $document = app(DocumentService::class)->store($this->pdfOf(5_000), ['folder_id' => $folder->id])['document'];

        $this->assertGreaterThan(0, $this->quotas()->usedFor($quota));

        $document->delete();

        $this->assertSame(0, $this->quotas()->usedFor($quota), 'A deleted document stops counting.');
    }

    public function test_a_limit_on_a_folder_that_no_longer_exists_counts_nothing(): void
    {
        // Rather than throwing. A stale limit is somebody's tidying-up left undone; it should not
        // break the upload path for everybody else.
        $quota = $this->quota(DocumentQuota::SCOPE_FOLDER, 999_999, 1_000);

        $this->assertSame(0, $this->quotas()->usedFor($quota));
    }

    // ── Warning ──────────────────────────────────────────────────────────────

    public function test_it_warns_before_it_refuses(): void
    {
        /*
         * A quota that refuses with no warning beforehand is indistinguishable from the system
         * being broken, and the person it stops is rarely the person who filled it.
         */
        $uploader = $this->uploader();
        $folder = $this->folder();
        $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 10_000, warnAt: 50);

        $this->actingAs($uploader);
        app(DocumentService::class)->store($this->pdfOf(7_000), ['folder_id' => $folder->id]);

        $warnings = $this->quotas()->warningsFor($uploader, $folder->fresh());

        $this->assertNotEmpty($warnings);
        $this->assertFalse($warnings[0]['full'], 'Warned while there is still room, which is the point.');
    }

    public function test_a_limit_well_under_its_threshold_says_nothing(): void
    {
        // Warning about everything is how somebody learns to ignore the warnings.
        $uploader = $this->uploader();
        $folder = $this->folder();
        $this->quota(DocumentQuota::SCOPE_FOLDER, $folder->id, 10_000_000, warnAt: 85);

        $this->actingAs($uploader);
        app(DocumentService::class)->store($this->pdfOf(5_000), ['folder_id' => $folder->id]);

        $this->assertSame([], $this->quotas()->warningsFor($uploader, $folder->fresh()));
    }

    // ── Setting them ─────────────────────────────────────────────────────────

    public function test_setting_a_limit_needs_the_right_to_change_settings(): void
    {
        // It is a statement about how the account is shared out, not about any document.
        $folder = $this->folder();

        $this->actingAs($this->uploader())
            ->post(route('document-management.quotas.store'), [
                'scope' => 'folder',
                'scope_id' => $folder->id,
                'gigabytes' => 5,
            ])
            ->assertForbidden();
    }

    public function test_a_limit_is_stored_in_bytes_from_gigabytes(): void
    {
        $admin = $this->user('document.view', 'document.settings.manage');
        $folder = $this->folder();

        $this->actingAs($admin)
            ->post(route('document-management.quotas.store'), [
                'scope' => 'folder',
                'scope_id' => $folder->id,
                'gigabytes' => 2,
            ])
            ->assertRedirect();

        $quota = DocumentQuota::where('scope_id', $folder->id)->first();

        $this->assertNotNull($quota);
        $this->assertSame(2 * 1024 * 1024 * 1024, (int) $quota->bytes_limit);
    }

    public function test_setting_a_limit_twice_on_one_thing_replaces_it(): void
    {
        /*
         * Two limits on the same folder is a contradiction somebody set by accident, and whichever
         * the code happened to read first would look arbitrary.
         */
        $admin = $this->user('document.view', 'document.settings.manage');
        $folder = $this->folder();

        $this->actingAs($admin);
        $this->post(route('document-management.quotas.store'), ['scope' => 'folder', 'scope_id' => $folder->id, 'gigabytes' => 2]);
        $this->post(route('document-management.quotas.store'), ['scope' => 'folder', 'scope_id' => $folder->id, 'gigabytes' => 5]);

        $this->assertSame(1, DocumentQuota::where('scope', 'folder')->where('scope_id', $folder->id)->count());
        $this->assertSame(
            5 * 1024 * 1024 * 1024,
            (int) DocumentQuota::where('scope_id', $folder->id)->value('bytes_limit'),
        );
    }
}
