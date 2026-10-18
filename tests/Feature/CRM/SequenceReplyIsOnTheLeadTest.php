<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmMailboxService;
use App\Modules\CRM\Services\CrmSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A reply to a sequence is kept, shown, and lands on the lead's timeline.
 *
 * Reported: "we want the sequence to see who replied and add the response to lead activities".
 * A reply was classified and its words thrown away, nothing reached the lead, and a reply to the
 * last step — after the cadence had finished — fell through to lead capture instead.
 */
class SequenceReplyIsOnTheLeadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $ai = $this->createMock(CrmAiService::class);
        $ai->method('classifyReply')->willReturn(['available' => true, 'intent' => 'interested', 'should_stop' => true]);
        $this->app->instance(CrmAiService::class, $ai);
    }

    private function enrolment(string $status, ?int $leadId = null): CrmSequenceEnrollment
    {
        $sequence = CrmSequence::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Q4 outreach', 'active' => true, 'stop_on_reply' => true,
            'steps' => [['channel' => 'email', 'day_offset' => 0, 'subject' => 'x', 'body' => 'x']],
        ]);

        return CrmSequenceEnrollment::create([
            'sequence_id' => $sequence->id, 'lead_id' => $leadId, 'email' => 'thandi@example.test',
            'status' => $status, 'current_step' => 1, 'enrolled_at' => now()->subDays(3),
            'last_sent_at' => now()->subDay(),
        ]);
    }

    private function lead(): CrmLead
    {
        return CrmLead::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Thandi', 'last_name' => 'M',
            'email' => 'Thandi@Example.test', 'status' => 'new',
        ]);
    }

    public function test_a_reply_is_kept_on_the_enrolment_and_logged_on_the_lead(): void
    {
        $lead = $this->lead();
        $e = $this->enrolment('active', $lead->id);

        app(CrmSequenceService::class)->recordReply($e, 'Yes, please call me on Thursday.');

        $e->refresh();
        $this->assertSame('replied', $e->status);
        $this->assertNotNull($e->replied_at);
        $this->assertSame('Yes, please call me on Thursday.', $e->last_reply);

        $activity = CrmActivity::where('related_type', 'lead')->where('related_id', $lead->id)->latest('id')->first();
        $this->assertNotNull($activity, 'The reply must appear in the lead\'s activities.');
        $this->assertStringContainsString('Replied to sequence: Q4 outreach', $activity->subject);
        $this->assertSame('Yes, please call me on Thursday.', $activity->description);
    }

    public function test_an_enrolment_without_a_lead_id_finds_the_lead_by_email(): void
    {
        $lead = $this->lead();
        $e = $this->enrolment('active');

        app(CrmSequenceService::class)->recordReply($e, 'Interested.');

        $this->assertTrue(CrmActivity::where('related_type', 'lead')->where('related_id', $lead->id)->exists());
    }

    public function test_a_reply_after_the_last_step_is_still_matched_and_does_not_restart_the_cadence(): void
    {
        $lead = $this->lead();
        $e = $this->enrolment('completed', $lead->id);

        $match = new \ReflectionMethod(CrmMailboxService::class, 'matchSequenceReply');
        $match->setAccessible(true);
        $handled = $match->invoke(app(CrmMailboxService::class), [
            'from' => 'thandi@example.test', 'subject' => 'Re: x', 'body' => 'Sounds good.',
        ]);

        $this->assertTrue($handled, 'A reply to a finished cadence must not become a new lead.');
        $e->refresh();
        $this->assertSame('replied', $e->status);
        $this->assertNull($e->next_step_at);
        $this->assertTrue(CrmActivity::where('related_type', 'lead')->where('related_id', $lead->id)->exists());
    }
}
