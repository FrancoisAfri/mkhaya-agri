<?php

namespace Tests\Feature\DocumentManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentLegalHold;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\LegalHoldService;
use App\Modules\Operations\DocumentManagement\Services\RetentionService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Keeping documents for as long as they are needed, and not a day past it.
 *
 * Everything here is about the two ways this feature can do real harm: destroying something that
 * had to be kept, and keeping a schedule that quietly destroys on a timer with nobody's name
 * against it. The tests are written from those two failures backwards.
 */
class RetentionAndLegalHoldTest extends TestCase
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

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ret+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);
        $this->actingAs($this->user);
    }

    private function retention(): RetentionService
    {
        return app(RetentionService::class);
    }

    private function holds(): LegalHoldService
    {
        return app(LegalHoldService::class);
    }

    private function category(array $attributes = []): DocumentCategory
    {
        return DocumentCategory::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'Category ' . Str::random(5),
            'slug' => 'cat-' . Str::lower(Str::random(8)),
            'is_system' => false, 'is_active' => true, 'sort_order' => 0,
        ], $attributes));
    }

    private function document(array $attributes = []): Document
    {
        return Document::create(array_merge([
            'title' => 'A document', 'status' => Document::STATUS_ACTIVE, 'version_count' => 1,
        ], $attributes));
    }

    // ── Working out the date ─────────────────────────────────────────────────

    public function test_a_category_without_a_schedule_never_makes_a_document_due(): void
    {
        $document = $this->document(['category_id' => $this->category()->id]);

        $this->assertNull($this->retention()->dueDateFor($document));
    }

    public function test_the_disposal_date_is_counted_from_the_basis_the_category_names(): void
    {
        $category = $this->category(['retention_years' => 5, 'retention_basis' => RetentionService::BASIS_EFFECTIVE_DATE]);

        $document = $this->document([
            'category_id' => $category->id,
            'effective_date' => '2020-06-01',
            // Deliberately different, so a rule reading the wrong field is visible rather than
            // coincidentally right.
            'document_date' => '2015-01-01',
            'created_at' => '2024-01-01',
        ]);

        $this->assertSame('2025-06-01', $this->retention()->dueDateFor($document)?->toDateString());
    }

    public function test_a_document_missing_the_date_its_schedule_counts_from_is_not_scheduled_at_all(): void
    {
        // The dangerous case. Falling back to the upload date here would destroy a document years
        // before its actual retention period ran out, and it would look like the policy working.
        $category = $this->category(['retention_years' => 5, 'retention_basis' => RetentionService::BASIS_EXPIRY_DATE]);

        $document = $this->document(['category_id' => $category->id, 'expiry_date' => null]);

        $this->assertNull(
            $this->retention()->dueDateFor($document),
            'With no expiry date there is nothing to count five years from, and guessing is how documents get destroyed early.',
        );
    }

    public function test_correcting_the_document_date_moves_the_disposal_date_with_it(): void
    {
        $category = $this->category(['retention_years' => 7, 'retention_basis' => RetentionService::BASIS_DOCUMENT_DATE]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => '2019-03-10']);

        $this->retention()->sweep();
        $this->assertSame('2026-03-10', $document->fresh()->disposal_due_at?->toDateString());

        $document->forceFill(['document_date' => '2021-03-10'])->save();
        $this->retention()->sweep();

        $this->assertSame(
            '2028-03-10',
            $document->fresh()->disposal_due_at?->toDateString(),
            'A disposal date frozen at upload time is wrong the moment the metadata it came from is corrected.',
        );
    }

    // ── The queue ────────────────────────────────────────────────────────────

    public function test_the_sweep_queues_what_has_come_due_and_leaves_the_rest_alone(): void
    {
        $category = $this->category(['retention_years' => 1, 'retention_basis' => RetentionService::BASIS_DOCUMENT_DATE]);

        $due = $this->document(['title' => 'Old enough', 'category_id' => $category->id, 'document_date' => now()->subYears(2)]);
        $notYet = $this->document(['title' => 'Still young', 'category_id' => $category->id, 'document_date' => now()->subMonths(2)]);

        $this->retention()->sweep();

        $this->assertSame(RetentionService::STATE_DUE, $due->fresh()->disposal_state);
        $this->assertNull($notYet->fresh()->disposal_state);
    }

    public function test_the_sweep_destroys_nothing(): void
    {
        // The whole design in one assertion: a retention run ends with documents waiting for a
        // person, never with documents gone.
        $category = $this->category(['retention_years' => 1, 'retention_basis' => RetentionService::BASIS_DOCUMENT_DATE]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(5)]);

        $this->retention()->sweep();

        $this->assertNotNull(Document::withTrashed()->find($document->id), 'The sweep must never delete.');
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_a_document_already_reviewed_does_not_come_back_into_the_queue(): void
    {
        $category = $this->category(['retention_years' => 1, 'retention_basis' => RetentionService::BASIS_DOCUMENT_DATE]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(3)]);

        $this->retention()->sweep();
        $this->retention()->retain($document->fresh(), 'Still referenced by an open matter.');

        $this->retention()->sweep();

        $this->assertSame(
            RetentionService::STATE_RETAINED,
            $document->fresh()->disposal_state,
            'A queue that re-asks a question already answered is a queue nobody reads.',
        );
    }

    public function test_keeping_a_document_schedules_the_question_again_rather_than_exempting_it_forever(): void
    {
        $category = $this->category(['retention_years' => 3, 'retention_basis' => RetentionService::BASIS_DOCUMENT_DATE]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(5)]);

        $this->retention()->retain($document, 'Keep for now.');

        $this->assertSame(
            now()->startOfDay()->addYears(3)->toDateString(),
            $document->fresh()->disposal_due_at?->toDateString(),
        );
    }

    // ── Legal hold ───────────────────────────────────────────────────────────

    public function test_a_held_document_cannot_be_deleted_or_destroyed(): void
    {
        $document = $this->document();
        $this->holds()->place($document, 'CASE-2026-014', 'Pending litigation.');

        $documents = app(DocumentService::class);

        $this->assertFalse($documents->delete($document->fresh())['ok']);
        $this->assertFalse($documents->purge($document->fresh())['ok']);
        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_a_held_document_never_enters_the_disposal_queue(): void
    {
        $category = $this->category(['retention_years' => 1, 'retention_basis' => RetentionService::BASIS_DOCUMENT_DATE]);
        $document = $this->document(['category_id' => $category->id, 'document_date' => now()->subYears(4)]);

        $this->holds()->place($document, 'CASE-2026-014');

        $counts = $this->retention()->sweep();

        $this->assertNull(
            $document->fresh()->disposal_state,
            'Queuing a held document offers somebody a destroy button for a document that must not be destroyed.',
        );
        $this->assertSame(1, $counts['held']);
    }

    public function test_approving_disposal_is_refused_while_a_hold_stands(): void
    {
        $document = $this->document();
        $this->holds()->place($document, 'CASE-2026-014');

        $result = $this->retention()->approveDisposal($document->fresh());

        $this->assertFalse($result['ok']);
        $this->assertNotNull($result['error']);
    }

    public function test_lifting_one_hold_leaves_a_document_held_by_another_matter(): void
    {
        // Two investigations touching the same contract is the ordinary case, and unholding on the
        // first release destroys evidence while the second matter is still live.
        $document = $this->document();
        $first = $this->holds()->place($document, 'CASE-2026-014');
        $this->holds()->place($document, 'AUDIT-2026-3');

        $this->holds()->release($first, 'Matter closed.');

        $this->assertTrue($document->fresh()->isUnderLegalHold());
        $this->assertFalse(app(DocumentService::class)->delete($document->fresh())['ok']);
    }

    public function test_releasing_the_last_hold_lets_the_document_be_deleted_again(): void
    {
        $document = $this->document();
        $hold = $this->holds()->place($document, 'CASE-2026-014');

        $this->holds()->release($hold, 'Matter closed.');

        $this->assertFalse($document->fresh()->isUnderLegalHold());
        $this->assertTrue(app(DocumentService::class)->delete($document->fresh())['ok']);
    }

    public function test_a_released_hold_is_kept_as_the_record_that_the_document_was_preserved(): void
    {
        $document = $this->document();
        $hold = $this->holds()->place($document, 'CASE-2026-014', 'Pending litigation.');

        $this->holds()->release($hold, 'Matter settled.');

        $stored = DocumentLegalHold::find($hold->id);

        $this->assertNotNull($stored, 'Deleting the row on release destroys the proof it was ever held.');
        $this->assertNotNull($stored->released_at);
        $this->assertSame($this->user->id, $stored->released_by);
        $this->assertSame('Matter settled.', $stored->release_reason);
        $this->assertSame('Pending litigation.', $stored->reason);
    }

    public function test_placing_the_same_hold_twice_does_not_create_a_second_one_to_release(): void
    {
        $document = $this->document();
        $first = $this->holds()->place($document, 'CASE-2026-014');
        $second = $this->holds()->place($document, 'CASE-2026-014');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DocumentLegalHold::where('document_id', $document->id)->active()->count());
    }

    public function test_a_matter_can_be_lifted_in_one_action(): void
    {
        $a = $this->document(['title' => 'Contract']);
        $b = $this->document(['title' => 'Correspondence']);
        $other = $this->document(['title' => 'Unrelated']);

        $this->holds()->place($a, 'CASE-2026-014');
        $this->holds()->place($b, 'CASE-2026-014');
        $this->holds()->place($other, 'AUDIT-2026-3');

        $lifted = $this->holds()->releaseMatter('CASE-2026-014', 'Matter closed.');

        $this->assertSame(2, $lifted);
        $this->assertFalse($a->fresh()->isUnderLegalHold());
        $this->assertFalse($b->fresh()->isUnderLegalHold());
        $this->assertTrue($other->fresh()->isUnderLegalHold(), 'A different matter must be untouched.');
    }

    public function test_the_register_groups_live_holds_by_matter(): void
    {
        $this->holds()->place($this->document(), 'CASE-2026-014');
        $this->holds()->place($this->document(), 'CASE-2026-014');
        $this->holds()->place($this->document(), 'AUDIT-2026-3');

        $register = collect($this->holds()->register())->keyBy('reference');

        $this->assertSame(2, $register['CASE-2026-014']['documents']);
        $this->assertSame(1, $register['AUDIT-2026-3']['documents']);
    }

    public function test_placing_and_lifting_a_hold_is_written_into_the_document_history(): void
    {
        $document = $this->document();
        $hold = $this->holds()->place($document, 'CASE-2026-014', 'Pending litigation.');
        $this->holds()->release($hold, 'Matter settled.');

        $actions = DocumentEvent::where('document_id', $document->id)->pluck('action');

        $this->assertTrue($actions->contains(DocumentEvent::HOLD_PLACED));
        $this->assertTrue($actions->contains(DocumentEvent::HOLD_RELEASED));
    }

    public function test_the_flag_listings_filter_on_follows_the_holds(): void
    {
        // Two sources of truth is how a document ends up flagged with no hold behind it.
        $document = $this->document();
        $this->assertFalse((bool) $document->is_legally_sensitive);

        $hold = $this->holds()->place($document, 'CASE-2026-014');
        $this->assertTrue((bool) $document->fresh()->is_legally_sensitive);

        $this->holds()->release($hold);
        $this->assertFalse((bool) $document->fresh()->is_legally_sensitive);
    }

    // ── The old sensitivity form, now routed through the holds ───────────────

    public function test_marking_a_document_legally_sensitive_places_a_hold_rather_than_only_setting_a_flag(): void
    {
        $document = $this->document();

        app(DocumentService::class)->setSensitivity($document, false, true, 'Subject of an internal investigation.');

        $hold = DocumentLegalHold::where('document_id', $document->id)->active()->first();

        $this->assertNotNull($hold, 'A flag with no hold behind it cannot say who held it or why.');
        $this->assertSame(DocumentService::UNSPECIFIED_MATTER, $hold->reference);
        $this->assertTrue($document->fresh()->isUnderLegalHold());
    }

    public function test_clearing_legal_sensitivity_lifts_the_holds_behind_it(): void
    {
        $document = $this->document();
        $documents = app(DocumentService::class);

        $documents->setSensitivity($document, false, true, 'Investigation opened.');
        $documents->setSensitivity($document->fresh(), false, false, 'Investigation closed.');

        $this->assertFalse($document->fresh()->isUnderLegalHold());
        $this->assertSame(0, DocumentLegalHold::where('document_id', $document->id)->active()->count());
        $this->assertSame(1, DocumentLegalHold::where('document_id', $document->id)->count(), 'The lifted hold is kept.');
    }

    public function test_a_flag_set_with_no_hold_behind_it_can_still_be_cleared(): void
    {
        // Documents flagged before holds were records, or by a path that bypassed them. There is no
        // hold to release, so releasing nothing would leave them flagged with no way out — and a
        // document nobody can unflag is a document nobody can ever delete.
        $document = $this->document(['is_legally_sensitive' => true]);

        $this->assertSame(0, DocumentLegalHold::where('document_id', $document->id)->count());

        app(DocumentService::class)->setSensitivity($document, false, false, 'Matter closed.');

        $this->assertFalse($document->fresh()->isUnderLegalHold());
        $this->assertTrue(app(DocumentService::class)->delete($document->fresh())['ok']);
    }

    // ── Setting the schedule ─────────────────────────────────────────────────

    public function test_a_retention_schedule_can_be_set_and_then_removed_again(): void
    {
        // The update path filters nulls out, so a schedule that could be added but never removed
        // was a real possibility — somebody sets "5 years" on the wrong category and the only way
        // back is the database.
        // Entitle the company to the module, so a 403 here means the policy refused rather than
        // the entitlement middleware — otherwise this would pass, or fail, for the wrong reason.
        $categoryId = DB::table('security_categories')->value('id')
            ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('security_modules')->updateOrInsert(
            ['code_name' => 'document_management'],
            ['uuid' => (string) Str::uuid(), 'name' => 'Document Management', 'category_id' => $categoryId,
                'active' => 1, 'created_at' => now(), 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::flush();

        // The category editor sits in the Configure hub, which is superuser tier. Without this the
        // 403 comes from the hub gate and the policy is never reached.
        $moduleId = DB::table('security_modules')->where('code_name', 'document_management')->value('id');
        DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $this->user->id, 'module_id' => $moduleId],
            ['access_level' => \App\Support\Hubs\ModuleHubs::SUPERUSER],
        );

        $this->user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('document.settings.manage', 'web'));
        $this->user = $this->user->fresh();
        $this->actingAs($this->user);

        $category = $this->category();

        $this->put(route('document-management.categories.update', $category->uuid), [
            'retention_years' => 5,
            'retention_basis' => RetentionService::BASIS_EFFECTIVE_DATE,
            'retention_note' => 'Tax records.',
        ])->assertRedirect();

        $category->refresh();
        $this->assertSame(5, $category->retention_years);
        $this->assertSame(RetentionService::BASIS_EFFECTIVE_DATE, $category->retention_basis);

        $this->put(route('document-management.categories.update', $category->uuid), [
            'retention_years' => '',
        ])->assertRedirect();

        $category->refresh();
        $this->assertNull($category->retention_years);
        $this->assertNull($category->retention_basis, 'A basis with no period left behind reads as a schedule that is not there.');
    }
}
