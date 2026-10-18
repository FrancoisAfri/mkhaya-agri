<?php

namespace Tests\Unit\CRM;

use App\Modules\CRM\Models\CrmActivity;
use Tests\TestCase;

/**
 * The account timeline had become unreadable.
 *
 * A sequence email is logged with the message that was actually sent, and a marketing email is a
 * complete HTML document — doctype, a stylesheet for Outlook, table scaffolding. Printed as text in
 * the activity list that is several hundred characters of CSS before the first real word, so the
 * feed showed "body, table, td, a { -webkit-text-size-adjust: 100%..." where the message should be.
 */
class ActivityDescriptionTest extends TestCase
{
    private function activity(?string $description): CrmActivity
    {
        $activity = new CrmActivity;
        $activity->description = $description;

        return $activity;
    }

    public function test_a_marketing_email_reads_as_its_message(): void
    {
        $email = <<<'HTML'
        <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
        <title>Mkhaya Hub - Follow Up</title>
        <style type="text/css"> body, table, td, a { -webkit-text-size-adjust: 100%; }
        table { border-collapse: collapse !important; } </style></head>
        <body style="margin:0; background-color:#000000;">
        <div style="display:none; max-height:0px;">A final follow-up regarding Mkhaya Hub.</div>
        <table><tr><td><p>Good Day</p>
        <p>I wanted to send one final follow-up regarding <strong>Mkhaya Hub</strong>.</p>
        </td></tr></table></body></html>
        HTML;

        $readable = $this->activity($email)->readableDescription();

        $this->assertStringNotContainsString('-webkit-text-size-adjust', $readable,
            'The stylesheet was still being shown to the reader.');
        $this->assertStringNotContainsString('<', $readable, 'Markup survived into the feed.');
        $this->assertStringContainsString('Good Day', $readable);
        $this->assertStringContainsString('Mkhaya Hub', $readable);
    }

    public function test_words_do_not_run_together_where_tags_were(): void
    {
        $readable = $this->activity('<p>Good Day</p><p>I wanted to follow up.</p>')->readableDescription();

        $this->assertStringContainsString('Good Day I wanted to follow up.', $readable);
    }

    public function test_a_note_typed_by_a_person_is_left_exactly_as_typed(): void
    {
        $note = "Called Jabulani. He wants a quote by Friday — budget is R80 000 < R100 000.";

        $this->assertSame($note, $this->activity($note)->readableDescription());
    }

    public function test_entities_are_decoded_rather_than_shown_raw(): void
    {
        $readable = $this->activity('<p>Terms &amp; Conditions &mdash; R80&nbsp;000</p>')->readableDescription();

        $this->assertStringContainsString('Terms & Conditions', $readable);
        $this->assertStringNotContainsString('&amp;', $readable);
    }

    public function test_an_injected_script_becomes_words_rather_than_markup(): void
    {
        // These bodies come from sequences and, later, from replies, so they are not ours to trust.
        $readable = $this->activity('<div>Hi<script>alert("xss")</script></div>')->readableDescription();

        $this->assertStringNotContainsString('alert(', $readable);
        $this->assertStringNotContainsString('<script', $readable);
    }

    public function test_a_long_email_is_clamped(): void
    {
        $long = '<p>' . str_repeat('word ', 500) . '</p>';

        // 400 characters, plus whatever Str::limit appends to mark the truncation.
        $this->assertLessThanOrEqual(410, mb_strlen((string) $this->activity($long)->readableDescription()));
    }

    public function test_an_empty_description_stays_empty(): void
    {
        $this->assertNull($this->activity(null)->readableDescription());
        $this->assertNull($this->activity('')->readableDescription());
        $this->assertNull($this->activity('<html><head><style>a{}</style></head><body></body></html>')->readableDescription());
    }
}
