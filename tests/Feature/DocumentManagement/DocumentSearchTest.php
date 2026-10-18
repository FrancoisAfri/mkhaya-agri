<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Services\DocumentSearch;
use App\Modules\Operations\DocumentManagement\Services\FolderService;
use App\Modules\Operations\DocumentManagement\Services\SignatureService;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Finding a document.
 *
 * The examples in the requirement are the tests: "supplier insurance certificates expiring within
 * 30 days" has to work, typed as a sentence, without the person learning a filter syntax first.
 */
class DocumentSearchTest extends TestCase
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

        Permission::findOrCreate('document.view', 'web');
        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'srch+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->user->givePermissionTo('document.view');
        $this->user = $this->user->fresh();
    }

    private function search(): DocumentSearch
    {
        return app(DocumentSearch::class);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    private function find(array $filters): \Illuminate\Support\Collection
    {
        return $this->search()->query($this->user, $filters)->pluck('title');
    }

    // ── Text ─────────────────────────────────────────────────────────────────

    public function test_words_are_matched_by_their_stem_rather_than_their_spelling(): void
    {
        $this->document(['title' => 'Public liability insurance certificate']);

        // The reason for full text rather than LIKE: a plural should find the singular, and
        // "%insur%" is not a search anybody can reason about.
        $this->assertTrue($this->find(['q' => 'insurance'])->contains('Public liability insurance certificate'));
    }

    public function test_a_stray_apostrophe_does_not_break_the_search(): void
    {
        $this->document(['title' => "O'Brien engagement letter"]);

        // websearch_to_tsquery tolerates what people type; plainto_tsquery would throw here.
        $this->assertIsIterable($this->find(['q' => "O'Brien's"]));
    }

    public function test_a_document_number_is_found_exactly(): void
    {
        $document = $this->document(['title' => 'Something forgettable']);

        // Somebody reading a number off a printout is searching for that string, not for words.
        $this->assertTrue($this->find(['q' => $document->document_number])->contains('Something forgettable'));
    }

    // ── Structured ───────────────────────────────────────────────────────────

    public function test_a_folder_filter_includes_everything_beneath_it(): void
    {
        $parent = app(FolderService::class)->create(['name' => 'Human Resources']);
        $child = app(FolderService::class)->create(['name' => 'Employee Files', 'parent_id' => $parent->uuid]);

        $this->document(['title' => 'Filed deep', 'folder_id' => $child->id]);

        // Searching a folder and being told it is empty, because everything sits in its
        // subfolders, is how people stop trusting search.
        $this->assertTrue($this->find(['folder' => $parent->uuid])->contains('Filed deep'));
    }

    public function test_tags_narrow_rather_than_widen(): void
    {
        $this->document(['title' => 'Both tags', 'tags' => ['urgent', 'legal']]);
        $this->document(['title' => 'One tag', 'tags' => ['urgent']]);

        $results = $this->find(['tags' => ['urgent', 'legal']]);

        $this->assertTrue($results->contains('Both tags'));
        $this->assertFalse($results->contains('One tag'));
    }

    public function test_expiring_soon_and_already_expired_are_different_questions(): void
    {
        $this->document(['title' => 'Lapses soon', 'expiry_date' => now()->addDays(10)]);
        $this->document(['title' => 'Already gone', 'expiry_date' => now()->subDays(10)]);

        $this->assertTrue($this->find(['expiring_within' => 30])->contains('Lapses soon'));
        $this->assertFalse($this->find(['expiring_within' => 30])->contains('Already gone'));
        $this->assertTrue($this->find(['expired' => true])->contains('Already gone'));
    }

    public function test_documents_can_be_found_by_what_they_are_about(): void
    {
        $supplier = \App\Modules\SupplierManagement\Models\Supplier::create([
            'company_name' => 'Acme ' . Str::random(4), 'company_id' => $this->companyId,
        ]);

        $about = $this->document(['title' => 'Acme insurance']);
        $about->linkTo($supplier);
        $this->document(['title' => 'Unrelated policy']);

        $results = $this->find(['relation' => DocumentRelations::SUPPLIER, 'relation_ids' => [$supplier->id]]);

        $this->assertTrue($results->contains('Acme insurance'));
        $this->assertFalse($results->contains('Unrelated policy'));
    }

    public function test_expiry_sorting_leaves_out_documents_that_never_expire(): void
    {
        $this->document(['title' => 'No expiry']);
        $this->document(['title' => 'Expires', 'expiry_date' => now()->addDays(5)]);

        // A renewals list is useless if the documents that never expire sort to the top.
        $results = $this->find(['sort' => 'expiry']);

        $this->assertSame('Expires', $results->first());
        $this->assertFalse($results->contains('No expiry'));
    }

    // ── The requirement's own examples ───────────────────────────────────────

    public function test_the_phrase_from_the_requirement_is_understood(): void
    {
        $filters = $this->search()->parse('Find all supplier insurance certificates expiring within 30 days');

        $this->assertSame(30, $filters['expiring_within']);
        $this->assertSame(DocumentRelations::SUPPLIER, $filters['relation']);
        // "find", "all" and "documents" are noise; what is left is what they were looking for.
        $this->assertStringContainsString('insurance', $filters['q']);
        $this->assertStringNotContainsString('Find', $filters['q']);
    }

    public function test_a_category_named_in_the_phrase_becomes_a_filter(): void
    {
        $category = DocumentCategory::create([
            'name' => 'Tax Document', 'slug' => 'tax-' . Str::random(4), 'is_active' => true,
        ]);

        $filters = $this->search()->parse('all tax documents for supplier');

        $this->assertSame($category->uuid, $filters['category']);
        $this->assertSame(DocumentRelations::SUPPLIER, $filters['relation']);
    }

    public function test_weeks_and_months_are_understood_as_well_as_days(): void
    {
        $this->assertSame(14, $this->search()->parse('expiring in 2 weeks')['expiring_within']);
        $this->assertSame(90, $this->search()->parse('expiring within 3 months')['expiring_within']);
        $this->assertSame(30, $this->search()->parse('expiring this month')['expiring_within']);
    }

    public function test_the_end_to_end_example_returns_the_right_documents(): void
    {
        $supplier = \App\Modules\SupplierManagement\Models\Supplier::create([
            'company_name' => 'Insured Co ' . Str::random(4), 'company_id' => $this->companyId,
        ]);

        $wanted = $this->document(['title' => 'Insurance certificate', 'expiry_date' => now()->addDays(20)]);
        $wanted->linkTo($supplier);

        $tooFar = $this->document(['title' => 'Insurance certificate long', 'expiry_date' => now()->addYear()]);
        $tooFar->linkTo($supplier);

        $filters = $this->search()->parse('supplier insurance expiring within 30 days');
        $results = $this->search()->query($this->user, $filters)->pluck('title');

        $this->assertTrue($results->contains('Insurance certificate'));
        $this->assertFalse($results->contains('Insurance certificate long'));
    }

    // ── Visibility ───────────────────────────────────────────────────────────

    public function test_a_filter_cannot_widen_what_somebody_may_see(): void
    {
        $this->document(['title' => 'Secret', 'is_confidential' => true]);

        // Every filter is applied on top of the visibility scope, so the worst a search can do is
        // show somebody less than they are allowed to see.
        $this->assertFalse($this->find(['confidentiality' => 'confidential'])->contains('Secret'));
    }

    // ── The workflow queues ──────────────────────────────────────────────────

    public function test_an_unfiled_document_is_visible_to_somebody_who_did_not_upload_it(): void
    {
        // The confidential-folder exclusion was written as NOT IN, which is never true for a NULL
        // folder_id. Every document at the root of the library disappeared for everyone except its
        // uploader, and nothing said so — the listing was simply short.
        $owner = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'other+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        // A confidential folder has to exist, or the exclusion this guards is never reached and the
        // test passes whether the bug is present or not.
        app(FolderService::class)->create(['name' => 'Employee Files', 'is_confidential' => true]);

        $this->document(['title' => 'Filed nowhere', 'folder_id' => null, 'created_by' => $owner->id]);

        $this->assertTrue($this->find([])->contains('Filed nowhere'));
    }

    public function test_each_workflow_queue_selects_only_its_own_documents(): void
    {
        $this->document(['title' => 'Idle']);
        $this->document(['title' => 'Submitted', 'approval_status' => Document::APPROVAL_SUBMITTED]);
        $this->document(['title' => 'Out for signature', 'signature_status' => SignatureService::AWAITING]);
        $this->document(['title' => 'Due soon', 'expiry_date' => now()->addDays(10)]);
        $this->document(['title' => 'Due next year', 'expiry_date' => now()->addDays(200)]);
        $this->document(['title' => 'Lapsed', 'expiry_date' => now()->subDays(10)]);

        $approval = $this->find(['approval_status' => [Document::APPROVAL_SUBMITTED, Document::APPROVAL_UNDER_REVIEW]]);
        $this->assertTrue($approval->contains('Submitted'));
        $this->assertFalse($approval->contains('Idle'));

        $signature = $this->find(['signature_status' => [SignatureService::AWAITING, SignatureService::PARTIAL]]);
        $this->assertTrue($signature->contains('Out for signature'));
        $this->assertFalse($signature->contains('Idle'));

        $expiring = $this->find(['expiring_within' => 90]);
        $this->assertTrue($expiring->contains('Due soon'));
        $this->assertFalse($expiring->contains('Due next year'));
        $this->assertFalse($expiring->contains('Idle'), 'A document with no expiry date is not expiring.');

        $expired = $this->find(['expired' => true]);
        $this->assertTrue($expired->contains('Lapsed'));
        $this->assertFalse($expired->contains('Due soon'));
    }

    public function test_the_queue_routes_ask_for_the_filters_their_cards_promise(): void
    {
        // The cards reach these through route defaults, which $request->boolean() cannot see. Both
        // expiry queues returned the entire library while looking like they had filtered it.
        $controller = app(\App\Modules\Operations\DocumentManagement\Http\Controllers\DocumentController::class);
        $method = new \ReflectionMethod($controller, 'searchFilters');
        $method->setAccessible(true);

        $filtersFor = function (string $name) use ($controller, $method): array {
            $route = collect(app('router')->getRoutes())->first(
                fn ($r) => $r->getName() === 'document-management.' . $name,
            );
            $this->assertNotNull($route, "Route document-management.{$name} is not registered.");

            $request = \Illuminate\Http\Request::create('/' . $route->uri(), 'GET');
            $request->setRouteResolver(fn () => $route);

            return $method->invoke($controller, $request);
        };

        $this->assertSame(90, $filtersFor('expiring')['expiring_within'] ?? null);
        $this->assertTrue($filtersFor('expired')['expired'] ?? false);
        $this->assertContains(Document::APPROVAL_SUBMITTED, $filtersFor('awaiting-approval')['approval_status'] ?? []);
        $this->assertContains(SignatureService::AWAITING, $filtersFor('awaiting-signature')['signature_status'] ?? []);

        // The plain listing must stay a plain listing.
        $this->assertArrayNotHasKey('expiring_within', $filtersFor('index'));
        $this->assertArrayNotHasKey('approval_status', $filtersFor('index'));
    }
}
