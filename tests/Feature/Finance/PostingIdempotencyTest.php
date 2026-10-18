<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinAccount;
use App\Modules\Finance\Accounting\Models\FinPosting;
use App\Modules\Finance\Accounting\Services\FinancePoster;
use Database\Seeders\FinanceGlSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Emitting the same financial event twice must post one journal.
 *
 * Every caller believed this already. `FinancePoster::emit()` said "idempotent via the event's
 * idempotency key" in its own docblock; `PayrollLedgerPoster` said "idempotent (keyed on the run) —
 * releasing twice posts once"; the project expense sink said "approving the same cost twice must
 * post one journal, not two".
 *
 * None of it was true at this layer. The deduplication looked up `event_id`, and `FinancialEvent`
 * mints a fresh uuid for that on every construction unless one is passed — which no caller does. So
 * the row it looked for could never already exist, and the idempotency key was written and never
 * read.
 *
 * Being exact about the damage: no duplicate journal was ever posted. `Ledger::post()` checks the
 * same key and the second attempt resolved to the first one's journal, so the books were right the
 * whole time. What was wrong was the outbox — a row per attempt, and `emit()` handing back a
 * different posting each time — and the fact that the only real guard sat in a class none of the
 * callers call. The ledger's check is the last line of defence, and there was nothing in front of
 * it.
 */
class PostingIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FinAccount::where('code', '1200')->exists()) {
            (new FinanceGlSeeder())->run();
        }
    }

    private function event(string $key, float $amount = 1000.0): FinancialEvent
    {
        return new FinancialEvent(
            sourceModule: 'test',
            eventType: 'test.posted',
            debits:  [['account' => '5000', 'amount' => $amount, 'narration' => 'Test']],
            credits: [['account' => '2100', 'amount' => $amount, 'narration' => 'Test']],
            sourceRef: 'test:' . $key,
            idempotencyKey: $key,
        );
    }

    private function countFor(string $key): int
    {
        return FinPosting::where('idempotency_key', $key)->count();
    }

    // ── The bug ──────────────────────────────────────────────────────────────

    public function test_the_same_event_emitted_twice_posts_once(): void
    {
        $key = 'test:' . Str::random(12);
        $poster = app(FinancePoster::class);

        $poster->emit($this->event($key));
        $poster->emit($this->event($key));

        $this->assertSame(1, $this->countFor($key),
            'The same financial event was posted twice; every caller believes it cannot be.');
    }

    /**
     * The shape the real callers use — a fresh event object each time, carrying the same key,
     * because that is what "approve this cost again" and "release this run again" actually do.
     */
    public function test_a_second_attempt_returns_the_original_posting(): void
    {
        $key = 'test:' . Str::random(12);
        $poster = app(FinancePoster::class);

        $first  = $poster->emit($this->event($key));
        $second = $poster->emit($this->event($key));

        $this->assertSame($first->id, $second->id);
    }

    /** A re-emitted event must not overwrite the journal already posted from the first. */
    public function test_the_second_attempt_does_not_repost(): void
    {
        $key = 'test:' . Str::random(12);
        $poster = app(FinancePoster::class);

        $journalId = $poster->emit($this->event($key))->fresh()->journal_id;

        $poster->emit($this->event($key, 9999.0));

        $this->assertSame($journalId, FinPosting::where('idempotency_key', $key)->first()->journal_id,
            'A repeat emission re-posted, or replaced the journal from the first.');
    }

    // ── What must still work ─────────────────────────────────────────────────

    /** Two genuinely different events are two postings. */
    public function test_different_events_still_post_separately(): void
    {
        $poster = app(FinancePoster::class);

        $one = 'test:' . Str::random(12);
        $two = 'test:' . Str::random(12);

        $poster->emit($this->event($one));
        $poster->emit($this->event($two));

        $this->assertSame(1, $this->countFor($one));
        $this->assertSame(1, $this->countFor($two));
    }

    /** The event id is still recorded — it is the audit trail, it was just never the dedup key. */
    public function test_the_event_id_is_still_stored(): void
    {
        $key = 'test:' . Str::random(12);

        $posting = app(FinancePoster::class)->emit($this->event($key));

        $this->assertNotEmpty($posting->event_id);
    }
}
