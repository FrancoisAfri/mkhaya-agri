<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use App\Modules\Operations\DocumentManagement\Models\DocumentGroup;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Services\GroupService;
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
 * Groups, folders given several at a time, and access that ends by itself.
 *
 * The thread through all of it: access nobody has to remember to revoke. An access somebody must
 * remember is one that outlives its reason — the auditor who still has the Finance folder two
 * years later got it that way.
 */
class GroupsAndTemporaryAccessTest extends TestCase
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

        $this->actingAs($this->user('document.view', 'document.folder.manage', 'document.share'));
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'grp+' . Str::random(8) . '@example.test',
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

    private function service(): GroupService
    {
        return app(GroupService::class);
    }

    /**
     * A folder that is closed unless something opens it.
     *
     * Department-scoped rather than merely confidential: a company-scope folder is readable by
     * anybody the module already lets in — that is what company-wide means — so a grant on one
     * decides nothing and a test built on it would pass whatever the grant did.
     */
    private function closedFolder(string $name): \App\Modules\Operations\DocumentManagement\Models\DocumentFolder
    {
        return app(FolderService::class)->create([
            'name' => $name . ' ' . Str::random(4),
            'scope' => \App\Modules\Operations\DocumentManagement\Models\DocumentFolder::SCOPE_DEPARTMENT,
            'department_unit_id' => 999_999,
            'is_confidential' => true,
        ]);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    // ── §7 Groups ────────────────────────────────────────────────────────────

    public function test_a_group_gives_its_members_what_it_was_granted(): void
    {
        $auditor = $this->user('document.view');

        $group = $this->service()->create('External auditors');
        $this->service()->addMembers($group, [$auditor->id]);

        $document = $this->document(['title' => 'Audit file', 'is_confidential' => true]);

        app(InternalShareService::class)->grant($document, DocumentGrant::SUBJECT_GROUP, $group->id, $group->name);

        $this->assertTrue(
            Document::query()->visibleTo($auditor->fresh())->where('id', $document->id)->exists(),
        );
    }

    public function test_somebody_outside_the_group_gets_nothing_from_it(): void
    {
        $inside = $this->user('document.view');
        $outside = $this->user('document.view');

        $group = $this->service()->create('Finance ' . Str::random(4));
        $this->service()->addMembers($group, [$inside->id]);

        $document = $this->document(['is_confidential' => true]);
        app(InternalShareService::class)->grant($document, DocumentGrant::SUBJECT_GROUP, $group->id, $group->name);

        $this->assertTrue(Document::query()->visibleTo($inside->fresh())->where('id', $document->id)->exists());
        $this->assertFalse(Document::query()->visibleTo($outside->fresh())->where('id', $document->id)->exists());
    }

    public function test_removing_somebody_from_a_group_removes_what_it_gave_them(): void
    {
        $person = $this->user('document.view');

        $group = $this->service()->create('Temporary team ' . Str::random(4));
        $this->service()->addMembers($group, [$person->id]);

        $document = $this->document(['is_confidential' => true]);
        app(InternalShareService::class)->grant($document, DocumentGrant::SUBJECT_GROUP, $group->id, $group->name);

        $this->assertTrue(Document::query()->visibleTo($person->fresh())->where('id', $document->id)->exists());

        $this->service()->removeMember($group, $person->id);

        $this->assertFalse(Document::query()->visibleTo($person->fresh())->where('id', $document->id)->exists());
    }

    // ── §8 Several folders at once ───────────────────────────────────────────

    public function test_a_group_can_be_given_several_folders_in_one_act(): void
    {
        /*
         * The reason this is one action: "give the auditors these four folders until the end of
         * September" is one decision, and making somebody express it as four separate edits is how
         * three get set correctly and the fourth is forgotten.
         */
        $auditor = $this->user('document.view');

        $group = $this->service()->create('Auditors ' . Str::random(4));
        $this->service()->addMembers($group, [$auditor->id]);

        $folders = collect(['Finance', 'Invoices', 'Bank statements', 'Policies'])
            ->map(fn (string $name) => $this->closedFolder($name));

        $count = $this->service()->grantFolders(
            $group,
            $folders->pluck('uuid')->all(),
            ['view' => true],
        );

        $this->assertSame(4, $count);

        $access = app(FolderAccess::class);

        foreach ($folders as $folder) {
            $this->assertTrue(
                $access->can($auditor->fresh(), $folder, 'view'),
                "The group should reach {$folder->name}.",
            );
        }
    }

    public function test_the_abilities_given_are_the_ones_asked_for_and_no_more(): void
    {
        $auditor = $this->user('document.view');
        $group = $this->service()->create('View only ' . Str::random(4));
        $this->service()->addMembers($group, [$auditor->id]);

        $folder = $this->closedFolder('Finance');

        $this->service()->grantFolders($group, [$folder->uuid], ['view' => true]);

        $access = app(FolderAccess::class);

        $this->assertTrue($access->can($auditor->fresh(), $folder, 'view'));
        $this->assertFalse($access->can($auditor->fresh(), $folder, 'edit'));
        $this->assertFalse($access->can($auditor->fresh(), $folder, 'delete'));
    }

    // ── §9 and §10 Access that ends by itself ────────────────────────────────

    public function test_folder_access_with_a_date_stops_when_the_date_passes(): void
    {
        $auditor = $this->user('document.view');
        $group = $this->service()->create('September audit ' . Str::random(4));
        $this->service()->addMembers($group, [$auditor->id]);

        $folder = $this->closedFolder('Audit');

        $this->service()->grantFolders($group, [$folder->uuid], ['view' => true], now()->addDays(30)->toDateString());

        $access = app(FolderAccess::class);
        $this->assertTrue($access->can($auditor->fresh(), $folder, 'view'));

        DocumentFolderPermission::where('subject_id', $group->id)->update(['expires_at' => now()->subDay()]);

        // A fresh resolver, because the end of the access is the passing of time and the next check
        // is the next request. Asking the same instance would be asking a cache built before the
        // date passed.
        $this->assertFalse(
            app(FolderAccess::class)->can($auditor->fresh(), $folder, 'view'),
            'Access with an end date has to actually end.',
        );
    }

    public function test_a_membership_can_end_without_the_group_ending(): void
    {
        // A secondee, or somebody covering leave. The group carries on; their part of it does not.
        $secondee = $this->user('document.view');
        $group = $this->service()->create('Project team ' . Str::random(4));
        $this->service()->addMembers($group, [$secondee->id], now()->addDays(7)->toDateString());

        $document = $this->document(['is_confidential' => true]);
        app(InternalShareService::class)->grant($document, DocumentGrant::SUBJECT_GROUP, $group->id, $group->name);

        $this->assertTrue(Document::query()->visibleTo($secondee->fresh())->where('id', $document->id)->exists());

        DB::table('document_group_members')->where('user_id', $secondee->id)->update(['expires_at' => now()->subDay()]);

        $this->assertFalse(Document::query()->visibleTo($secondee->fresh())->where('id', $document->id)->exists());
    }

    public function test_when_the_group_ends_everything_it_gave_ends_with_it(): void
    {
        /*
         * The engagement finishing should not require revoking four folders one at a time — that is
         * exactly the housekeeping nobody does, and how access outlives its reason.
         */
        $auditor = $this->user('document.view');
        $group = $this->service()->create('Engagement ' . Str::random(4), ['expires_at' => now()->addDays(30)->toDateString()]);
        $this->service()->addMembers($group, [$auditor->id]);

        $folder = $this->closedFolder('Audit');
        $this->service()->grantFolders($group, [$folder->uuid], ['view' => true]);

        $document = $this->document(['is_confidential' => true]);
        app(InternalShareService::class)->grant($document, DocumentGrant::SUBJECT_GROUP, $group->id, $group->name);

        $access = app(FolderAccess::class);
        $this->assertTrue($access->can($auditor->fresh(), $folder, 'view'));
        $this->assertTrue(Document::query()->visibleTo($auditor->fresh())->where('id', $document->id)->exists());

        DocumentGroup::whereKey($group->id)->update(['expires_at' => now()->subDay()]);

        $this->assertFalse(
            app(FolderAccess::class)->can($auditor->fresh(), $folder, 'view'),
            'The folder access should end with the group.',
        );
        $this->assertFalse(
            Document::query()->visibleTo($auditor->fresh())->where('id', $document->id)->exists(),
            'The document grant should end with the group too.',
        );
    }

    public function test_a_grant_takes_effect_in_the_request_that_made_it(): void
    {
        /*
         * The permissions screen saves and then re-renders the tree in the same request. Resolving
         * a folder is cached — it walks every ancestor — so without invalidation the administrator
         * is shown the state from before their own save, which reads as the save having failed.
         *
         * Deliberately the same resolver instance throughout: a fresh one would pass whether or not
         * the invalidation exists, and prove nothing.
         */
        $auditor = $this->user('document.view');
        $group = $this->service()->create('Late arrivals ' . Str::random(4));
        $this->service()->addMembers($group, [$auditor->id]);

        $folder = $this->closedFolder('Finance');

        $access = app(FolderAccess::class);
        $this->assertFalse($access->can($auditor->fresh(), $folder, 'view'), 'Nothing has been granted yet.');

        $this->service()->grantFolders($group, [$folder->uuid], ['view' => true]);

        $this->assertTrue(
            $access->can($auditor->fresh(), $folder, 'view'),
            'A grant made in this request has to be visible in it.',
        );
    }

    // ── §32 Two groups, one person ───────────────────────────────────────────

    public function test_somebody_in_several_groups_gets_the_most_permissive_of_them(): void
    {
        /*
         * §32's rule, and the one people expect: a person does not lose an ability by being added
         * to another group. Denial is expressed by not granting, because a deny that overrode a
         * grant would make effective permissions impossible to predict by looking at them.
         */
        $person = $this->user('document.view');

        $viewers = $this->service()->create('Viewers ' . Str::random(4));
        $editors = $this->service()->create('Editors ' . Str::random(4));

        $this->service()->addMembers($viewers, [$person->id]);
        $this->service()->addMembers($editors, [$person->id]);

        $folder = $this->closedFolder('Shared');

        $this->service()->grantFolders($viewers, [$folder->uuid], ['view' => true]);
        $this->service()->grantFolders($editors, [$folder->uuid], ['view' => true, 'edit' => true]);

        $access = app(FolderAccess::class);

        $this->assertTrue($access->can($person->fresh(), $folder, 'view'));
        $this->assertTrue($access->can($person->fresh(), $folder, 'edit'), 'The more permissive group wins.');
    }

    // ── Through the routes ───────────────────────────────────────────────────

    public function test_the_groups_page_loads_and_managing_them_needs_folder_management(): void
    {
        $this->get(route('document-management.groups'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Modules/DocumentManagement/Pages/Groups'));

        $this->actingAs($this->user('document.view'));

        $this->get(route('document-management.groups'))->assertForbidden();
    }

    public function test_deleting_a_group_takes_its_folder_access_with_it(): void
    {
        // A group deleted while its grants survived would leave access nobody can see the source
        // of, which is the worst kind to have.
        $group = $this->service()->create('Doomed ' . Str::random(4));
        $folder = $this->closedFolder('Finance');

        $this->service()->grantFolders($group, [$folder->uuid], ['view' => true]);

        $this->assertSame(1, DocumentFolderPermission::where('subject_id', $group->id)
            ->where('subject_type', DocumentFolderPermission::SUBJECT_GROUP)->count());

        $this->delete(route('document-management.groups.destroy', $group->uuid))->assertRedirect();

        $this->assertSame(0, DocumentFolderPermission::where('subject_id', $group->id)
            ->where('subject_type', DocumentFolderPermission::SUBJECT_GROUP)->count());
    }
}
