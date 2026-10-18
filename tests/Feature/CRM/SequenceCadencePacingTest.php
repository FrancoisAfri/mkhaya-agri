<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Services\CrmSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A cadence sends over days, not over cron ticks.
 *
 * Reported from a live sequence begun the previous day: it was already on step 5, with the funnel
 * decaying 260 → 121 → 47 → 17 → 4 as each pass of the runner carried fewer people further along.
 *
 * A step's `day_offset` is counted from enrolment, and the next send was scheduled purely as
 * `enrolled_at + offset`. Two steps sharing an offset — or an enrolment picked up after several
 * offsets have already gone by — therefore leave every remaining step due at the same instant. The
 * runner then sends one per pass, and a five-step cadence empties in the time it takes the cron to
 * tick five times.
 *
 * Five messages to a stranger inside an hour is not a cadence. It is how a sending domain gets
 * blocked, which is the damage this prevents.
 */
class SequenceCadencePacingTest extends TestCase
{
    use DatabaseTransactions;

    /** @param list<array<string, mixed>> $steps */
    private function sequence(array $steps): CrmSequence
    {
        return CrmSequence::create([
            'uuid'   => (string) Str::uuid(),
            'name'   => 'Cadence ' . Str::random(6),
            'steps'  => $steps,
            'active' => true,
        ]);
    }

    private function enrolment(CrmSequence $sequence, int $step, string $enrolledAt, ?string $lastSentAt): CrmSequenceEnrollment
    {
        return CrmSequenceEnrollment::create([
            'sequence_id'  => $sequence->id,
            'email'        => 'someone@example.test',
            'status'       => 'active',
            'current_step' => $step,
            'enrolled_at'  => $enrolledAt,
            'last_sent_at' => $lastSentAt,
            'next_step_at' => now(),
        ]);
    }

    /** advance() is where the next send is scheduled; it is the thing under test. */
    private function advance(CrmSequenceEnrollment $e, array $steps): CrmSequenceEnrollment
    {
        $method = new \ReflectionMethod(CrmSequenceService::class, 'advance');
        $method->setAccessible(true);
        $method->invoke(app(CrmSequenceService::class), $e, $steps);

        return $e->fresh();
    }

    private function steps(array $offsets): array
    {
        return array_map(
            fn (int $day) => ['channel' => 'email', 'day_offset' => $day, 'subject' => 'x', 'body' => 'x'],
            $offsets,
        );
    }

    // ── The reported failure ─────────────────────────────────────────────────

    public function test_steps_sharing_a_day_do_not_all_fire_at_once(): void
    {
        /*
         * The shape that produced the report. Every offset is 0, so every step is due the moment
         * the one before it was sent, and each pass of the runner carries the enrolment one further.
         */
        $sequence = $this->sequence($this->steps([0, 0, 0, 0, 0]));
        $enrolment = $this->enrolment($sequence, 0, now()->subDay()->toDateTimeString(), now()->toDateTimeString());

        $advanced = $this->advance($enrolment, $sequence->steps);

        $this->assertTrue(
            $advanced->next_step_at->greaterThan(now()->addHours(23)),
            'A second step must not be due within the hour of the first.',
        );
    }

    public function test_a_cadence_caught_up_late_does_not_empty_in_one_sitting(): void
    {
        /*
         * The other route to the same burst: nobody ran the sequence for a fortnight, so by the
         * time it runs, days 0, 2, 4 and 7 are all in the past and every step is due together.
         */
        $sequence = $this->sequence($this->steps([0, 2, 4, 7]));
        $enrolment = $this->enrolment($sequence, 0, now()->subDays(30)->toDateTimeString(), now()->toDateTimeString());

        $advanced = $this->advance($enrolment, $sequence->steps);

        $this->assertTrue(
            $advanced->next_step_at->greaterThan(now()->addHours(23)),
            'A backlog of overdue steps is still a cadence, not a burst.',
        );
    }

    // ── What must not change ────────────────────────────────────────────────

    public function test_a_cadence_running_to_plan_keeps_exactly_its_own_spacing(): void
    {
        /*
         * The floor is a floor, not a rewrite. A sequence whose author asked for day 4 gets day 4 —
         * counted from enrolment, as it always was.
         */
        $sequence = $this->sequence($this->steps([0, 4, 9]));
        $enrolledAt = now()->startOfDay();

        $enrolment = $this->enrolment($sequence, 0, $enrolledAt->toDateTimeString(), now()->toDateTimeString());

        $advanced = $this->advance($enrolment, $sequence->steps);

        $this->assertSame(
            $enrolledAt->copy()->addDays(4)->toDateString(),
            $advanced->next_step_at->toDateString(),
            'Step two was asked for on day 4 and belongs on day 4.',
        );
    }

    public function test_the_last_step_completes_the_enrolment(): void
    {
        // Unchanged, and worth holding: the floor must not keep somebody enrolled forever.
        $sequence = $this->sequence($this->steps([0, 3]));
        $enrolment = $this->enrolment($sequence, 1, now()->subDays(3)->toDateTimeString(), now()->toDateTimeString());

        $advanced = $this->advance($enrolment, $sequence->steps);

        $this->assertSame('completed', $advanced->status);
        $this->assertNull($advanced->next_step_at);
    }

    // ── The floor must be the cadence, not one day ───────────────────────────

    /**
     * Reported from the live site: a six-week cadence had already finished.
     *
     * The offsets say 0/7/14/21/28/35 — six weeks, as the author wrote it. The anchor was stale, so
     * every one of those days was in the past and the flat one-day floor replayed the whole thing
     * at a step a day. That is the original burst again, slowed by a factor of twenty-four and no
     * more of a cadence for it.
     */
    public function test_a_six_week_cadence_stays_six_weeks_when_its_anchor_is_stale(): void
    {
        $sequence = $this->sequence($this->steps([0, 7, 14, 21, 28, 35]));

        // Enrolled long enough ago that day 7 is well behind us — a sequence left paused, or
        // switched on after the fact, which is how the reported one got here.
        $enrolment = $this->enrolment($sequence, 0, now()->subDays(60)->toDateTimeString(), now()->toDateTimeString());

        $advanced = $this->advance($enrolment, $sequence->steps);

        $this->assertTrue(
            $advanced->next_step_at->greaterThan(now()->addDays(6)),
            'Step two was written for a week after step one and must still be a week after it.',
        );
    }

    /** The whole cadence, not just its first gap: six steps must still span six weeks. */
    public function test_the_whole_stale_cadence_still_spans_its_full_length(): void
    {
        $sequence = $this->sequence($this->steps([0, 7, 14, 21, 28, 35]));
        $enrolment = $this->enrolment($sequence, 0, now()->subDays(60)->toDateTimeString(), now()->toDateTimeString());

        // Walk it the way the runner does: advance, then pretend that step was sent when it was due.
        $sent = now();
        for ($step = 0; $step < 5; $step++) {
            $enrolment = $this->advance($enrolment, $sequence->steps);
            $sent = $enrolment->next_step_at;
            $enrolment->update(['last_sent_at' => $sent]);
        }

        $this->assertTrue(
            $sent->greaterThan(now()->addDays(34)),
            'Six steps a week apart span five weeks of sending; this cadence finished in ' .
            now()->diffInDays($sent) . ' days.',
        );
    }

    /** An uneven cadence keeps each of its own gaps, not the average and not the smallest. */
    public function test_each_gap_is_the_one_its_author_wrote(): void
    {
        $sequence = $this->sequence($this->steps([0, 3, 30]));

        // Stale anchor again, so nothing can come from `enrolled_at + offset`.
        $enrolment = $this->enrolment($sequence, 1, now()->subDays(90)->toDateTimeString(), now()->toDateTimeString());

        $advanced = $this->advance($enrolment, $sequence->steps);

        // Step 3 sits 27 days after step 2, and that gap is what survives.
        $this->assertTrue(
            $advanced->next_step_at->greaterThan(now()->addDays(26)),
            'The 27-day gap between the last two steps was collapsed.',
        );
    }

    public function test_an_enrolment_that_has_never_been_sent_to_is_paced_from_now(): void
    {
        // `last_sent_at` is null before the first send. The floor has to fall back to something
        // rather than treating null as the epoch and letting the burst through.
        $sequence = $this->sequence($this->steps([0, 0]));
        $enrolment = $this->enrolment($sequence, 0, now()->subDays(10)->toDateTimeString(), null);

        $advanced = $this->advance($enrolment, $sequence->steps);

        $this->assertTrue($advanced->next_step_at->greaterThan(now()->addHours(23)));
    }
}
