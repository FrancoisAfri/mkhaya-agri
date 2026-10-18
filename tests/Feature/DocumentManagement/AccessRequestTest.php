<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Services\AccessRequestService;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Asking to be let in.
 *
 * The repository hides what somebody cannot open, which leaves one way for them to meet a document
 * they have no access to: a colleague sends a link. Answering that with a bare 403 sends them to
 * ask in a corridor, and whatever grant follows is recorded nowhere.
 */
class AccessRequestTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'ar+' . Str::random(8) . '@example.test',
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

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'Board pack', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    public function test_a_refused_document_offers_a_way_to_ask_rather_than_a_bare_403(): void
    {
        $outsider = $this->user('document.view');
        $document = $this->document(['title' => 'Confidential board pack', 'is_confidential' => true]);

        $this->actingAs($outsider)
            ->get(route('document-management.documents.show', $document->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/DocumentManagement/Pages/NoAccess')
                ->where('document.title', 'Confidential board pack'));
    }

    public function test_a_document_under_legal_hold_is_not_offered_at_all(): void
    {
        /*
         * Telling somebody a document exists and is being preserved for a matter is itself the
         * disclosure, and a request to see one is not answered by a share.
         */
        $outsider = $this->user('document.view');
        $held = $this->document(['title' => 'Held document', 'is_legally_sensitive' => true]);

        $this->actingAs($outsider)
            ->get(route('document-management.documents.show', $held->uuid))
            ->assertForbidden();
    }

    public function test_asking_records_who_asked_and_why(): void
    {
        $outsider = $this->user('document.view');
        $document = $this->document(['is_confidential' => true]);

        $this->actingAs($outsider)
            ->post(route('document-management.access-requests.store'), [
                'document' => $document->uuid,
                'reason' => 'I am preparing the audit pack.',
            ])->assertRedirect();

        $request = DocumentAccessRequest::where('document_id', $document->id)->first();

        $this->assertNotNull($request);
        $this->assertSame($outsider->id, $request->requested_by);
        $this->assertSame('I am preparing the audit pack.', $request->reason);
        $this->assertTrue($request->isPending());
    }

    public function test_asking_twice_is_one_request(): void
    {
        // Otherwise the queue fills with the same person asking again because nothing visibly
        // happened, and whoever answers cannot tell the repeats from the new ones.
        $outsider = $this->user('document.view');
        $document = $this->document(['is_confidential' => true]);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($outsider)->post(route('document-management.access-requests.store'), [
                'document' => $document->uuid,
            ]);
        }

        $this->assertSame(1, DocumentAccessRequest::where('document_id', $document->id)->count());
    }

    public function test_granting_lets_them_in_through_an_ordinary_share(): void
    {
        /*
         * Not a second mechanism. A granted request produces the same internal share anybody could
         * have made by hand, so there is one place to look when asking why somebody can open this.
         */
        $outsider = $this->user('document.view');
        $document = $this->document(['title' => 'Wanted', 'is_confidential' => true]);

        $this->assertFalse(Document::query()->visibleTo($outsider)->where('id', $document->id)->exists());

        $request = app(AccessRequestService::class)->request($outsider, $document, 'Please');

        $this->actingAs($this->user('document.view', 'document.share'))
            ->post(route('document-management.access-requests.grant', $request->uuid))
            ->assertRedirect();

        $this->assertTrue(Document::query()->visibleTo($outsider->fresh())->where('id', $document->id)->exists());
        $this->assertSame(DocumentAccessRequest::GRANTED, $request->fresh()->status);
        $this->assertDatabaseHas('document_grants', ['document_id' => $document->id, 'subject_id' => $outsider->id]);
    }

    public function test_declining_is_recorded_rather_than_silent(): void
    {
        $outsider = $this->user('document.view');
        $document = $this->document(['is_confidential' => true]);
        $request = app(AccessRequestService::class)->request($outsider, $document);

        $decider = $this->user('document.view', 'document.share');

        $this->actingAs($decider)
            ->post(route('document-management.access-requests.decline', $request->uuid), ['note' => 'Not needed for this role.'])
            ->assertRedirect();

        $request = $request->fresh();

        $this->assertSame(DocumentAccessRequest::DECLINED, $request->status);
        $this->assertSame($decider->id, $request->decided_by);
        $this->assertSame('Not needed for this role.', $request->decision_note);
        $this->assertFalse(Document::query()->visibleTo($outsider->fresh())->where('id', $document->id)->exists());
    }

    public function test_answering_needs_the_share_permission(): void
    {
        // Requesting needs nothing but being able to use the module; answering creates a grant.
        $outsider = $this->user('document.view');
        $request = app(AccessRequestService::class)->request($outsider, $this->document(['is_confidential' => true]));

        $this->actingAs($this->user('document.view'))
            ->post(route('document-management.access-requests.grant', $request->uuid))
            ->assertForbidden();

        $this->assertTrue($request->fresh()->isPending());
    }

    // ── Asking for several things at once ────────────────────────────────────

    public function test_somebody_can_ask_for_several_folders_in_one_go(): void
    {
        /*
         * Somebody who has just been handed a job needs Finance, Legal and two contracts. That is
         * one conversation, not four — and asking one at a time is how the fourth is forgotten.
         */
        $asker = $this->user('document.view');

        $this->actingAs($this->user('document.view', 'document.folder.manage'));
        $folders = [
            app(FolderService::class)->create(['name' => 'Finance ' . \Illuminate\Support\Str::random(4)]),
            app(FolderService::class)->create(['name' => 'Legal ' . \Illuminate\Support\Str::random(4)]),
        ];

        $this->actingAs($asker)
            ->post(route('document-management.access-requests.store-many'), [
                'folders' => array_map(fn (DocumentFolder $f) => $f->uuid, $folders),
                'reason' => 'Starting on the audit',
            ])
            ->assertRedirect();

        $this->assertSame(2, DocumentAccessRequest::where('requested_by', $asker->id)->count());
    }

    public function test_folders_and_documents_can_be_asked_for_together(): void
    {
        $asker = $this->user('document.view');

        $this->actingAs($this->user('document.view', 'document.folder.manage'));
        $folder = app(FolderService::class)->create(['name' => 'Finance ' . \Illuminate\Support\Str::random(4)]);

        $document = $this->document();

        $this->actingAs($asker)
            ->post(route('document-management.access-requests.store-many'), [
                'folders' => [$folder->uuid],
                'documents' => [$document->uuid],
            ])
            ->assertRedirect();

        $made = DocumentAccessRequest::where('requested_by', $asker->id)->get();

        $this->assertCount(2, $made);
        $this->assertSame(1, $made->whereNotNull('folder_id')->count());
        $this->assertSame(1, $made->whereNotNull('document_id')->count());
    }

    public function test_each_thing_asked_for_is_its_own_request(): void
    {
        /*
         * One row per thing, because each is granted or refused on its own merits — a request
         * covering four folders could only ever be answered all-or-nothing.
         */
        $asker = $this->user('document.view');

        $this->actingAs($this->user('document.view', 'document.folder.manage'));
        $folders = collect(range(1, 3))->map(
            fn ($i) => app(FolderService::class)->create(['name' => "Folder {$i} " . \Illuminate\Support\Str::random(4)]),
        );

        $this->actingAs($asker)
            ->post(route('document-management.access-requests.store-many'), [
                'folders' => $folders->pluck('uuid')->all(),
            ])
            ->assertRedirect();

        $this->assertSame(
            3,
            DocumentAccessRequest::where('requested_by', $asker->id)->distinct('folder_id')->count('folder_id'),
        );
    }

    public function test_asking_for_nothing_is_refused(): void
    {
        $this->actingAs($this->user('document.view'))
            ->post(route('document-management.access-requests.store-many'), [])
            ->assertSessionHas('error');
    }

    public function test_a_document_the_person_cannot_see_cannot_be_asked_for_in_bulk(): void
    {
        /*
         * The bulk form only offers what the person can already see, so a uuid arriving here from
         * anywhere else is not a pick — it is a probe, and answering it would confirm the document
         * exists.
         */
        $asker = $this->user('document.view');
        $hidden = $this->document(['is_confidential' => true, 'created_by' => 0]);

        $this->actingAs($asker)
            ->post(route('document-management.access-requests.store-many'), [
                'documents' => [$hidden->uuid],
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, DocumentAccessRequest::where('requested_by', $asker->id)->count());
    }

    public function test_asking_needs_no_more_than_being_able_to_use_the_module(): void
    {
        // Requiring the right to *answer* requests before being allowed to *make* one would shut
        // out exactly the people the feature exists for.
        $this->actingAs($this->user('document.view'))
            ->get(route('document-management.access-requests'))
            ->assertOk();
    }
}
