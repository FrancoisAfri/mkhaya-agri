<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Repairing a cadence that had already collapsed before the fix landed.
 *
 * Correcting `advance()` governs the next time an enrolment moves. It cannot help the ones whose
 * `next_step_at` was already written a day out by the old rule — and on the sequence this came
 * from, that was nineteen hundred people. Without this command the fix arrives one send too late
 * for every one of them.
 */
class SequenceRespaceCommandTest extends TestCase
{
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
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    /** @param list<int> $offsets */
    private function sequence(array $offsets): CrmSequence
    {
        return CrmSequence::create([
            'uuid'   => (string) Str::uuid(),
            'name'   => 'Respace ' . Str::random(6),
            'active' => true,
            'steps'  => array_map(
                fn (int $day) => ['channel' => 'email', 'day_offset' => $day, 'subject' => 'x', 'body' => 'x'],
                $offsets,
            ),
        ]);
    }

    private function enrolment(CrmSequence $sequence, int $step, ?string $lastSentAt, string $nextStepAt): CrmSequenceEnrollment
    {
        return CrmSequenceEnrollment::create([
            'sequence_id'  => $sequence->id,
            'email'        => Str::random(8) . '@example.test',
            'status'       => 'active',
            'current_step' => $step,
            'enrolled_at'  => now()->subDays(60),
            'last_sent_at' => $lastSentAt,
            'next_step_at' => $nextStepAt,
        ]);
    }

    private function respace(CrmSequence $sequence, bool $apply): void
    {
        $this->artisan('crm:respace-sequences', array_filter([
            '--company'  => (string) $this->companyId,
            '--sequence' => (string) $sequence->id,
            '--apply'    => $apply ?: null,
        ]))->assertSuccessful();
    }

    // ── The repair ───────────────────────────────────────────────────────────

    public function test_a_step_queued_a_day_out_is_moved_to_the_week_its_cadence_asks_for(): void
    {
        $sequence = $this->sequence([0, 7, 14, 21, 28, 35]);

        // Exactly what the old floor wrote: sent yesterday, next one due tomorrow.
        $e = $this->enrolment($sequence, 1, now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString());

        $this->respace($sequence, apply: true);

        $this->assertTrue(
            $e->fresh()->next_step_at->greaterThan(now()->addDays(5)),
            'A weekly cadence still had its next step queued for tomorrow.',
        );
    }

    /** A dry run has to be dry, or nobody can look before they leap. */
    public function test_without_apply_nothing_is_written(): void
    {
        $sequence = $this->sequence([0, 7]);
        $due = now()->addDay()->startOfMinute();
        $e = $this->enrolment($sequence, 1, now()->subDay()->toDateTimeString(), $due->toDateTimeString());

        $this->respace($sequence, apply: false);

        $this->assertSame($due->toDateTimeString(), $e->fresh()->next_step_at->toDateTimeString());
    }

    // ── What it must not touch ───────────────────────────────────────────────

    /** Only ever later: pulling a send forward is the fault, not the repair. */
    public function test_a_send_already_further_out_than_the_cadence_is_left_alone(): void
    {
        $sequence = $this->sequence([0, 7]);
        $due = now()->addDays(30)->startOfMinute();
        $e = $this->enrolment($sequence, 1, now()->toDateTimeString(), $due->toDateTimeString());

        $this->respace($sequence, apply: true);

        $this->assertSame($due->toDateTimeString(), $e->fresh()->next_step_at->toDateTimeString());
    }

    /** No send has happened, so there is no anchor to measure a gap from. */
    public function test_an_enrolment_never_sent_to_is_left_alone(): void
    {
        $sequence = $this->sequence([0, 7]);
        $due = now()->addHour()->startOfMinute();
        $e = $this->enrolment($sequence, 0, null, $due->toDateTimeString());

        $this->respace($sequence, apply: true);

        $this->assertSame($due->toDateTimeString(), $e->fresh()->next_step_at->toDateTimeString());
    }

    /** A finished enrolment is finished; re-spacing one would put somebody back into a cadence. */
    public function test_a_completed_enrolment_is_not_revived(): void
    {
        $sequence = $this->sequence([0, 7]);
        $e = $this->enrolment($sequence, 1, now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString());
        $e->update(['status' => 'completed', 'next_step_at' => null]);

        $this->respace($sequence, apply: true);

        $this->assertSame('completed', $e->fresh()->status);
        $this->assertNull($e->fresh()->next_step_at);
    }
}
