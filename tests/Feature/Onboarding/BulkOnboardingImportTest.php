<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Actions\ImportBulkOnboardingAction;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Database\Seeders\OnboardingTemplateCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\ActivatesBoardingModule;
use Tests\TestCase;

/**
 * The whole bulk loop: download the spreadsheet, fill it in, upload it, get onboarding plans.
 *
 * The round trip is the point. A template generated from one column list and parsed against another
 * produces "your file is invalid" for a file the system itself handed over, so the test fills in the
 * real downloaded header rather than a hand-written one.
 */
class BulkOnboardingImportTest extends TestCase
{
    use RefreshDatabase, ActivatesBoardingModule;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateBoardingModule();
        $this->seed(OnboardingTemplateCatalogueSeeder::class);

        $this->hr = User::factory()->create();
        $this->grantBoardingTier($this->hr);
        $this->hr = $this->hr->fresh();
    }

    private function upload(string $csv, array $extra = [])
    {
        return $this->actingAs($this->hr)->post(
            route('onboarding.bulk.import'),
            array_merge(['file' => UploadedFile::fake()->createWithContent('hires.csv', $csv)], $extra)
        );
    }

    public function test_the_downloaded_template_has_the_columns_the_importer_expects(): void
    {
        $response = $this->actingAs($this->hr)->get(route('onboarding.bulk.template'));

        $response->assertOk();

        $csv = $response->streamedContent();
        $header = str_getcsv(strtok($csv, "\n"));

        $this->assertSame(
            ImportBulkOnboardingAction::COLUMNS,
            $header,
            'The file handed to the user must be the file the importer parses.'
        );

        $this->assertStringContainsString('Nomsa', $csv, 'A worked example row saves a support call.');
    }

    public function test_a_filled_in_template_creates_a_person_and_a_plan_for_every_row(): void
    {
        $header = implode(',', ImportBulkOnboardingAction::COLUMNS);
        $start = now()->addDays(7)->toDateString();

        $csv = <<<CSV
        {$header}
        Thabo,Nkosi,thabo@example.test,0821112222,,EMP1,Site Foreman,Operations,permanent,{$start},construction
        Lerato,Mokoena,lerato@example.test,,,,Developer,Engineering,permanent,{$start},engineering
        CSV;

        $this->upload($csv)->assertSessionHasNoErrors();

        $this->assertSame(2, HrPerson::count());
        $this->assertSame(2, OnboardingPlan::count());

        $thabo = HrPerson::where('surname', 'Nkosi')->first();

        $this->assertSame('Thabo Nkosi', $thabo->fullName());
        $this->assertNull($thabo->candidate_id, 'A bulk hire has no candidate — that is the point.');
        $this->assertNull($thabo->contract_id);
        $this->assertSame('bulk_import', $thabo->source);
    }

    /** Tasks come from the named template, dated relative to that person's own start date. */
    public function test_rows_are_onboarded_onto_the_template_they_name(): void
    {
        $header = implode(',', ImportBulkOnboardingAction::COLUMNS);
        $start = now()->addDays(10)->toDateString();

        $this->upload("{$header}\nAyanda,Zulu,,,,,Nurse,Clinical,permanent,{$start},healthcare")
            ->assertSessionHasNoErrors();

        $plan = OnboardingPlan::first();

        $this->assertGreaterThan(10, $plan->tasks()->count(), 'The healthcare template carries a real checklist.');
        $this->assertSame($start, $plan->start_date->toDateString());

        $this->assertTrue(
            $plan->tasks()->where('title', 'like', '%HPCSA%')->exists(),
            'The tasks should be the healthcare ones, not the generic set.'
        );
    }

    /**
     * One bad row must not cost the good ones.
     *
     * The whole file being a single transaction would mean re-uploading to fix a typo, and
     * re-uploading a file that partly succeeded is how duplicates happen.
     */
    public function test_a_bad_row_is_reported_without_losing_the_good_rows(): void
    {
        $header = implode(',', ImportBulkOnboardingAction::COLUMNS);
        $start = now()->addDays(5)->toDateString();

        $csv = <<<CSV
        {$header}
        Good,Person,good@example.test,,,,Clerk,Admin,permanent,{$start},standard
        ,Missing,nofirstname@example.test,,,,Clerk,Admin,permanent,{$start},standard
        Another,Good,another@example.test,,,,Clerk,Admin,permanent,not-a-date,standard
        CSV;

        $this->upload($csv)->assertSessionHasNoErrors();

        $this->assertSame(1, HrPerson::count(), 'Only the valid row should have been created.');

        $import = \App\Modules\HR\Onboarding\Models\OnboardingBulkImport::first();

        $this->assertSame(3, $import->total_rows);
        $this->assertSame(1, $import->created_count);
        $this->assertSame(2, $import->failed_count);

        // The row numbers must match what the user sees in the spreadsheet, header included.
        $this->assertSame([3, 4], array_column($import->row_errors, 'row'));
    }

    public function test_an_unknown_template_is_rejected_by_name_rather_than_silently_ignored(): void
    {
        $header = implode(',', ImportBulkOnboardingAction::COLUMNS);
        $start = now()->addDays(3)->toDateString();

        $this->upload("{$header}\nSipho,Ndlovu,,,,,Clerk,Admin,permanent,{$start},no-such-template");

        $import = \App\Modules\HR\Onboarding\Models\OnboardingBulkImport::first();

        $this->assertSame(0, $import->created_count);
        $this->assertStringContainsString('no-such-template', $import->row_errors[0]['error']);
    }

    /** A reordered header must not shift every value one column to the left. */
    public function test_columns_are_matched_by_header_name_not_position(): void
    {
        $start = now()->addDays(4)->toDateString();

        $csv = "surname,first_name,start_date\nMabaso,Zanele,{$start}";

        $this->upload($csv)->assertSessionHasNoErrors();

        $person = HrPerson::first();

        $this->assertSame('Zanele', $person->first_name);
        $this->assertSame('Mabaso', $person->surname);
    }

    public function test_a_file_with_only_a_header_is_refused_with_a_reason(): void
    {
        $this->upload(implode(',', ImportBulkOnboardingAction::COLUMNS))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, HrPerson::count());
    }
}
