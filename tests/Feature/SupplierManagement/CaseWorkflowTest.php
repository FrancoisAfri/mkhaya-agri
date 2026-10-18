<?php

namespace Tests\Feature\SupplierManagement;

use App\Core\User\Models\User;
use App\Modules\SupplierManagement\DueDiligence\Services\Blacklist;
use App\Modules\SupplierManagement\DueDiligence\Services\CaseWorkflow;
use App\Modules\SupplierManagement\DueDiligence\Services\EddPack;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Services\RoleMap;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CaseState;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\RoleSlot;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\DueDiligenceCase;
use App\Modules\SupplierManagement\Models\DueDiligenceEddTask;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierBlacklistEntry;
use App\Modules\SupplierManagement\Models\SupplierEntity;
use App\Modules\SupplierManagement\Models\SupplierOwnership;
use App\Modules\SupplierManagement\Models\SupplierPerson;
use App\Modules\SupplierManagement\Models\SupplierSetting;
use Illuminate\Validation\ValidationException;

/**
 * Phase 4: the case, and the refusals that make an approval worth something.
 *
 * The tests that carry the weight are the ones about who may NOT do a thing. An approval workflow is
 * only as good as its refusals — a state machine that records the same yes a checkbox would have
 * recorded, but with more steps, is worse than the checkbox because it looks like a control.
 */
class CaseWorkflowTest extends SupplierTestCase
{
    private CaseWorkflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->workflow = app(CaseWorkflow::class);
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_name' => 'Zolani Civils (Pty) Ltd',
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'tax_reference_number' => '9012345678',
            'account_holder_name' => 'Zolani Civils',
            'bank_account_number' => '62012345678',
            'is_active' => 1,
        ], $attributes));
    }

    /** A second user, so separation of duties can actually be tested. */
    private function otherUser(): User
    {
        return $this->makeUser($this->companyId);
    }

    /** Point every slot at named people, so the fallback is not what is under test. */
    private function mapRoles(array $map): void
    {
        app(RoleMap::class)->save(collect($map)->map(fn ($users) => [
            'users' => collect($users)->pluck('id')->all(),
            'roles' => [],
        ])->all());
    }

    /** Everything a supplier needs for the gates to pass. */
    private function verified(Supplier $supplier): Supplier
    {
        $engine = app(VerificationEngine::class);

        foreach ([CheckType::COMPANY_REGISTRY, CheckType::COMPANY_DIRECTORS, CheckType::BANK_ACCOUNT,
            CheckType::TAX_COMPLIANCE, CheckType::TAX_VAT_VENDOR] as $check) {
            $engine->recordManual(
                supplier: $supplier, checkType: $check, status: VerificationStatus::VERIFIED,
                observed: $engine->claimsFor($supplier, $check), claimed: $engine->claimsFor($supplier, $check),
            );
        }

        $engine->recordManual($supplier, CheckType::SCREENING_SANCTIONS, VerificationStatus::VERIFIED);
        $engine->recordManual($supplier, CheckType::OWNERSHIP_BENEFICIAL, VerificationStatus::VERIFIED);

        \App\Modules\SupplierManagement\Models\SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 90, 'score_max' => 100, 'report_date' => now()->toDateString(),
        ]);

        $root = SupplierEntity::rootFor($supplier);
        $person = SupplierPerson::create([
            'supplier_id' => $supplier->id, 'first_name' => 'Sole', 'surname' => 'Owner', 'role' => 'shareholder',
        ]);
        SupplierOwnership::create([
            'supplier_id' => $supplier->id, 'holder_kind' => 'person', 'holder_id' => $person->id,
            'subject_entity_id' => $root->id, 'percentage' => 100, 'as_at' => now()->toDateString(),
        ]);

        app(RiskEngine::class)->assess($supplier->fresh());

        return $supplier->fresh();
    }

    /** Walk a case to the point of decision. */
    private function readyForDecision(Supplier $supplier): DueDiligenceCase
    {
        $case = $this->workflow->open($supplier);

        foreach (['submit', 'verify', 'screen', 'assess', 'to_procurement'] as $action) {
            $case = $this->workflow->apply($case, $action);
        }

        return $case;
    }

    // ── The rule no configuration may switch off ────────────────────────────

    public function test_the_person_who_raised_a_case_cannot_approve_it(): void
    {
        $supplier = $this->verified($this->supplier());
        $manager = $this->otherUser();

        // The raiser holds every slot, including the manager's — and still cannot approve.
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$this->user, $manager],
            RoleSlot::COMPLIANCE_OFFICER => [$this->user],
            RoleSlot::EXECUTIVE => [$this->user],
        ]);

        $case = $this->readyForDecision($supplier);

        $check = $this->workflow->check($case, 'approve', $this->user);
        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('somebody else has to decide', implode(' ', $check['reasons']));

        $this->expectException(ValidationException::class);
        $this->workflow->apply($case, 'approve', [], $this->user);
    }

    public function test_somebody_else_in_the_manager_slot_can_approve(): void
    {
        $supplier = $this->verified($this->supplier());
        $manager = $this->otherUser();

        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
            RoleSlot::COMPLIANCE_OFFICER => [$manager],
            RoleSlot::EXECUTIVE => [$manager],
        ]);

        $case = $this->readyForDecision($supplier);
        $case = $this->workflow->apply($case, 'approve', [], $manager);

        $this->assertEquals(CaseState::APPROVED, $case->state);
        $this->assertEquals($manager->id, $case->decided_by);
        // The role is captured as it was, because a person's role changes and what they held when
        // they approved a supplier does not.
        $this->assertEquals('Procurement Manager', $case->decided_role);
    }

    public function test_an_override_cannot_be_used_to_get_round_the_raiser_rule(): void
    {
        $supplier = $this->verified($this->supplier());
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$this->user],
            RoleSlot::COMPLIANCE_OFFICER => [$this->user],
            RoleSlot::EXECUTIVE => [$this->user],
        ]);

        $case = $this->readyForDecision($supplier);

        $this->expectException(ValidationException::class);
        $this->workflow->apply($case, 'approve', ['override' => true, 'reason' => 'I am in a hurry.'], $this->user);
    }

    // ── The gates ───────────────────────────────────────────────────────────

    public function test_a_supplier_whose_gates_fail_cannot_be_approved(): void
    {
        $supplier = $this->supplier();          // nothing verified
        app(RiskEngine::class)->assess($supplier);
        $manager = $this->otherUser();

        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
            RoleSlot::COMPLIANCE_OFFICER => [$manager],
            RoleSlot::EXECUTIVE => [$manager],
        ]);

        $case = $this->readyForDecision($supplier);
        $check = $this->workflow->check($case, 'approve', $manager);

        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('gates are failing', implode(' ', $check['reasons']));
    }

    public function test_approving_against_the_gates_is_possible_but_only_on_the_record(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);
        $manager = $this->otherUser();

        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
            RoleSlot::COMPLIANCE_OFFICER => [$manager],
            RoleSlot::EXECUTIVE => [$manager],
        ]);

        $case = $this->readyForDecision($supplier);

        // No reason: refused. An override with no explanation is indistinguishable from a mistake.
        try {
            $this->workflow->apply($case, 'approve', ['override' => true], $manager);
            $this->fail('An override with no reason was accepted.');
        } catch (ValidationException) {
            // expected
        }

        $case = $this->workflow->apply($case, 'approve', [
            'override' => true,
            'reason' => 'Board resolution 2026-14: single-source emergency supply, checks to follow within 14 days.',
        ], $manager);

        $this->assertEquals(CaseState::APPROVED, $case->state);

        // The override is on the file, with what was failing at the time.
        $transition = $case->transitions()->where('action', 'approve')->first();
        $this->assertTrue($transition->context['override']);
        $this->assertNotEmpty($transition->context['gates']);
        $this->assertStringContainsString('Board resolution', $transition->reason);
    }

    public function test_rejection_never_needs_an_override_but_always_needs_a_reason(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);
        $manager = $this->otherUser();

        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
            RoleSlot::COMPLIANCE_OFFICER => [$manager],
            RoleSlot::EXECUTIVE => [$manager],
        ]);

        $case = $this->readyForDecision($supplier);

        try {
            $this->workflow->apply($case, 'reject', [], $manager);
            $this->fail('A rejection with no reason was accepted.');
        } catch (ValidationException) {
            // expected
        }

        $case = $this->workflow->apply($case, 'reject', ['reason' => 'Could not verify the company at all.'], $manager);
        $this->assertEquals(CaseState::REJECTED, $case->state);
    }

    // ── Seniority and slots ─────────────────────────────────────────────────

    public function test_a_senior_slot_can_do_the_work_of_a_junior_one(): void
    {
        $executive = $this->otherUser();
        $this->mapRoles([RoleSlot::EXECUTIVE => [$executive]]);

        $roles = app(RoleMap::class);

        $this->assertTrue($roles->can($executive, RoleSlot::PROCUREMENT_OFFICER));
        $this->assertTrue($roles->can($executive, RoleSlot::COMPLIANCE_OFFICER));
        // …but not the other way round.
        $this->assertFalse($roles->can($executive, 'not_a_slot'));
    }

    public function test_a_junior_slot_cannot_do_a_senior_ones_work(): void
    {
        $supplier = $this->verified($this->supplier());
        $officer = $this->otherUser();

        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user, $officer],
            RoleSlot::PROCUREMENT_MANAGER => [$this->otherUser()],
            RoleSlot::COMPLIANCE_OFFICER => [$this->otherUser()],
            RoleSlot::EXECUTIVE => [$this->otherUser()],
        ]);

        $case = $this->readyForDecision($supplier);
        $check = $this->workflow->check($case, 'approve', $officer);

        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('Procurement Manager', implode(' ', $check['reasons']));
    }

    public function test_an_unconfigured_tenant_can_still_work_cases(): void
    {
        // No role map at all. A workflow nobody can advance is one that gets bypassed by the second
        // day, so the fallback is the module's existing approvers.
        SupplierSetting::query()->firstOrCreate([])->update(['dd_roles' => null]);

        $supplier = $this->verified($this->supplier());
        $case = $this->readyForDecision($supplier);

        $other = $this->otherUser();
        $this->assertTrue(app(RoleMap::class)->can($other, RoleSlot::PROCUREMENT_MANAGER));

        $case = $this->workflow->apply($case, 'approve', [], $other);
        $this->assertEquals(CaseState::APPROVED, $case->state);
    }

    public function test_the_settings_screen_says_which_slots_are_only_falling_back(): void
    {
        $this->mapRoles([RoleSlot::EXECUTIVE => [$this->user]]);

        $config = collect(app(RoleMap::class)->configuration())->keyBy('slot');

        // A slot silently falling back is how a client believes it has segregation it does not have.
        $this->assertFalse($config[RoleSlot::EXECUTIVE]['is_fallback']);
        $this->assertTrue($config[RoleSlot::COMPLIANCE_OFFICER]['is_fallback']);
    }

    // ── The machine itself ──────────────────────────────────────────────────

    public function test_a_case_cannot_skip_from_draft_to_approved(): void
    {
        $supplier = $this->verified($this->supplier());
        $manager = $this->otherUser();
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
        ]);

        $case = $this->workflow->open($supplier);
        $check = $this->workflow->check($case, 'approve', $manager);

        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('cannot be', implode(' ', $check['reasons']));
    }

    public function test_nothing_moves_out_of_a_terminal_state(): void
    {
        $supplier = $this->verified($this->supplier());
        $manager = $this->otherUser();
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
            RoleSlot::COMPLIANCE_OFFICER => [$manager],
            RoleSlot::EXECUTIVE => [$manager],
        ]);

        $case = $this->readyForDecision($supplier);
        $case = $this->workflow->apply($case, 'reject', ['reason' => 'Not proceeding.'], $manager);

        // A rejected case stays rejected: the original decision has to stay legible rather than be
        // edited into something else.
        $check = $this->workflow->check($case, 'approve', $manager);
        $this->assertFalse($check['allowed']);
        $this->assertStringContainsString('Raise a new case', implode(' ', $check['reasons']));
    }

    public function test_opening_a_case_twice_returns_the_same_file(): void
    {
        $supplier = $this->supplier();

        $first = $this->workflow->open($supplier);
        $second = $this->workflow->open($supplier);

        // Two files for one engagement would split the evidence between them.
        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, DueDiligenceCase::where('supplier_id', $supplier->id)->count());
    }

    public function test_every_move_is_recorded_with_who_made_it_and_in_what_capacity(): void
    {
        $supplier = $this->verified($this->supplier());
        $manager = $this->otherUser();
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
            RoleSlot::COMPLIANCE_OFFICER => [$manager],
            RoleSlot::EXECUTIVE => [$manager],
        ]);

        $case = $this->readyForDecision($supplier);
        $this->workflow->apply($case, 'approve', [], $manager);

        $transitions = $case->fresh()->transitions;

        $this->assertGreaterThanOrEqual(6, $transitions->count());
        $approval = $transitions->firstWhere('action', 'approve');
        $this->assertEquals($manager->id, $approval->actor_id);
        $this->assertEquals(RoleSlot::PROCUREMENT_MANAGER, $approval->actor_slot);
        $this->assertStringContainsString('Approve', $approval->summary());

        // And it reaches the evidence chain, which is where "on what basis" is answered.
        $this->assertDatabaseHas('dd_evidence_entries', [
            'company_id' => $this->companyId,
            'action' => 'case.approve',
            'supplier_id' => $supplier->id,
        ]);
    }

    public function test_a_conditional_approval_must_state_its_conditions_and_a_deadline(): void
    {
        $supplier = $this->verified($this->supplier());
        $manager = $this->otherUser();
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$manager],
            RoleSlot::COMPLIANCE_OFFICER => [$manager],
            RoleSlot::EXECUTIVE => [$manager],
        ]);

        $case = $this->readyForDecision($supplier);

        try {
            $this->workflow->apply($case, 'approve_conditionally', ['reason' => 'Pending insurance.'], $manager);
            $this->fail('A conditional approval with no conditions was accepted.');
        } catch (ValidationException) {
            // expected
        }

        $case = $this->workflow->apply($case, 'approve_conditionally', [
            'reason' => 'Insurance certificate outstanding.',
            'conditions' => ['Public liability certificate on file'],
            'conditions_due' => now()->addDays(14)->toDateString(),
        ], $manager);

        $this->assertEquals(CaseState::CONDITIONAL_APPROVAL, $case->state);
        // An "approved subject to" with no deadline is an approval.
        $this->assertNotNull($case->conditions_due);
        $this->assertFalse($case->conditionsLapsed());

        $case->forceFill(['conditions_due' => now()->subDay()->toDateString()])->save();
        $this->assertTrue($case->fresh()->conditionsLapsed());
    }

    // ── Enhanced due diligence ──────────────────────────────────────────────

    public function test_the_edd_pack_is_built_from_what_actually_triggered_it(): void
    {
        $supplier = $this->verified($this->supplier());

        // A bank mismatch — a specific trigger with specific consequences.
        app(VerificationEngine::class)->recordManual(
            supplier: $supplier, checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::MISMATCH, observed: ['account_holder_name' => 'Someone Else'],
        );
        app(RiskEngine::class)->assess($supplier->fresh());

        $case = $this->workflow->open($supplier->fresh());
        $added = app(EddPack::class)->generate($case);

        $this->assertGreaterThan(0, $added);

        $codes = $case->eddTasks()->pluck('code');
        $this->assertContains('bank_letter', $codes);
        // …and not the eleven-item boilerplate every high-risk supplier would otherwise get.
        $this->assertNotContains('subcontractor_disclosure', $codes);

        // The task says why it was demanded, so the supplier can be told.
        $task = $case->eddTasks()->where('code', 'bank_letter')->first();
        $this->assertStringContainsString('bank account holder', mb_strtolower($task->why));
    }

    public function test_a_case_cannot_leave_edd_with_required_items_outstanding(): void
    {
        $supplier = $this->verified($this->supplier());
        $compliance = $this->otherUser();
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$compliance],
            RoleSlot::COMPLIANCE_OFFICER => [$compliance],
            RoleSlot::EXECUTIVE => [$compliance],
        ]);

        $case = $this->workflow->open($supplier);
        foreach (['submit', 'verify', 'screen', 'assess'] as $action) {
            $case = $this->workflow->apply($case, $action);
        }
        $case = $this->workflow->apply($case, 'require_edd', ['reason' => 'High risk.'], $compliance);

        DueDiligenceEddTask::create([
            'case_id' => $case->id, 'supplier_id' => $supplier->id,
            'code' => 'financial_statements', 'label' => 'Financial statements',
            'is_required' => true, 'status' => DueDiligenceEddTask::STATUS_OUTSTANDING,
        ]);

        $check = $this->workflow->check($case, 'to_procurement', $compliance);
        $this->assertFalse($check['allowed'], 'A case left EDD with the pack unfinished.');
        $this->assertStringContainsString('outstanding', implode(' ', $check['reasons']));
    }

    public function test_waiving_an_edd_item_requires_a_reason(): void
    {
        $supplier = $this->supplier();
        $case = $this->workflow->open($supplier);

        $task = DueDiligenceEddTask::create([
            'case_id' => $case->id, 'supplier_id' => $supplier->id,
            'code' => 'site_visit', 'label' => 'Site visit',
            'is_required' => true, 'status' => DueDiligenceEddTask::STATUS_OUTSTANDING,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(EddPack::class)->settle($task, DueDiligenceEddTask::STATUS_WAIVED, '   ');
    }

    // ── Blacklist ───────────────────────────────────────────────────────────

    public function test_a_barred_company_is_caught_by_registration_number_and_by_name(): void
    {
        $blacklist = app(Blacklist::class);

        $blacklist->add([
            'entity_name' => 'Ghost Trading (Pty) Ltd',
            'registration_number' => '2015/000111/07',
            'reason' => 'Invoiced for undelivered goods; matter with the SAPS.',
        ]);

        // Same company, name written differently.
        $byName = $blacklist->checkDetails('GHOST TRADING PROPRIETARY LIMITED');
        $this->assertTrue($byName['blocked']);

        // Same registration number, renamed.
        $byNumber = $blacklist->checkDetails('Completely New Name', '2015/000111/07');
        $this->assertTrue($byNumber['blocked']);
        $this->assertEquals('registration_number', $byNumber['matches'][0]['kind']);

        $unrelated = $blacklist->checkDetails('Some Other Company', '2020/999888/07');
        $this->assertFalse($unrelated['blocked']);
    }

    public function test_a_barred_person_is_caught_on_a_newly_registered_company(): void
    {
        // The oldest trick there is: deregister, register again next week, same people.
        app(Blacklist::class)->add([
            'entity_name' => 'Sipho Ndlovu',
            'scope' => SupplierBlacklistEntry::SCOPE_PERSON,
            'identifier' => '8501015800085',
            'reason' => 'Director of two suppliers found invoicing for undelivered work.',
        ]);

        $newCompany = $this->supplier(['company_name' => 'Brand New Civils (Pty) Ltd']);
        SupplierPerson::create([
            'supplier_id' => $newCompany->id, 'first_name' => 'Sipho', 'surname' => 'Ndlovu',
            'role' => 'director', 'id_number' => '8501015800085',
        ]);

        $result = app(Blacklist::class)->check($newCompany);

        $this->assertTrue($result['blocked'], 'A barred director walked in on a new registration.');
        $this->assertEquals('person', $result['matches'][0]['kind']);
        $this->assertStringContainsString('identity number matches', $result['matches'][0]['matched']);
    }

    public function test_a_barred_parent_in_the_ownership_chain_is_caught(): void
    {
        app(Blacklist::class)->add([
            'entity_name' => 'Opaque Holdings (Pty) Ltd',
            'reason' => 'Parent of two blacklisted suppliers.',
        ]);

        $supplier = $this->supplier(['company_name' => 'Innocent Sounding Trading']);
        SupplierEntity::rootFor($supplier);
        SupplierEntity::create([
            'supplier_id' => $supplier->id, 'name' => 'OPAQUE HOLDINGS PROPRIETARY LIMITED', 'source' => 'supplier',
        ]);

        $result = app(Blacklist::class)->check($supplier);

        $this->assertTrue($result['blocked']);
        $this->assertEquals('ownership_chain', $result['matches'][0]['kind']);
    }

    public function test_an_identifier_is_never_stored_in_the_clear(): void
    {
        app(Blacklist::class)->add([
            'entity_name' => 'Sipho Ndlovu',
            'scope' => SupplierBlacklistEntry::SCOPE_PERSON,
            'identifier' => '8501015800085',
            'reason' => 'Barred.',
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('supplier_blacklist')->first();

        // Matching needs "same number", not the number — and a blacklist of ID numbers in plaintext
        // is a POPIA incident waiting for a database dump.
        $this->assertStringNotContainsString('8501015800085', json_encode($raw));
        $this->assertNotNull($raw->person_identifier_hash);
    }

    public function test_a_lifted_bar_stops_matching_but_stays_on_record(): void
    {
        $entry = app(Blacklist::class)->add([
            'entity_name' => 'Rehabilitated Trading',
            'reason' => 'Historic non-delivery.',
        ]);

        $this->assertTrue(app(Blacklist::class)->checkDetails('Rehabilitated Trading')['blocked']);

        $entry->lift($this->user->id, 'Matter settled; three years of clean delivery since.');

        $this->assertFalse(app(Blacklist::class)->checkDetails('Rehabilitated Trading')['blocked']);
        // The history survives — a bar that vanishes takes the reason with it.
        $this->assertDatabaseHas('supplier_blacklist', ['id' => $entry->id, 'is_active' => false]);
    }

    public function test_an_expired_fixed_term_bar_stops_matching_on_its_own(): void
    {
        app(Blacklist::class)->add([
            'entity_name' => 'Two Year Ban Trading',
            'reason' => 'Two-year exclusion.',
            'severity' => 'fixed_term',
            'expires_on' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse(app(Blacklist::class)->checkDetails('Two Year Ban Trading')['blocked'],
            'A fixed-term bar outlived its own expiry date.');
    }

    public function test_cases_and_the_blacklist_are_tenant_scoped(): void
    {
        $supplier = $this->supplier();
        $this->workflow->open($supplier);
        app(Blacklist::class)->add(['entity_name' => 'Barred Co', 'reason' => 'Because.']);

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->makeCompany('Other Tenant'));

        $this->assertEquals(0, DueDiligenceCase::count());
        $this->assertEquals(0, SupplierBlacklistEntry::count());
    }

    // ── The Passport ────────────────────────────────────────────────────────

    public function test_the_passport_shows_never_checked_as_never_checked(): void
    {
        $supplier = $this->supplier();
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}/passport")
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $tiles = collect($props['tiles']);

                // A supplier with nothing done shows honest gaps, not a clean sheet.
                $this->assertTrue($tiles->every(fn ($t) => in_array($t['state'], ['unchecked', 'problem', 'clear'], true)));
                $this->assertGreaterThan(5, $tiles->where('state', 'unchecked')->count());
                $this->assertNull($props['risk']);
                $this->assertNull($props['ddCase']);
            });
    }

    public function test_a_verified_supplier_shows_verified_tiles_with_their_age(): void
    {
        $supplier = $this->verified($this->supplier());
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}/passport")
            ->assertOk()
            ->assertInertia(function ($page) {
                $tiles = collect($page->toArray()['props']['tiles'])->keyBy('key');

                $this->assertEquals('clear', $tiles[CheckType::COMPANY_REGISTRY]['state']);
                $this->assertEquals('clear', $tiles['banking']['state']);
                // Every tile carries the two facts that make it trustworthy: what and how long ago.
                $this->assertNotNull($tiles[CheckType::COMPANY_REGISTRY]['as_at']);
                $this->assertIsInt($tiles[CheckType::COMPANY_REGISTRY]['age_days']);
            });
    }

    public function test_a_confirmed_pep_is_a_review_not_a_problem(): void
    {
        $supplier = $this->verified($this->supplier());
        \App\Modules\SupplierManagement\Models\ScreeningHit::create([
            'supplier_id' => $supplier->id, 'subject_type' => 'person', 'subject_name' => 'A Director',
            'list_type' => \App\Modules\SupplierManagement\Models\ScreeningHit::TYPE_PEP,
            'matched_name' => 'A Director', 'match_score' => 95, 'provider_code' => 'manual',
            'status' => \App\Modules\SupplierManagement\Models\ScreeningHit::STATUS_CONFIRMED,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}/passport")->assertInertia(function ($page) {
            $tiles = collect($page->toArray()['props']['tiles'])->keyBy('key');

            // Being politically exposed is a reason for more diligence, not a finding of wrongdoing —
            // colouring it red teaches people to dismiss PEP hits on sight.
            $this->assertEquals('review', $tiles['pep']['state']);
        });
    }

    public function test_the_passport_leads_with_a_blacklist_match(): void
    {
        $supplier = $this->supplier(['company_name' => 'Barred Trading (Pty) Ltd']);
        app(Blacklist::class)->add(['entity_name' => 'BARRED TRADING PROPRIETARY LIMITED', 'reason' => 'Invoice fraud.']);
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}/passport")->assertInertia(function ($page) {
            $blacklist = $page->toArray()['props']['blacklist'];

            $this->assertTrue($blacklist['blocked']);
            $this->assertStringContainsString('Invoice fraud', $blacklist['matches'][0]['reason']);
        });
    }

    public function test_the_passport_offers_the_moves_and_says_why_the_others_are_closed(): void
    {
        $supplier = $this->verified($this->supplier());
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$this->otherUser()],
        ]);
        $case = $this->readyForDecision($supplier);
        $this->withoutVite();

        $this->get("/suppliers/{$supplier->uuid}/passport")->assertInertia(function ($page) {
            $actions = collect($page->toArray()['props']['ddCase']['actions'])->keyBy('action');

            $approve = $actions['approve'];
            $this->assertFalse($approve['allowed']);
            // A disabled button with no explanation is how a workflow gets reported as broken.
            $this->assertNotEmpty($approve['reasons']);
        });
    }

    public function test_a_case_can_be_opened_and_moved_through_the_route(): void
    {
        $supplier = $this->verified($this->supplier());

        $this->post("/suppliers/{$supplier->uuid}/case")->assertRedirect()->assertSessionHasNoErrors();

        $case = DueDiligenceCase::openFor($supplier->id);
        $this->assertNotNull($case);

        $this->post("/suppliers/{$supplier->uuid}/case/{$case->id}/move", ['action' => 'submit'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(CaseState::SUBMITTED, $case->fresh()->state);
    }

    public function test_entering_edd_through_the_route_builds_the_pack(): void
    {
        $supplier = $this->verified($this->supplier());
        app(VerificationEngine::class)->recordManual(
            supplier: $supplier, checkType: CheckType::BANK_ACCOUNT,
            status: VerificationStatus::MISMATCH, observed: ['account_holder_name' => 'Someone Else'],
        );
        app(RiskEngine::class)->assess($supplier->fresh());

        $compliance = $this->otherUser();
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::COMPLIANCE_OFFICER => [$compliance],
        ]);

        $case = $this->workflow->open($supplier->fresh());
        foreach (['submit', 'verify', 'screen', 'assess'] as $action) {
            $case = $this->workflow->apply($case, $action);
        }

        $this->actingAs($compliance)
            ->post("/suppliers/{$supplier->uuid}/case/{$case->id}/move", [
                'action' => 'require_edd', 'reason' => 'Bank account holder does not match.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertGreaterThan(0, $case->fresh()->eddTasks()->count(),
            'Entering EDD did not build the pack from what triggered it.');
    }

    public function test_blacklisting_needs_the_executive_slot(): void
    {
        $supplier = $this->supplier();
        $other = $this->otherUser();

        // Somebody who is not in the Executive slot.
        $this->mapRoles([
            RoleSlot::PROCUREMENT_OFFICER => [$this->user],
            RoleSlot::PROCUREMENT_MANAGER => [$this->user],
            RoleSlot::COMPLIANCE_OFFICER => [$this->user],
            RoleSlot::EXECUTIVE => [$other],
        ]);

        $this->post("/suppliers/{$supplier->uuid}/blacklist", [
            'scope' => 'entity', 'entity_name' => $supplier->company_name, 'reason' => 'Because I said so.',
        ])->assertSessionHasErrors('reason');

        $this->assertEquals(0, SupplierBlacklistEntry::count());

        $this->actingAs($other)
            ->post("/suppliers/{$supplier->uuid}/blacklist", [
                'scope' => 'entity', 'entity_name' => $supplier->company_name,
                'reason' => 'Invoiced for undelivered goods; matter with the SAPS.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals(1, SupplierBlacklistEntry::count());
    }

    public function test_a_blacklisted_party_cannot_be_onboarded_at_all(): void
    {
        app(Blacklist::class)->add([
            'entity_name' => 'Ghost Trading (Pty) Ltd',
            'registration_number' => '2015/000111/07',
            'reason' => 'Invoiced for undelivered goods.',
        ]);

        // The point of a bar is to stop the application, not to annotate a record that already exists.
        $this->post('/suppliers', [
            'company_name' => 'GHOST TRADING PROPRIETARY LIMITED',
            'company_registration_number' => '2015/000111/07',
            'email' => 'new@ghost.test',
        ])->assertSessionHasErrors('company_name');

        $this->assertEquals(0, Supplier::where('company_name', 'like', 'GHOST%')->count());
    }

    public function test_a_barred_director_on_a_new_company_is_caught_at_onboarding(): void
    {
        app(Blacklist::class)->add([
            'entity_name' => 'Sipho Ndlovu',
            'scope' => SupplierBlacklistEntry::SCOPE_PERSON,
            'reason' => 'Director of two suppliers found invoicing for undelivered work.',
        ]);

        // Deregister, register again next week, same people — the move the bar exists to stop.
        $this->post('/suppliers', [
            'company_name' => 'Brand New Civils (Pty) Ltd',
            'company_registration_number' => '2026/'.random_int(100000, 999999).'/07',
            'email' => 'hello@brandnew.test',
            'directors' => [['first_name' => 'Sipho', 'surname' => 'Ndlovu']],
        ])->assertSessionHasErrors('company_name');
    }

    public function test_onboarding_is_untouched_for_a_tenant_without_the_addon(): void
    {
        app(Blacklist::class)->add(['entity_name' => 'Ghost Trading', 'reason' => 'Barred.']);

        \Illuminate\Support\Facades\DB::table('company_modules')
            ->where('company_id', $this->companyId)
            ->where('module_code', 'supplier_due_diligence')
            ->delete();

        // A tenant without the add-on has no blacklist, and must not have onboarding slowed by a
        // check that can never match.
        $this->post('/suppliers', [
            'company_name' => 'Ghost Trading',
            'company_registration_number' => '2026/'.random_int(100000, 999999).'/07',
            'email' => 'ghost@example.test',
        ])->assertSessionHasNoErrors();
    }
}
