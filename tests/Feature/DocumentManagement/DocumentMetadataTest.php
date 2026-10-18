<?php

namespace Tests\Feature\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentLink;
use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Document metadata, and the links that let the rest of the platform reach the repository.
 *
 * The dates are the part that looks simple and is not: a certificate issued in March, effective
 * from April, expiring in September and uploaded in June has four different dates, and every one of
 * them gets asked for by somebody.
 */
class DocumentMetadataTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private \App\Core\User\Models\User $user;

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

        $this->user = \App\Core\User\Models\User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'meta+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->user);
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'Tax clearance certificate',
            'status' => Document::STATUS_ACTIVE,
            'version_count' => 1,
        ], $attributes));
    }

    // ── Numbering ────────────────────────────────────────────────────────────

    public function test_a_document_is_given_a_number_when_none_is_supplied(): void
    {
        $this->assertMatchesRegularExpression('/^DOC-\d{4}-\d{5}$/', $this->document()->document_number);
    }

    public function test_an_organisations_own_number_is_kept(): void
    {
        // An organisation with its own numbering does not want ours on top of it.
        $this->assertSame('HR/2026/014', $this->document(['document_number' => 'HR/2026/014'])->document_number);
    }

    public function test_numbers_do_not_repeat_after_a_deletion(): void
    {
        $first = $this->document();
        $first->delete();
        $second = $this->document();

        // Derived from the highest number rather than a count, so a deletion does not hand its
        // number to the next document — two documents sharing a reference is worse than a gap.
        $this->assertNotSame($first->document_number, $second->document_number);
    }

    // ── Dates ────────────────────────────────────────────────────────────────

    public function test_a_document_can_exist_and_not_yet_be_in_force(): void
    {
        $document = $this->document([
            'document_date' => now()->subMonth(),
            'effective_date' => now()->addWeek(),
        ]);

        // Active, current, and not yet applicable — answering "is this the one in force" from the
        // status alone gets this wrong.
        $this->assertSame(Document::STATUS_ACTIVE, $document->status);
        $this->assertFalse($document->isInForce());
    }

    public function test_an_expired_document_is_not_in_force_and_is_reported_as_expired(): void
    {
        $document = $this->document(['expiry_date' => now()->subDay()]);

        $this->assertTrue($document->isExpired());
        $this->assertFalse($document->isInForce());
        $this->assertTrue(Document::expired()->whereKey($document->id)->exists());
    }

    public function test_documents_about_to_expire_are_listed_separately_from_expired_ones(): void
    {
        $soon = $this->document(['expiry_date' => now()->addDays(10)]);
        $gone = $this->document(['expiry_date' => now()->subDays(10)]);

        $expiring = Document::expiringWithin(30)->pluck('id');

        // Different lists with different urgency: mixing them loses the ones still worth renewing
        // among the ones that are not.
        $this->assertTrue($expiring->contains($soon->id));
        $this->assertFalse($expiring->contains($gone->id));
    }

    // ── Confidentiality ──────────────────────────────────────────────────────

    public function test_the_level_and_the_flags_never_disagree(): void
    {
        $byFlag = $this->document(['is_confidential' => true]);
        $byLevel = $this->document(['confidentiality' => Document::CONFIDENTIALITY_RESTRICTED]);

        // Drift here means a document marked restricted on screen that the access rules — which
        // read the flags — treat as ordinary.
        $this->assertSame(Document::CONFIDENTIALITY_CONFIDENTIAL, $byFlag->confidentiality);
        $this->assertTrue($byLevel->is_legally_sensitive);
        $this->assertTrue($byLevel->is_confidential);
    }

    public function test_a_document_with_no_opinion_about_sensitivity_is_internal(): void
    {
        $document = $this->document(['confidentiality' => null]);

        // Null is "nobody said", not "public". Defaulting the other way would publish things.
        $this->assertSame(Document::CONFIDENTIALITY_INTERNAL, $document->confidentiality);
        $this->assertFalse($document->is_confidential);
    }

    // ── Links to other modules ───────────────────────────────────────────────

    public function test_a_document_can_be_about_several_things_at_once(): void
    {
        $document = $this->document();
        $supplier = $this->supplier();

        $document->linkTo($supplier);
        DocumentLink::create([
            'document_id' => $document->id, 'relation' => DocumentRelations::PROJECT,
            'linkable_type' => 'App\\Modules\\Operations\\ProjectManagement\\Models\\Project',
            'linkable_id' => 99, 'label' => 'Roadworks',
        ]);

        // A signed subcontract concerns the supplier, the project and the contract at once, which
        // is what a column per relation would have forced a choice between.
        $this->assertCount(2, $document->fresh()->links);
        $this->assertArrayHasKey(DocumentRelations::SUPPLIER, $document->fresh()->linksByRelation());
    }

    public function test_a_module_reaches_its_own_documents_without_knowing_how_they_are_stored(): void
    {
        $document = $this->document();
        $supplier = $this->supplier();

        $supplier->attachDocument($document);

        // The point of the section: a record carries its documents.
        $this->assertTrue($supplier->hasLibraryDocuments());
        $this->assertTrue($supplier->libraryDocuments()->pluck('id')->contains($document->id));
    }

    public function test_linking_the_same_document_twice_is_not_an_error(): void
    {
        $document = $this->document();
        $supplier = $this->supplier();

        $supplier->attachDocument($document);
        $supplier->attachDocument($document);

        // A double click is a click, not a duplicate row.
        $this->assertSame(1, $supplier->documentLinks()->count());
    }

    public function test_unlinking_leaves_the_document_alone(): void
    {
        $document = $this->document();
        $supplier = $this->supplier();

        $supplier->attachDocument($document);
        $supplier->detachDocument($document);

        // The document exists in its own right. Removing it from a supplier's page is not a
        // decision to destroy it.
        $this->assertFalse($supplier->hasLibraryDocuments());
        $this->assertNotNull(Document::find($document->id));
    }

    public function test_a_link_still_reads_after_the_record_it_points_at_is_gone(): void
    {
        $document = $this->document();
        $supplier = $this->supplier();
        $name = $supplier->company_name;

        $link = $supplier->attachDocument($document);
        $supplier->forceDelete();

        // The label was captured when the link was made, which is why the row still says something
        // useful rather than rendering as a blank somebody has to investigate.
        $this->assertSame($name, $link->fresh()->label);
        $this->assertNull($link->fresh()->target());
    }

    public function test_a_relation_whose_module_is_absent_is_not_offered(): void
    {
        // Property has no module yet. Offering it would put a dropdown entry in front of someone
        // that fatals when chosen.
        $this->assertArrayNotHasKey(DocumentRelations::PROPERTY, DocumentRelations::available());
        $this->assertArrayHasKey(DocumentRelations::SUPPLIER, DocumentRelations::available());
    }

    // ── Category ─────────────────────────────────────────────────────────────

    public function test_a_document_carries_its_category(): void
    {
        $category = DocumentCategory::create(['name' => 'Tax Document', 'slug' => 'tax-' . Str::random(4)]);
        $document = $this->document(['category_id' => $category->id]);

        $this->assertSame('Tax Document', $document->fresh()->category->name);
    }

    private function supplier(): \App\Modules\SupplierManagement\Models\Supplier
    {
        return \App\Modules\SupplierManagement\Models\Supplier::create([
            'company_name' => 'Acme Trading ' . Str::random(4),
            'company_id' => $this->companyId,
        ]);
    }

    // ── The edit has to leave a trace ────────────────────────────────────────

    public function test_renaming_moving_and_editing_a_document_are_each_recorded(): void
    {
        /*
         * An audit trail that records views and downloads but not edits is worse than an incomplete
         * one, because it looks complete. A document could be renamed, re-dated and moved to another
         * folder with nothing at all to show it happened.
         */
        $this->entitle();

        $folder = app(\App\Modules\Operations\DocumentManagement\Services\FolderService::class)
            ->create(['name' => 'Contracts ' . Str::random(4)]);

        $document = $this->document(['title' => 'Old name']);

        $this->put(route('document-management.documents.update', $document->uuid), [
            'title' => 'New name',
            'folder' => $folder->uuid,
            'expiry_date' => '2027-01-31',
        ])->assertRedirect();

        $actions = DocumentEvent::where('document_id', $document->id)->pluck('action');

        $this->assertTrue($actions->contains(DocumentEvent::RENAMED), 'A rename must be recorded.');
        $this->assertTrue($actions->contains(DocumentEvent::MOVED), 'A move must be recorded.');
        $this->assertTrue($actions->contains(DocumentEvent::UPDATED), 'A field change must be recorded.');

        $renamed = DocumentEvent::where('document_id', $document->id)
            ->where('action', DocumentEvent::RENAMED)->first();

        $this->assertSame('Old name', $renamed->detail['from']);
        $this->assertSame('New name', $renamed->detail['to']);
    }

    public function test_saving_a_document_without_changing_anything_records_nothing(): void
    {
        // Otherwise the history fills with entries nobody made, and the ones that matter are lost
        // among them.
        $this->entitle();

        $document = $this->document(['title' => 'Unchanged']);

        $this->put(route('document-management.documents.update', $document->uuid), [
            'title' => 'Unchanged',
        ])->assertRedirect();

        $this->assertSame(
            0,
            DocumentEvent::where('document_id', $document->id)
                ->whereIn('action', [DocumentEvent::RENAMED, DocumentEvent::MOVED, DocumentEvent::UPDATED])
                ->count(),
        );
    }

    /** Entitles the company and gives the acting user what editing needs. */
    private function entitle(): void
    {
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();

        foreach (['document.view', 'document.update', 'document.folder.manage'] as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
        }

        $this->user->givePermissionTo(['document.view', 'document.update', 'document.folder.manage']);
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);
    }
}
