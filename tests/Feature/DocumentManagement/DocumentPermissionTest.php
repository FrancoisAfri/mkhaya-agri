<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Support\Hubs\ModuleHubs;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Policies\DocumentPolicy;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Who may do what to a document.
 *
 * The module seeded fourteen permissions and checked none of them, so module entitlement — a
 * billing fact — was the only thing between a user and the purge route. These tests are written
 * against the policy directly rather than through HTTP, because the interesting cases are
 * combinations of permission and document state, and there are more of them than routes.
 */
class DocumentPermissionTest extends TestCase
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

        // Entitle the company to the module, so the routes below are refused by the policy and not
        // by the entitlement middleware — otherwise these tests would pass for the wrong reason.
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::flush();
    }

    /** A user holding exactly the named permissions and nothing else. */
    private function userWith(string ...$permissions): User
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'perm+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user->fresh();
    }

    /** Gives a user an access level on the module, which the hub tier gate reads. */
    private function grantTier(User $user, int $level): void
    {
        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');

        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => $level],
        );
    }

    private function document(array $attributes = []): Document
    {
        return Document::forceCreate(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'title' => 'Board pack', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    private function policy(): DocumentPolicy
    {
        return new DocumentPolicy();
    }

    // ── The gap this closes ──────────────────────────────────────────────────

    public function test_module_access_alone_does_not_allow_permanent_destruction(): void
    {
        // The state the module shipped in: entitled to the module, holding no permission at all.
        $user = $this->userWith('document.view');

        $this->assertFalse($this->policy()->purge($user, $this->document()));
        $this->assertFalse($this->policy()->delete($user, $this->document()));
    }

    public function test_deleting_and_purging_are_separate_permissions(): void
    {
        $user = $this->userWith('document.delete');

        // Recoverable removal and permanent destruction are different decisions, and a role
        // trusted with the first is not automatically trusted with the second.
        $this->assertTrue($this->policy()->delete($user, $this->document()));
        $this->assertFalse($this->policy()->purge($user, $this->document()));
    }

    public function test_reading_on_screen_and_taking_a_copy_are_separate_permissions(): void
    {
        $viewer = $this->userWith('document.view');
        $document = $this->document();

        $this->assertTrue($this->policy()->view($viewer, $document));
        $this->assertFalse($this->policy()->download($viewer, $document));
    }

    // ── Legal hold ───────────────────────────────────────────────────────────

    public function test_legal_hold_survives_every_permission(): void
    {
        // Holding every destructive permission there is.
        $user = $this->userWith('document.delete', 'document.purge', 'document.legal_hold');
        $held = $this->document(['is_legally_sensitive' => true]);

        // The point of the flag. A permission check alone would let an administrator destroy the
        // one document the hold exists to preserve.
        $this->assertFalse($this->policy()->delete($user, $held));
        $this->assertFalse($this->policy()->purge($user, $held));
    }

    public function test_a_document_under_hold_is_invisible_without_the_legal_hold_permission(): void
    {
        $ordinary = $this->userWith('document.view', 'document.download');
        $held = $this->document(['is_legally_sensitive' => true]);

        $this->assertFalse($this->policy()->view($ordinary, $held));
        $this->assertFalse($this->policy()->download($ordinary, $held));

        $cleared = $this->userWith('document.view', 'document.legal_hold');
        $this->assertTrue($this->policy()->view($cleared, $held));
    }

    public function test_a_document_under_hold_has_no_author_exemption(): void
    {
        $author = $this->userWith('document.view');
        $held = $this->document(['is_legally_sensitive' => true, 'created_by' => $author->id]);

        // Unlike confidentiality: a document is often under hold precisely because of the person
        // who created it.
        $this->assertFalse($this->policy()->view($author, $held));
    }

    // ── Confidentiality ──────────────────────────────────────────────────────

    public function test_view_permission_does_not_open_a_confidential_document(): void
    {
        $user = $this->userWith('document.view', 'document.download');
        $confidential = $this->document(['is_confidential' => true]);

        // `document.view` is a statement about someone's job; confidentiality is a statement about
        // the document. Holding the first is not an answer to the second.
        $this->assertFalse($this->policy()->view($user, $confidential));
        $this->assertFalse($this->policy()->download($user, $confidential));
    }

    public function test_the_person_who_uploaded_it_can_still_open_it(): void
    {
        $author = $this->userWith('document.view');
        $confidential = $this->document(['is_confidential' => true, 'created_by' => $author->id]);

        // Otherwise someone locks themselves out of their own file the moment they mark it.
        $this->assertTrue($this->policy()->view($author, $confidential));
    }

    public function test_confidential_clearance_opens_it(): void
    {
        $cleared = $this->userWith('document.view', 'document.confidential.manage');

        $this->assertTrue($this->policy()->view($cleared, $this->document(['is_confidential' => true])));
    }

    public function test_reclassifying_a_sensitive_document_needs_more_than_edit_rights(): void
    {
        $editor = $this->userWith('document.update');
        $confidential = $this->document(['is_confidential' => true]);

        // Otherwise the protection is removable by anyone who wants what it protects.
        $this->assertFalse($this->policy()->setSensitivity($editor, $confidential));
        $this->assertTrue($this->policy()->setSensitivity($editor, $this->document()));

        $cleared = $this->userWith('document.confidential.manage');
        $this->assertTrue($this->policy()->setSensitivity($cleared, $confidential));
    }

    // ── Platform administrators ──────────────────────────────────────────────

    public function test_a_platform_administrator_is_answered_before_the_per_document_rules(): void
    {
        $admin = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'padmin+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
        ]);

        // They have no company, so the tenant-scoped reasoning below would have nothing to reason
        // about.
        $this->assertTrue($this->policy()->before($admin, 'view'));
        $this->assertNull($this->policy()->before($this->userWith('document.view'), 'view'));
    }

    // ── Through the routes, not just the policy ──────────────────────────────

    public function test_the_purge_route_refuses_a_user_without_the_permission(): void
    {
        $document = $this->document();

        // The exact gap this closes: before, module entitlement was the only check, so this
        // request permanently destroyed the document and returned a redirect.
        $this->actingAs($this->userWith('document.view', 'document.delete'))
            ->delete(route('document-management.documents.purge', $document))
            ->assertForbidden();

        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_the_download_route_refuses_a_confidential_document(): void
    {
        $document = $this->document(['is_confidential' => true]);

        $this->actingAs($this->userWith('document.view', 'document.download'))
            ->get(route('document-management.documents.download', $document))
            ->assertForbidden();
    }

    public function test_the_settings_route_needs_its_own_permission(): void
    {
        /*
         * The hub tier is granted deliberately. Settings sits in the Configure hub, so without it
         * the request is turned away by the tier gate and this test passes without the permission
         * ever being consulted — which is what it did before, refused by a third gate entirely.
         * Granting the tier is what makes the assertion below about document.settings.manage.
         */
        $user = $this->userWith('document.view');
        $this->grantTier($user, ModuleHubs::SUPERUSER);

        $this->actingAs($user)
            ->get(route('document-management.settings'))
            ->assertForbidden();
    }

    public function test_a_user_with_the_permission_and_the_tier_reaches_settings(): void
    {
        /*
         * The other half, and the one that would have caught a module-wide lockout: every route in
         * this module was gated by a permission derived from its route name
         * ('document-management.view'), which exists nowhere in the catalogue and which no role can
         * hold. Every screen returned 403 to everybody except platform admins, and no test noticed
         * because the ones that went through routes were all asserting a refusal.
         */
        $user = $this->userWith('document.view', 'document.settings.manage');
        $this->grantTier($user, ModuleHubs::SUPERUSER);

        $this->actingAs($user)
            ->get(route('document-management.settings'))
            ->assertOk();
    }

    public function test_the_library_is_reachable_by_somebody_who_may_only_read(): void
    {
        // The plainest version of the same check: the front door of the module opens for a user
        // holding nothing but document.view.
        $this->actingAs($this->userWith('document.view'))
            ->get(route('document-management.index'))
            ->assertOk();
    }

    // ── The listing, which is a separate disclosure from the document ────────

    public function test_a_confidential_document_is_not_listed_without_clearance(): void
    {
        $confidential = $this->document(['is_confidential' => true, 'title' => 'Disciplinary hearing']);
        $ordinary = $this->document(['title' => 'Staff handbook']);
        $user = $this->userWith('document.view');

        $visible = Document::visibleTo($user)->pluck('id');

        // The title alone discloses what the flag was set to hide, and the search box matches on
        // description and notes — so a listing is also an oracle for the contents.
        $this->assertFalse($visible->contains($confidential->id));
        $this->assertTrue($visible->contains($ordinary->id));
    }

    public function test_a_document_under_legal_hold_is_not_listed_without_clearance(): void
    {
        $held = $this->document(['is_legally_sensitive' => true]);

        $this->assertFalse(Document::visibleTo($this->userWith('document.view'))->pluck('id')->contains($held->id));
        $this->assertTrue(Document::visibleTo($this->userWith('document.view', 'document.legal_hold'))
            ->pluck('id')->contains($held->id));
    }

    public function test_a_document_in_a_confidential_folder_is_hidden_even_when_not_itself_flagged(): void
    {
        $folder = DocumentFolder::forceCreate([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'HR — restricted', 'is_confidential' => true,
        ]);
        $child = DocumentFolder::forceCreate([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Investigations', 'parent_id' => $folder->id,
        ]);
        $filed = $this->document(['folder_id' => $child->id]);

        // Inherited down the tree: filing it correctly has to be enough on its own, or the
        // protection depends on the uploader also remembering to tick the box.
        $this->assertFalse(Document::visibleTo($this->userWith('document.view'))->pluck('id')->contains($filed->id));
    }

    public function test_the_uploader_still_sees_their_own_confidential_document(): void
    {
        $author = $this->userWith('document.view');
        $own = $this->document(['is_confidential' => true, 'created_by' => $author->id]);

        $this->assertTrue(Document::visibleTo($author)->pluck('id')->contains($own->id));
    }

    public function test_the_listing_scope_and_the_policy_agree(): void
    {
        $user = $this->userWith('document.view');

        $documents = [
            $this->document(),
            $this->document(['is_confidential' => true]),
            $this->document(['is_legally_sensitive' => true]),
            $this->document(['is_confidential' => true, 'created_by' => $user->id]),
        ];

        $listed = Document::visibleTo($user)->pluck('id')->all();

        foreach ($documents as $document) {
            // The day these disagree is the day a document appears in a list nobody can open, or
            // worse, is hidden from a list but reachable by its URL.
            $this->assertSame(
                $this->policy()->view($user, $document),
                in_array($document->id, $listed, true),
                "Listing and policy disagree about document {$document->id}.",
            );
        }
    }
}
