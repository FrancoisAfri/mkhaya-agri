<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\BoqItem;
use App\Modules\Operations\ProjectManagement\Models\ProgressClaim;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\SiteDiary;
use App\Modules\Operations\ProjectManagement\Models\Snag;
use App\Modules\Operations\ProjectManagement\Services\DeliveryPackService;
use App\Modules\Operations\ProjectManagement\Services\EarnedValueService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;

/**
 * The site pack: diary, bill of quantities, snags and payment certificates (EPIC-100).
 *
 * The arithmetic is what these tests are for. Every figure on a payment certificate is somebody's
 * money, and the mistakes are the quiet ones — deducting retention twice, certifying the same
 * milestone on two claims, counting a provisional sum as work done. None of those throw.
 */
class DeliveryPackTest extends ProjectManagementTestCase
{
    private Project $project;

    private DeliveryPackService $pack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['budget_amount' => 1000000]);
        $this->pack = app(DeliveryPackService::class);
        $this->actingAs($this->manager);
    }

    // ── Site diary ───────────────────────────────────────────────────────────

    public function test_one_diary_per_day_no_matter_how_often_it_is_edited(): void
    {
        $this->pack->recordDiary($this->project, ['entry_date' => now()->toDateString(), 'work_done' => 'Excavation']);
        $this->pack->recordDiary($this->project, ['entry_date' => now()->toDateString(), 'deliveries' => 'Rebar']);

        // Two accounts of the same day is two versions of what happened, and a delay claim built on
        // the wrong one falls apart.
        $this->assertSame(1, SiteDiary::where('project_id', $this->project->id)->count());

        $diary = SiteDiary::first();
        $this->assertSame('Excavation', $diary->work_done);
        $this->assertSame('Rebar', $diary->deliveries);
    }

    public function test_a_diary_cannot_be_written_for_a_day_that_has_not_happened(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pack->recordDiary($this->project, ['entry_date' => now()->addDay()->toDateString()]);
    }

    /** A rained-off day is the evidence behind an extension of time, so it reaches the ledger. */
    public function test_a_disrupted_day_is_recorded_as_evidence(): void
    {
        $this->pack->recordDiary($this->project, [
            'entry_date' => now()->subDay()->toDateString(),
            'weather'    => 'heavy_rain',
            'rain_hours' => 6,
            'delays'     => 'No concrete pour, site flooded.',
        ]);

        $this->assertDatabaseHas('pm_evidence_events', [
            'project_id' => $this->project->id,
            'event_type' => 'site.visit',
        ]);
    }

    // ── Bill of quantities ───────────────────────────────────────────────────

    public function test_a_bill_item_stores_the_price_it_was_agreed_at(): void
    {
        $item = $this->pack->addBoqItem($this->project, [
            'description' => 'Mass concrete', 'unit' => 'm3', 'quantity' => 120, 'rate' => 1850,
        ]);

        // Stored rather than computed on read: a BOQ is a priced document, and recomputing it would
        // silently restate the contract the day somebody corrects a rate.
        $this->assertSame(222000.0, (float) $item->amount);
    }

    /**
     * Measuring the bill moves the task's progress — through the evidence ledger, not directly.
     *
     * That is the property worth guarding: a measured quantity outranks a typed percentage
     * (PM-ADR-010), so nobody can improve reported progress by claiming a bigger number.
     */
    public function test_measuring_records_installed_quantity_as_evidence(): void
    {
        $task = app(TaskService::class)->create($this->project, ['name' => 'Foundations']);

        $item = $this->pack->addBoqItem($this->project, [
            'description' => 'Mass concrete', 'unit' => 'm3', 'quantity' => 120, 'rate' => 1850,
            'task_id' => $task->uuid,
        ]);

        $this->pack->measure($item->fresh(), 45);

        $this->assertDatabaseHas('pm_evidence_events', [
            'project_id' => $this->project->id,
            'event_type' => 'quantity.installed',
        ]);

        $summary = $this->pack->boqSummary($this->project);

        $this->assertSame(222000.0, $summary['total']);
        $this->assertSame(83250.0, $summary['measured']);
        $this->assertSame(37.5, $summary['percent']);
    }

    public function test_over_measuring_does_not_report_more_than_complete(): void
    {
        $item = $this->pack->addBoqItem($this->project, [
            'description' => 'Blockwork', 'unit' => 'm2', 'quantity' => 100, 'rate' => 300,
        ]);

        $this->pack->measure($item->fresh(), 140);

        // Over-measure is a variation, not 140% done — and a completion figure over 100 is one
        // nobody can put on a certificate.
        $this->assertSame(100.0, $item->fresh()->percentComplete());
    }

    public function test_a_provisional_sum_is_reported_separately(): void
    {
        $this->pack->addBoqItem($this->project, [
            'description' => 'Measured work', 'unit' => 'm3', 'quantity' => 10, 'rate' => 1000,
        ]);
        $this->pack->addBoqItem($this->project, [
            'description' => 'Provisional sum — rock excavation', 'unit' => 'sum',
            'quantity' => 1, 'rate' => 50000, 'is_provisional' => true,
        ]);

        // Money in the bill that may never be spent. A completion percentage that quietly counts it
        // reads high, which is the direction that flatters the contractor.
        $this->assertSame(50000.0, $this->pack->boqSummary($this->project)['provisional']);
    }

    // ── Snags ────────────────────────────────────────────────────────────────

    public function test_a_snag_gets_a_reference_people_can_call_it_by(): void
    {
        $first = $this->pack->raiseSnag($this->project, ['title' => 'Cracked tile', 'kind' => 'snag']);
        $ncr = $this->pack->raiseSnag($this->project, ['title' => 'Wrong grade steel', 'kind' => 'ncr']);

        $this->assertSame('SNAG-001', $first->reference);
        // Numbered per kind: NCRs are counted and quoted separately from cosmetic snags.
        $this->assertSame('NCR-001', $ncr->reference);
    }

    public function test_a_non_conformance_reaches_the_ledger(): void
    {
        $this->pack->raiseSnag($this->project, ['title' => 'Wrong grade steel', 'kind' => 'ncr', 'severity' => 'critical']);

        $this->assertDatabaseHas('pm_evidence_events', [
            'project_id' => $this->project->id,
            'event_type' => 'inspection.failed',
        ]);
    }

    public function test_a_non_conformance_cannot_be_closed_without_saying_how(): void
    {
        $ncr = $this->pack->raiseSnag($this->project, ['title' => 'Wrong grade steel', 'kind' => 'ncr']);

        $this->expectException(\InvalidArgumentException::class);

        // A register full of critical items closed with a blank note is one nobody can stand
        // behind at handover, which is the one moment it exists for.
        $this->pack->closeSnag($ncr, []);
    }

    public function test_a_cosmetic_snag_closes_without_ceremony(): void
    {
        $snag = $this->pack->raiseSnag($this->project, ['title' => 'Paint scuff', 'kind' => 'snag']);

        $this->pack->closeSnag($snag, []);

        $this->assertSame('closed', $snag->fresh()->status);
    }

    // ── Payment certificates ─────────────────────────────────────────────────

    public function test_a_claim_is_worked_out_from_the_bill(): void
    {
        $item = $this->pack->addBoqItem($this->project, [
            'description' => 'Mass concrete', 'unit' => 'm3', 'quantity' => 120, 'rate' => 1850,
        ]);
        $this->pack->measure($item->fresh(), 45);

        $claim = $this->pack->draftClaim($this->project, ['retention_pct' => 10]);

        $this->assertSame(1, $claim->claim_number);
        $this->assertSame(83250.0, $claim->gross());
        $this->assertSame(8325.0, (float) $claim->retention_held);
        $this->assertSame(74925.0, (float) $claim->net_due);
    }

    /**
     * The mistake this guards against is deducting retention twice.
     *
     * "Previously certified" has to be the *gross* of earlier certificates less their retention —
     * the amount actually paid. Using the gross alone would short the contractor by exactly the
     * retention on every certificate after the first, and it is the kind of error nobody spots
     * until a final account.
     */
    public function test_a_second_claim_deducts_what_was_already_paid_and_no_more(): void
    {
        $item = $this->pack->addBoqItem($this->project, [
            'description' => 'Mass concrete', 'unit' => 'm3', 'quantity' => 120, 'rate' => 1850,
        ]);

        $this->pack->measure($item->fresh(), 45);
        $first = $this->pack->draftClaim($this->project, ['retention_pct' => 10]);
        $this->pack->certifyClaim($first);

        // More work measured; the second certificate covers the difference.
        $this->pack->measure($item->fresh(), 90);
        $second = $this->pack->draftClaim($this->project, ['retention_pct' => 10]);

        $this->assertSame(2, $second->claim_number);
        $this->assertSame(166500.0, $second->gross());
        $this->assertSame(16650.0, (float) $second->retention_held);
        // Paid on certificate one: 83,250 gross less 8,325 retention.
        $this->assertSame(74925.0, (float) $second->previously_certified);
        $this->assertSame(74925.0, (float) $second->net_due);

        // Which is to say: the two certificates together pay the gross less retention, exactly once.
        $this->assertSame(
            166500.0 - 16650.0,
            round((float) $first->fresh()->net_due + (float) $second->net_due, 2),
        );
    }

    public function test_certifying_records_the_money_as_owed(): void
    {
        $claim = $this->pack->draftClaim($this->project, ['work_to_date' => 100000, 'retention_pct' => 10]);

        $this->pack->certifyClaim($claim);

        $this->assertSame('certified', $claim->fresh()->status);

        // Committed cost, through the ledger — the same route a supplier invoice takes, so the
        // project's cost position moves once rather than through a second path.
        $this->assertDatabaseHas('pm_evidence_events', [
            'project_id' => $this->project->id,
            'event_type' => 'invoice.received',
        ]);
    }

    public function test_a_milestone_cannot_be_claimed_twice(): void
    {
        $milestone = app(TaskService::class)->create($this->project, ['name' => 'Practical completion']);
        $milestone->forceFill(['is_milestone' => true, 'payment_amount' => 250000])->save();

        $claim = $this->pack->draftClaim($this->project, [
            'work_to_date' => 250000, 'milestone_task_id' => $milestone->uuid,
        ]);

        $this->pack->certifyClaim($claim);

        $this->assertTrue((bool) $milestone->fresh()->payment_claimed);
    }

    public function test_retention_accumulates_across_certificates(): void
    {
        foreach ([100000, 200000] as $value) {
            $claim = $this->pack->draftClaim($this->project, ['work_to_date' => $value, 'retention_pct' => 10]);
            $this->pack->certifyClaim($claim);
        }

        $position = $this->pack->retentionPosition($this->project);

        $this->assertSame(30000.0, $position['held']);
        $this->assertSame(30000.0, $position['outstanding']);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_the_site_page_withholds_money_from_a_role_without_the_grant(): void
    {
        $this->pack->addBoqItem($this->project, [
            'description' => 'Mass concrete', 'unit' => 'm3', 'quantity' => 10, 'rate' => 1000,
        ]);

        $props = $this->actingAs($this->fieldWorker)
            ->get(route('project-management.site', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        // A site agent keeps the diary and the snag list without seeing what the job is worth.
        $this->assertSame([], $props['boq']);
        $this->assertNull($props['boqSummary']);
        $this->assertSame([], $props['claims']);
        $this->assertNull($props['retention']);
        $this->assertFalse($props['can']['costs']);
    }

    public function test_certifying_needs_more_than_edit_rights(): void
    {
        $claim = $this->pack->draftClaim($this->project, ['work_to_date' => 1000]);

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.site.claims.certify', [$this->project->uuid, $claim->uuid]))
            ->assertForbidden();

        $this->assertSame('draft', $claim->fresh()->status);
    }

    // ── Earned value ─────────────────────────────────────────────────────────

    /**
     * Weighted by money, not by task count.
     *
     * Ten trivial tasks finished and one large one untouched is not 91% complete, and a count would
     * say it was — which is exactly the figure that gets reported to a client.
     */
    public function test_earned_value_weights_by_money_rather_than_task_count(): void
    {
        $tasks = app(TaskService::class);

        $small = $tasks->create($this->project, ['name' => 'Signage', 'budget_amount' => 10000]);
        $large = $tasks->create($this->project, ['name' => 'Structure', 'budget_amount' => 990000]);

        $small->forceFill(['progress_pct' => 100])->save();
        $large->forceFill(['progress_pct' => 0])->save();

        $evm = app(EarnedValueService::class)->forProject($this->project);

        // One of two tasks done, but only 1% of the work.
        $this->assertSame(1.0, $evm['percent_complete']);
        $this->assertSame(10000.0, $evm['ev']);
    }

    public function test_the_indices_are_null_rather_than_flattering_when_nothing_has_happened(): void
    {
        $evm = app(EarnedValueService::class)->forProject($this->project);

        // An SPI of 1.0 on a project that has earned nothing reads as "on plan", which is the
        // opposite of what it means.
        $this->assertNull($evm['spi']);
        $this->assertNull($evm['cpi']);
        $this->assertNull($evm['eac']);
    }
}
