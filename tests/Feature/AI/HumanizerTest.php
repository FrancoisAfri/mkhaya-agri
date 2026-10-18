<?php

namespace Tests\Feature\AI;

use App\Core\AI\Services\HumanizerService;
use Tests\TestCase;

/**
 * Taking the tells out of machine-written copy.
 *
 * Every article, ad, subject line and outreach message this platform sends comes out of a language
 * model, and models have a house style people recognise on sight. A prospect who spots it stops
 * reading; a client who spots it in work they paid for asks what they are paying for.
 */
class HumanizerTest extends TestCase
{
    private function humanizer(): HumanizerService
    {
        return app(HumanizerService::class);
    }

    // ── The guidance that goes into the prompt ───────────────────────────────

    public function test_the_rules_are_appended_to_a_system_prompt(): void
    {
        $prompt = $this->humanizer()->applyTo('You are a copywriter.');

        $this->assertStringContainsString('You are a copywriter.', $prompt);
        // Cheapest place to fix this is before the text is written, not after.
        $this->assertStringContainsString('No em dashes', $prompt);
        $this->assertStringContainsString('delve', $prompt);
    }

    public function test_it_can_be_switched_off(): void
    {
        config(['ai.humanizer.enabled' => false]);

        $this->assertSame('You are a copywriter.', $this->humanizer()->applyTo('You are a copywriter.'));
        $this->assertSame('We delve into the tapestry.', $this->humanizer()->clean('We delve into the tapestry.'));
    }

    // ── The mechanical pass ──────────────────────────────────────────────────

    public function test_filler_is_shortened_without_changing_the_meaning(): void
    {
        $cleaned = $this->humanizer()->clean(
            'In order to reduce costs, and due to the fact that margins are thin, the system has the ability to batch invoices.',
        );

        $this->assertStringContainsString('To reduce costs', $cleaned);
        $this->assertStringContainsString('because margins are thin', $cleaned);
        $this->assertStringContainsString('can batch invoices', $cleaned);
    }

    public function test_em_dashes_become_ordinary_punctuation(): void
    {
        $cleaned = $this->humanizer()->clean('Payroll is late — again.');

        $this->assertStringNotContainsString('—', $cleaned);
        $this->assertStringContainsString('Payroll is late, again.', $cleaned);
    }

    public function test_a_pair_of_em_dashes_becomes_brackets(): void
    {
        $cleaned = $this->humanizer()->clean('The rate — set every March — applies from the first.');

        $this->assertStringContainsString('(set every March)', $cleaned);
        $this->assertStringNotContainsString('—', $cleaned);
    }

    public function test_an_em_dash_joining_two_sentences_becomes_a_full_stop(): void
    {
        $cleaned = $this->humanizer()->clean('The run failed — Thandi had to reprocess it.');

        $this->assertStringContainsString('failed. Thandi', $cleaned);
    }

    public function test_curly_quotes_are_straightened(): void
    {
        $cleaned = $this->humanizer()->clean("It\u{2019}s the \u{201C}best\u{201D} option\u{2026}");

        $this->assertStringContainsString("It's the \"best\" option...", $cleaned);
    }

    public function test_emoji_are_removed_from_copy(): void
    {
        $cleaned = $this->humanizer()->clean("- 🚀 Fast setup\n- ✅ Simple pricing");

        $this->assertStringNotContainsString('🚀', $cleaned);
        // The bullet survives; only the decoration goes.
        $this->assertStringContainsString('- Fast setup', $cleaned);
    }

    public function test_copula_avoidance_is_undone(): void
    {
        $cleaned = $this->humanizer()->clean('The module serves as a central record.');

        $this->assertStringContainsString('The module is a central record.', $cleaned);
    }

    public function test_chatbot_pleasantries_are_stripped(): void
    {
        $cleaned = $this->humanizer()->clean("Certainly! Here's the summary you requested: payroll ran on Friday. I hope this helps!");

        $this->assertStringNotContainsString('Certainly', $cleaned);
        $this->assertStringNotContainsString('I hope this helps', $cleaned);
        // The sentence that lost its opening clause is recapitalised rather than left headless.
        $this->assertStringContainsString('Payroll ran on Friday', $cleaned);
    }

    public function test_bolded_list_labels_are_unbolded(): void
    {
        $cleaned = $this->humanizer()->clean("- **Speed:** it runs in a second\n- **Cost:** it is free");

        $this->assertStringContainsString('- Speed: it runs in a second', $cleaned);
        $this->assertStringNotContainsString('**Speed:**', $cleaned);
    }

    public function test_title_case_headings_lose_their_capitalised_function_words(): void
    {
        $cleaned = $this->humanizer()->clean('## How To Run Your First Payroll');

        // Function words are lowered; everything else keeps its capital, because the alternative
        // is renaming things. Slightly imperfect Title Case beats "cape town".
        $this->assertStringContainsString('How to Run your First Payroll', $cleaned);
    }

    public function test_a_place_name_in_a_heading_is_never_renamed(): void
    {
        $cleaned = $this->humanizer()->clean('## Cape Town Office Safety Review');

        // The old rule lowered any capitalised ordinary word, which produced "Cape town".
        $this->assertStringContainsString('Cape Town', $cleaned);
    }

    public function test_an_organisation_name_survives(): void
    {
        $cleaned = $this->humanizer()->clean('## Working With SARS And The Department Of Labour');

        $this->assertStringContainsString('SARS', $cleaned);
        $this->assertStringContainsString('Department of Labour', $cleaned);
        $this->assertStringContainsString('Working with', $cleaned);
    }

    public function test_a_short_heading_is_left_alone(): void
    {
        // Two or three words are as likely to be a name as a Title Case habit.
        $this->assertStringContainsString('## Leave Bureau', $this->humanizer()->clean('## Leave Bureau'));
    }

    public function test_an_acronym_keeps_its_case(): void
    {
        $cleaned = $this->humanizer()->clean('## Understanding Your SARS Tax Certificate Obligations');

        $this->assertStringContainsString('SARS', $cleaned);
        $this->assertStringContainsString('your', $cleaned);
    }

    // ── Structured payloads are left alone ───────────────────────────────────

    public function test_a_json_payload_is_never_cleaned(): void
    {
        $json = '{"linkedin": "The ' . "\u{201C}" . 'best' . "\u{201D}" . ' payroll option"}';

        // Straightening a curly quote inside a JSON string value ends the string, and the caller
        // silently gets an empty array back instead of the content it asked for.
        $this->assertSame($json, $this->humanizer()->clean($json));
        $this->assertIsArray(json_decode($this->humanizer()->clean($json), true));
    }

    public function test_prose_fields_of_a_decoded_payload_are_cleaned(): void
    {
        $cleaned = $this->humanizer()->cleanFields([
            'linkedin' => 'We leverage best practice ' . "\u{2014}" . ' always.',
            'nested'   => ['x' => 'In order to win, we utilise data.'],
        ]);

        $this->assertStringContainsString('We use best practice', $cleaned['linkedin']);
        $this->assertStringNotContainsString("\u{2014}", $cleaned['linkedin']);
        $this->assertStringContainsString('To win', $cleaned['nested']['x']);
    }

    public function test_identifiers_in_a_payload_are_left_alone(): void
    {
        $cleaned = $this->humanizer()->cleanFields([
            'slug' => 'leverage-your-payroll',
            'url'  => 'https://example.test/in-order-to-win',
            'type' => 'linkedin',
        ]);

        // Rewriting a word inside a slug or a URL breaks whatever reads it.
        $this->assertSame('leverage-your-payroll', $cleaned['slug']);
        $this->assertSame('https://example.test/in-order-to-win', $cleaned['url']);
        $this->assertSame('linkedin', $cleaned['type']);
    }

    public function test_a_sentence_left_headless_by_a_deletion_is_recapitalised(): void
    {
        $cleaned = $this->humanizer()->clean('It is important to note that payroll runs on Friday.');

        $this->assertSame('Payroll runs on Friday.', $cleaned);
    }

    // ── The reporting pass ───────────────────────────────────────────────────

    public function test_inflated_significance_is_reported_rather_than_rewritten(): void
    {
        $report = $this->humanizer()->scan(
            'The launch is a testament to our commitment, marking a pivotal moment in the evolving landscape.',
        );

        $keys = array_column($report['findings'], 'key');
        $this->assertContains('inflated_significance', $keys);
        // Fixing this properly needs to know what the writer meant, so it is flagged, not guessed.
        $this->assertLessThan(85, $report['score']);
    }

    public function test_vague_attribution_is_caught(): void
    {
        $report = $this->humanizer()->scan('Experts say the market will grow. Industry reports suggest the same.');

        $finding = collect($report['findings'])->firstWhere('key', 'vague_attribution');
        $this->assertNotNull($finding);
        $this->assertStringContainsString('Name the source', $finding['note']);
    }

    public function test_a_trailing_ing_clause_is_caught(): void
    {
        $report = $this->humanizer()->scan(
            'The system batches invoices, ensuring accuracy and highlighting the importance of automation.',
        );

        $this->assertContains('superficial_ing', array_column($report['findings'], 'key'));
    }

    public function test_negative_parallelism_is_caught(): void
    {
        $report = $this->humanizer()->scan("It's not just software, it's a way of working.");

        $this->assertContains('negative_parallelism', array_column($report['findings'], 'key'));
    }

    public function test_uniform_sentence_length_is_measured(): void
    {
        $robotic = 'The system runs fast. The system runs well. The system runs clean. The system runs late.';
        $human = 'It runs. Most of the time it does exactly what you would expect, which is to say it '
            . 'processes the batch and gets out of the way. Sometimes not. Friday afternoons are the worst.';

        // The tell that survives every vocabulary fix, and the one no word list catches.
        $this->assertLessThan(
            $this->humanizer()->scan($human)['stats']['sentence_variance'],
            $this->humanizer()->scan($robotic)['stats']['sentence_variance'],
        );
    }

    public function test_clean_copy_scores_well(): void
    {
        $report = $this->humanizer()->scan(
            'Payroll ran on Friday. Two employees were paid late because their bank details had changed '
            . 'that week. Both were corrected on Monday and the run was reissued.',
        );

        $this->assertSame([], $report['findings']);
        $this->assertGreaterThanOrEqual(85, $report['score']);
        $this->assertStringContainsString('human', $this->humanizer()->verdict($report['score']));
    }

    public function test_the_worst_copy_is_called_what_it_is(): void
    {
        $report = $this->humanizer()->scan(
            'Nestled in the vibrant realm of fintech, our groundbreaking platform serves as a testament '
            . 'to innovation — delivering seamless, robust, and multifaceted solutions. It\'s not just '
            . 'software, it\'s a journey. Experts believe the future looks bright. 🚀',
        );

        $this->assertLessThan(40, $report['score']);
        $this->assertStringContainsString('Rewrite', $this->humanizer()->verdict($report['score']));
    }

    public function test_process_cleans_then_reports_on_what_is_left(): void
    {
        $result = $this->humanizer()->process('In order to win — we delve into the data. Experts say it works.');

        $this->assertStringNotContainsString('—', $result['text']);
        $this->assertStringNotContainsString('delve into', $result['text']);
        // The vague attribution survives cleaning and is reported instead.
        $this->assertContains('vague_attribution', array_column($result['report']['findings'], 'key'));
    }

    public function test_empty_input_is_handled_without_fuss(): void
    {
        $this->assertSame('', $this->humanizer()->clean(null));
        $this->assertSame(100, $this->humanizer()->scan(null)['score']);
    }
}
