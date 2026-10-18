<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The audit trail as a screen.
 *
 * The card in the Governance hub pointed here from the day the module was built and the ordinary
 * document listing sat behind it, so the one page that answers "who did what to our documents"
 * showed a list of documents.
 *
 * The thing to get right is what it does not show. An audit line reading "Sarah downloaded
 * Disciplinary hearing — J. Dlamini" discloses exactly what the confidentiality flag was set to
 * protect, so the trail is scoped through the same visibility rule as every listing.
 */
class DocumentActivityTest extends TestCase
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
            'uuid' => (string) Str::uuid(), 'email' => 'act+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        $user = $user->fresh();

        /*
         * The hub tier as well as the permission. Activity sits in the Governance hub, so without
         * this every assertion below would be answered by the tier gate and none of them would be
         * about the audit trail at all.
         */
        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $user->id, 'module_id' => $moduleId],
            ['access_level' => \App\Support\Hubs\ModuleHubs::SUPERUSER],
        );

        return $user;
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'Board pack', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    private function event(Document $document, string $action): DocumentEvent
    {
        return DocumentEvent::create([
            'document_id' => $document->id, 'action' => $action,
            'created_at' => now(),
        ]);
    }

    public function test_the_activity_page_shows_the_audit_trail_rather_than_the_document_list(): void
    {
        $user = $this->user('document.view', 'document.activity.view');

        $document = $this->document(['title' => 'Board pack']);
        $this->event($document, DocumentEvent::UPLOADED);

        $response = $this->actingAs($user)->get(route('document-management.activity'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Modules/DocumentManagement/Pages/Activity')
            ->has('events.data', 1));
    }

    public function test_it_needs_the_activity_permission(): void
    {
        $this->actingAs($this->user('document.view'))
            ->get(route('document-management.activity'))
            ->assertForbidden();
    }

    public function test_it_does_not_disclose_documents_the_viewer_may_not_see(): void
    {
        /*
         * The disclosure this page could so easily become. A title like "Disciplinary hearing —
         * J. Dlamini" is the thing the confidentiality flag protects, and an audit line naming it
         * hands it over just as completely as opening the file would.
         */
        $viewer = $this->user('document.view', 'document.activity.view');

        $confidential = $this->document(['title' => 'Disciplinary hearing', 'is_confidential' => true]);
        $ordinary = $this->document(['title' => 'Ordinary memo']);

        $this->event($confidential, DocumentEvent::DOWNLOADED);
        $this->event($ordinary, DocumentEvent::DOWNLOADED);

        $response = $this->actingAs($viewer)->get(route('document-management.activity'));

        $response->assertOk();
        $response->assertDontSee('Disciplinary hearing');
        $response->assertSee('Ordinary memo');
    }

    public function test_it_can_be_narrowed_to_one_kind_of_action(): void
    {
        $user = $this->user('document.view', 'document.activity.view');

        $document = $this->document(['title' => 'Filtered document']);
        $this->event($document, DocumentEvent::UPLOADED);
        $this->event($document, DocumentEvent::DOWNLOADED);

        $this->actingAs($user)
            ->get(route('document-management.activity', ['action' => DocumentEvent::DOWNLOADED]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('events.data', 1));
    }
}
