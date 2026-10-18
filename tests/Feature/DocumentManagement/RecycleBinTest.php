<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\LegalHoldService;
use App\Modules\Operations\DocumentManagement\Services\RecycleBin;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Deleted documents, and getting them back.
 *
 * A deletion nobody can undo is the one mistake in a repository that cannot be talked about
 * afterwards. What matters as much as the restore is who deleted it and when — the two questions
 * asked every time somebody finds a document missing.
 */
class RecycleBinTest extends TestCase
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

        $this->user = $this->userWith('document.view', 'document.delete', 'document.restore', 'document.purge');
        $this->actingAs($this->user);

        DocumentSetting::query()->delete();
        DocumentSetting::create(['recycle_bin_days' => 30, 'max_file_size_mb' => 25]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'bin+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($permissions);
        $user = $user->fresh();

        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    private function bin(): RecycleBin
    {
        return app(RecycleBin::class);
    }

    public function test_deleting_puts_a_document_in_the_bin_rather_than_destroying_it(): void
    {
        $document = $this->document(['title' => 'Deleted by mistake']);

        app(DocumentService::class)->delete($document, 'Cleaning up duplicates');

        $this->assertNull(Document::find($document->id));
        $this->assertNotNull(Document::onlyTrashed()->find($document->id));
    }

    public function test_the_bin_says_who_deleted_it_when_and_why(): void
    {
        // The three questions somebody arrives with. A bin that lists titles and nothing else sends
        // them to ask around, which is what they were doing before there was a bin.
        $document = $this->document();

        app(DocumentService::class)->delete($document, 'Superseded by the 2026 version');

        $deleted = Document::onlyTrashed()->find($document->id);

        $this->assertSame($this->user->id, $deleted->deleted_by);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame('Superseded by the 2026 version', $deleted->delete_reason);
    }

    public function test_restoring_brings_it_back_and_clears_the_deletion(): void
    {
        $document = $this->document(['title' => 'Wanted back']);
        app(DocumentService::class)->delete($document, 'Oops');

        $result = $this->bin()->restore(Document::onlyTrashed()->find($document->id));

        $this->assertTrue($result['ok']);

        $restored = Document::find($document->id);

        $this->assertNotNull($restored);
        $this->assertNull($restored->deleted_by);
        $this->assertNull($restored->delete_reason);
    }

    public function test_restoring_into_a_folder_that_has_gone_is_refused_with_a_reason(): void
    {
        /*
         * Restoring into a folder that no longer exists produces a document nobody can navigate to,
         * which looks exactly like the restore having failed silently.
         */
        $folder = app(\App\Modules\Operations\DocumentManagement\Services\FolderService::class)
            ->create(['name' => 'Temporary ' . Str::random(4)]);

        $document = $this->document(['folder_id' => $folder->id]);
        app(DocumentService::class)->delete($document);

        DB::table('document_folders')->where('id', $folder->id)->delete();

        $result = $this->bin()->restore(Document::onlyTrashed()->find($document->id));

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
        $this->assertNull(Document::find($document->id));
    }

    public function test_the_bin_shows_when_each_document_stops_being_recoverable(): void
    {
        $document = $this->document();
        app(DocumentService::class)->delete($document);

        $deleted = Document::onlyTrashed()->find($document->id);

        $this->assertSame(
            $deleted->deleted_at->copy()->addDays(30)->toDateString(),
            $this->bin()->purgeableFrom($deleted)?->toDateString(),
        );
    }

    public function test_emptying_removes_what_is_past_its_window_and_leaves_the_rest(): void
    {
        $old = $this->document(['title' => 'Long gone']);
        $recent = $this->document(['title' => 'Just deleted']);

        app(DocumentService::class)->delete($old);
        app(DocumentService::class)->delete($recent);

        Document::onlyTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(45)]);

        $counts = $this->bin()->empty();

        $this->assertSame(1, $counts['purged']);
        $this->assertNull(Document::withTrashed()->find($old->id));
        $this->assertNotNull(Document::onlyTrashed()->find($recent->id));
    }

    public function test_a_held_document_is_never_purged_however_old_it_is(): void
    {
        /*
         * The bin is exactly where somebody would look to get around a legal hold: delete it, wait,
         * and let the cleanup do the rest.
         */
        $document = $this->document(['title' => 'Held']);
        app(LegalHoldService::class)->place($document, 'CASE-2026-014');

        // Placed under hold, so ordinary deletion is refused — put it in the bin the hard way.
        $document->forceFill(['deleted_at' => now()->subYears(2), 'deleted_by' => $this->user->id])->save();

        $counts = $this->bin()->empty(includeRecent: true);

        $this->assertSame(0, $counts['purged']);
        $this->assertSame(1, $counts['held']);
        $this->assertNotNull(Document::onlyTrashed()->find($document->id));
    }

    public function test_the_bin_does_not_show_documents_the_viewer_could_never_open(): void
    {
        // A deleted confidential document is still a confidential document, and a bin that showed
        // everything would be the easiest way in the system to read what somebody was refused.
        $secret = $this->document(['title' => 'Secret', 'is_confidential' => true]);
        app(DocumentService::class)->delete($secret);

        $onlooker = $this->userWith('document.view', 'document.restore');

        $this->assertFalse(
            $this->bin()->contents($onlooker)->pluck('title')->contains('Secret'),
        );
    }

    public function test_the_page_loads_and_lists_what_was_deleted(): void
    {
        $document = $this->document(['title' => 'On the page']);
        app(DocumentService::class)->delete($document);

        $this->get(route('document-management.recycle-bin'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/DocumentManagement/Pages/RecycleBin')
                ->has('documents.data', 1));
    }

    public function test_emptying_the_bin_needs_the_purge_permission(): void
    {
        // Restoring and destroying are deliberately not the same person's decision.
        $this->actingAs($this->userWith('document.view', 'document.restore'));

        $this->post(route('document-management.recycle-bin.empty'))->assertForbidden();
    }

    // ── Bulk operations ──────────────────────────────────────────────────────

    public function test_a_bulk_action_checks_every_document_rather_than_authorising_once(): void
    {
        /*
         * The failure this prevents: somebody selects forty documents, the batch is authorised
         * against the first, and three confidential ones go along with the rest.
         */
        $ordinary = $this->document(['title' => 'Ordinary']);
        $confidential = $this->document(['title' => 'Confidential', 'is_confidential' => true]);

        // Can delete, but is not cleared for confidential documents.
        $this->actingAs($this->userWith('document.view', 'document.delete'));

        $this->post(route('document-management.bulk'), [
            'action' => 'delete',
            'documents' => [$ordinary->uuid, $confidential->uuid],
        ])->assertRedirect();

        $this->assertNull(Document::find($ordinary->id), 'The one they may delete should be gone.');
        $this->assertNotNull(Document::find($confidential->id), 'The one they may not must survive the batch.');
    }

    public function test_bulk_tagging_adds_rather_than_replaces(): void
    {
        // A bulk tag that replaced would wipe whatever each document already carried — a
        // destructive operation wearing the name of an additive one.
        $document = $this->document(['tags' => ['existing']]);

        $this->actingAs($this->userWith('document.view', 'document.update'));

        $this->post(route('document-management.bulk'), [
            'action' => 'tag',
            'documents' => [$document->uuid],
            'tags' => ['added'],
        ])->assertRedirect();

        $tags = $document->fresh()->tags;

        $this->assertContains('existing', $tags);
        $this->assertContains('added', $tags);
    }

    public function test_bulk_moving_records_where_each_document_went(): void
    {
        $folder = app(\App\Modules\Operations\DocumentManagement\Services\FolderService::class)
            ->create(['name' => 'Destination ' . Str::random(4)]);

        $document = $this->document();

        $this->actingAs($this->userWith('document.view', 'document.update'));

        $this->post(route('document-management.bulk'), [
            'action' => 'move',
            'documents' => [$document->uuid],
            'folder' => $folder->uuid,
        ])->assertRedirect();

        $this->assertSame($folder->id, $document->fresh()->folder_id);

        $event = \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::query()
            ->where('document_id', $document->id)
            ->where('action', \App\Modules\Operations\DocumentManagement\Models\DocumentEvent::MOVED)
            ->first();

        $this->assertNotNull($event, 'A bulk move is still a move, and the trail must say so.');
        $this->assertTrue($event->detail['bulk'] ?? false);
    }

    public function test_a_uuid_the_person_cannot_see_is_counted_as_refused_rather_than_ignored(): void
    {
        // Otherwise the count reports success for documents that were never touched.
        $hidden = $this->document(['title' => 'Hidden', 'is_confidential' => true]);

        $this->actingAs($this->userWith('document.view', 'document.update'));

        $this->post(route('document-management.bulk'), [
            'action' => 'tag',
            'documents' => [$hidden->uuid],
            'tags' => ['nope'],
        ])->assertRedirect();

        $this->assertNotContains('nope', $hidden->fresh()->tags ?? []);
    }
}
