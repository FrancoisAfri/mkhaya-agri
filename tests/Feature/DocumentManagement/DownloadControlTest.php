<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use App\Modules\Operations\DocumentManagement\Services\AccessRequestService;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Services\InternalShareService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Reading something and taking a copy of it are different acts.
 *
 * They travelled together until now: anyone who could open a folder could download everything in
 * it. That is the wrong default for exactly the documents people want a repository for — the
 * signed contract, the board pack, the salary schedule — where the point is that it can be read
 * and not circulated.
 *
 * Two rules and one escape hatch. A document or a folder can be marked read-only, which applies to
 * everybody. A grant can allow a copy, which applies to one person. And somebody refused a copy can
 * ask for one, which is the difference between a rule and a wall.
 */
class DownloadControlTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'dl+' . Str::random(8) . '@example.test',
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

    private function reader(): User
    {
        return $this->user('document.view', 'document.download');
    }

    private function folder(string $name, array $attributes = []): DocumentFolder
    {
        return app(FolderService::class)->create(array_merge(
            ['name' => $name . ' ' . Str::random(4)],
            $attributes,
        ));
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));
    }

    // ── §4 Read-only ─────────────────────────────────────────────────────────

    public function test_reading_is_still_allowed_when_copying_is_not(): void
    {
        /*
         * The whole point of the marking. If refusing the copy also closed the document, the
         * feature would be indistinguishable from withdrawing access, and nobody would use it for
         * the documents it exists for.
         */
        $reader = $this->reader();
        $document = $this->document(['download_disabled' => true]);

        $this->assertTrue($reader->can('view', $document), 'A read-only document is still readable.');
        $this->assertFalse($reader->can('download', $document), 'A read-only document is not downloadable.');
    }

    public function test_a_document_is_downloadable_until_somebody_says_otherwise(): void
    {
        // The default has to stay what it was, or the migration is a silent withdrawal of a
        // capability everybody already had.
        $reader = $this->reader();

        $this->assertTrue($reader->can('download', $this->document()));
    }

    public function test_marking_a_folder_read_only_covers_what_is_filed_in_it(): void
    {
        $reader = $this->reader();
        $folder = $this->folder('Board packs', ['download_disabled' => true]);
        $document = $this->document(['folder_id' => $folder->id]);

        $this->assertTrue($reader->can('view', $document));
        $this->assertFalse($reader->can('download', $document), 'The folder said no copies leave it.');
    }

    public function test_a_read_only_folder_covers_the_folders_beneath_it(): void
    {
        /*
         * Inherited restrictively, unlike a grant. If the nearest rule won here, marking Human
         * Resources read-only would mean nothing the moment somebody made a subfolder inside it —
         * and the person making the subfolder would have no idea they had just undone it.
         */
        $reader = $this->reader();
        $parent = $this->folder('Human Resources', ['download_disabled' => true]);
        $child = $this->folder('Employee files', ['parent_id' => $parent->uuid]);
        $document = $this->document(['folder_id' => $child->id]);

        $this->assertFalse(
            $reader->can('download', $document),
            'A subfolder cannot quietly undo a restriction placed above it.',
        );
    }

    public function test_a_folder_grant_can_withhold_the_copy_without_withholding_the_folder(): void
    {
        $reader = $this->reader();
        $folder = $this->folder('Contracts', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT,
            'department_unit_id' => 999_999,
        ]);

        DB::table('document_folder_permissions')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'folder_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $reader->id,
            'subject_name' => $reader->email,
            'can_view' => true,
            'can_download' => false,
            'can_upload' => false,
            'can_edit' => false,
            'can_delete' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $access = app(FolderAccess::class);

        $this->assertTrue($access->can($reader, $folder, 'view'));
        $this->assertFalse($access->can($reader, $folder, 'download'));
    }

    // ── The escape hatch ─────────────────────────────────────────────────────

    public function test_a_grant_that_allows_downloading_beats_the_marking(): void
    {
        /*
         * Not a hole in the marking — it is how an exception to it is made. Approving a request for
         * a copy mints exactly this grant, so if the marking outranked it, approving a request
         * would do nothing at all and the queue would fill with requests that were granted and
         * still did not work.
         */
        $reader = $this->reader();
        $document = $this->document(['download_disabled' => true]);

        $this->actingAs($reader);
        app(InternalShareService::class)->grant(
            $document,
            DocumentGrant::SUBJECT_USER,
            $reader->id,
            $reader->email,
            ['can_download' => true],
        );

        $this->assertTrue($reader->fresh()->can('download', $document));
    }

    public function test_a_share_that_says_nothing_about_copies_does_not_take_one_away(): void
    {
        /*
         * A grant only ever adds. Somebody who could already download this document must not lose
         * that because a colleague shared it with them view-only — otherwise sharing becomes a way
         * of quietly demoting people, and the safe thing to do with a share is refuse it.
         */
        $reader = $this->reader();
        $document = $this->document();

        $this->assertTrue($reader->can('download', $document), 'Downloadable to begin with.');

        $this->actingAs($reader);
        app(InternalShareService::class)->grant(
            $document,
            DocumentGrant::SUBJECT_USER,
            $reader->id,
            $reader->email,
            ['can_download' => false],
        );

        $this->assertTrue(
            $reader->fresh()->can('download', $document),
            'A view-only share must not withdraw a copy they could already have made.',
        );
    }

    // ── §5 Asking for a copy ─────────────────────────────────────────────────

    public function test_somebody_refused_a_copy_may_ask_for_one(): void
    {
        $reader = $this->reader();
        $document = $this->document(['download_disabled' => true]);

        $this->assertTrue($reader->can('requestDownload', $document));
    }

    public function test_somebody_who_may_already_download_is_not_offered_the_ask(): void
    {
        // A request that would change nothing is noise in somebody else's queue.
        $reader = $this->reader();

        $this->assertFalse($reader->can('requestDownload', $this->document()));
    }

    public function test_somebody_who_cannot_read_it_is_asking_the_other_question(): void
    {
        /*
         * Letting them ask for a copy of something they cannot open would confirm it exists, which
         * is the disclosure the confidentiality was protecting against. They ask for access.
         */
        $outsider = $this->user('document.view', 'document.download');
        $document = $this->document(['is_confidential' => true, 'created_by' => 0]);

        $this->assertFalse($outsider->can('view', $document));
        $this->assertFalse($outsider->can('requestDownload', $document));
    }

    public function test_approving_the_request_actually_lets_them_download(): void
    {
        $reader = $this->reader();
        $document = $this->document(['download_disabled' => true]);

        $request = app(AccessRequestService::class)->request(
            $reader,
            $document,
            'For the board meeting',
            DocumentAccessRequest::KIND_DOWNLOAD,
        );

        $this->actingAs($this->user('document.view', 'document.share'));
        app(AccessRequestService::class)->grant($request);

        $this->assertTrue(
            $reader->fresh()->can('download', $document),
            'An approved request for a copy has to produce a copy.',
        );
    }

    public function test_an_approved_copy_stops_being_approved(): void
    {
        /*
         * Approved once is not approved forever. The alternative is a grant nobody revokes because
         * nobody remembers it was made — which is the same failure as the auditor who still has the
         * Finance folder two years later.
         */
        $reader = $this->reader();
        $document = $this->document(['download_disabled' => true]);

        $request = app(AccessRequestService::class)->request(
            $reader, $document, null, DocumentAccessRequest::KIND_DOWNLOAD,
        );

        $this->actingAs($this->user('document.view', 'document.share'));
        app(AccessRequestService::class)->grant($request);

        $grant = DocumentGrant::where('document_id', $document->id)->where('subject_id', $reader->id)->first();

        $this->assertNotNull($grant->expires_at, 'An approved copy has to have an end.');

        $grant->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertFalse(
            $reader->fresh()->can('download', $document),
            'Once the window closes the copy is refused again.',
        );
    }

    public function test_asking_for_access_and_asking_for_a_copy_are_two_questions(): void
    {
        /*
         * Somebody let in to read a document who then wants a copy of it is asking something new.
         * Folding it into the answered request would leave the second question silently unanswered,
         * with nothing in any queue to show for it.
         */
        $reader = $this->reader();
        $document = $this->document();
        $service = app(AccessRequestService::class);

        $access = $service->request($reader, $document, null, DocumentAccessRequest::KIND_ACCESS);
        $copy = $service->request($reader, $document, null, DocumentAccessRequest::KIND_DOWNLOAD);

        $this->assertNotSame($access->id, $copy->id);
        $this->assertSame(2, DocumentAccessRequest::where('document_id', $document->id)->count());
    }

    public function test_asking_twice_for_the_same_thing_is_one_request(): void
    {
        $reader = $this->reader();
        $document = $this->document();
        $service = app(AccessRequestService::class);

        $first = $service->request($reader, $document, null, DocumentAccessRequest::KIND_DOWNLOAD);
        $second = $service->request($reader, $document, null, DocumentAccessRequest::KIND_DOWNLOAD);

        $this->assertSame($first->id, $second->id, 'Asking again while nothing has happened is one request.');
    }

    public function test_a_folder_can_be_marked_read_only_through_the_screen_that_offers_it(): void
    {
        /*
         * Both halves, because they were written at different times and each was half-wired: the
         * create form validated a field it never passed on, and the edit form applied one it never
         * validated. Either way the switch appeared to work and did nothing.
         */
        $manager = $this->user('document.view', 'document.download', 'document.folder.manage');

        $this->actingAs($manager)
            ->post(route('document-management.folders.store'), [
                'name' => 'Board packs ' . Str::random(4),
                'download_disabled' => true,
            ])
            ->assertRedirect();

        $folder = DocumentFolder::query()->latest('id')->first();
        $this->assertTrue((bool) $folder->download_disabled, 'The create form has to carry the marking.');

        $this->actingAs($manager)
            ->put(route('document-management.folders.update', $folder->uuid), [
                'name' => $folder->name,
                'download_disabled' => false,
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $folder->fresh()->download_disabled, 'The edit form has to lift it again.');
    }

    // ── The route ────────────────────────────────────────────────────────────

    public function test_the_download_route_refuses_what_the_policy_refuses(): void
    {
        // The policy is the rule; this is the proof that the route asks it. A rule enforced only in
        // the page that renders the button is not enforced.
        $reader = $this->reader();
        $document = $this->document(['download_disabled' => true]);

        $this->actingAs($reader)
            ->get(route('document-management.documents.download', $document->uuid))
            ->assertForbidden();
    }

    public function test_marking_a_document_read_only_needs_the_right_to_change_it(): void
    {
        $reader = $this->reader();
        $document = $this->document();

        $this->actingAs($reader)
            ->post(route('document-management.documents.download-policy', $document->uuid), ['restricted' => true])
            ->assertForbidden();

        $this->assertFalse((bool) $document->fresh()->download_disabled);
    }

    public function test_somebody_who_may_edit_it_may_mark_it(): void
    {
        $editor = $this->user('document.view', 'document.download', 'document.update');
        $document = $this->document();

        $this->actingAs($editor)
            ->post(route('document-management.documents.download-policy', $document->uuid), ['restricted' => true])
            ->assertRedirect();

        $this->assertTrue((bool) $document->fresh()->download_disabled);
        $this->assertFalse($editor->fresh()->can('download', $document->fresh()));
    }
}
