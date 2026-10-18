<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Models\CrmSuppression;
use App\Modules\CRM\Services\CrmSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A mailbox that does not exist is not written to twice.
 *
 * From the production log, the same address on the same cadence, a day apart:
 *
 *   "550-The mail server could not deliver mail to neil.dilgee@africaneagle.co.uk.
 *    550-The account or domain may not exist, they may be blacklisted, or missing
 *    550 the proper dns entries."
 *
 * The suppression list was consulted when somebody was enrolled and never added to, so a permanent
 * rejection was logged and the next step written to the same address anyway — once an hour, for as
 * long as the cadence ran. Every one of those is a hard bounce recorded against the sending domain,
 * which is how a domain's reputation gets spent on addresses that were never going to receive
 * anything.
 */
class SequenceBounceHandlingTest extends TestCase
{
    use DatabaseTransactions;

    private function enrolment(string $email): CrmSequenceEnrollment
    {
        $sequence = CrmSequence::create([
            'uuid'   => (string) Str::uuid(),
            'name'   => 'Cadence ' . Str::random(6),
            'steps'  => [['channel' => 'email', 'day_offset' => 0, 'subject' => 'Hi', 'body' => 'Hello']],
            'active' => true,
        ]);

        return CrmSequenceEnrollment::create([
            'sequence_id'  => $sequence->id,
            'email'        => $email,
            'status'       => 'active',
            'current_step' => 0,
            'enrolled_at'  => now(),
            'next_step_at' => now(),
        ]);
    }

    private function bounce(CrmSequenceEnrollment $e, string $error): void
    {
        $method = new \ReflectionMethod(CrmSequenceService::class, 'handleBounce');
        $method->setAccessible(true);
        $method->invoke(app(CrmSequenceService::class), $e, $error);
    }

    private const HARD = 'Expected response code "250/251/252" but got code "550", with message "550-The account or domain may not exist."';

    public function test_a_permanent_rejection_suppresses_the_address(): void
    {
        $e = $this->enrolment('nobody@africaneagle.test');

        $this->bounce($e, self::HARD);

        $this->assertTrue(
            CrmSuppression::isSuppressed('email', 'nobody@africaneagle.test'),
            'A hard bounce has to reach the suppression list, or the next cadence writes to it again.',
        );
    }

    public function test_a_permanent_rejection_stops_that_cadence(): void
    {
        // The remaining steps would each be another hard bounce against the same dead mailbox.
        $e = $this->enrolment('nobody@africaneagle.test');

        $this->bounce($e, self::HARD);

        $e->refresh();

        $this->assertSame('completed', $e->status);
        $this->assertNull($e->next_step_at);
    }

    public function test_a_timeout_is_left_alone(): void
    {
        /*
         * The mail server having a bad minute is not the recipient being wrong. Suppressing on a
         * timeout would quietly lose a real customer — and on this installation, where the SMTP
         * host times out regularly, it would lose most of them.
         */
        $e = $this->enrolment('real.person@example.test');

        $this->bounce($e, 'Connection to "mail.hub.mkhayamk.co.za:25" timed out.');

        $this->assertFalse(CrmSuppression::isSuppressed('email', 'real.person@example.test'));
        $this->assertSame('active', $e->fresh()->status);
    }

    public function test_a_temporary_rejection_is_left_alone(): void
    {
        // 4xx is "try later", which is the opposite of "this address is wrong".
        $e = $this->enrolment('busy@example.test');

        $this->bounce($e, 'Expected response code "250" but got code "451", with message "451 Try again later".');

        $this->assertFalse(CrmSuppression::isSuppressed('email', 'busy@example.test'));
        $this->assertSame('active', $e->fresh()->status);
    }

    public function test_the_out_of_sync_conversation_is_not_treated_as_a_bounce(): void
    {
        /*
         * Also in the log, and easy to mistake for a rejection:
         * `Expected response code "354" but got code "250"`. That is the SMTP conversation itself
         * going out of step, not the recipient being refused — the address is very probably fine.
         */
        $e = $this->enrolment('fine@example.test');

        $this->bounce($e, 'Expected response code "354" but got code "250", with message "250 OK".');

        $this->assertFalse(CrmSuppression::isSuppressed('email', 'fine@example.test'));
        $this->assertSame('active', $e->fresh()->status);
    }
}
