<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who may do what, and where.
 *
 * Two rules interact here and the order is the whole design: grants inherit downwards so nobody has
 * to repeat themselves at every level, and departmental folders are closed by default while
 * company folders are open. Getting either backwards is silent — a folder that quietly shows
 * everybody the disciplinary file, or one that shows nobody anything.
 */
class FolderAccessTest extends TestCase
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

        /*
         * Every test here used to call the service directly; the folder-admin ones go through the
         * routes, and a route in this module is behind the module-entitlement middleware. Without
         * the module row and the per-user access, the answer to everything is "not on your
         * subscription" rather than the authorisation question being asked.
         */
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function user(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'fa+' . Str::random(8) . '@example.test',
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
            ['access_level' => \App\Support\Hubs\ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function folder(string $name, array $extra = []): DocumentFolder
    {
        return app(FolderService::class)->create(array_merge(['name' => $name], $extra));
    }

    private function access(): FolderAccess
    {
        // Resolved fresh each time: the service caches per user per folder, which is right in a
        // request and wrong across two assertions about different grants.
        return new FolderAccess();
    }

    // ── Defaults ─────────────────────────────────────────────────────────────

    public function test_a_company_folder_is_open_to_anyone_the_module_already_admits(): void
    {
        $folder = $this->folder('Company Policies');

        // "Company-wide" has to mean something without setting up permissions first, or the
        // repository is unusable until somebody configures it.
        $this->assertTrue($this->access()->can($this->user('document.view'), $folder, 'view'));
    }

    public function test_a_department_folder_is_closed_to_everyone_else(): void
    {
        $folder = $this->folder('Employee Files', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT,
            'department_unit_id' => 4242,
        ]);

        // Being able to open the library is not the same as being in HR.
        $this->assertFalse($this->access()->can($this->user('document.view'), $folder, 'view'));
    }

    // ── Inheritance ──────────────────────────────────────────────────────────

    public function test_a_grant_on_a_parent_covers_the_folders_beneath_it(): void
    {
        $hr = $this->folder('Human Resources', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT, 'department_unit_id' => 4242,
        ]);
        $files = app(FolderService::class)->create(['name' => 'Employee Files', 'parent_id' => $hr->uuid]);

        $user = $this->user('document.view');
        Role::findOrCreate('HR Officer', 'web');
        $user->assignRole('HR Officer');

        DocumentFolderPermission::create([
            'folder_id' => $hr->id, 'subject_type' => 'role', 'subject_name' => 'HR Officer',
            'can_view' => true, 'can_upload' => true,
        ]);

        // Nobody expects to repeat the same grant at every level, and a subfolder created later
        // would otherwise be silently unreachable.
        $this->assertTrue($this->access()->can($user->fresh(), $files, 'view'));
        $this->assertTrue($this->access()->can($user->fresh(), $files, 'upload'));
    }

    public function test_the_nearest_folder_with_a_rule_decides(): void
    {
        $hr = $this->folder('Human Resources', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT, 'department_unit_id' => 4242,
        ]);
        $disciplinary = app(FolderService::class)->create(['name' => 'Disciplinary', 'parent_id' => $hr->uuid]);

        $user = $this->user('document.view');
        Role::findOrCreate('HR Officer', 'web');
        $user->assignRole('HR Officer');

        DocumentFolderPermission::create([
            'folder_id' => $hr->id, 'subject_type' => 'role', 'subject_name' => 'HR Officer',
            'can_view' => true,
        ]);

        // A subfolder can be closed off without touching its parent — which is what someone wants
        // when one part of a department's filing is more sensitive than the rest.
        DocumentFolderPermission::create([
            'folder_id' => $disciplinary->id, 'subject_type' => 'role', 'subject_name' => 'HR Officer',
            'can_view' => false,
        ]);

        $this->assertTrue($this->access()->can($user->fresh(), $hr, 'view'));
        $this->assertFalse($this->access()->can($user->fresh(), $disciplinary, 'view'));
    }

    public function test_naming_one_person_works_where_a_role_would_be_too_broad(): void
    {
        $folder = $this->folder('Board Papers', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT, 'department_unit_id' => 4242,
        ]);
        $user = $this->user('document.view');

        DocumentFolderPermission::create([
            'folder_id' => $folder->id, 'subject_type' => 'user', 'subject_id' => $user->id,
            'can_view' => true,
        ]);

        $this->assertTrue($this->access()->can($user, $folder, 'view'));
        $this->assertFalse($this->access()->can($this->user('document.view'), $folder, 'view'));
    }

    public function test_two_grants_that_both_apply_give_the_more_permissive_answer(): void
    {
        $folder = $this->folder('Shared', [
            'scope' => DocumentFolder::SCOPE_DEPARTMENT, 'department_unit_id' => 4242,
        ]);
        $user = $this->user('document.view');
        Role::findOrCreate('Reader', 'web');
        $user->assignRole('Reader');

        DocumentFolderPermission::create([
            'folder_id' => $folder->id, 'subject_type' => 'role', 'subject_name' => 'Reader',
            'can_view' => true, 'can_upload' => false,
        ]);
        DocumentFolderPermission::create([
            'folder_id' => $folder->id, 'subject_type' => 'user', 'subject_id' => $user->id,
            'can_view' => true, 'can_upload' => true,
        ]);

        // Two grants are two people saying yes. The alternative means adding somebody to a team can
        // take away access they already had.
        $this->assertTrue($this->access()->can($user->fresh(), $folder, 'upload'));
    }

    public function test_a_folder_grant_cannot_hand_out_an_ability_the_module_withheld(): void
    {
        $folder = $this->folder('Shared');
        $user = $this->user('document.view'); // no document.delete anywhere

        DocumentFolderPermission::create([
            'folder_id' => $folder->id, 'subject_type' => 'user', 'subject_id' => $user->id,
            'can_view' => true, 'can_delete' => true,
        ]);

        // The folder narrows what someone may do; it is not a second place to grant permissions.
        // The policy is still asked first, and it says no.
        $document = \App\Modules\Operations\DocumentManagement\Models\Document::forceCreate([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'folder_id' => $folder->id,
            'title' => 'Anything', 'status' => 'active', 'version_count' => 1,
        ]);

        $this->assertFalse(
            (new \App\Modules\Operations\DocumentManagement\Policies\DocumentPolicy())->delete($user, $document)
        );
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public function test_a_built_in_category_cannot_be_deleted_but_can_be_switched_off(): void
    {
        $category = DocumentCategory::create([
            'name' => 'Contract', 'slug' => 'contract-' . Str::random(4), 'is_system' => true,
        ]);

        // Reporting and retention rules name these; a deleted one turns those into silence rather
        // than an error.
        $this->assertFalse($category->isDeletable());

        $category->update(['is_active' => false]);
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_a_category_an_administrator_added_can_be_removed(): void
    {
        $category = DocumentCategory::create([
            'name' => 'Site Photo', 'slug' => 'site-photo-' . Str::random(4), 'is_system' => false,
        ]);

        $this->assertTrue($category->isDeletable());
    }

    public function test_only_active_categories_are_offered(): void
    {
        $suffix = Str::random(4);
        DocumentCategory::create(['name' => 'Live', 'slug' => 'live-' . $suffix, 'is_active' => true, 'sort_order' => 1]);
        DocumentCategory::create(['name' => 'Retired', 'slug' => 'retired-' . $suffix, 'is_active' => false]);

        $names = DocumentCategory::usable()->pluck('name')->all();

        $this->assertContains('Live', $names);
        $this->assertNotContains('Retired', $names);
    }

    // ── Administering one folder rather than all of them ─────────────────────

    public function test_a_folder_admin_may_decide_who_else_uses_their_folder(): void
    {
        /*
         * The point of the ability. Until now the Finance manager could either administer every
         * folder in the company or none, so nobody gave them the first — and every request about
         * Finance went to whoever holds the module permission and has no basis to judge it.
         */
        $manager = $this->user('document.view');
        $folder = $this->folder('Finance');

        DB::table('document_folder_permissions')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'folder_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $manager->id,
            'subject_name' => $manager->email,
            'can_view' => true, 'can_download' => true, 'can_upload' => false,
            'can_edit' => false, 'can_delete' => false, 'can_manage' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($manager->fresh())
            ->put(route('document-management.folders.permissions', $folder->uuid), [
                'grants' => [[
                    'subject_type' => 'user',
                    'subject_id' => $manager->id,
                    'subject_name' => $manager->email,
                    'can_view' => true,
                    'can_manage' => true,
                ]],
            ])
            ->assertRedirect();

        $this->assertSame(1, DB::table('document_folder_permissions')->where('folder_id', $folder->id)->count());
    }

    public function test_a_folder_admin_cannot_administer_a_different_folder(): void
    {
        // "And nothing beyond it" is the whole reason this is narrower than the module permission.
        $manager = $this->user('document.view');
        $theirs = $this->folder('Finance');
        $somebodyElses = $this->folder('Human Resources');

        DB::table('document_folder_permissions')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->companyId,
            'folder_id' => $theirs->id,
            'subject_type' => 'user',
            'subject_id' => $manager->id,
            'subject_name' => $manager->email,
            'can_view' => true, 'can_download' => true, 'can_upload' => false,
            'can_edit' => false, 'can_delete' => false, 'can_manage' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($manager->fresh())
            ->put(route('document-management.folders.permissions', $somebodyElses->uuid), ['grants' => []])
            ->assertForbidden();
    }

    public function test_being_able_to_read_a_folder_does_not_make_somebody_its_administrator(): void
    {
        /*
         * A company folder is readable by everybody the module lets in. If that default extended to
         * administering it, every reader would be an administrator of it — which is the opposite of
         * what this is for.
         */
        $reader = $this->user('document.view');
        $folder = $this->folder('Company handbook');

        $access = app(FolderAccess::class);

        $this->assertTrue($access->can($reader, $folder, 'view'), 'A company folder is open to read.');
        $this->assertFalse($access->can($reader, $folder, 'manage'), 'Reading is not administering.');
    }

    public function test_whoever_administers_folders_for_the_company_administers_this_one(): void
    {
        // Granting folder admins must not take the ability away from the people who already had it.
        $admin = $this->user('document.view', 'document.folder.manage');
        $folder = $this->folder('Finance');

        $this->assertTrue(app(FolderAccess::class)->can($admin->fresh(), $folder, 'manage'));
    }

    public function test_a_folder_can_be_given_to_a_group_from_the_folder_itself(): void
    {
        /*
         * It could only be done from the group's own screen before, which meant somebody looking at
         * a folder had no way to say "the auditors" and reached for a list of names instead.
         */
        $admin = $this->user('document.view', 'document.folder.manage');
        $folder = $this->folder('Finance');

        $group = app(\App\Modules\Operations\DocumentManagement\Services\GroupService::class)
            ->create('Auditors ' . Str::random(4));

        $this->actingAs($admin->fresh())
            ->put(route('document-management.folders.permissions', $folder->uuid), [
                'grants' => [[
                    'subject_type' => 'group',
                    'subject_id' => $group->id,
                    'subject_name' => $group->name,
                    'can_view' => true,
                ]],
            ])
            ->assertRedirect();

        // Surfaced deliberately: attempt() turns a RuntimeException into a flashed message, which
        // assertSessionHasNoErrors does not look at.
        $this->assertNull(session('error'), 'The save reported: ' . (string) session('error'));

        $this->assertSame(
            'group',
            DB::table('document_folder_permissions')->where('folder_id', $folder->id)->value('subject_type'),
        );
    }
}
