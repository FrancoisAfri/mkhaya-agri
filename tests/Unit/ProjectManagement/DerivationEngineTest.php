<?php

namespace Tests\Unit\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Support\Derivation\DerivationEngine;
use App\Modules\Operations\ProjectManagement\Support\Derivation\DerivationRules;
use App\Modules\Operations\ProjectManagement\Support\Derivation\EvidenceRow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Evidence becoming numbers (PM-1200, PM-1201).
 *
 * The tests that matter here are the precedence ones. A module that lets a typed percentage
 * outrank a measured quantity is a module that will certify payment on someone's optimism, and no
 * amount of correct arithmetic elsewhere makes up for it.
 */
class DerivationEngineTest extends TestCase
{
    private function at(string $when): CarbonImmutable
    {
        return CarbonImmutable::parse($when, 'UTC');
    }

    private function event(string $type, string $when, array $payload = [], array $options = []): EvidenceRow
    {
        static $n = 0;

        return new EvidenceRow(
            uuid: $options['uuid'] ?? sprintf('00000000-0000-0000-0000-%012d', ++$n),
            eventType: $type,
            occurredAt: $this->at($when),
            payload: $payload,
            confidence: $options['confidence'] ?? 1.0,
            isSuperseded: $options['superseded'] ?? false,
        );
    }

    private function derive(array $evidence, array $context = []): array
    {
        return (new DerivationEngine)->derive($evidence, $context);
    }

    // ── Precedence ───────────────────────────────────────────────────────────

    /**
     * The rule the whole module rests on: what was counted beats what was claimed. A supervisor
     * typing 80% cannot raise a figure the tape measure puts at 40%.
     */
    public function test_a_measured_quantity_beats_a_typed_percentage(): void
    {
        $figures = $this->derive([
            $this->event('progress.reported', '2026-03-05 10:00', ['percent' => 80]),
            $this->event('quantity.installed', '2026-03-04 10:00', ['quantity' => 40]),
        ], ['planned_quantity' => 100]);

        $progress = $figures['progress_pct'];

        $this->assertSame(40.0, $progress->value);
        $this->assertSame('measured', $progress->kind);
        $this->assertStringContainsString('planned quantity', $progress->because);
    }

    public function test_a_completed_task_beats_everything_below_it(): void
    {
        $figures = $this->derive([
            $this->event('quantity.installed', '2026-03-04 10:00', ['quantity' => 40]),
            $this->event('progress.reported', '2026-03-05 10:00', ['percent' => 55]),
            $this->event('task.completed', '2026-03-06 10:00'),
        ], ['planned_quantity' => 100]);

        $this->assertSame(100.0, $figures['progress_pct']->value);
        $this->assertSame('definitive', $figures['progress_pct']->kind);
    }

    public function test_a_verified_milestone_outranks_even_a_completion(): void
    {
        $figures = $this->derive([
            $this->event('task.completed', '2026-03-06 10:00'),
            $this->event('milestone.verified', '2026-03-07 10:00', ['milestone' => 'Slab', 'verified_by' => 'Eng']),
        ]);

        $this->assertSame('verified', $figures['progress_pct']->kind);
    }

    /**
     * A later event of a *weaker* kind does not displace a stronger one. Recency is not authority —
     * otherwise anyone could undo a measurement by typing over it the next morning.
     */
    public function test_recency_does_not_beat_authority(): void
    {
        $figures = $this->derive([
            $this->event('quantity.installed', '2026-03-01 10:00', ['quantity' => 30]),
            $this->event('progress.reported', '2026-03-20 10:00', ['percent' => 95]),
        ], ['planned_quantity' => 100]);

        $this->assertSame(30.0, $figures['progress_pct']->value);
    }

    public function test_a_typed_percentage_is_used_when_nothing_measured_it(): void
    {
        $figures = $this->derive([
            $this->event('progress.reported', '2026-03-05 10:00', ['percent' => 65]),
        ]);

        $this->assertSame(65.0, $figures['progress_pct']->value);
        $this->assertSame('claimed', $figures['progress_pct']->kind);
        // Reported honestly as weaker: a reader can see how much to trust it.
        $this->assertSame(0.6, $figures['progress_pct']->confidence);
    }

    /**
     * A rule that matches events but cannot produce a figure must fall through, not answer null.
     * Here there are installed quantities but no planned quantity to measure them against.
     */
    public function test_a_rule_that_cannot_compute_falls_through_to_a_weaker_one(): void
    {
        $figures = $this->derive([
            $this->event('quantity.installed', '2026-03-04 10:00', ['quantity' => 40]),
            $this->event('progress.reported', '2026-03-05 10:00', ['percent' => 70]),
        ]);   // no planned_quantity in context

        $this->assertSame(70.0, $figures['progress_pct']->value);
        $this->assertSame('claimed', $figures['progress_pct']->kind);
    }

    public function test_a_started_task_with_nothing_measured_reads_as_barely_begun(): void
    {
        $figures = $this->derive([$this->event('task.started', '2026-03-02 08:00')]);

        $this->assertSame(5.0, $figures['progress_pct']->value);
    }

    // ── The drill-down ───────────────────────────────────────────────────────

    public function test_a_figure_names_the_events_behind_it(): void
    {
        $a = $this->event('quantity.installed', '2026-03-02 10:00', ['quantity' => 10]);
        $b = $this->event('quantity.installed', '2026-03-03 10:00', ['quantity' => 15]);
        $unrelated = $this->event('photo.captured', '2026-03-03 11:00');

        $figures = $this->derive([$a, $b, $unrelated], ['planned_quantity' => 50]);

        $this->assertSame(50.0, $figures['progress_pct']->value);
        // Exactly the two that produced the number — not the photo, which contributed nothing.
        $this->assertSame([$a->uuid, $b->uuid], $figures['progress_pct']->evidenceUuids);
        $this->assertSame(2, $figures['progress_pct']->count());
    }

    /** A correction must not leave the original still counting, or the ledger argues with itself. */
    public function test_superseded_evidence_is_excluded(): void
    {
        $figures = $this->derive([
            $this->event('quantity.installed', '2026-03-02 10:00', ['quantity' => 90], ['superseded' => true]),
            $this->event('quantity.installed', '2026-03-03 10:00', ['quantity' => 20]),
        ], ['planned_quantity' => 100]);

        $this->assertSame(20.0, $figures['progress_pct']->value);
        $this->assertSame(1, $figures['progress_pct']->count());
    }

    public function test_confidence_falls_to_the_weakest_event_behind_the_figure(): void
    {
        $figures = $this->derive([
            $this->event('quantity.installed', '2026-03-02 10:00', ['quantity' => 10], ['confidence' => 1.0]),
            $this->event('quantity.installed', '2026-03-03 10:00', ['quantity' => 10], ['confidence' => 0.5]),
        ], ['planned_quantity' => 100]);

        // Rule confidence 0.95, weakest event 0.5.
        $this->assertSame(0.48, $figures['progress_pct']->confidence);
    }

    // ── The other metrics ────────────────────────────────────────────────────

    public function test_paid_invoices_outrank_received_ones_for_actual_cost(): void
    {
        $figures = $this->derive([
            $this->event('invoice.received', '2026-03-02 10:00', ['amount' => 50000]),
            $this->event('invoice.paid', '2026-03-09 10:00', ['amount' => 20000]),
        ]);

        // Money that has actually left the account, not money that might.
        $this->assertSame(20000.0, $figures['actual_cost']->value);
        $this->assertSame('definitive', $figures['actual_cost']->kind);
    }

    public function test_approved_hours_outrank_logged_ones(): void
    {
        $figures = $this->derive([
            $this->event('time.logged', '2026-03-02 10:00', ['hours' => 40]),
            $this->event('time.approved', '2026-03-05 10:00', ['hours' => 36]),
        ]);

        $this->assertSame(36.0, $figures['actual_hours']->value);
    }

    public function test_quantities_are_summed_across_events(): void
    {
        $figures = $this->derive([
            $this->event('quantity.installed', '2026-03-02 10:00', ['quantity' => 12.5]),
            $this->event('quantity.installed', '2026-03-03 10:00', ['quantity' => 7.5]),
        ]);

        $this->assertSame(20.0, $figures['qty_installed']->value);
    }

    public function test_last_activity_is_the_most_recent_evidence_of_any_kind(): void
    {
        $figures = $this->derive([
            $this->event('photo.captured', '2026-03-02 10:00'),
            $this->event('site.visit', '2026-03-09 14:30'),
        ]);

        $this->assertSame('2026-03-09 14:30:00', $figures['last_activity_at']->text);
    }

    // ── Edges ────────────────────────────────────────────────────────────────

    public function test_no_evidence_produces_no_figures_rather_than_zeroes(): void
    {
        // Zero progress and "no information" are different claims, and a project reporting 0%
        // because nobody has recorded anything reads as a project that has gone wrong.
        $this->assertSame([], $this->derive([]));
    }

    public function test_over_delivery_is_capped_but_the_raw_quantity_survives(): void
    {
        $figures = $this->derive([
            $this->event('quantity.installed', '2026-03-02 10:00', ['quantity' => 130]),
        ], ['planned_quantity' => 100]);

        $this->assertSame(100.0, $figures['progress_pct']->value, 'A progress bar cannot read 130%.');
        $this->assertSame(130.0, $figures['qty_installed']->value, 'The measured quantity is not lost.');
    }

    public function test_an_event_missing_its_field_does_not_blank_an_earlier_complete_one(): void
    {
        $figures = $this->derive([
            $this->event('progress.reported', '2026-03-02 10:00', ['percent' => 45]),
            $this->event('progress.reported', '2026-03-03 10:00', ['note' => 'rained all day']),
        ]);

        $this->assertSame(45.0, $figures['progress_pct']->value);
    }

    public function test_the_method_version_travels_with_every_figure(): void
    {
        $figures = $this->derive([$this->event('task.started', '2026-03-02 08:00')]);

        $this->assertSame(DerivationRules::VERSION, $figures['progress_pct']->methodVersion);
    }

    public function test_only_the_requested_metrics_are_computed(): void
    {
        $figures = (new DerivationEngine)->derive(
            [$this->event('task.started', '2026-03-02 08:00')],
            [],
            ['progress_pct'],
        );

        $this->assertSame(['progress_pct'], array_keys($figures));
    }

    public function test_the_watched_event_list_covers_every_rule(): void
    {
        $watched = DerivationRules::watchedEvents();

        $this->assertContains('quantity.installed', $watched);
        $this->assertContains('invoice.paid', $watched);
        $this->assertNotContains('*', $watched, 'The wildcard is not an event type.');
    }
}
