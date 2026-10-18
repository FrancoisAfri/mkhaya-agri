<?php

namespace Tests\Feature\SupplierManagement;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierAppraisal;
use App\Modules\SupplierManagement\Models\SupplierIndustryType;
use App\Modules\SupplierManagement\Models\SupplierPerformanceTemplate;
use App\Modules\SupplierManagement\Models\SupplierQuestionLibrary;
use App\Modules\SupplierManagement\Services\SupplierAppraisalService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Designing a scorecard, and filling one in.
 *
 * These were one screen: every appraisal ever done, a box to start a new one, a box to create a
 * template, and a box to add a question to a template — four unrelated jobs stacked down a page,
 * and nothing that could be edited or removed afterwards. A form that creates something it cannot
 * then change is a one-way door: a weight typed wrong stayed wrong, and the only way out was a new
 * template.
 *
 * The rules held here are the ones that make a score mean something. Weights that total more than
 * 100 let a supplier beat full marks. A template deleted after it was used takes every past
 * appraisal's questions with it, leaving a number with nothing behind it. And the rating a person
 * gave has to survive, because the score is derived from it and cannot be read back the other way.
 */
class AppraisalWorkbenchTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);

        if (! DB::table('security_modules')->where('code_name', 'supplier_management')->where('active', 1)->exists()) {
            $categoryId = DB::table('security_categories')->value('id')
                ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('security_modules')->updateOrInsert(
                ['code_name' => 'supplier_management'],
                ['uuid' => (string) Str::uuid(), 'name' => 'Supplier Management', 'path' => 'suppliers',
                    'category_id' => $categoryId, 'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        Cache::flush();
    }

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'appr+'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'uuid' => (string) Str::uuid(), 'company_name' => 'Scored '.Str::random(5),
            'payment_terms' => '30 days', 'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true, 'health_safety_compliance' => true,
            'is_active' => 1, 'is_preferred' => false,
        ]);
    }

    private function template(): SupplierPerformanceTemplate
    {
        return SupplierPerformanceTemplate::create(['name' => 'Logistics '.Str::random(4), 'status' => 'active']);
    }

    private function question(SupplierPerformanceTemplate $template, float $weight, ?int $minimum = null): SupplierQuestionLibrary
    {
        return SupplierQuestionLibrary::create([
            'template_id' => $template->id, 'question' => 'On-time delivery '.Str::random(4),
            'weight_percentage' => $weight, 'minimum_threshold' => $minimum,
        ]);
    }

    // ── The pages are separate ───────────────────────────────────────────────

    public function test_each_job_has_its_own_page(): void
    {
        /*
         * The whole point. Appraising a supplier and designing the scorecard it is appraised against
         * are different tasks, done by different people, at different times.
         */
        $template = $this->template();
        $user = $this->user();

        foreach ([
            route('suppliers.appraisals.index'),
            route('suppliers.appraisals.templates'),
            route('suppliers.appraisals.templates.show', $template),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    public function test_an_appraisal_can_be_read_back_question_by_question(): void
    {
        /*
         * The thing the old screen could not do at all: a total score with no way to see what
         * produced it settles an argument by assertion, and a renewal gets decided on it.
         */
        $supplier = $this->supplier();
        $template = $this->template();
        $question = $this->question($template, 100, minimum: 3);

        $appraisal = app(SupplierAppraisalService::class)->store(
            $supplier->id, null, $template->id, null,
            [['question_library_id' => $question->id, 'rating' => 4, 'comment' => 'Consistently early.']],
        );

        $this->actingAs($this->user())
            ->get(route('suppliers.appraisals.show', $appraisal))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/SupplierManagement/Appraisals/Show')
                ->where('responses.0.rating', 4)
                ->where('responses.0.weight', fn ($w) => abs((float) $w - 100) < 0.01)
                ->where('responses.0.outcome', 'pass')
                ->where('responses.0.comment', 'Consistently early.'));
    }

    public function test_the_rating_a_person_gave_survives(): void
    {
        /*
         * The score is rating ÷ 5 × weight, so it cannot be read back the other way — a question
         * weighted at zero scores zero whatever was given. Storing only the score threw away the
         * evidence behind every decision the score summarises.
         */
        $supplier = $this->supplier();
        $template = $this->template();
        $question = $this->question($template, 0);

        $appraisal = app(SupplierAppraisalService::class)->store(
            $supplier->id, null, $template->id, null,
            [['question_library_id' => $question->id, 'rating' => 2]],
        );

        $response = $appraisal->responses()->first();

        $this->assertSame(2, $response->rating);
        $this->assertEqualsWithDelta(0, (float) $response->score, 0.01, 'a zero-weight question scores zero');
    }

    // ── Weights ─────────────────────────────────────────────────────────────

    public function test_weights_cannot_total_more_than_a_hundred(): void
    {
        // Over 100 lets a supplier score above full marks, and nothing downstream would question it.
        $template = $this->template();
        $this->question($template, 70);

        $this->actingAs($this->user())
            ->post(route('suppliers.appraisals.questions.store', $template), [
                'question' => 'Quality', 'weight_percentage' => 40,
            ])
            ->assertSessionHasErrors('weight_percentage');
    }

    public function test_the_refusal_says_how_much_room_is_left(): void
    {
        // "Would exceed 100%" leaves somebody doing arithmetic to find out what fits.
        $template = $this->template();
        $this->question($template, 70);

        $this->actingAs($this->user())
            ->post(route('suppliers.appraisals.questions.store', $template), [
                'question' => 'Quality', 'weight_percentage' => 40,
            ]);

        $this->assertStringContainsString('30', (string) session('errors')->first('weight_percentage'));
    }

    public function test_a_question_can_be_edited_without_fighting_its_own_weight(): void
    {
        /*
         * Editing counts the question's current weight against the ceiling unless it is excluded —
         * which would make a question at 70% impossible to change to 80% without deleting it first.
         */
        $template = $this->template();
        $question = $this->question($template, 70);

        $this->actingAs($this->user())
            ->patch(route('suppliers.appraisals.questions.update', [$template, $question]), [
                'question' => 'On-time delivery', 'weight_percentage' => 80,
            ])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(80, (float) $question->fresh()->weight_percentage, 0.01);
    }

    public function test_a_question_can_be_removed(): void
    {
        // The old screen could add one and never take it away.
        $template = $this->template();
        $question = $this->question($template, 50);

        $this->actingAs($this->user())
            ->delete(route('suppliers.appraisals.questions.destroy', [$template, $question]))
            ->assertSessionHasNoErrors();

        $this->assertNull(SupplierQuestionLibrary::find($question->id));
    }

    public function test_a_question_belonging_to_another_template_is_refused(): void
    {
        // Both ids come off a URL. Without the check, one template's form edits another's questions.
        $mine = $this->template();
        $theirs = $this->template();
        $question = $this->question($theirs, 50);

        $this->actingAs($this->user())
            ->delete(route('suppliers.appraisals.questions.destroy', [$mine, $question]))
            ->assertNotFound();

        $this->assertNotNull(SupplierQuestionLibrary::find($question->id));
    }

    // ── Templates ───────────────────────────────────────────────────────────

    public function test_a_template_that_has_been_used_cannot_be_deleted(): void
    {
        /*
         * A past appraisal reads its questions, weights and thresholds through its template.
         * Deleting one leaves every appraisal that used it showing a score with nothing behind it,
         * which is the same as losing the appraisal.
         */
        $template = $this->template();
        $question = $this->question($template, 100);

        app(SupplierAppraisalService::class)->store(
            $this->supplier()->id, null, $template->id, null,
            [['question_library_id' => $question->id, 'rating' => 5]],
        );

        $this->actingAs($this->user())
            ->delete(route('suppliers.appraisals.templates.destroy', $template))
            ->assertSessionHas('error');

        $this->assertNotNull(SupplierPerformanceTemplate::find($template->id));
    }

    public function test_an_unused_template_can_be_deleted_with_its_questions(): void
    {
        $template = $this->template();
        $question = $this->question($template, 100);

        $this->actingAs($this->user())
            ->delete(route('suppliers.appraisals.templates.destroy', $template))
            ->assertRedirect(route('suppliers.appraisals.templates'));

        $this->assertNull(SupplierPerformanceTemplate::find($template->id));
        $this->assertNull(SupplierQuestionLibrary::find($question->id), 'its questions went with it');
    }

    public function test_creating_a_template_lands_in_its_builder(): void
    {
        // A template with no questions cannot appraise anything, and leaving somebody on a list is
        // how empty templates accumulate.
        $response = $this->actingAs($this->user())
            ->post(route('suppliers.appraisals.templates.store'), ['name' => 'Cleaning services']);

        $template = SupplierPerformanceTemplate::where('name', 'Cleaning services')->firstOrFail();
        $response->assertRedirect(route('suppliers.appraisals.templates.show', $template));
    }

    public function test_the_templates_list_reports_whether_each_one_adds_up(): void
    {
        /*
         * Weights that never reach 100 silently cap every supplier below full marks, and nothing
         * anywhere said so.
         */
        $template = $this->template();
        $this->question($template, 60);

        $this->actingAs($this->user())
            ->get(route('suppliers.appraisals.templates'))
            ->assertInertia(fn ($page) => $page->where(
                'templates',
                fn ($rows) => abs((float) collect($rows)->firstWhere('uuid', $template->uuid)['weight'] - 60) < 0.01,
            ));
    }

    // ── Addressing and filtering ────────────────────────────────────────────

    public function test_appraisals_and_templates_are_addressed_by_uuid(): void
    {
        $template = $this->template();
        $question = $this->question($template, 100);
        $appraisal = app(SupplierAppraisalService::class)->store(
            $this->supplier()->id, null, $template->id, null,
            [['question_library_id' => $question->id, 'rating' => 3]],
        );

        $this->assertStringContainsString($appraisal->uuid, route('suppliers.appraisals.show', $appraisal));
        $this->assertStringContainsString($template->uuid, route('suppliers.appraisals.templates.show', $template));
        // Compared as a whole segment: a row id of 4 is a substring of a uuid beginning "4fda", and
        // asserting on the substring passes or fails by luck.
        $this->assertSame(
            $appraisal->uuid,
            basename(parse_url(route('suppliers.appraisals.show', $appraisal), PHP_URL_PATH)),
        );
    }

    public function test_the_list_filters_to_one_supplier(): void
    {
        // The question somebody actually brings to this page is about one supplier or one quarter.
        $template = $this->template();
        $question = $this->question($template, 100);
        $wanted = $this->supplier();
        $other = $this->supplier();

        foreach ([$wanted, $other] as $supplier) {
            app(SupplierAppraisalService::class)->store(
                $supplier->id, null, $template->id, null,
                [['question_library_id' => $question->id, 'rating' => 4]],
            );
        }

        $this->actingAs($this->user())
            ->get(route('suppliers.appraisals.index', ['supplier_id' => $wanted->id]))
            ->assertInertia(fn ($page) => $page->where(
                'appraisals.data',
                fn ($rows) => collect($rows)->every(fn ($r) => $r['supplier'] === $wanted->company_name),
            ));
    }

    public function test_an_average_of_nothing_is_not_zero(): void
    {
        /*
         * "No appraisals yet" and "everybody scored nothing" are the same number on a dashboard and
         * opposite facts.
         */
        SupplierAppraisal::query()->forceDelete();

        $this->actingAs($this->user())
            ->get(route('suppliers.appraisals.index'))
            ->assertInertia(fn ($page) => $page->where('stats.average', null));
    }
}
