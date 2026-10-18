<?php

namespace Tests\Feature\HR\Payroll;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Services\SubmissionStatusService;
use App\Modules\Finance\Sars\Support\SubmissionLifecycle;
use Illuminate\Validation\ValidationException;

/**
 * A statutory return moves forwards, and says who moved it.
 *
 * SarsSubmission::STATUSES has described this lifecycle since the table was created and nothing
 * enforced it: setStatus() checked only that the value was one of the six, so a return could go
 * from draft to accepted without being validated or filed, and — the one that matters — a return
 * already submitted to SARS could be walked back to draft, leaving the record disagreeing with
 * what the revenue service holds.
 *
 * Shared by every return type in the module, so this covers VAT201 and EMP501 as much as EMP201,
 * and the bureau's client filings as much as the employer's own.
 */
class Emp201LifecycleTest extends PayrollTestCase
{
    private function submission(string $status = 'generated'): SarsSubmission
    {
        return SarsSubmission::create([
            'company_id' => $this->hrManager->company_id,
            'tax_type'   => 'emp201',
            'period'     => '2026-08',
            'status'     => $status,
            'version'    => 1,
            'totals'     => ['total_payable' => 125000.00],
            'payload'    => [],
            'validation' => ['validated' => 8, 'errors' => []],
        ]);
    }

    private function lifecycle(): SubmissionStatusService
    {
        return app(SubmissionStatusService::class);
    }

    /**
     * SARS submission history lives in Finance → Accounting, not Payroll.
     *
     * The two HTTP tests below reach routes guarded by `accounting`, which this module's fixture
     * knows nothing about — so without this they are turned away by the module guard and never
     * reach the controller whose behaviour they are checking.
     */
    private function activateAccounting(): void
    {
        $categoryId = \Illuminate\Support\Facades\DB::table('security_categories')->value('id');

        \Illuminate\Support\Facades\DB::table('security_modules')->insertOrIgnore([[
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'code_name' => 'accounting',
            'category_id' => $categoryId, 'active' => 1, 'name' => 'Accounting',
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        $moduleId = \Illuminate\Support\Facades\DB::table('security_modules')
            ->where('code_name', 'accounting')->value('id');

        \Illuminate\Support\Facades\DB::table('security_module_access')->updateOrInsert(
            ['user_id' => $this->hrManager->id, 'module_id' => $moduleId],
            ['access_level' => \App\Support\Hubs\ModuleHubs::SUPERUSER, 'active' => 1,
                'created_at' => now(), 'updated_at' => now()],
        );

        \Illuminate\Support\Facades\Cache::flush();
    }

    // ── The order ────────────────────────────────────────────────────────────

    public function test_a_return_moves_forward_through_its_lifecycle(): void
    {
        $submission = $this->submission('generated');

        $this->actingAs($this->hrManager);

        $this->lifecycle()->move($submission, 'validated');
        $this->assertSame('validated', $submission->fresh()->status);

        $this->lifecycle()->move($submission, 'submitted');
        $this->assertSame('submitted', $submission->fresh()->status);

        $this->lifecycle()->move($submission, 'accepted');
        $this->assertSame('accepted', $submission->fresh()->status);
    }

    public function test_a_return_already_filed_with_sars_cannot_be_walked_back(): void
    {
        $submission = $this->submission('submitted');

        $this->expectException(ValidationException::class);
        $this->lifecycle()->move($submission, 'draft');
    }

    public function test_a_return_cannot_skip_being_checked(): void
    {
        // generated → submitted would file a return nobody validated.
        $submission = $this->submission('generated');

        $this->expectException(ValidationException::class);
        $this->lifecycle()->move($submission, 'submitted');
    }

    public function test_a_rejected_return_is_amended_rather_than_revived(): void
    {
        $submission = $this->submission('rejected');

        try {
            $this->lifecycle()->move($submission, 'validated');
            $this->fail('A rejected return should not be movable.');
        } catch (ValidationException $e) {
            // The refusal has to say what to do instead, or the user simply tries again.
            $this->assertStringContainsString('new version', $e->errors()['status'][0]);
        }

        $this->assertSame('rejected', $submission->fresh()->status);
    }

    public function test_an_accepted_return_is_the_end_of_that_version(): void
    {
        $this->assertSame([], SubmissionLifecycle::nextFrom('accepted'));
        $this->assertTrue(SubmissionLifecycle::isTerminal('accepted'));
    }

    public function test_the_submitted_date_is_stamped_when_it_is_filed(): void
    {
        $submission = $this->submission('validated');
        $this->actingAs($this->hrManager);

        $this->lifecycle()->move($submission, 'submitted');

        $this->assertNotNull($submission->fresh()->submitted_at);
    }

    // ── Who did it ───────────────────────────────────────────────────────────

    public function test_every_move_records_who_made_it(): void
    {
        $submission = $this->submission('generated');
        $this->actingAs($this->hrManager);

        $this->lifecycle()->move($submission, 'validated', 'Checked against the SARS profile.');

        $event = $submission->events()->latest('id')->first();

        $this->assertSame('generated', $event->from_status);
        $this->assertSame('validated', $event->to_status);
        $this->assertSame('Checked against the SARS profile.', $event->note);
        $this->assertSame($this->hrManager->id, $event->actor_id);
    }

    public function test_a_refused_move_leaves_nothing_behind(): void
    {
        // An attempt that was refused is not a step in the return's life, and recording it would
        // make the trail read as though the return had been somewhere it never was.
        $submission = $this->submission('submitted');
        $before = $submission->events()->count();

        try {
            $this->lifecycle()->move($submission, 'draft');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($before, $submission->fresh()->events()->count());
    }

    public function test_the_trail_names_the_person_not_their_id(): void
    {
        // There is no users.name column, so an actor recorded as an id renders as "#41" to whoever
        // reads the trail later — and as nothing at all once that user has left.
        $submission = $this->submission('generated');
        $this->actingAs($this->hrManager);

        $this->lifecycle()->move($submission, 'validated');

        $this->assertNotNull($submission->events()->latest('id')->first()->actor_name);
    }

    // ── What the screen offers ───────────────────────────────────────────────

    public function test_the_screen_offers_only_the_moves_that_are_possible(): void
    {
        $submission = $this->submission('generated');
        $this->activateAccounting();

        $this->actingAs($this->hrManager)
            ->get(route('finance.accounting.sars.submissions.show', $submission->id))
            ->assertInertia(fn ($page) => $page
                ->where('statuses', ['validated', 'rejected'])
                ->where('isTerminal', false));
    }

    public function test_the_route_refuses_a_backwards_move_too(): void
    {
        $submission = $this->submission('submitted');
        $this->activateAccounting();

        $this->actingAs($this->hrManager)
            ->post(route('finance.accounting.sars.submissions.status', $submission->id), ['status' => 'draft'])
            ->assertSessionHasErrors('status');

        $this->assertSame('submitted', $submission->fresh()->status);
    }
}
