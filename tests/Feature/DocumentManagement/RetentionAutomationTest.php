<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Services\LegalHoldService;
use App\Modules\Operations\DocumentManagement\Services\RetentionService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Letting the retention schedule act on its own.
 *
 * Archiving unattended is housekeeping; deleting unattended is not, and the tests are weighted that
 * way. Most of what follows is about the things that must stop an automatic deletion: the company
 * switch, the category switch, a person's approval, a grace period, and a legal hold placed after
 * the approval.
 */
class RetentionAutomationTest extends TestCase
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

        $this->actingAs(User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'auto+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]));
    }

    private function retention(): RetentionService
    {
        return app(RetentionService::class);
    }

    private function settings(array $attributes): void
    {
        DocumentSetting::query()->delete();
        DocumentSetting::create($attributes + ['max_file_size_mb' => 25]);
    }

    private function category(array $attributes = []): DocumentCategory
    {
        return DocumentCategory::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Category ' . Str::random(5),
            'slug' => 'cat-' . Str::lower(Str::random(8)),
            'is_system' => false, 'is_active' => true, 'sort_order' => 0,
            'retention_basis' => RetentionService::BASIS_DOCUMENT_DATE,
        ], $attributes));
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    // ── Archiving ────────────────────────────────────────────────────────────

    public function test_a_document_past_its_active_life_is_archived_without_asking(): void
    {
        $category = $this->category(['archive_after_years' => 2, 'auto_archive' => true]);

        $old = $this->document(['title' => 'Old', 'category_id' => $category->id, 'document_date' => now()->subYears(3)]);
        $recent = $this->document(['title' => 'Recent', 'category_id' => $category->id, 'document_date' => now()->subMonths(6)]);

        $counts = $this->retention()->autoArchive();

        $this->assertSame(1, $counts['archived']);
        $this->assertSame(Document::STATUS_ARCHIVED, $old->fresh()->status);
        $this->assertNotNull($old->fresh()->auto_archived_at, 'A person archiving by hand and a schedule doing it are different answers to "why did this leave my listing".');
        $this->assertSame(Document::STATUS_ACTIVE, $recent->fresh()->status);
    }

    public function test_archiving_is_left_alone_where_the_category_did_not_ask_for_it(): void
    {
        $category = $this->category(['archive_after_years' => 1, 'auto_archive' => false]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(5)]);

        $this->retention()->autoArchive();

        $this->assertSame(Document::STATUS_ACTIVE, $document->fresh()->status);
    }

    public function test_a_held_document_is_not_archived_out_from_under_whoever_is_preserving_it(): void
    {
        $category = $this->category(['archive_after_years' => 1, 'auto_archive' => true]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(5)]);

        app(LegalHoldService::class)->place($document, 'CASE-2026-014');

        $counts = $this->retention()->autoArchive();

        $this->assertSame(0, $counts['archived']);
        $this->assertSame(1, $counts['held']);
        $this->assertSame(Document::STATUS_ACTIVE, $document->fresh()->status);
    }

    // ── Deleting ─────────────────────────────────────────────────────────────

    private function approvedForDisposal(array $categoryAttributes = []): Document
    {
        $category = $this->category(array_merge(['auto_delete' => true], $categoryAttributes));
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(9)]);

        $this->retention()->approveDisposal($document);

        // Approved a fortnight ago, so the grace period has run unless a test says otherwise.
        $document->fresh()->forceFill(['disposal_reviewed_at' => now()->subDays(14)])->save();

        return $document->fresh();
    }

    public function test_nothing_is_deleted_while_the_company_switch_is_off(): void
    {
        // The outer of the two switches. A company that never turns this on can configure whatever
        // it likes per category and still have nothing deleted for it.
        $this->settings(['auto_delete_enabled' => false, 'disposal_grace_days' => 7]);

        $document = $this->approvedForDisposal();

        $counts = $this->retention()->autoDelete();

        $this->assertSame(0, $counts['deleted']);
        $this->assertNotNull(Document::find($document->id));
    }

    public function test_nothing_is_deleted_where_the_category_did_not_ask_for_it(): void
    {
        $this->settings(['auto_delete_enabled' => true, 'disposal_grace_days' => 7]);

        $document = $this->approvedForDisposal(['auto_delete' => false]);

        $this->assertSame(0, $this->retention()->autoDelete()['deleted']);
        $this->assertNotNull(Document::find($document->id));
    }

    public function test_a_document_nobody_approved_is_never_deleted(): void
    {
        /*
         * The line this feature must not cross. The sweep carries out decisions; it does not make
         * them, and a document that came due but was never reviewed is a decision nobody has taken.
         */
        $this->settings(['auto_delete_enabled' => true, 'disposal_grace_days' => 7]);

        $category = $this->category(['retention_years' => 1, 'auto_delete' => true]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(9)]);

        $this->retention()->sweep();
        $this->assertSame(RetentionService::STATE_DUE, $document->fresh()->disposal_state);

        $this->assertSame(0, $this->retention()->autoDelete()['deleted']);
        $this->assertNotNull(Document::find($document->id));
    }

    public function test_an_approval_waits_out_its_grace_period(): void
    {
        // Somebody who approves a disposal by mistake on a Friday has until the following week.
        $this->settings(['auto_delete_enabled' => true, 'disposal_grace_days' => 7]);

        $document = $this->approvedForDisposal();
        $document->forceFill(['disposal_reviewed_at' => now()->subDay()])->save();

        $counts = $this->retention()->autoDelete();

        $this->assertSame(0, $counts['deleted']);
        $this->assertSame(1, $counts['waiting']);
        $this->assertNotNull(Document::find($document->id));
    }

    public function test_an_approved_disposal_past_its_grace_period_is_carried_out(): void
    {
        $this->settings(['auto_delete_enabled' => true, 'disposal_grace_days' => 7]);

        $document = $this->approvedForDisposal();

        $this->assertSame(1, $this->retention()->autoDelete()['deleted']);
        $this->assertNull(Document::find($document->id));
    }

    public function test_what_it_does_is_recoverable_and_never_permanent(): void
    {
        /*
         * The hard limit on the whole feature. Permanent destruction stays behind a person with the
         * purge permission — nothing running unattended should be able to make a document
         * unrecoverable, and a retention policy is not improved by being irreversible.
         */
        $this->settings(['auto_delete_enabled' => true, 'disposal_grace_days' => 7]);

        $document = $this->approvedForDisposal();

        $this->retention()->autoDelete();

        $this->assertNotNull(
            Document::withTrashed()->find($document->id),
            'The sweep must delete recoverably and never destroy.',
        );
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_a_hold_placed_after_the_approval_stops_the_deletion(): void
    {
        // The approval does not survive a hold. Somebody deciding on Monday that a document may go
        // is overruled by somebody deciding on Tuesday that it must be preserved.
        $this->settings(['auto_delete_enabled' => true, 'disposal_grace_days' => 7]);

        $document = $this->approvedForDisposal();
        app(LegalHoldService::class)->place($document, 'CASE-2026-014');

        $counts = $this->retention()->autoDelete();

        $this->assertSame(0, $counts['deleted']);
        $this->assertSame(1, $counts['held']);
        $this->assertNotNull(Document::find($document->id));
    }
}
