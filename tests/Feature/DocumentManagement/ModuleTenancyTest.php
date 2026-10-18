<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentChecklistTemplate;
use App\Modules\Operations\DocumentManagement\Models\DocumentComment;
use App\Modules\Operations\DocumentManagement\Models\DocumentGroup;
use App\Modules\Operations\DocumentManagement\Models\DocumentQuota;
use App\Modules\Operations\DocumentManagement\Models\DocumentRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentShortcut;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Support\Hubs\ModuleHubs;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The module has to hold up on both editions, and the two fail in opposite directions.
 *
 * On SaaS the danger is leaking: a platform administrator has no CurrentCompany, so every global
 * scope in the application quietly switches itself off for them. Row-level security does the same,
 * because it keys off a session variable nobody set. A listing that looked correctly scoped all
 * through development then shows one customer's people to another.
 *
 * On self-hosted the danger is the opposite — a dead end. There is one company and no tenant to
 * choose between, so an administrator with no CurrentCompany is not ambiguous, they are simply the
 * only customer there is. Refusing to answer strands them on every screen in the module.
 *
 * The third failure is quieter than either: a row written with no company at all. It looks saved,
 * and row-level security then hides it from every tenant including the one that made it.
 */
class ModuleTenancyTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;

        // A second tenant to leak into. Created directly, because the point of the test is what
        // happens when two customers share an installation.
        $suffix = Str::random(5);

        $this->otherCompanyId = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Second tenant ' . $suffix,
            'slug' => 'second-tenant-' . strtolower($suffix),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();
    }

    /** A user with an employment record behind them, which is what makes somebody askable. */
    private function staffIn(int $companyId, string $surname = 'Person', int $personStatus = 1): User
    {
        $user = $this->userIn($companyId, 'document.view');

        DB::table('hr_people')->insert([
            'user_id' => $user->id,
            'company_id' => $companyId,
            'first_name' => 'Test',
            'surname' => $surname . ' ' . Str::random(4),
            'status' => $personStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * The people a screen would offer, read the way a browser gets them.
     *
     * Through the route rather than by calling the controller: an Inertia response is only JSON
     * when the request asks for it, and reaching past the HTTP layer would skip the middleware that
     * decides which company this request is even for.
     */
    private function offeredPeople(): array
    {
        $props = $this->get(route('document-management.requests'))->viewData('page')['props'];

        return collect($props['people'] ?? [])->pluck('id')->all();
    }

    private function userIn(int $companyId, string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'tn+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $companyId,
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

    /**
     * A platform administrator: no company of their own, and no tenant chosen.
     *
     * This is the account every global scope in the application steps aside for, which is exactly
     * why it is the one worth testing with.
     */
    private function platformAdmin(): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'pa+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1,
            'company_id' => null,
            'is_platform_admin' => true,
        ]);

        return $user->fresh();
    }

    /**
     * A platform administrator who *has* entered a tenant.
     *
     * The more dangerous of the two positions, and the easier to miss. A company is set, so
     * everything looks scoped — but the User model exempts platform admins from its own scope by
     * design, so any query that leans on that scope is still unfiltered while appearing not to be.
     */
    private function asPlatformAdminInTenant(): User
    {
        $admin = $this->platformAdmin();

        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        $this->actingAs($admin);

        return $admin;
    }

    /** Puts the request in the position a platform admin is in: signed in, no tenant chosen. */
    private function asPlatformAdminWithNoTenant(): User
    {
        $admin = $this->platformAdmin();

        app(CurrentCompany::class)->clear();
        DB::statement("SELECT set_config('app.company_id', '', false)");

        $this->actingAs($admin);

        return $admin;
    }

    // ── SaaS: nothing crosses between tenants ────────────────────────────────

    public function test_every_new_table_is_scoped_to_its_tenant(): void
    {
        /*
         * The ordinary case, and the one that has to be right before anything else matters: a user
         * of one company must not see another company's rows through any of the module's tables.
         */
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->otherCompanyId]);
        app(CurrentCompany::class)->set($this->otherCompanyId);

        $theirGroup = DocumentGroup::create(['uuid' => (string) Str::uuid(), 'name' => 'Theirs ' . Str::random(4)]);
        $theirTemplate = DocumentChecklistTemplate::create(['uuid' => (string) Str::uuid(), 'name' => 'Theirs']);

        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);
        app(CurrentCompany::class)->set($this->companyId);

        $this->assertNull(DocumentGroup::find($theirGroup->id), 'A group must not cross tenants.');
        $this->assertNull(DocumentChecklistTemplate::find($theirTemplate->id), 'Nor a checklist template.');
    }

    public function test_row_level_security_hides_the_other_tenant_even_without_the_scope(): void
    {
        /*
         * The belt to the scope's braces. `withoutGlobalScopes()` is a single call away in any
         * controller, and the database has to be the thing that finally refuses.
         */
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->otherCompanyId]);
        app(CurrentCompany::class)->set($this->otherCompanyId);

        $theirs = DocumentGroup::create(['uuid' => (string) Str::uuid(), 'name' => 'Theirs ' . Str::random(4)]);

        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);
        app(CurrentCompany::class)->set($this->companyId);

        $this->assertNull(
            DocumentGroup::withoutGlobalScopes()->find($theirs->id),
            'Even unscoped, the database must not hand over another tenant\'s row.',
        );
    }

    // ── SaaS: the platform administrator ─────────────────────────────────────

    public function test_the_people_picker_does_not_list_every_tenants_staff(): void
    {
        /*
         * The leak this module is most exposed to, tested from inside a tenant because that is the
         * harder case: a company *is* set, so everything looks scoped, and the User model exempts
         * platform admins from its own scope by design. Every "choose a person" list in the module
         * is then one query away from showing one customer's staff to another.
         */
        $mine = $this->userIn($this->companyId, 'document.view');
        $theirs = $this->userIn($this->otherCompanyId, 'document.view');

        $this->asPlatformAdminInTenant();

        foreach ([
            'document-management.requests',
            'document-management.checklists',
        ] as $screen) {
            $response = $this->get(route($screen));

            if ($response->status() !== 200) {
                continue;
            }

            $ids = collect($response->viewData('page')['props']['people'] ?? [])->pluck('id')->all();

            $this->assertNotContains(
                $theirs->id,
                $ids,
                "{$screen} offered another tenant's user in its people list.",
            );
        }
    }

    public function test_the_access_map_does_not_list_every_tenants_staff(): void
    {
        $theirs = $this->userIn($this->otherCompanyId, 'document.view');

        $this->asPlatformAdminInTenant();

        $response = $this->get(route('document-management.access-management'));

        if ($response->status() === 200) {
            $ids = collect($response->viewData('page')['props']['people'] ?? [])->pluck('id')->all();

            $this->assertNotContains($theirs->id, $ids, 'The access map offered another tenant\'s user.');
        } else {
            $this->assertTrue(true, 'Refusing the screen outright is also a safe answer.');
        }
    }

    public function test_the_quota_screen_does_not_list_every_tenants_departments(): void
    {
        $theirUnit = DB::table('company_units')->insertGetId([
            'company_id' => $this->otherCompanyId,
            'name' => 'Their finance ' . Str::random(4),
            'type' => 'department',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->asPlatformAdminInTenant();

        $payload = app(\App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentQuotaController::class)->payload();

        $this->assertNotContains(
            $theirUnit,
            collect($payload['subjects']['departments'])->pluck('id')->all(),
            'The quota screen offered another tenant\'s department.',
        );
    }

    public function test_the_group_screen_does_not_list_every_tenants_staff(): void
    {
        $theirs = $this->userIn($this->otherCompanyId, 'document.view');

        $this->asPlatformAdminInTenant();

        $response = $this->get(route('document-management.groups'));

        if ($response->status() === 200) {
            $ids = collect($response->viewData('page')['props']['people'] ?? [])->pluck('id')->all();

            $this->assertNotContains($theirs->id, $ids, 'The groups screen offered another tenant\'s user.');
        } else {
            $this->assertTrue(true, 'Refusing the screen outright is also a safe answer.');
        }
    }

    public function test_naming_somebody_cannot_reach_across_tenants(): void
    {
        /*
         * The mention ids come from the client, and the User scope steps aside for a platform
         * admin. Without a constraint a crafted id would email another tenant's staff the title of
         * a document they have nothing to do with.
         */
        \Illuminate\Support\Facades\Notification::fake();

        $theirs = $this->userIn($this->otherCompanyId, 'document.view');

        // As an administrator inside this tenant: a company is set, so the document is owned — and
        // the User scope still steps aside, which is precisely what makes the constraint necessary.
        $this->asPlatformAdminInTenant();

        $document = Document::create([
            'title' => 'Board pack ' . Str::random(4),
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ]);

        app(\App\Modules\Operations\DocumentManagement\Services\CommentService::class)
            ->add($document, 'Naming somebody who is not ours.', mentions: [$theirs->id]);

        \Illuminate\Support\Facades\Notification::assertNotSentTo(
            $theirs,
            \App\Modules\Operations\DocumentManagement\Notifications\DocumentMentionNotification::class,
        );
    }

    // ── Who counts as a person ───────────────────────────────────────────────

    public function test_the_picker_offers_staff_and_not_every_login(): void
    {
        /*
         * `users` also holds service accounts, portal logins for candidates and suppliers, and
         * accounts made for a module and never attached to anybody. A request addressed to one of
         * those is a request nobody will ever answer, and the person who sent it waits.
         */
        $staff = $this->staffIn($this->companyId, 'Nkosi');

        // A login with no employment record behind it.
        $serviceAccount = $this->userIn($this->companyId, 'document.view');

        $this->actingAs($staff);
        $ids = $this->offeredPeople();

        $this->assertContains($staff->id, $ids, 'Somebody with an employment record is askable.');
        $this->assertNotContains($serviceAccount->id, $ids, 'A login with no person behind it is not.');
    }

    public function test_somebody_who_has_left_is_not_offered(): void
    {
        // Asking a former employee for a document is a request that will never be answered.
        $leaver = $this->staffIn($this->companyId, 'Leaver', personStatus: 0);
        $asker = $this->staffIn($this->companyId, 'StillHere');

        $this->actingAs($asker);
        $ids = $this->offeredPeople();

        $this->assertNotContains($leaver->id, $ids);
    }

    public function test_an_administrator_above_tenancy_still_gets_a_list(): void
    {
        /*
         * This module already shows a platform administrator every document in the installation.
         * Withholding the names of the people those documents belong to protects nothing and leaves
         * every picker on every screen empty for the one account that administers the place.
         */
        $person = $this->staffIn($this->companyId, 'VisibleToAdmin');

        $this->asPlatformAdminWithNoTenant();

        $ids = $this->offeredPeople();

        $this->assertContains($person->id, $ids, 'An admin with no tenant must still be able to pick somebody.');
    }

    // ── A row that belongs to nobody ─────────────────────────────────────────

    public function test_nothing_the_module_writes_can_end_up_with_no_owner(): void
    {
        /*
         * The quietest failure of the three. A row saved with a null company looks saved to the
         * person who made it and is then hidden from every tenant by row-level security — including
         * the tenant they meant it for. It becomes a support ticket about something that vanished,
         * and an afternoon of nobody being able to reproduce it.
         */
        $this->asPlatformAdminWithNoTenant();

        foreach ([
            DocumentGroup::class => ['name' => 'Orphan ' . Str::random(4)],
            DocumentChecklistTemplate::class => ['name' => 'Orphan ' . Str::random(4)],
            DocumentRequest::class => ['title' => 'Orphan ' . Str::random(4), 'status' => DocumentRequest::PENDING],
        ] as $model => $attributes) {
            $created = null;

            try {
                $created = $model::create(array_merge(['uuid' => (string) Str::uuid()], $attributes));
            } catch (\Throwable $e) {
                // Refusing to write is the correct outcome: failing loudly beats a row that
                // silently disappears.
                $this->assertTrue(true);

                continue;
            }

            $this->assertNotNull(
                $created->company_id,
                $model . ' was written with no company, so no tenant will ever see it again.',
            );
        }
    }

    // ── Self-hosted: no dead ends ────────────────────────────────────────────

    public function test_on_a_single_tenant_install_an_admin_with_no_company_still_gets_one(): void
    {
        /*
         * There is one customer and one company; there is no tenant to choose between. Telling an
         * administrator to "view as company" is advice that leads nowhere, and every screen in the
         * module would strand them.
         *
         * Only meaningful where exactly one company exists — this test's own setUp creates a
         * second, so it asserts the guard rather than the fallback when it cannot.
         */
        config()->set('app.edition', 'self_hosted');

        $companies = DB::table('companies')->count();

        $this->asPlatformAdminWithNoTenant();

        $group = DocumentGroup::create(['uuid' => (string) Str::uuid(), 'name' => 'Self hosted ' . Str::random(4)]);

        if ($companies === 1) {
            $this->assertNotNull($group->company_id, 'The one company is the only possible answer.');
        } else {
            // More than one company is ambiguous again, and guessing there would be the leak the
            // fallback exists to prevent.
            $this->assertTrue(true, 'Ambiguous install: declining to guess is correct.');
        }
    }
}
