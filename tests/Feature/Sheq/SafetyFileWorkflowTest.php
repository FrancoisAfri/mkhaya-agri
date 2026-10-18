<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SafetyFileWorkflow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The safety file approval workflow (CON-7).
 *
 * A compliance percentage answers "is every demanded document on file and in date". It cannot
 * answer whether a competent person looked at the file and accepted it, which is what a client's
 * auditor asks — a file can hold every certificate and still be rubbish: the method statement
 * describes different work, the appointment names somebody who left.
 */
class SafetyFileWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private array $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.contractor.manage', 'sheq.configure']);

        // Nothing demanded, so a file can be submitted without filing twenty-five sections first.
        // What is under test is the state machine, not the section model.
        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $this->companyId],
            ['contractor_required_documents' => json_encode([]), 'updated_at' => now()],
        );

        $this->contractor = app(ContractorService::class)->register($this->companyId, [
            'name' => 'Acme Scaffolding', 'trade' => 'SCAFFOLDING',
        ], $this->user->id);

        // The safety file profile demands sections too; clear them for the same reason.
        app(\App\Modules\Operations\Sheq\Services\SafetyFileService::class)
            ->applyProfile($this->companyId, 'GENERAL', $this->user->id);
        DB::table('sheq_safety_file_sections')->where('company_id', $this->companyId)
            ->update(['is_required' => false]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'sfw-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }

    private function act(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/safety-file/transition", $payload);
    }

    private function state(): string
    {
        return (string) DB::table('sheq_safety_file_submissions')
            ->where('company_id', $this->companyId)
            ->orderByDesc('version')->value('state');
    }

    private function workflow(): SafetyFileWorkflow
    {
        return app(SafetyFileWorkflow::class);
    }

    // ── The states, in order ──────────────────────────────────────────────────────────────────

    public function test_a_file_starts_as_a_draft(): void
    {
        $this->assertSame(SafetyFileWorkflow::DRAFT,
            $this->workflow()->current($this->companyId, $this->contractor['uuid'])->state);
    }

    public function test_the_full_happy_path_runs(): void
    {
        $this->act(['action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(SafetyFileWorkflow::SUBMITTED, $this->state());

        $this->act(['action' => 'review'])->assertSessionHasNoErrors();
        $this->assertSame(SafetyFileWorkflow::UNDER_REVIEW, $this->state());

        $this->act(['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(SafetyFileWorkflow::APPROVED, $this->state());
    }

    public function test_approval_carries_a_validity_and_a_reviewer(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        $submission = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $this->companyId)->orderByDesc('version')->first();

        // A file approved last March says nothing about the insurance that lapsed in June, so the
        // approval carries its own expiry.
        $this->assertNotNull($submission->valid_until);
        $this->assertSame($this->user->id, (int) $submission->reviewer_id);
        $this->assertNotNull($submission->reviewed_at);
    }

    public function test_sending_it_back_records_the_reason_and_the_list_of_changes(): void
    {
        $this->act(['action' => 'submit']);

        $this->act([
            'action' => 'request_changes',
            'reason' => 'The method statement describes a different scope of work.',
            'changes' => ['Replace the method statement', 'Re-issue the 37(2) with our name on it'],
        ])->assertSessionHasNoErrors();

        $submission = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $this->companyId)->orderByDesc('version')->first();

        /*
         * A reason is for the record; requested changes are a list somebody works through.
         * Collapsing them into one text box loses the second, which is the half the contractor
         * actually acts on.
         */
        $this->assertSame(SafetyFileWorkflow::CHANGES_REQUIRED, $submission->state);
        $this->assertStringContainsString('different scope', $submission->decision_reason);
        $this->assertCount(2, json_decode((string) $submission->requested_changes, true));
    }

    public function test_sending_it_back_needs_a_reason(): void
    {
        $this->act(['action' => 'submit']);

        // "Rejected" on its own starts a phone call rather than a corrected file.
        $this->act(['action' => 'request_changes'])->assertSessionHasErrors('reason');
        $this->assertSame(SafetyFileWorkflow::SUBMITTED, $this->state());
    }

    public function test_resubmitting_after_changes_opens_the_next_version(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'request_changes', 'reason' => 'The appointment names a leaver.']);
        $this->act(['action' => 'submit'])->assertSessionHasNoErrors();

        $versions = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $this->companyId)->orderBy('version')->get(['version', 'state']);

        /*
         * Without versions, "rejected then fixed" has nowhere to live: the rejection reason gets
         * overwritten by the approval, and the history an auditor wants is the one thing lost.
         */
        $this->assertCount(2, $versions);
        $this->assertSame(SafetyFileWorkflow::CHANGES_REQUIRED, $versions[0]->state);
        $this->assertSame(SafetyFileWorkflow::SUBMITTED, $versions[1]->state);
    }

    public function test_the_first_versions_rejection_survives_the_second_versions_approval(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'request_changes', 'reason' => 'Insurance expired before submission.']);
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        $first = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $this->companyId)->where('version', 1)->first();

        $this->assertStringContainsString('Insurance expired', $first->decision_reason);
        $this->assertSame(SafetyFileWorkflow::CHANGES_REQUIRED, $first->state);
    }

    public function test_suspending_and_reinstating_an_approved_file(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        $this->act(['action' => 'suspend', 'reason' => 'Two unreported incidents on site last week.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(SafetyFileWorkflow::SUSPENDED, $this->state());

        $this->act(['action' => 'reinstate'])->assertSessionHasNoErrors();
        $this->assertSame(SafetyFileWorkflow::APPROVED, $this->state());
    }

    public function test_suspending_needs_a_reason(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        $this->act(['action' => 'suspend'])->assertSessionHasErrors('reason');
        $this->assertSame(SafetyFileWorkflow::APPROVED, $this->state());
    }

    // ── Illegal moves are refused, and the refusal is useful ──────────────────────────────────

    public function test_a_draft_cannot_be_approved(): void
    {
        /*
         * A state machine whose illegal transitions fail quietly is one that eventually holds every
         * state at once. The refusal reaches the person and names what is possible from here.
         */
        $this->act(['action' => 'approve'])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(SafetyFileWorkflow::DRAFT, $this->state());
    }

    public function test_the_refusal_says_what_is_possible_instead(): void
    {
        $this->act(['action' => 'suspend', 'reason' => 'Trying to suspend a draft file.'])
            ->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('draft', strtolower((string) $error));
        $this->assertStringContainsString('submitted', strtolower((string) $error));
    }

    public function test_a_file_already_with_a_reviewer_cannot_be_submitted_again(): void
    {
        $this->act(['action' => 'submit']);

        $this->act(['action' => 'submit'])->assertSessionHas('error');
        $this->assertSame(SafetyFileWorkflow::SUBMITTED, $this->state());
    }

    public function test_an_approved_file_is_not_resubmitted_by_accident(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        $this->act(['action' => 'submit'])->assertSessionHas('error');
        $this->assertSame(SafetyFileWorkflow::APPROVED, $this->state());
    }

    // ── Submission is refused while the file cannot be approved ───────────────────────────────

    public function test_a_file_missing_a_demanded_section_cannot_be_submitted(): void
    {
        DB::table('sheq_safety_file_sections')->where('company_id', $this->companyId)
            ->where('code', 'COID')->update(['is_required' => true]);

        // Submitting a file that cannot be approved wastes the reviewer's time and teaches
        // everybody that submission is a formality. The refusal names what is missing.
        $this->act(['action' => 'submit'])->assertSessionHas('error');

        $this->assertStringContainsString('COID', (string) session('error'));
        $this->assertSame(SafetyFileWorkflow::DRAFT, $this->state());
    }

    // ── History and audit ─────────────────────────────────────────────────────────────────────

    public function test_every_transition_is_in_the_history(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'review']);
        $this->act(['action' => 'approve']);

        $history = $this->workflow()->history($this->companyId, $this->contractor['uuid']);

        $states = array_column($history[0]['transitions'], 'to_state');

        // The approval history CON-7 asks for: what state, when, and who moved it.
        $this->assertContains(SafetyFileWorkflow::DRAFT, $states);
        $this->assertContains(SafetyFileWorkflow::SUBMITTED, $states);
        $this->assertContains(SafetyFileWorkflow::UNDER_REVIEW, $states);
        $this->assertContains(SafetyFileWorkflow::APPROVED, $states);
        $this->assertSame($this->user->email, $history[0]['transitions'][0]['actor']);
    }

    public function test_the_history_carries_both_versions(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'request_changes', 'reason' => 'The scope does not match the work.']);
        $this->act(['action' => 'submit']);

        $history = $this->workflow()->history($this->companyId, $this->contractor['uuid']);

        $this->assertCount(2, $history);
        // Newest first: the version somebody is dealing with now.
        $this->assertSame(2, $history[0]['version']);
        $this->assertStringContainsString('scope does not match', $history[1]['decision_reason']);
    }

    public function test_each_transition_is_audited(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        foreach (['safety_file.submitted', 'safety_file.approved'] as $action) {
            $entry = DB::table('sheq_audit_log')
                ->where('company_id', $this->companyId)->where('action', $action)
                ->orderByDesc('id')->first();

            $this->assertNotNull($entry, "No audit entry for {$action}.");
            $this->assertSame($this->user->id, (int) $entry->actor_id);
        }
    }

    public function test_the_transition_history_is_append_only(): void
    {
        $this->act(['action' => 'submit']);
        $before = DB::table('sheq_safety_file_transitions')
            ->where('company_id', $this->companyId)->count();

        $this->act(['action' => 'request_changes', 'reason' => 'Nothing in it matches the work.']);

        // A row that can be rewritten answers "what state was this in, when" only until somebody
        // rewrites it, so transitions are added and never updated.
        $this->assertGreaterThan($before, DB::table('sheq_safety_file_transitions')
            ->where('company_id', $this->companyId)->count());
    }

    // ── Time, permissions and tenancy ─────────────────────────────────────────────────────────

    public function test_an_approval_expires_when_its_validity_passes(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        DB::table('sheq_safety_file_submissions')->where('company_id', $this->companyId)
            ->update(['valid_until' => Carbon::today()->subDay()->toDateString()]);

        $moved = $this->workflow()->expireLapsed($this->companyId);

        // Time does this, not a person: an approval that stood until March stops standing in April
        // whether or not anybody opens the screen.
        $this->assertSame(1, $moved);
        $this->assertSame(SafetyFileWorkflow::EXPIRED, $this->state());
    }

    public function test_the_nightly_pass_expires_lapsed_approvals(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        DB::table('sheq_safety_file_submissions')->where('company_id', $this->companyId)
            ->update(['valid_until' => Carbon::today()->subDay()->toDateString()]);

        /*
         * `expireLapsed()` was written and nothing called it, so on a live installation no
         * approval would ever have expired — a contractor approved once would read APPROVED for
         * ever, which is the opposite of what an expiring approval is for. It now runs at the
         * front of the reminders pass, before the morning's messages are composed.
         */
        $this->artisan('sheq:reminders:send', ['--company' => $this->companyId])
            ->assertExitCode(0);

        $this->assertSame(SafetyFileWorkflow::EXPIRED, $this->state());
    }

    public function test_an_expired_file_is_resubmitted_as_a_new_version(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        DB::table('sheq_safety_file_submissions')->where('company_id', $this->companyId)
            ->update(['valid_until' => Carbon::today()->subDay()->toDateString()]);
        $this->workflow()->expireLapsed($this->companyId);

        $this->act(['action' => 'submit'])->assertSessionHasNoErrors();

        // The way out of expired is a fresh file, not somebody reinstating the old one.
        $this->assertSame(2, (int) DB::table('sheq_safety_file_submissions')
            ->where('company_id', $this->companyId)->max('version'));
    }

    public function test_an_approval_still_inside_its_validity_is_left_alone(): void
    {
        $this->act(['action' => 'submit']);
        $this->act(['action' => 'approve']);

        $this->assertSame(0, $this->workflow()->expireLapsed($this->companyId));
        $this->assertSame(SafetyFileWorkflow::APPROVED, $this->state());
    }

    public function test_moving_a_file_needs_the_contractor_entitlement(): void
    {
        $onlooker = $this->userWith([]);

        $this->actingAs($onlooker)
            ->post("/sheq/contractors/{$this->contractor['uuid']}/safety-file/transition",
                ['action' => 'submit'])
            ->assertForbidden();
    }

    public function test_another_tenants_file_cannot_be_moved(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Other Employer',
            'slug' => 'sfw-other-'.Str::lower(Str::random(8)), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreign = (string) Str::uuid();

        DB::table('sheq_contractors')->insert([
            'uuid' => $foreign, 'company_id' => $other, 'name' => 'Theirs',
            'access_status' => 'BLOCKED', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->post("/sheq/contractors/{$foreign}/safety-file/transition", ['action' => 'submit'])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('sheq_safety_file_submissions', ['company_id' => $other]);
    }

    // ── The screen can actually drive it ──────────────────────────────────────────────────────

    public function test_the_screen_offers_the_moves_that_are_legal_from_here(): void
    {
        /*
         * The buttons are built from the state, so the screen must receive the state. Offering
         * every action and letting the server refuse five of them is how people learn to ignore
         * buttons.
         */
        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->viewData('page')['props'];

        $this->assertSame(SafetyFileWorkflow::DRAFT, $props['workflow']['state']);

        $this->act(['action' => 'submit']);

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->viewData('page')['props'];

        $this->assertSame(SafetyFileWorkflow::SUBMITTED, $props['workflow']['state']);
    }

    public function test_the_screen_receives_what_the_document_dialog_needs(): void
    {
        DB::table('sheq_sites')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'W'.Str::random(5), 'name' => 'Workshop', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'EQUIPMENT_CERTIFICATE',
                'activities' => ['LIFTING'],
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $this->user->id);

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->viewData('page')['props'];

        // Without the site list the applicability field is a dropdown with nothing in it, and
        // without the activity suggestions every practitioner invents their own spelling.
        $this->assertNotEmpty($props['sites']);
        $this->assertContains('LIFTING', $props['knownActivities']);
    }

    public function test_a_supplied_document_is_shown_as_not_counting_yet(): void
    {
        app(ContractorService::class)->attachDocument($this->companyId,
            $this->contractor['uuid'], [
                'document_type' => 'COIDA_LETTER',
                'status' => 'SUPPLIED',
                'expires_on' => Carbon::today()->addYear()->toDateString(),
            ], $this->user->id);

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->viewData('page')['props'];

        $section = collect($props['file']['sections'])->firstWhere('code', 'COID');

        /*
         * The accept and reject buttons are drawn from this. Without `status` and `counts` on the
         * item the screen cannot tell a certificate waiting to be checked from one that has been,
         * and the whole SUPPLIED state would be invisible.
         */
        $this->assertSame('NOT_ACCEPTED', $section['state']);
        $this->assertSame('SUPPLIED', $section['items'][0]['status']);
        $this->assertFalse($section['items'][0]['counts']);
    }

    public function test_the_state_and_the_history_reach_the_screen(): void
    {
        $this->act(['action' => 'submit']);

        $props = $this->actingAs($this->user)
            ->get("/sheq/contractors/{$this->contractor['uuid']}/safety-file")
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(SafetyFileWorkflow::SUBMITTED, $props['workflow']['state']);
        $this->assertNotEmpty($props['workflow']['history']);
    }
}
