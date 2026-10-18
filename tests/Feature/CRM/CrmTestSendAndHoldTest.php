<?php

namespace Tests\Feature\CRM;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmCampaign;
use App\Modules\CRM\Models\CrmCampaignRecipient;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmSequenceEnrollment;
use App\Modules\CRM\Models\CrmSequenceSend;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\CRM\Services\CrmDealHoldService;
use App\Modules\CRM\Services\CrmSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two things that must be safe before anybody trusts them: a test send that only tests, and a hold
 * that gives a deal back.
 */
class CrmTestSendAndHoldTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateCrm();
    }

    private function activateCrm(): void
    {
        if (! DB::table('security_modules')->where('code_name', 'crm')->where('active', 1)->exists()) {
            $categoryId = DB::table('security_categories')->value('id')
                ?? DB::table('security_categories')->insertGetId(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

            DB::table('security_modules')->updateOrInsert(
                ['code_name' => 'crm'],
                ['uuid' => (string) Str::uuid(), 'name' => 'CRM', 'path' => 'crm', 'category_id' => $categoryId,
                    'active' => 1, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        Cache::flush();
    }

    /** Messages the array transport has collected — raw HTML sends never reach Mail::fake(). */
    private function outbox(): \Illuminate\Support\Collection
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }

    private function user(): User
    {
        return User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'hold+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'is_platform_admin' => true,
        ]);
    }

    private function pipeline(): CrmPipeline
    {
        $pipeline = CrmPipeline::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'Hold Pipeline ' . Str::random(4)]);
        CrmStage::forceCreate(['pipeline_id' => $pipeline->id, 'name' => 'Qualify', 'sort_order' => 1, 'probability' => 20]);
        CrmStage::forceCreate(['pipeline_id' => $pipeline->id, 'name' => 'Proposal', 'sort_order' => 2, 'probability' => 60]);

        return $pipeline->load('stages');
    }

    private function deal(CrmPipeline $pipeline, ?int $contactId = null): CrmDeal
    {
        return CrmDeal::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Fibre rollout', 'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->last()->id, 'amount' => 250000, 'currency' => 'ZAR',
            'status' => 'open', 'probability' => 60, 'contact_id' => $contactId, 'stage_changed_at' => now(),
        ]);
    }

    private function sequence(array $steps): CrmSequence
    {
        return CrmSequence::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Stay warm', 'steps' => $steps, 'active' => true,
        ]);
    }

    // ── Why a run sent nothing ───────────────────────────────────────────────

    /** Enrol one contact and force its next step to be due. */
    private function enrolDue(CrmSequence $sequence): CrmSequenceEnrollment
    {
        $contact = CrmContact::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Nomsa', 'last_name' => 'Dlamini',
            'email' => 'nomsa+' . Str::random(6) . '@example.test',
        ]);

        app(CrmSequenceService::class)->enroll($sequence, [[
            'contact_id' => $contact->id, 'name' => 'Nomsa Dlamini', 'email' => $contact->email,
        ]]);

        $enrolment = CrmSequenceEnrollment::where('sequence_id', $sequence->id)->firstOrFail();
        $enrolment->update(['next_step_at' => now()->subMinute()]);

        return $enrolment->fresh();
    }

    private function step(): array
    {
        return ['channel' => 'email', 'day_offset' => 0, 'subject' => 'Hello {{first_name}}', 'body' => 'Hi {{first_name}}'];
    }

    public function test_a_switched_off_sequence_says_so_instead_of_reporting_zero(): void
    {
        $sequence = $this->sequence([$this->step()]);
        $this->enrolDue($sequence);
        $sequence->update(['active' => false]);   // enrol first, then switch it off

        $this->actingAs($this->user())
            ->post(route('crm.sequences.run', $sequence->id))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'switched off'));
    }

    public function test_a_cadence_whose_first_step_is_not_due_says_when_it_will_be(): void
    {
        $sequence = $this->sequence([['channel' => 'email', 'day_offset' => 1, 'subject' => 'Hi', 'body' => 'Hi']]);
        $sequence->update(['active' => true]);

        // Enrolled, waiting on day 1 — exactly the shape that reported "0 sent" on a screen
        // showing two thousand active enrolments.
        $contact = CrmContact::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Sipho',
            'email' => 'sipho+' . Str::random(6) . '@example.test',
        ]);
        app(CrmSequenceService::class)->enroll($sequence, [[
            'contact_id' => $contact->id, 'name' => 'Sipho', 'email' => $contact->email,
        ]]);

        $this->actingAs($this->user())
            ->post(route('crm.sequences.run', $sequence->id))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'no step is due yet') && str_contains($m, 'day 1 sends tomorrow'));
    }

    public function test_a_run_reports_what_it_sent(): void
    {
        $sequence = $this->sequence([$this->step()]);
        $sequence->update(['active' => true]);
        $this->enrolDue($sequence);

        $this->actingAs($this->user())
            ->post(route('crm.sequences.run', $sequence->id))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Sent 1 message'));
    }

    public function test_running_one_sequence_leaves_another_alone(): void
    {
        $mine = $this->sequence([$this->step()]);
        $mine->update(['active' => true]);
        $this->enrolDue($mine);

        $other = $this->sequence([$this->step()]);
        $other->update(['active' => true]);
        $otherEnrolment = $this->enrolDue($other);

        $this->actingAs($this->user())->post(route('crm.sequences.run', $mine->id));

        // Run Now used to process every cadence in the tenant, so pressing it on one sequence
        // quietly sent another one's mail too.
        $this->assertTrue($otherEnrolment->fresh()->next_step_at->isPast());
        $this->assertSame(0, CrmSequenceSend::where('sequence_id', $other->id)->count());
    }

    // ── The leads list, after an import ──────────────────────────────────────

    /**
     * The page that returned a 500 once the CSV import had pushed the table past seven thousand.
     *
     * Two causes, both here: it handed every lead to the browser, and its presenter asked for each
     * row's custom fields one row at a time.
     */
    public function test_the_leads_page_survives_a_large_list_and_stays_within_a_query_budget(): void
    {
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = [
                'uuid' => (string) Str::uuid(), 'first_name' => 'Lead', 'last_name' => (string) $i,
                'company' => 'Company ' . $i, 'email' => "lead{$i}@example.test",
                'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('crm_leads')->insert($rows);

        DB::enableQueryLog();

        $response = $this->actingAs($this->user())->get(route('crm.leads'));

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Modules/CRM/Leads')
            ->has('leads', 500)
            // The count is shown so nobody concludes their import failed.
            ->where('leadTotal', fn ($t) => $t >= 600));

        // Two per row was 1,200 queries for this page alone. A budget rather than an exact number,
        // because the surrounding page legitimately runs a handful of its own.
        $this->assertLessThan(60, $queries, "The leads page ran {$queries} queries — the per-row custom-field lookup is back.");
    }

    public function test_a_notification_link_opens_the_lead_it_points_at_even_when_it_falls_past_the_cap(): void
    {
        $old = \App\Modules\CRM\Models\CrmLead::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Older', 'last_name' => 'Lead',
            'email' => 'older' . Str::random(5) . '@example.test', 'status' => 'new',
        ]);
        // Everything newer than it, so a plain newest-first page would leave it out.
        $old->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->actingAs($this->user())
            ->get(route('crm.leads', ['lead' => $old->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('focusLead', $old->id)
                ->where('leads', fn ($leads) => collect($leads)->contains('id', $old->id)));
    }

    // ── Campaign test send ───────────────────────────────────────────────────

    /**
     * The defect this replaced: "Send a Test" created a throwaway recipient and then ran the
     * campaign job, which sends to every pending recipient. One click on a campaign with an
     * audience loaded sent the draft to the whole list.
     */
    public function test_a_campaign_test_send_does_not_touch_the_real_audience(): void
    {
        $campaign = CrmCampaign::create([
            'uuid' => Str::uuid(), 'name' => 'Spring promo', 'channel' => 'email',
            'subject' => 'Our spring offer', 'content' => '<html><body><p>Hello</p></body></html>',
            'status' => 'draft', 'total_recipients' => 2,
        ]);

        $audience = collect(['client-a@example.test', 'client-b@example.test'])->map(
            fn ($email) => CrmCampaignRecipient::create([
                'campaign_id' => $campaign->id, 'email' => $email,
                'token' => Str::random(40), 'status' => 'pending',
            ]),
        );

        $this->actingAs($this->user())
            ->post(route('crm.campaigns.test', $campaign->uuid), ['to' => 'me@example.test'])
            ->assertRedirect();

        // Exactly one message, and it went to the tester.
        $outbox = $this->outbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('me@example.test', $outbox->first()->getOriginalMessage()->getTo()[0]->getAddress());

        foreach ($audience as $recipient) {
            $this->assertSame('pending', $recipient->fresh()->status, 'A real recipient was sent the test.');
        }

        // The campaign is still a draft: a test must not consume the send.
        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertSame(2, $campaign->fresh()->recipients()->count(), 'The throwaway test recipient was left behind.');
    }

    public function test_a_campaign_with_no_content_says_so_instead_of_sending_an_empty_email(): void
    {
        $campaign = CrmCampaign::create([
            'uuid' => Str::uuid(), 'name' => 'Empty', 'channel' => 'email', 'status' => 'draft',
        ]);

        $this->actingAs($this->user())
            ->post(route('crm.campaigns.test', $campaign->uuid), ['to' => 'me@example.test'])
            ->assertSessionHas('error');

        $this->assertCount(0, $this->outbox());
    }

    // ── Sequence test send ───────────────────────────────────────────────────

    public function test_a_sequence_test_sends_every_step_and_enrols_nobody(): void
    {
        $sequence = $this->sequence([
            ['channel' => 'email', 'day_offset' => 0, 'subject' => 'Hi {{first_name}}', 'body' => 'About {{company}}'],
            ['channel' => 'email', 'day_offset' => 3, 'subject' => 'Following up', 'body' => 'Still interested?'],
            ['channel' => 'task',  'day_offset' => 5, 'subject' => 'Call them', 'body' => 'Ring the switchboard'],
        ]);

        $result = app(CrmSequenceService::class)->sendTest($sequence, 'tester@example.test');

        $this->assertSame(3, $result['sent']);
        $this->assertSame([], $result['warnings']);
        $this->assertCount(3, $this->outbox());

        // Nothing written: no enrolment, no send log, so the A/B statistics stay honest.
        $this->assertSame(0, CrmSequenceEnrollment::where('sequence_id', $sequence->id)->count());
        $this->assertSame(0, CrmSequenceSend::where('sequence_id', $sequence->id)->count());
    }

    public function test_both_sides_of_an_ab_step_are_sent_so_they_can_be_compared(): void
    {
        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0,
            'variants' => [
                ['subject' => 'Short subject', 'body' => 'Version one'],
                ['subject' => 'A longer, friendlier subject', 'body' => 'Version two'],
            ],
        ]]);

        $result = app(CrmSequenceService::class)->sendTest($sequence, 'tester@example.test');

        $this->assertSame(2, $result['sent']);
    }

    /**
     * A misspelled tag renders as an empty string in a real send, so the sentence silently loses a
     * word. The test send is the one place that has to say so.
     */
    public function test_an_unrecognised_merge_tag_is_reported_rather_than_rendered_as_nothing(): void
    {
        $sequence = $this->sequence([
            ['channel' => 'email', 'day_offset' => 0, 'subject' => 'Hi {{frst_name}}', 'body' => 'Hello {{first_name}}'],
        ]);

        $result = app(CrmSequenceService::class)->sendTest($sequence, 'tester@example.test');

        $this->assertSame(['frst_name'], $result['warnings']);
    }

    public function test_only_one_step_can_be_tested(): void
    {
        $sequence = $this->sequence([
            ['channel' => 'email', 'day_offset' => 0, 'subject' => 'One', 'body' => 'First'],
            ['channel' => 'email', 'day_offset' => 3, 'subject' => 'Two', 'body' => 'Second'],
        ]);

        $result = app(CrmSequenceService::class)->sendTest($sequence, 'tester@example.test', 1);

        $this->assertSame(1, $result['sent']);
    }

    // ── Preview text and the call to action ──────────────────────────────────

    /**
     * The body of the first message the array transport collected.
     *
     * Decoded: the transport quoted-printable-encodes it, which folds long lines with "=\r\n" —
     * so a URL asserted against the raw string fails on where the wrap happened rather than on
     * anything real.
     */
    private function firstBody(): string
    {
        return quoted_printable_decode(
            $this->outbox()->first()->getOriginalMessage()->getBody()->bodyToString(),
        );
    }

    public function test_a_step_can_set_its_own_preview_line(): void
    {
        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0,
            'subject' => 'A question about stock counts',
            'preview_text' => 'Two minutes, no pitch — the three numbers worth checking first.',
            'body' => 'Hi {{first_name}}',
        ]]);

        app(CrmSequenceService::class)->sendTest($sequence, 'tester@example.test');
        $html = $this->firstBody();

        $this->assertStringContainsString('Two minutes, no pitch', $html);
        // Hidden, and padded — without the padding the client runs on into the body text.
        $this->assertStringContainsString('display:none', $html);
        $this->assertStringContainsString('zwnj', $html);
    }

    public function test_a_step_can_carry_a_button(): void
    {
        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0, 'subject' => 'The checklist',
            'body' => 'Hi {{first_name}}',
            'cta_label' => 'Read the checklist', 'cta_url' => 'https://example.test/checklist',
        ]]);

        app(CrmSequenceService::class)->sendTest($sequence, 'tester@example.test');
        $html = $this->firstBody();

        $this->assertStringContainsString('https://example.test/checklist', $html);
        $this->assertStringContainsString('Read the checklist', $html);
        // A table, because Outlook ignores padding on a styled anchor.
        $this->assertStringContainsString('<table role="presentation"', $html);
    }

    public function test_a_button_whose_link_does_not_resolve_is_dropped_rather_than_sent_broken(): void
    {
        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0, 'subject' => 'Hi',
            'body' => 'Hi {{first_name}}',
            // A tag nothing can fill would otherwise be baked into the href.
            'cta_label' => 'Book a call', 'cta_url' => 'https://example.test/{{booking_ref}}',
        ]]);

        app(CrmSequenceService::class)->sendTest($sequence, 'tester@example.test');

        $this->assertStringNotContainsString('Book a call', $this->firstBody());
    }

    /**
     * Guards a live regression: the shared merge resolver had no alias for rep_name, so every
     * sequence signed off with the literal text "{{rep_name}}", and a record carrying both the
     * recipient's `company` and our `company_name` resolved both to the same slot — so a
     * prospecting email told the prospect that *we* were the company it was about.
     */
    public function test_the_signature_and_the_recipients_company_merge_correctly_on_a_real_send(): void
    {
        $contact = CrmContact::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Nomsa', 'last_name' => 'Dlamini',
            'email' => 'nomsa+' . Str::random(6) . '@example.test',
        ]);

        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0, 'subject' => 'Hello {{first_name}}',
            'body' => "Working with teams like {{company}}.\n\n{{rep_name}}",
        ]]);
        $sequence->update(['active' => true]);

        app(CrmSequenceService::class)->enroll($sequence, [[
            'contact_id' => $contact->id, 'name' => 'Nomsa Dlamini', 'email' => $contact->email,
        ]]);

        CrmSequenceEnrollment::where('sequence_id', $sequence->id)->update(['next_step_at' => now()->subMinute()]);

        app(CrmSequenceService::class)->processDue();

        $html = $this->firstBody();
        $this->assertStringNotContainsString('rep_name', $html, 'The signature token survived the merge.');
        $this->assertStringNotContainsString('{{company}}', $html);

        // The half of this that used to pass for the wrong reason. This contact has no account, so
        // there is no recipient company to merge — and the slot was being filled by OUR name,
        // carried across from a sibling key, so the prospect read "working with teams like
        // <us>". The assertion above was satisfied by the very bug the docblock describes.
        $this->assertStringNotContainsString(
            (string) config('app.name'),
            $html,
            'Our own company name must never be merged in as the recipient\'s.',
        );
    }

    // ── Deals on hold ────────────────────────────────────────────────────────

    public function test_holding_a_deal_keeps_its_stage_and_takes_it_out_of_the_forecast(): void
    {
        $pipeline = $this->pipeline();
        $deal = $this->deal($pipeline);
        $proposal = $deal->stage_id;

        $this->actingAs($this->user())->post(route('crm.deals.hold', $deal->uuid), [
            'hold_reason' => 'Budget frozen until the new financial year',
            'hold_until'  => now()->addMonths(3)->toDateString(),
        ])->assertRedirect();

        $deal->refresh();

        $this->assertSame('on_hold', $deal->status);
        $this->assertSame($proposal, $deal->stage_id, 'A held deal must keep its stage.');
        $this->assertSame($proposal, $deal->hold_stage_id);
        $this->assertNotNull($deal->held_at);

        // Every forecast and dashboard query in the module filters on status = open, so this is
        // what actually removes the deal from the numbers.
        $this->assertFalse(CrmDeal::where('status', 'open')->whereKey($deal->id)->exists());
    }

    public function test_a_hold_needs_a_reason_and_a_future_date(): void
    {
        $deal = $this->deal($this->pipeline());

        $this->actingAs($this->user())
            ->post(route('crm.deals.hold', $deal->uuid), ['hold_reason' => '', 'hold_until' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors(['hold_reason', 'hold_until']);

        $this->assertSame('open', $deal->fresh()->status);
    }

    public function test_resuming_returns_the_deal_to_the_stage_it_left(): void
    {
        $pipeline = $this->pipeline();
        $deal = $this->deal($pipeline);
        $proposal = $pipeline->stages->last();

        $holds = app(CrmDealHoldService::class);
        $holds->hold($deal, 'Paused', now()->addMonths(2)->toDateString());

        // Someone reorganises the board while it sleeps.
        $deal->refresh();
        $holds->resume($deal);

        $deal->refresh();
        $this->assertSame('open', $deal->status);
        $this->assertSame($proposal->id, $deal->stage_id);
        $this->assertSame($proposal->probability, $deal->probability);
    }

    public function test_the_wake_up_returns_deals_on_the_day_the_client_asked_for(): void
    {
        $pipeline = $this->pipeline();
        $due    = $this->deal($pipeline);
        $notYet = $this->deal($pipeline);

        $holds = app(CrmDealHoldService::class);
        $holds->hold($due, 'Call us in March', now()->addMonths(3)->toDateString());
        $holds->hold($notYet, 'Call us next year', now()->addMonths(9)->toDateString());

        // The date arrives.
        $due->forceFill(['hold_until' => now()->subDay()->toDateString()])->save();

        $this->assertSame(1, $holds->wakeDue());

        $this->assertSame('open', $due->fresh()->status);
        $this->assertSame('on_hold', $notYet->fresh()->status);

        // A retried cron must not wake the same deal twice.
        $this->assertSame(0, $holds->wakeDue());
    }

    public function test_the_owner_gets_a_task_when_a_hold_expires(): void
    {
        $pipeline = $this->pipeline();
        $deal = $this->deal($pipeline);

        $holds = app(CrmDealHoldService::class);
        $holds->hold($deal, 'Revisit after their merger', now()->addMonth()->toDateString());
        $deal->forceFill(['hold_until' => now()->subDay()->toDateString()])->save();
        $holds->wakeDue();

        $task = $deal->activities()->where('type', 'task')->where('status', 'pending')->first();

        $this->assertNotNull($task, 'A woken deal must leave a to-do, not just a notification.');
        $this->assertStringContainsString('Hold expired', $task->subject);
    }

    public function test_the_nurture_cadence_starts_on_hold_and_stops_on_resume(): void
    {
        $pipeline = $this->pipeline();
        $contact = CrmContact::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Nomsa', 'last_name' => 'Dlamini',
            'email' => 'nomsa+' . Str::random(6) . '@example.test',
        ]);
        $deal = $this->deal($pipeline, $contact->id);
        $sequence = $this->sequence([['channel' => 'email', 'day_offset' => 30, 'subject' => 'Still here', 'body' => 'Hi']]);

        $holds = app(CrmDealHoldService::class);
        $result = $holds->hold($deal, 'Paused for six months', now()->addMonths(6)->toDateString(), $sequence->id);

        $this->assertStringContainsString($sequence->name, (string) $result['nurture']);

        $enrollment = CrmSequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('email', mb_strtolower($contact->email))->firstOrFail();
        $this->assertSame('active', $enrollment->status);

        $holds->resume($deal->fresh());

        // The rep is about to phone them; a nurture email the same week reads as chaos.
        $this->assertSame('stopped', $enrollment->fresh()->status);
    }

    public function test_a_deal_with_nobody_reachable_says_nobody_was_enrolled_rather_than_failing_quietly(): void
    {
        $deal = $this->deal($this->pipeline());
        $sequence = $this->sequence([['channel' => 'email', 'day_offset' => 30, 'subject' => 'Hi', 'body' => 'Hi']]);

        $result = app(CrmDealHoldService::class)->hold($deal, 'Paused', now()->addMonth()->toDateString(), $sequence->id);

        // The point of the assertion is unchanged — a hold that nurtures nobody says so out loud.
        // The wording widened with the behaviour: a hold now looks at everyone on the account, not
        // only the deal's named contact, so "no contact" would have been describing the old rule.
        $this->assertStringContainsString('nobody', (string) $result['nurture']);
    }

    // ── The hold nurture template ────────────────────────────────────────────

    /**
     * The mix the sequence was specified with: 40% education, 30% problem/solution, 20% proof,
     * 10% direct sales. Asserted because it is the thing most likely to erode — one more "quick
     * check-in" at a time — until a paused client is being sold to every fortnight.
     */
    public function test_the_nurture_template_keeps_the_forty_thirty_twenty_ten_mix(): void
    {
        $template = CrmSequence::holdNurtureTemplate('warehouse management');
        $emails = collect($template['steps'])->where('channel', 'email');

        $this->assertCount(10, $emails);

        $mix = $emails->countBy('kind');
        $this->assertSame(4, $mix['education'], 'Education should be 40% of the cadence.');
        $this->assertSame(3, $mix['problem'], 'Problem/solution should be 30%.');
        $this->assertSame(2, $mix['proof'], 'Proof should be 20%.');
        $this->assertSame(1, $mix['sales'], 'There should be exactly one direct ask.');
    }

    public function test_the_nurture_talks_about_what_they_were_buying(): void
    {
        $template = CrmSequence::holdNurtureTemplate('warehouse management');
        $text = json_encode($template['steps']);

        $this->assertStringContainsString('warehouse management', $template['name']);
        // Every email, not just the first: the point is that six months of follow-up stays on
        // their subject rather than drifting back to talking about us.
        foreach ($template['steps'] as $i => $step) {
            $this->assertStringContainsString(
                'warehouse management',
                $step['subject'] . ' ' . $step['body'],
                "Step {$i} never mentions what they were buying.",
            );
        }
        $this->assertStringNotContainsString('{topic}', $text, 'A topic placeholder was left unmerged.');
    }

    public function test_every_nurture_email_carries_its_own_preview_line(): void
    {
        $steps = CrmSequence::holdNurtureTemplate('warehouse management')['steps'];

        foreach ($steps as $i => $step) {
            $preview = trim((string) ($step['preview_text'] ?? ''));

            $this->assertNotSame('', $preview, "Step {$i} has no preview line, so the inbox will scrape \"Hi Sample,\".");
            // Long enough to say something, short enough that no client truncates it mid-word.
            $this->assertLessThanOrEqual(140, mb_strlen($preview), "Step {$i}'s preview line will be cut off.");
        }
    }

    public function test_the_only_direct_ask_comes_last(): void
    {
        $steps = collect(CrmSequence::holdNurtureTemplate('fleet tracking')['steps']);
        $sales = $steps->firstWhere('kind', 'sales');

        $this->assertSame($steps->max('day_offset'), $sales['day_offset']);
        $this->assertGreaterThan(150, $sales['day_offset'], 'The ask should come months in, not weeks.');
    }

    public function test_a_drafted_cadence_is_paused_and_says_so(): void
    {
        $pipeline = $this->pipeline();
        $contact = CrmContact::create([
            'uuid' => (string) Str::uuid(), 'first_name' => 'Sipho', 'last_name' => 'Khumalo',
            'email' => 'sipho+' . Str::random(6) . '@example.test',
        ]);
        $deal = $this->deal($pipeline, $contact->id);

        $result = app(CrmDealHoldService::class)->hold(
            $deal, 'Paused until the new year', now()->addMonths(4)->toDateString(),
            null, 'warehouse management',
        );

        $sequence = CrmSequence::findOrFail($result['drafted_sequence']);

        // Two of its emails carry [placeholders]; sending those would be worse than sending nothing.
        $this->assertFalse((bool) $sequence->active);
        $this->assertTrue((bool) $sequence->stop_on_reply);
        $this->assertSame([1, 2, 3, 4, 5], $sequence->send_days, 'Nurture should stay off weekends.');

        // And the rep is told, rather than left believing the client is being looked after.
        $this->assertStringContainsString('switched OFF', (string) $result['nurture']);
    }

    public function test_a_topic_and_an_existing_sequence_are_not_both_applied(): void
    {
        $pipeline = $this->pipeline();
        $existing = $this->sequence([['channel' => 'email', 'day_offset' => 10, 'subject' => 'Hi', 'body' => 'Hi']]);
        $deal = $this->deal($pipeline);

        $this->actingAs($this->user())->post(route('crm.deals.hold', $deal->uuid), [
            'hold_reason'      => 'Paused',
            'hold_until'       => now()->addMonths(2)->toDateString(),
            'hold_sequence_id' => $existing->id,
            'nurture_topic'    => 'warehouse management',
        ]);

        // The drafted one wins; the deal must not end up pointing at the other.
        $this->assertNotSame($existing->id, $deal->fresh()->hold_sequence_id);
    }

    /**
     * The board has to ship the hold dialog's ingredients and keep held cards in the payload —
     * they are filtered into their own column client-side, not queried separately.
     */
    public function test_the_board_ships_a_held_deal_and_what_the_hold_dialog_needs(): void
    {
        $pipeline = $this->pipeline();
        $working = $this->deal($pipeline);
        $held    = $this->deal($pipeline);

        app(CrmDealHoldService::class)->hold($held, 'Budget frozen', now()->addMonths(3)->toDateString());

        $this->actingAs($this->user())
            ->get(route('crm.deals', ['pipeline' => $pipeline->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Modules/CRM/Deals')
                ->has('sequences')
                ->has('deals', 2)
                ->where('deals.0.status', fn ($s) => in_array($s, ['open', 'on_hold'], true))
                // The card needs all three to render: the reason, the return date, and the fact
                // that it is asleep at all.
                ->has('deals.0.hold_reason')
                ->has('deals.0.hold_until'));

        $payload = $this->actingAs($this->user())
            ->get(route('crm.deals', ['pipeline' => $pipeline->id]))
            ->viewData('page')['props']['deals'];

        $statuses = collect($payload)->pluck('status', 'id');
        $this->assertSame('on_hold', $statuses[$held->id]);
        $this->assertSame('open', $statuses[$working->id]);
    }

    public function test_dragging_a_held_deal_back_onto_the_board_resumes_it_properly(): void
    {
        $pipeline = $this->pipeline();
        $deal = $this->deal($pipeline);
        $qualify = $pipeline->stages->first();

        app(CrmDealHoldService::class)->hold($deal, 'Paused', now()->addMonth()->toDateString());

        $this->actingAs($this->user())
            ->post(route('crm.deals.move', $deal->fresh()->uuid), ['stage_id' => $qualify->id])
            ->assertRedirect();

        $deal->refresh();
        $this->assertSame('open', $deal->status);
        $this->assertSame($qualify->id, $deal->stage_id);
        $this->assertNotNull($deal->hold_woken_at, 'The drag bypassed the resume path.');
    }
}
