<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Actions\ImportBulkOnboardingAction;
use App\Modules\HR\Onboarding\Actions\ReverseBulkImportAction;
use App\Modules\HR\Onboarding\Models\OnboardingBulkImport;
use App\Modules\HR\Onboarding\Models\OnboardingDocument;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Database\Seeders\OnboardingTemplateCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Undoing a bulk import.
 *
 * Two hundred people imported against the wrong template is a mistake that takes one click to make
 * and two hundred to correct by hand. What the reversal must *not* do is take somebody's work with
 * it: a person whose documents have been uploaded or whose tasks have been ticked off has been
 * dealt with, and deleting them silently would destroy that along with the mistake.
 */
class ReverseBulkImportTest extends TestCase
{
    use RefreshDatabase;

    private function import(int $rows = 3): OnboardingBulkImport
    {
        $this->seed(OnboardingTemplateCatalogueSeeder::class);

        $start = now()->addDays(14)->toDateString();
        $data = [];

        foreach (range(1, $rows) as $i) {
            $data[] = [
                'first_name' => "Person{$i}", 'surname' => 'Test',
                'start_date' => $start, 'template' => 'standard',
            ];
        }

        return app(ImportBulkOnboardingAction::class)
            ->execute($data, User::factory()->create()->id);
    }

    public function test_it_removes_the_people_and_plans_the_import_created(): void
    {
        $import = $this->import(3);

        $this->assertSame(3, HrPerson::count());

        $result = app(ReverseBulkImportAction::class)->execute($import);

        $this->assertSame(3, $result['deleted']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame(0, HrPerson::count());
    }

    /** Soft deletes, so the reversal is itself recoverable. */
    public function test_the_rows_leave_the_module_without_leaving_the_database(): void
    {
        $import = $this->import(2);

        app(ReverseBulkImportAction::class)->execute($import);

        $this->assertSame(0, HrPerson::count());
        $this->assertSame(2, HrPerson::withTrashed()->count());
    }

    /** Somebody has already dealt with this person; the mistake is no longer the only thing here. */
    public function test_a_person_with_an_uploaded_document_is_kept_and_reported(): void
    {
        $import = $this->import(2);
        $person = HrPerson::first();

        OnboardingDocument::create([
            'hr_person_id' => $person->id,
            'document_type' => 'id_document',
            'label' => 'ID copy',
            'is_required' => true,
            'status' => 'submitted',
            'file_data' => base64_encode('scan'),
            'created_by' => User::factory()->create()->id,
        ]);

        $result = app(ReverseBulkImportAction::class)->execute($import);

        $this->assertSame(1, $result['deleted']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('documents', $result['skipped'][0]['reason']);
        $this->assertNotNull($person->fresh(), 'The person who was worked on must survive.');
    }

    /** A link already sent means somebody outside the company may already be acting on it. */
    public function test_a_person_who_has_been_sent_a_portal_link_is_kept(): void
    {
        $import = $this->import(2);
        HrPerson::first()->issuePortalToken();

        $result = app(ReverseBulkImportAction::class)->execute($import);

        $this->assertSame(1, $result['deleted']);
        $this->assertStringContainsString('portal link', $result['skipped'][0]['reason']);
    }

    /** The record says what actually happened, so a partial reversal is not mistaken for a clean one. */
    public function test_the_import_records_whether_the_reversal_was_complete(): void
    {
        $clean = $this->import(1);
        app(ReverseBulkImportAction::class)->execute($clean);
        $this->assertSame('reversed', $clean->fresh()->status);

        $partial = $this->import(2);
        HrPerson::whereNull('deleted_at')->first()->issuePortalToken();
        app(ReverseBulkImportAction::class)->execute($partial);
        $this->assertSame('partially_reversed', $partial->fresh()->status);
    }
}
