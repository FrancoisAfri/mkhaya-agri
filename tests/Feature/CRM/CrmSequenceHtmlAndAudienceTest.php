<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\CrmAccount;
use App\Modules\CRM\Models\CrmContact;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Models\CrmStage;
use App\Modules\CRM\Services\CrmDealHoldService;
use App\Modules\CRM\Services\CrmSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Three things a nurture cadence was getting wrong.
 *
 * A step written with markup arrived showing its own tags, because every body was escaped on the
 * way out — the cadence "looked very nice" in the editor and landed as plain text with visible
 * angle brackets. A hold enrolled the deal's named contact and nobody else, so the sponsor got
 * nurtured while the finance lead and whoever inherits the account heard nothing for months. And
 * reading a cadence before it went to a client meant mailing yourself a copy.
 */
class CrmSequenceHtmlAndAudienceTest extends TestCase
{
    use DatabaseTransactions;

    private function sequence(array $steps): CrmSequence
    {
        return CrmSequence::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cadence ' . Str::random(6),
            'steps' => $steps,
            'active' => true,
        ]);
    }

    // ── HTML bodies ─────────────────────────────────────────────────────────

    public function test_an_html_step_is_rendered_as_markup_not_shown_as_tags(): void
    {
        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0, 'subject' => 'Hello',
            'body' => '<p style="color:#333">Hi <strong>{{first_name}}</strong></p>',
            'body_format' => 'html',
        ]]);

        $html = app(CrmSequenceService::class)->preview($sequence)[0]['html'];

        $this->assertStringContainsString('<strong>', $html, 'HTML markup must survive to the inbox.');
        $this->assertStringNotContainsString('&lt;strong&gt;', $html, 'HTML must not be escaped into visible tags.');
    }

    public function test_a_plain_text_step_is_still_escaped_so_prose_cannot_become_markup(): void
    {
        // The default, and the safe one: a sentence containing a < is a sentence, not an element.
        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0, 'subject' => 'Hello',
            'body' => "Margins <10% are the problem\nSecond line",
            'body_format' => 'text',
        ]]);

        $html = app(CrmSequenceService::class)->preview($sequence)[0]['html'];

        $this->assertStringContainsString('&lt;10%', $html, 'Text bodies must be escaped.');
        $this->assertStringContainsString('<br', $html, 'Text bodies keep their line breaks.');
    }

    public function test_a_step_that_predates_the_setting_is_treated_as_text(): void
    {
        // Every seeded cadence is in this state. Rendering these raw would collapse their line
        // breaks and hand ordinary punctuation to the mail client as markup.
        $sequence = $this->sequence([[
            'channel' => 'email', 'day_offset' => 0, 'subject' => 'Hello',
            'body' => "Line one\nLine two",
        ]]);

        $html = app(CrmSequenceService::class)->preview($sequence)[0]['html'];

        $this->assertStringContainsString('<br', $html);
    }

    // ── Preview ─────────────────────────────────────────────────────────────

    public function test_preview_renders_every_step_without_sending_anything(): void
    {
        $sequence = $this->sequence([
            ['channel' => 'email', 'day_offset' => 0, 'subject' => 'One', 'body' => 'First'],
            ['channel' => 'email', 'day_offset' => 3, 'subject' => 'Two', 'body' => 'Second'],
        ]);

        $preview = app(CrmSequenceService::class)->preview($sequence);

        $this->assertCount(2, $preview);
        $this->assertSame('One', $preview[0]['subject']);
        $this->assertStringContainsString('Second', $preview[1]['html']);
    }

    public function test_preview_can_be_narrowed_to_one_step(): void
    {
        $sequence = $this->sequence([
            ['channel' => 'email', 'day_offset' => 0, 'subject' => 'One', 'body' => 'First'],
            ['channel' => 'email', 'day_offset' => 3, 'subject' => 'Two', 'body' => 'Second'],
        ]);

        $preview = app(CrmSequenceService::class)->preview($sequence, 1);

        $this->assertCount(1, $preview);
        $this->assertSame(1, $preview[0]['index']);
    }

    // ── Who a hold nurtures ─────────────────────────────────────────────────

    private function heldDeal(): array
    {
        $pipeline = CrmPipeline::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'P' . Str::random(4)]);
        $stage = CrmStage::forceCreate(['pipeline_id' => $pipeline->id, 'name' => 'Qualify']);
        $account = CrmAccount::create(['uuid' => (string) Str::uuid(), 'name' => 'Acme ' . Str::random(4)]);

        $sponsor = CrmContact::create([
            'uuid' => (string) Str::uuid(), 'account_id' => $account->id,
            'first_name' => 'Sponsor', 'last_name' => 'One', 'email' => 'sponsor+' . Str::random(5) . '@example.test',
        ]);
        CrmContact::create([
            'uuid' => (string) Str::uuid(), 'account_id' => $account->id,
            'first_name' => 'Finance', 'last_name' => 'Two', 'email' => 'finance+' . Str::random(5) . '@example.test',
        ]);
        CrmContact::create([
            'uuid' => (string) Str::uuid(), 'account_id' => $account->id,
            'first_name' => 'Ops', 'last_name' => 'Three', 'email' => 'ops+' . Str::random(5) . '@example.test',
        ]);

        $deal = CrmDeal::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Deal ' . Str::random(4),
            'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'account_id' => $account->id, 'contact_id' => $sponsor->id,
            'status' => 'open', 'amount' => 1000,
        ]);

        return [$deal, $account];
    }

    public function test_holding_a_deal_nurtures_everybody_on_the_account(): void
    {
        // A pause is a decision the client's side takes together. Nurturing the sponsor alone is
        // how a deal comes back to somebody who never heard from us.
        [$deal] = $this->heldDeal();
        $sequence = $this->sequence([['channel' => 'email', 'day_offset' => 0, 'subject' => 'Still here', 'body' => 'Hi']]);

        app(CrmDealHoldService::class)->hold($deal, 'Budget frozen', now()->addMonths(3)->toDateString(), $sequence->id);

        $this->assertSame(3, $sequence->enrollments()->count(), 'Every contact on the account should be nurtured.');
    }

    public function test_the_same_person_on_the_account_twice_is_only_written_to_once(): void
    {
        [$deal, $account] = $this->heldDeal();

        // Routine on a real account: one person, two records, one inbox.
        $duplicate = mb_strtolower((string) $deal->contact->email);
        CrmContact::create([
            'uuid' => (string) Str::uuid(), 'account_id' => $account->id,
            'first_name' => 'Sponsor', 'last_name' => 'Duplicate', 'email' => strtoupper($duplicate),
        ]);

        $sequence = $this->sequence([['channel' => 'email', 'day_offset' => 0, 'subject' => 'Still here', 'body' => 'Hi']]);

        app(CrmDealHoldService::class)->hold($deal, 'Budget frozen', now()->addMonths(3)->toDateString(), $sequence->id);

        $this->assertSame(3, $sequence->enrollments()->count(), 'A duplicated contact must not be mailed twice.');
    }

    public function test_an_account_with_nobody_reachable_says_so_rather_than_failing_quietly(): void
    {
        $pipeline = CrmPipeline::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'P' . Str::random(4)]);
        $stage = CrmStage::forceCreate(['pipeline_id' => $pipeline->id, 'name' => 'Qualify']);
        $account = CrmAccount::create(['uuid' => (string) Str::uuid(), 'name' => 'Silent ' . Str::random(4)]);

        $deal = CrmDeal::create([
            'uuid' => (string) Str::uuid(), 'title' => 'Deal ' . Str::random(4),
            'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id,
            'account_id' => $account->id, 'status' => 'open', 'amount' => 1000,
        ]);

        $sequence = $this->sequence([['channel' => 'email', 'day_offset' => 0, 'subject' => 'Hi', 'body' => 'Hi']]);

        $result = app(CrmDealHoldService::class)->hold($deal, 'Paused', now()->addMonth()->toDateString(), $sequence->id);

        $this->assertStringContainsString('nobody', (string) $result['nurture']);
        $this->assertSame(0, $sequence->enrollments()->count());
    }
}
