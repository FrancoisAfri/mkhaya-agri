<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentVersion;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The versions table drew one empty row on every document in the system.
 *
 * The detail payload built the list under the key `versions`, and joined it to the row summary
 * with `+`. Array union keeps the left operand for a duplicate key, and the summary already
 * returned `versions` as a count — so the integer won, the list was discarded without a word, and
 * the template iterated the number 1. One row, every cell blank, on every document.
 *
 * Nothing failed. The page rendered, the table had a row, and the only clue was that the row said
 * nothing — which reads as a file whose metadata did not survive upload rather than as a bug in
 * the page.
 */
class TheVersionsTableShowsVersionsTest extends TestCase
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
            ?? DB::table('security_categories')->insertGetId([
                'name' => 'Test', 'created_at' => now(), 'updated_at' => now(),
            ]);

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
            'uuid' => (string) Str::uuid(), 'email' => 'ver+'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        $user = $user->fresh();

        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id,
                'module_id' => DB::table('security_modules')->where('code_name', 'document_management')->value('id')],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function documentWithVersions(int $count): Document
    {
        $document = Document::create([
            'title' => 'Mkhaya Move Images', 'status' => Document::STATUS_ACTIVE, 'version_count' => $count,
        ]);

        $last = null;

        for ($n = 1; $n <= $count; $n++) {
            $last = DocumentVersion::create([
                'company_id' => $this->companyId,
                'document_id' => $document->id,
                'version' => $n, 'major' => $n, 'minor' => 0,
                'original_name' => "move-images-v{$n}.zip",
                'stored_name' => Str::random(20).'.zip',
                'disk' => 'documents', 'path' => 'documents/'.Str::random(12),
                'extension' => 'zip', 'mime' => 'application/zip',
                'size' => 1048576 * $n,
                'checksum' => hash('sha256', "v{$n}"),
            ]);
        }

        $document->update(['current_version_id' => $last?->id]);

        return $document->fresh();
    }

    private function openDocument(Document $document): array
    {
        return $this->actingAs($this->user('document.view'))
            ->get(route('document-management.documents.show', $document->uuid))
            ->assertOk()
            ->viewData('page')['props'];
    }

    public function test_the_list_of_versions_reaches_the_page(): void
    {
        $document = $this->documentWithVersions(2);

        $versions = $this->openDocument($document)['document']['versions_list'] ?? null;

        $this->assertIsArray($versions, 'The page was handed no list of versions at all.');
        $this->assertCount(2, $versions);
    }

    public function test_each_row_carries_what_the_table_prints(): void
    {
        $document = $this->documentWithVersions(1);

        $row = $this->openDocument($document)['document']['versions_list'][0];

        /*
         * The four columns the table draws. A row that reaches the page without these is the
         * failure that was happening — present, countable, and empty in every cell.
         */
        $this->assertNotEmpty($row['label'] ?? null, 'The version column would be blank.');
        $this->assertSame('move-images-v1.zip', $row['name'] ?? null, 'The file column would be blank.');
        $this->assertNotEmpty($row['size'] ?? null, 'The size column would be blank.');
        $this->assertNotEmpty($row['uploaded_at'] ?? null, 'The uploaded column would be blank.');
    }

    public function test_the_count_and_the_list_are_not_the_same_thing(): void
    {
        $document = $this->documentWithVersions(3);

        $props = $this->openDocument($document)['document'];

        // The collision that caused this: both were called `versions`, and the integer won.
        $this->assertIsInt($props['versions'], 'The summary count is no longer a count.');
        $this->assertSame(3, $props['versions']);
        $this->assertIsArray($props['versions_list']);
        $this->assertCount(3, $props['versions_list']);
    }

    // ── What a reader is shown ──────────────────────────────────────────────────────────────────

    public function test_somebody_who_cannot_upload_is_not_shown_a_dropzone(): void
    {
        $this->documentWithVersions(1);

        $props = $this->actingAs($this->user('document.view'))
            ->get(route('document-management.index'))
            ->assertOk()
            ->viewData('page')['props'];

        /*
         * The flag answered "does uploading mean anything on this screen", which is not the same
         * question as "may this person upload". A reader was given a dropzone occupying half the
         * page that refuses every file dropped on it.
         */
        $this->assertFalse($props['view']['uploadable'], 'A reader is still offered a dropzone.');
    }

    public function test_somebody_who_can_upload_still_gets_one(): void
    {
        $props = $this->actingAs($this->user('document.view', 'document.upload'))
            ->get(route('document-management.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue($props['view']['uploadable']);
    }

    public function test_each_row_says_what_this_person_may_do_with_it(): void
    {
        $this->documentWithVersions(1);

        $props = $this->actingAs($this->user('document.view'))
            ->get(route('document-management.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $row = $props['documents']['data'][0] ?? null;

        $this->assertNotNull($row, 'The list came back empty.');

        // The row menu offered archive and delete and nothing else, because nothing told it what
        // else this person was allowed to do.
        $this->assertIsArray($row['can'] ?? null, 'The row carries no abilities, so the menu cannot be built from them.');

        foreach (['update', 'download', 'archive', 'restore', 'share', 'replace'] as $ability) {
            $this->assertArrayHasKey($ability, $row['can']);
        }

        // A reader holds none of them, which is the point: they are shown a menu of what they can
        // do rather than a list of refusals.
        $this->assertFalse($row['can']['archive']);
    }
}
