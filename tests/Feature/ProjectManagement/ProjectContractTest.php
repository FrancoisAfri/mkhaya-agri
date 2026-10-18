<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\ApprovalFlow;
use App\Modules\Operations\ProjectManagement\Models\ApprovalRequest;
use App\Modules\Operations\ProjectManagement\Models\ApprovalStep;
use App\Modules\Operations\ProjectManagement\Models\ContractSignature;
use App\Modules\Operations\ProjectManagement\Models\ContractTemplate;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectContract;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Notifications\ContractAwaitingSignatureNotification;
use App\Modules\Operations\ProjectManagement\Notifications\ContractReopenedNotification;
use App\Modules\Operations\ProjectManagement\Support\ContractBody;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Project contracts, end to end, inside Project Management.
 *
 * The rule these assert is the one the client stated: a company may buy this module and nothing
 * else, so drafting, amending, reviewing and signing all have to work with Contract Management
 * absent. Nothing here installs that module — which is the point.
 */
class ProjectContractTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    private ProjectMember $contractor;

    private User $contractorUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Aseda Subcontracting']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();

        $this->project = $this->asCompany(
            $this->company,
            fn () => $this->makeProject(['code' => 'MP23001', 'name' => 'Kwaggafontein Recreational Centre'])
        );

        $this->contractorUser = User::factory()->create([
            'company_id' => $this->company->id,
            'email'      => 'accounts@sizweplant.co.za',
            'status'     => 1,
        ]);

        $this->contractor = $this->asCompany($this->company, fn () => ProjectMember::create([
            'project_id'   => $this->project->id,
            'user_id'      => $this->contractorUser->id,
            'role_key'     => 'contractor',
            'display_name' => 'Sizwe Plant Hire',
            'email'        => 'accounts@sizweplant.co.za',
            'is_active'    => true,
        ]));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function template(string $body): ContractTemplate
    {
        return $this->asCompany($this->company, fn () => ContractTemplate::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Standard subcontract',
            'body' => $body,
            'source' => 'own',
            'is_active' => true,
        ]));
    }

    private function draft(array $overrides = []): ProjectContract
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.store'), array_merge([
                'project'        => $this->project->uuid,
                'member'         => $this->contractor->uuid,
                'title'          => 'Plant hire subcontract',
                'contract_value' => '250000.00',
            ], $overrides))
            ->assertRedirect();

        return $this->asCompany($this->company, fn () => ProjectContract::query()->latest('id')->firstOrFail());
    }

    /** Get it to `approved` and out to both parties. */
    private function sendForSignature(ProjectContract $contract, ?User $signatory = null): ProjectContract
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.submit', $contract->uuid))
            ->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.send', $contract->uuid), [
                'signatory_user_id' => ($signatory ?? $this->manager)->id,
            ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->asCompany($this->company, fn () => $contract->fresh());
    }

    // ── Drafting ────────────────────────────────────────────────────────────

    public function test_a_contract_can_be_drafted_from_a_template(): void
    {
        $template = $this->template('<p>Clause 1: {{contractor_name}} on {{project_name}}.</p>');

        $contract = $this->draft(['template' => $template->uuid]);

        $this->assertSame('MP23001/C001', $contract->contract_number);
        $this->assertSame('draft', $contract->status);
        $this->assertSame(1, $contract->current_version);
        $this->assertSame(
            '<p>Clause 1: Sizwe Plant Hire on Kwaggafontein Recreational Centre.</p>',
            $this->asCompany($this->company, fn () => $contract->currentVersion()->body),
        );
    }

    /** The client asked for this explicitly: not every contract starts from a template. */
    public function test_a_contract_can_be_drafted_from_scratch(): void
    {
        $contract = $this->draft(['body' => '<p>Written by hand.</p>']);

        $this->assertNull($contract->template_id);
        $this->assertSame(
            '<p>Written by hand.</p>',
            $this->asCompany($this->company, fn () => $contract->currentVersion()->body),
        );
    }

    /** Nothing in the flow may require Contract Management to be installed. */
    public function test_the_whole_flow_works_without_contract_management(): void
    {
        $this->assertFalse(
            app(\App\Modules\Operations\ProjectManagement\Services\ModuleRegistry::class)
                ->has('contracts.register'),
            'This test is meaningless if Contract Management is entitled.',
        );

        $contract = $this->sendForSignature($this->draft());

        $this->assertSame('out_for_signature', $contract->status);
    }

    // ── Amendment ───────────────────────────────────────────────────────────

    public function test_amending_writes_a_new_version_and_keeps_the_old_one(): void
    {
        $contract = $this->draft(['body' => '<p>Version one.</p>']);

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body'        => '<p>Version two.</p>',
                'change_note' => 'Removed clause 7, no plant on this package.',
            ])->assertRedirect();

        $fresh = $this->asCompany($this->company, fn () => $contract->fresh());

        $this->assertSame(2, $fresh->current_version);

        $versions = $this->asCompany($this->company, fn () => $fresh->versions()->get());

        $this->assertCount(2, $versions);
        $this->assertSame('<p>Version one.</p>', $versions->firstWhere('version_no', 1)->body);
        $this->assertSame(
            'Removed clause 7, no plant on this package.',
            $versions->firstWhere('version_no', 2)->change_note,
        );
    }

    /** A version history whose entries cannot say what changed is a list of timestamps. */
    public function test_an_amendment_must_say_what_changed(): void
    {
        $contract = $this->draft();

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body' => '<p>Changed.</p>',
            ])->assertSessionHasErrors('change_note');
    }

    /**
     * The negotiation loop, and the reason the version hash exists.
     *
     * Amending a contract that is out for signature must not carry a signature forward onto wording
     * the signer never read.
     */
    public function test_amending_withdraws_signatures_already_requested(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft(['body' => '<p>One.</p>']));

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body'        => '<p>Two.</p>',
                'change_note' => 'Rate renegotiated.',
            ])->assertRedirect();

        $fresh = $this->asCompany($this->company, fn () => $contract->fresh());

        $this->assertSame('draft', $fresh->status, 'An amended contract is no longer approved.');
        $this->assertSame(
            0,
            $this->asCompany($this->company, fn () => ContractSignature::where('contract_id', $contract->id)
                ->where('status', 'pending')->count()),
        );
    }

    /** A signature given against v1 must read as stale once v2 lands. */
    public function test_a_signature_is_marked_stale_when_the_wording_changes_after_it(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft(['body' => '<p>One.</p>']));

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Sizwe Ndlovu',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body'        => '<p>Two.</p>',
                'change_note' => 'Changed after they signed.',
            ])->assertRedirect();

        $this->actingAs($this->manager)
            ->get(route('project-management.contracts.show', $contract->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // The current round is the first; a stale signature is one given against wording
                // that has changed since.
                ->where('signatureRounds', fn ($rounds) => collect($rounds[0]['signatures'])
                    ->firstWhere('status', 'signed')['stale'] === true));
    }

    // ── Review ──────────────────────────────────────────────────────────────

    /** A company that designed no flow signs directly. That is their stated position, not a bypass. */
    public function test_with_no_flow_designed_a_contract_goes_straight_to_approved(): void
    {
        $contract = $this->draft();

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.submit', $contract->uuid))
            ->assertRedirect();

        $this->assertSame('approved', $this->asCompany($this->company, fn () => $contract->fresh()->status));
    }

    /** With steps designed in PM Setup, the same contract queues instead. */
    public function test_with_a_flow_designed_a_contract_goes_to_review(): void
    {
        $this->asCompany($this->company, function () {
            $flow = ApprovalFlow::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $this->company->id,
                'name' => 'Contracts',
                'subject_type' => 'contract',
                'is_active' => true,
            ]);

            ApprovalStep::create([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'flow_id' => $flow->id,
                'position' => 1,
                'name' => 'Director',
                'min_approvals' => 1,
                // Not null on this table: a step has to say who it routes to.
                'approver_type' => 'any_manager',
            ]);
        });

        $contract = $this->draft();

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.submit', $contract->uuid))
            ->assertRedirect();

        $this->assertSame('in_review', $this->asCompany($this->company, fn () => $contract->fresh()->status));

        $this->assertTrue(
            $this->asCompany($this->company, fn () => ApprovalRequest::where('subject_type', 'contract')
                ->where('subject_id', $contract->id)->exists()),
        );
    }

    // ── Signature ───────────────────────────────────────────────────────────

    public function test_the_nominated_signatory_is_notified(): void
    {
        Notification::fake();

        $director = User::factory()->create([
            'company_id' => $this->company->id,
            'email'      => 'director@aseda.co.za',
            'status'     => 1,
        ]);

        $this->sendForSignature($this->draft(), $director);

        Notification::assertSentTo($director, ContractAwaitingSignatureNotification::class);
    }

    /**
     * Order is enforced, not merely recorded.
     *
     * E-Sign stores `signing_order` and then mails everybody at once, so either party can go first.
     * A contract we signed before the contractor did is an offer, not an agreement.
     */
    public function test_we_cannot_countersign_before_the_contractor_has_signed(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Thabo Mokoena',
            ])->assertSessionHasErrors('contract');

        $this->assertSame(
            'out_for_signature',
            $this->asCompany($this->company, fn () => $contract->fresh()->status),
        );
    }

    public function test_both_signatures_complete_the_contract(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Sizwe Ndlovu',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(
            'out_for_signature',
            $this->asCompany($this->company, fn () => $contract->fresh()->status),
            'One signature of two does not complete it.',
        );

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Thabo Mokoena',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('signed', $this->asCompany($this->company, fn () => $contract->fresh()->status));
    }

    /** Declining ends the round for everybody, with a reason the other side can act on. */
    public function test_a_contractor_can_decline_with_a_reason(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.decline', $contract->uuid), [
                'reason' => 'Clause 7 obliges plant we do not supply.',
            ])->assertSessionHasNoErrors()->assertRedirect();
        $sigs = $this->asCompany($this->company, fn () => \App\Modules\Operations\ProjectManagement\Models\ContractSignature::where('contract_id', $contract->id)->get());
        $fresh = $this->asCompany($this->company, fn () => $contract->fresh());

        $this->assertSame('declined', $fresh->status);
        $this->assertSame(
            0,
            $this->asCompany($this->company, fn () => ContractSignature::where('contract_id', $contract->id)
                ->where('status', 'pending')->count()),
        );
    }

    /** Somebody with no signature pending cannot sign by knowing the contract's uuid. */
    public function test_a_stranger_cannot_sign(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Not Me',
            ])->assertStatus(403);
    }

    // ── Cost gate ───────────────────────────────────────────────────────────

    public function test_the_value_is_hidden_from_somebody_who_may_not_see_cost(): void
    {
        $contract = $this->draft();

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.contracts.show', $contract->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('contract.value', null)->where('costs', false));
    }

    // ── Injection ───────────────────────────────────────────────────────────

    /**
     * A contractor's own name is text they chose, and a template body is HTML.
     *
     * Escaped on write and sanitised on render — either alone leaves a hole.
     */
    public function test_a_contractor_cannot_inject_markup_through_their_name(): void
    {
        $this->asCompany($this->company, fn () => $this->contractor->forceFill([
            'display_name' => '<script>alert(1)</script>Sizwe',
        ])->save());

        $template = $this->template('<p>Between {{contractor_name}} and us.</p>');
        $contract = $this->draft(['template' => $template->uuid]);

        $body = $this->asCompany($this->company, fn () => $contract->currentVersion()->body);

        $this->assertStringNotContainsString('<script>', (string) $body);
        $this->assertStringContainsString('Sizwe', (string) $body);
    }

    // ── The portal is the only screen an outside party gets ─────────────────

    /**
     * A subcontractor must not land on the company's own dashboard.
     *
     * That page carries headcount, leave, assets and the CEO's message — the shape of the business
     * a third party invoices. They are sent to their portal instead, which is the one screen that
     * is actually theirs.
     */
    public function test_a_subcontractor_is_sent_to_the_portal_rather_than_the_dashboard(): void
    {
        // Asserted on the rule rather than on GET '/', because that route belongs to another
        // module and is gated independently — a 403 there would pass a redirect assertion for
        // entirely the wrong reason.
        $evaluator = app(\App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator::class);

        $this->assertTrue(
            $this->asCompany($this->company, fn () => $evaluator->isExternalPartyOnly($this->contractorUser)),
            'A subcontractor must be treated as an outside party.',
        );
    }

    /** Staff who also happen to sit on a project keep their dashboard. */
    public function test_a_manager_on_a_project_is_not_treated_as_an_outside_party(): void
    {
        $this->asCompany($this->company, fn () => ProjectMember::create([
            'project_id'   => $this->project->id,
            'user_id'      => $this->manager->id,
            'role_key'     => 'contractor',
            'display_name' => 'Also a member',
            'is_active'    => true,
        ]));

        $evaluator = app(\App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator::class);

        // Staff who happen to sit on a job keep their own module. Membership alone must not
        // demote somebody to a read-only portal.
        $this->assertFalse(
            $this->asCompany($this->company, fn () => $evaluator->isExternalPartyOnly($this->manager)),
        );
    }

    /** Their contract reaches the portal, flagged when it is their turn to sign. */
    public function test_the_portal_shows_a_contract_awaiting_their_signature(): void
    {
        Notification::fake();

        $this->sendForSignature($this->draft());

        $this->actingAs($this->contractorUser)
            ->get(route('project-management.portal'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('projects.0.contracts', fn ($c) => count($c) === 1
                    && $c[0]['awaiting_me'] === true));
    }

    /**
     * A contract is negotiated, and the other side is half of that negotiation.
     *
     * The contractor may revise their own contract. The amendment behaves like any other — a new
     * version, and the signatures already given are withdrawn — so nothing is agreed behind
     * anybody's back, which is what makes it safe to allow.
     */
    public function test_a_contractor_can_propose_a_change_to_their_own_contract(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft(['body' => '<p>Clause 7 applies.</p>']));

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body'        => '<p>Clause 7 struck.</p>',
                'change_note' => 'We do not supply plant on this package.',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $this->asCompany($this->company, fn () => $contract->fresh());

        $this->assertSame(2, $fresh->current_version);
        $this->assertSame('draft', $fresh->status, 'An amended contract is no longer out for signature.');
        $this->assertSame(
            0,
            $this->asCompany($this->company, fn () => ContractSignature::where('contract_id', $contract->id)
                ->where('status', 'pending')->count()),
            'Amending must withdraw the signatures already requested.',
        );
    }

    /** Sending and submitting stay with the practice — those are ours to decide. */
    public function test_a_contractor_cannot_send_or_submit_the_contract(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.submit', $contract->uuid))
            ->assertStatus(403);

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.send', $contract->uuid), [
                'signatory_user_id' => $this->manager->id,
            ])->assertStatus(403);
    }

    /** A stranger still cannot touch the wording. */
    public function test_a_stranger_cannot_amend_a_contract(): void
    {
        $contract = $this->draft();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body'        => '<p>Rewritten.</p>',
                'change_note' => 'Not mine to change.',
            ])->assertStatus(403);
    }

    // ── Signing, and what it closes off ─────────────────────────────────────

    /** A signature is an agreement. Having given it, you do not get to rewrite the document. */
    public function test_a_contractor_cannot_amend_after_they_have_signed(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Sizwe Ndlovu',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body'        => '<p>Rewritten after signing.</p>',
                'change_note' => 'Should not be allowed.',
            ])->assertStatus(403);
    }

    /** A drawn signature is stored as the drawing, and recorded as drawn. */
    public function test_a_contract_can_be_signed_by_drawing(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        // A 1x1 PNG is enough — the shape of the value is what matters here.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Sizwe Ndlovu',
                'drawing'    => $png,
            ])->assertSessionHasNoErrors()->assertRedirect();

        $signature = $this->asCompany($this->company, fn () => ContractSignature::where('contract_id', $contract->id)
            ->where('party', 'contractor')->where('status', 'signed')->firstOrFail());

        $this->assertSame('drawn', $signature->signature_type);
        // Through signatureImage(), not the column: 8fd20c34 moved the bytes to the shared file
        // store and left this accessor as the one thing that knows where they are — it is what the
        // payload serves, so asserting the column tested the old hiding place rather than the
        // signature surviving the round trip.
        $this->assertSame($png, $this->asCompany($this->company, fn () => $signature->signatureImage()));
    }

    /** Anything that is not a PNG data URI is refused rather than stored and later rendered. */
    public function test_a_signature_image_that_is_not_a_png_is_refused(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.sign', $contract->uuid), [
                'typed_name' => 'Sizwe Ndlovu',
                'drawing'    => '<svg onload="alert(1)"></svg>',
            ])->assertSessionHasErrors('contract');
    }

    // ── Reopening a signed contract ─────────────────────────────────────────

    /**
     * A signed contract is closed to everybody, and reopening tells every party who signed.
     */
    public function test_a_signed_contract_can_only_be_reopened_by_the_practice_and_notifies_everybody(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        foreach ([[$this->contractorUser, 'Sizwe Ndlovu'], [$this->manager, 'Thabo Mokoena']] as [$user, $name]) {
            $this->actingAs($user)
                ->post(route('project-management.contracts.sign', $contract->uuid), ['typed_name' => $name])
                ->assertSessionHasNoErrors()->assertRedirect();
        }

        $this->assertSame('signed', $this->asCompany($this->company, fn () => $contract->fresh()->status));

        // Neither side may amend it now.
        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body' => '<p>No.</p>', 'change_note' => 'Should be refused.',
            ])->assertStatus(403);

        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.amend', $contract->uuid), [
                'body' => '<p>No.</p>', 'change_note' => 'Should be refused.',
            ])->assertSessionHasErrors('contract');

        // The practice reopens it, and everybody who signed is told.
        $this->actingAs($this->manager)
            ->post(route('project-management.contracts.reopen', $contract->uuid), [
                'reason' => 'The rate changed after the pre-start meeting.',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $fresh = $this->asCompany($this->company, fn () => $contract->fresh());

        $this->assertSame('draft', $fresh->status, 'A reopened contract goes back to draft.');

        Notification::assertSentTo($this->contractorUser, ContractReopenedNotification::class);
        Notification::assertSentTo($this->manager, ContractReopenedNotification::class);
    }

    /** The other party cannot reopen it — that decision is the practice's. */
    public function test_a_contractor_cannot_reopen_a_signed_contract(): void
    {
        Notification::fake();

        $contract = $this->sendForSignature($this->draft());

        foreach ([[$this->contractorUser, 'Sizwe'], [$this->manager, 'Thabo']] as [$user, $name]) {
            $this->actingAs($user)
                ->post(route('project-management.contracts.sign', $contract->uuid), ['typed_name' => $name])
                ->assertRedirect();
        }

        $this->actingAs($this->contractorUser)
            ->post(route('project-management.contracts.reopen', $contract->uuid), [
                'reason' => 'I would like to change it.',
            ])->assertStatus(403);
    }

    #[DataProvider('dangerousBodies')]
    public function test_the_sanitiser_neutralises_dangerous_markup(string $body, string $mustNotContain): void
    {
        $this->assertStringNotContainsString($mustNotContain, (string) ContractBody::sanitize($body));
    }

    public static function dangerousBodies(): array
    {
        return [
            'script tag'      => ['<script>alert(1)</script><p>ok</p>', 'alert(1)'],
            'event handler'   => ['<p onclick="alert(1)">ok</p>', 'onclick'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', 'javascript'],
            'obfuscated href' => ["<a href=\"java\tscript:alert(1)\">x</a>", 'script'],
            'data href'       => ['<a href="data:text/html,x">x</a>', 'data:'],
            'iframe'          => ['<iframe src="//evil.test"></iframe>', 'iframe'],
            'inline style'    => ['<p style="position:fixed">x</p>', 'style'],
        ];
    }
}
