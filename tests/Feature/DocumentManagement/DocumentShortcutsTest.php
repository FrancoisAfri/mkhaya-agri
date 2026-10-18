<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentShortcut;
use App\Modules\Operations\DocumentManagement\Services\DocumentSearch;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * One document, filed in more than one place.
 *
 * A document belongs in one folder and is looked for in several. The signed lease lives under
 * Property and the finance team looks for it under Contracts. Today the answer is to upload it
 * twice, and two copies of one document is the oldest failure a repository has: one gets a new
 * version, the other quietly becomes wrong, and nobody can tell which they are reading.
 */
class DocumentShortcutsTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'sc+' . Str::random(8) . '@example.test',
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

    private function editor(): User
    {
        return $this->user('document.view', 'document.update');
    }

    private function folder(string $name): DocumentFolder
    {
        return app(FolderService::class)->create(['name' => $name . ' ' . Str::random(4)]);
    }

    private function document(DocumentFolder $folder): Document
    {
        return Document::create([
            'title' => 'Signed lease ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
            'folder_id' => $folder->id,
        ]);
    }

    private function inFolder(User $user, DocumentFolder $folder): array
    {
        // Through the search itself, which is what the folder listing uses. Reimplementing the
        // folder filter here would prove that this test can find shortcuts, not that the module can.
        return app(DocumentSearch::class)
            ->query($user, ['folder' => $folder->uuid])
            ->pluck('uuid')
            ->all();
    }

    // ── Finding it in both places ────────────────────────────────────────────

    public function test_a_shortcut_makes_the_document_show_up_in_the_other_folder(): void
    {
        // A shortcut that did not show when its folder was opened would be a bookmark nobody can
        // see, which is the same as not having made it.
        $editor = $this->editor();
        $property = $this->folder('Property');
        $contracts = $this->folder('Contracts');
        $document = $this->document($property);

        $this->actingAs($editor)
            ->post(route('document-management.documents.shortcut', $document->uuid), [
                'folder' => $contracts->uuid,
            ])
            ->assertRedirect();

        $this->assertContains($document->uuid, $this->inFolder($editor, $contracts));
    }

    public function test_it_still_shows_up_where_it_actually_lives(): void
    {
        $editor = $this->editor();
        $property = $this->folder('Property');
        $contracts = $this->folder('Contracts');
        $document = $this->document($property);

        $this->actingAs($editor)
            ->post(route('document-management.documents.shortcut', $document->uuid), ['folder' => $contracts->uuid]);

        $this->assertContains($document->uuid, $this->inFolder($editor, $property));
    }

    public function test_it_stays_one_document(): void
    {
        /*
         * The whole point. Two copies is the oldest failure a repository has — one gets a new
         * version, the other quietly becomes wrong, and nobody can tell which they are reading.
         */
        $editor = $this->editor();
        $property = $this->folder('Property');
        $contracts = $this->folder('Contracts');
        $document = $this->document($property);

        $this->actingAs($editor)
            ->post(route('document-management.documents.shortcut', $document->uuid), ['folder' => $contracts->uuid]);

        $this->assertSame(1, Document::where('title', $document->title)->count());
        $this->assertSame($property->id, (int) $document->fresh()->folder_id, 'It has not moved.');
    }

    // ── What a shortcut is not ───────────────────────────────────────────────

    public function test_a_shortcut_does_not_open_a_document_to_anybody(): void
    {
        /*
         * The document's own rules still decide. If a shortcut carried access, putting one in an
         * open folder would be a way of publishing something confidential — and it would be the
         * quietest way, because nothing about the document itself changed.
         */
        $editor = $this->editor();
        $property = $this->folder('Property');
        $open = $this->folder('Everyone');

        $confidential = Document::create([
            'title' => 'Disciplinary record ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
            'folder_id' => $property->id,
            'is_confidential' => true,
            'created_by' => 0,
        ]);

        DocumentShortcut::create([
            'uuid' => (string) Str::uuid(),
            'document_id' => $confidential->id,
            'folder_id' => $open->id,
        ]);

        $outsider = $this->user('document.view');

        $this->assertFalse($outsider->can('view', $confidential));
        $this->assertNotContains($confidential->uuid, $this->inFolder($outsider, $open));
    }

    public function test_the_same_shortcut_twice_is_one_shortcut(): void
    {
        $editor = $this->editor();
        $property = $this->folder('Property');
        $contracts = $this->folder('Contracts');
        $document = $this->document($property);

        $this->actingAs($editor);
        $this->post(route('document-management.documents.shortcut', $document->uuid), ['folder' => $contracts->uuid]);
        $this->post(route('document-management.documents.shortcut', $document->uuid), ['folder' => $contracts->uuid]);

        $this->assertSame(1, DocumentShortcut::where('document_id', $document->id)->count());
    }

    public function test_a_shortcut_into_its_own_folder_is_refused(): void
    {
        // It would render as the same document listed twice in one folder.
        $editor = $this->editor();
        $property = $this->folder('Property');
        $document = $this->document($property);

        $this->actingAs($editor)
            ->post(route('document-management.documents.shortcut', $document->uuid), ['folder' => $property->uuid])
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentShortcut::where('document_id', $document->id)->count());
    }

    public function test_filing_it_elsewhere_needs_the_right_to_change_it(): void
    {
        /*
         * The right to change the document rather than to upload into the folder: nothing new
         * arrives anywhere, and what this needs is somebody entitled to say where this document is
         * found.
         */
        $property = $this->folder('Property');
        $contracts = $this->folder('Contracts');
        $document = $this->document($property);

        $this->actingAs($this->user('document.view'))
            ->post(route('document-management.documents.shortcut', $document->uuid), ['folder' => $contracts->uuid])
            ->assertForbidden();
    }

    public function test_a_shortcut_can_be_taken_off_again(): void
    {
        $editor = $this->editor();
        $property = $this->folder('Property');
        $contracts = $this->folder('Contracts');
        $document = $this->document($property);

        $this->actingAs($editor);
        $this->post(route('document-management.documents.shortcut', $document->uuid), ['folder' => $contracts->uuid]);

        $shortcut = DocumentShortcut::where('document_id', $document->id)->first();

        $this->post(route('document-management.documents.shortcut', $document->uuid), [
            'remove' => true,
            'shortcut' => $shortcut->uuid,
        ])->assertRedirect();

        $this->assertNotContains($document->uuid, $this->inFolder($editor, $contracts));
        $this->assertContains($document->uuid, $this->inFolder($editor, $property), 'The document itself is untouched.');
    }
}
