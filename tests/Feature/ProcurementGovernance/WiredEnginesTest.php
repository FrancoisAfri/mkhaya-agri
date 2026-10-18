<?php

namespace Tests\Feature\ProcurementGovernance;

use App\Modules\Procurement\Governance\Services\CriteriaService;
use App\Modules\Procurement\Governance\Services\ModerationService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ShipsLocales;
use Tests\TestCase;

/**
 * Three capabilities that were written, tested in isolation by nothing, and reachable by nobody.
 *
 * Each had a service method that worked. What none of them had was the single call or route that
 * connects it to the rest of the module, which is a defect no unit test can see — the engine passes,
 * the screen renders, and the thing simply never happens.
 *
 *  - An approved criteria change left the criteria untouched. The committee asked, the chain
 *    approved, the screen said APPROVED, and the evaluation carried on being scored against the
 *    criteria the change was meant to replace.
 *  - A clarification could be raised and never answered, and approving the committee's scores
 *    refuses while one is outstanding. Raising one stopped the tender permanently.
 *  - A criteria set could not be copied from an earlier tender, so a twenty-line matrix was retyped.
 *
 * These are governance controls in a module sold to public buyers, so the tests are about the
 * join, not the arithmetic: that approving applies, that answering unblocks, and that the routes
 * exist at all.
 */
class WiredEnginesTest extends TestCase
{
    use ShipsLocales;

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
    }

    private function review(): int
    {
        return DB::table('procgov_tender_reviews')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'TR-'.Str::random(6), 'title' => 'Network refresh',
            'methodology' => 'WEIGHTED', 'status' => 'EVALUATION', 'currency' => 'ZAR',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function criterion(int $reviewId, array $attributes = []): int
    {
        return DB::table('procgov_criteria')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'review_id' => $reviewId,
            'name' => 'Technical capability', 'criterion_group' => 'TECHNICAL',
            'weight' => 40, 'max_score' => 100, 'scoring_method' => 'POINTS',
            'is_mandatory' => false, 'is_pass_fail' => false, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    // ── An approved change has to change something ───────────────────────────

    public function test_approving_a_criteria_change_actually_changes_the_criterion(): void
    {
        /*
         * The defect, stated as plainly as it can be: this used to pass with the weight still at 40,
         * because approving set a status and nothing applied it.
         */
        $reviewId = $this->review();
        $criterionId = $this->criterion($reviewId);

        $change = app(CriteriaService::class)->requestChange(
            $this->companyId, $reviewId, $criterionId, 'AMENDED',
            ['weight' => 55], 'The technical weighting was agreed at 55 in the committee minutes.',
        );

        DB::table('procgov_criteria_changes')->where('id', $change->id)->update(['status' => 'APPROVED']);
        app(CriteriaService::class)->applyChange($this->companyId, (int) $change->id);

        $this->assertEqualsWithDelta(
            55, (float) DB::table('procgov_criteria')->where('id', $criterionId)->value('weight'), 0.01,
        );
        $this->assertSame('APPLIED',
            DB::table('procgov_criteria_changes')->where('id', $change->id)->value('status'));
    }

    public function test_a_change_that_was_not_approved_cannot_be_applied(): void
    {
        // Applying a PENDING change would let a requester edit frozen criteria by asking politely.
        $reviewId = $this->review();
        $criterionId = $this->criterion($reviewId);

        $change = app(CriteriaService::class)->requestChange(
            $this->companyId, $reviewId, $criterionId, 'AMENDED',
            ['weight' => 55], 'Still waiting on the committee to decide this one.',
        );

        $this->expectException(\Throwable::class);
        app(CriteriaService::class)->applyChange($this->companyId, (int) $change->id);
    }

    public function test_a_removal_takes_the_criterion_out_of_the_matrix(): void
    {
        $reviewId = $this->review();
        $criterionId = $this->criterion($reviewId);

        $change = app(CriteriaService::class)->requestChange(
            $this->companyId, $reviewId, $criterionId, 'REMOVED',
            ['name' => 'Technical capability'], 'Duplicated by the functional criterion below it.',
        );

        DB::table('procgov_criteria_changes')->where('id', $change->id)->update(['status' => 'APPROVED']);
        app(CriteriaService::class)->applyChange($this->companyId, (int) $change->id);

        $this->assertNotNull(DB::table('procgov_criteria')->where('id', $criterionId)->value('deleted_at'));
    }

    public function test_the_approval_screen_is_what_applies_it(): void
    {
        /*
         * The join itself. Asserting the service works proves nothing about whether anybody calls
         * it — which was the entire defect — so this holds the controller's dependency on it.
         */
        $dependencies = collect((new \ReflectionClass(
            \App\Modules\Procurement\Governance\Http\Controllers\ApprovalController::class
        ))->getConstructor()->getParameters())->map(fn ($p) => (string) $p->getType())->all();

        $this->assertContains(CriteriaService::class, $dependencies,
            'the approval controller cannot apply a criteria change it cannot reach');
    }

    // ── A clarification has to be answerable ─────────────────────────────────

    public function test_answering_a_clarification_resolves_it(): void
    {
        /*
         * Raising CLARIFY puts the item in AWAITING_RESPONSE, and approving the committee's scores
         * refuses while anything is OPEN or AWAITING_RESPONSE. Without this the tender could not
         * reach the recommendation stage at all.
         */
        $reviewId = $this->review();
        $moderationId = DB::table('procgov_moderations')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'review_id' => $reviewId,
            'status' => 'OPEN', 'opened_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('procgov_moderation_items')->insertGetId([
            'company_id' => $this->companyId,
            'moderation_id' => $moderationId, 'item_type' => 'VARIANCE',
            'action' => 'CLARIFY', 'status' => 'AWAITING_RESPONSE',
            'moderator_comment' => 'Your score is 30 points below the panel. Please explain.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ModerationService::class)->respond(
            $this->companyId, $itemId, 'I misread the specification; the revised score stands at 72.',
        );

        $item = DB::table('procgov_moderation_items')->find($itemId);
        $this->assertSame('RESOLVED', $item->status);
        $this->assertNotNull($item->responded_at);
        $this->assertStringContainsString('misread', (string) $item->evaluator_response);
    }

    public function test_the_evaluator_has_a_route_to_answer_on(): void
    {
        // The method existed and nothing could call it. This is the whole fix.
        $route = Route::getRoutes()->getByName('procgov.evaluations.moderation.respond');

        $this->assertNotNull($route, 'an evaluator cannot answer a clarification without a route');
        $this->assertStringContainsString('respond', $route->uri());
        $this->assertTrue(method_exists(
            \App\Modules\Procurement\Governance\Http\Controllers\EvaluationController::class,
            'respondToModeration',
        ));
    }

    public function test_answering_is_the_evaluators_permission_not_the_moderators(): void
    {
        /*
         * If the moderator could answer their own clarification the round trip would be a formality,
         * and the record of why a score stands would be written by the person who questioned it.
         */
        $route = Route::getRoutes()->getByName('procgov.evaluations.moderation.respond');
        $middleware = implode(' ', $route->gatherMiddleware());

        $this->assertStringContainsString('procgov.evaluate', $middleware);
        $this->assertStringNotContainsString('procgov.tender.moderate', $middleware);
    }

    // ── Copying a criteria set ───────────────────────────────────────────────

    public function test_a_criteria_set_can_be_taken_from_an_earlier_tender(): void
    {
        $source = $this->review();
        $this->criterion($source, ['name' => 'Technical capability', 'weight' => 40]);
        $this->criterion($source, ['name' => 'Experience', 'weight' => 30, 'sort_order' => 1]);

        $target = $this->review();

        $copied = app(CriteriaService::class)->copyFrom($this->companyId, $target, $source);

        $this->assertSame(2, $copied);
        $this->assertSame(2, DB::table('procgov_criteria')
            ->where('review_id', $target)->whereNull('deleted_at')->count());
        $this->assertSame(2, DB::table('procgov_criteria')
            ->where('review_id', $source)->whereNull('deleted_at')->count(), 'the source was moved, not copied');
    }

    public function test_copying_has_a_route(): void
    {
        $route = Route::getRoutes()->getByName('procgov.tenders.criteria.copy');

        $this->assertNotNull($route);
        $this->assertTrue(method_exists(
            \App\Modules\Procurement\Governance\Http\Controllers\TenderReviewController::class,
            'copyCriteria',
        ));
    }

    public function test_every_new_flash_message_exists_in_all_twelve_languages(): void
    {
        // A wired feature whose message is missing says the key name back to the user.
        $keys = [
            'procurement_governance.flash.evaluations.clarification_answered',
            'procurement_governance.flash.evaluations.not_your_clarification',
            'procurement_governance.flash.tenders.criteria_copied',
            'procurement_governance.flash.tenders.criteria_copy_empty',
            'procurement_governance.flash.tenders.criteria_copy_same',
        ];

        foreach (array_merge(['en'], $this->shippedLocales(['af', 'fr', 'nr', 'nso', 'ss', 'st', 'tn', 'ts', 've', 'xh', 'zu'])) as $locale) {
            foreach ($keys as $key) {
                $this->assertNotSame($key, __($key, [], $locale), "{$key} is missing in {$locale}");
            }
        }
    }
}
