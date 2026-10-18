<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Auditing the structure, not only what is filed in it.
 *
 * The permission change is the reason this exists. Granting yourself access to Employee Files and
 * then reading it is two acts, and until now only the second was written down — which made the
 * trail read as complete while missing the half somebody would actually be asked about.
 */
class FolderAuditTest extends TestCase
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

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();

        foreach (['document.view', 'document.folder.manage'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'fa+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->user->givePermissionTo(['document.view', 'document.folder.manage']);
        $this->user = $this->user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $this->user->id, 'module_id' => $moduleId],
            ['access_level' => \App\Support\Hubs\ModuleHubs::SUPERUSER],
        );

        $this->actingAs($this->user);
    }

    private function folders(): FolderService
    {
        return app(FolderService::class);
    }

    private function actionsFor(DocumentFolder $folder)
    {
        return DocumentEvent::where('folder_id', $folder->id)->pluck('action');
    }

    public function test_the_lifecycle_of_a_folder_is_recorded(): void
    {
        $folder = $this->folders()->create(['name' => 'Human Resources ' . Str::random(4)]);

        $this->folders()->rename($folder, 'People ' . Str::random(4));
        $this->folders()->archive($folder->fresh());
        $this->folders()->restore($folder->fresh());

        $actions = $this->actionsFor($folder);

        $this->assertTrue($actions->contains(DocumentEvent::FOLDER_CREATED));
        $this->assertTrue($actions->contains(DocumentEvent::FOLDER_RENAMED));
        $this->assertTrue($actions->contains(DocumentEvent::FOLDER_ARCHIVED));
        $this->assertTrue($actions->contains(DocumentEvent::FOLDER_RESTORED));
    }

    public function test_a_rename_records_what_it_was_called_before(): void
    {
        $folder = $this->folders()->create(['name' => 'Old name ' . Str::random(4)]);
        $was = $folder->name;

        $this->folders()->rename($folder, 'New name ' . Str::random(4));

        $event = DocumentEvent::where('folder_id', $folder->id)
            ->where('action', DocumentEvent::FOLDER_RENAMED)->first();

        $this->assertNotNull($event);
        $this->assertSame($was, $event->detail['from']);
    }

    public function test_deleting_a_folder_leaves_the_record_behind(): void
    {
        // The event outlives the folder on purpose: "who deleted Employee Files" is asked precisely
        // because the folder is no longer there to ask about.
        $folder = $this->folders()->create(['name' => 'Temporary ' . Str::random(4)]);
        $id = $folder->id;
        $name = $folder->name;

        $this->folders()->delete($folder);

        $event = DocumentEvent::where('folder_id', $id)
            ->where('action', DocumentEvent::FOLDER_DELETED)->first();

        $this->assertNotNull($event, 'A deletion nobody recorded is a deletion nobody can ask about.');
        $this->assertSame($name, $event->detail['name']);
        $this->assertNull(DocumentFolder::find($id));
    }

    // ── The one this exists for ──────────────────────────────────────────────

    public function test_granting_access_to_a_folder_is_recorded_with_who_gained_it(): void
    {
        $folder = $this->folders()->create(['name' => 'Employee Files ' . Str::random(4)]);

        $this->put(route('document-management.folders.permissions', $folder->uuid), [
            'grants' => [
                ['subject_type' => 'role', 'subject_name' => 'Payroll', 'can_view' => true],
            ],
        ])->assertRedirect();

        $event = DocumentEvent::where('folder_id', $folder->id)
            ->where('action', DocumentEvent::FOLDER_PERMISSIONS_CHANGED)->first();

        $this->assertNotNull($event, 'The change that opens a folder must be the one thing recorded above all.');
        $this->assertContains('Payroll', $event->detail['granted']);
    }

    public function test_taking_access_away_is_recorded_too(): void
    {
        $folder = $this->folders()->create(['name' => 'Employee Files ' . Str::random(4)]);

        DocumentFolderPermission::create([
            'uuid' => (string) Str::uuid(), 'folder_id' => $folder->id,
            'subject_type' => 'role', 'subject_name' => 'Payroll', 'can_view' => true,
        ]);

        $this->put(route('document-management.folders.permissions', $folder->uuid), ['grants' => []])
            ->assertRedirect();

        $event = DocumentEvent::where('folder_id', $folder->id)
            ->where('action', DocumentEvent::FOLDER_PERMISSIONS_CHANGED)->first();

        $this->assertNotNull($event);
        $this->assertContains('Payroll', $event->detail['revoked']);
    }

    public function test_saving_the_permissions_form_unchanged_records_nothing(): void
    {
        /*
         * A history full of "permissions changed" entries where nothing changed is one nobody reads
         * closely enough to spot the entry that mattered.
         */
        $folder = $this->folders()->create(['name' => 'Finance ' . Str::random(4)]);

        $grants = [['subject_type' => 'role', 'subject_name' => 'Payroll', 'can_view' => true]];

        $this->put(route('document-management.folders.permissions', $folder->uuid), ['grants' => $grants]);
        $this->put(route('document-management.folders.permissions', $folder->uuid), ['grants' => $grants]);

        $this->assertSame(
            1,
            DocumentEvent::where('folder_id', $folder->id)
                ->where('action', DocumentEvent::FOLDER_PERMISSIONS_CHANGED)->count(),
        );
    }

    public function test_an_event_must_belong_to_a_document_or_a_folder(): void
    {
        // The database refuses a row about nothing, because the only way to write one is a bug and
        // an audit table quietly accumulating them is worse than an error.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DocumentEvent::create(['action' => 'invented', 'created_at' => now()]);
    }
}
