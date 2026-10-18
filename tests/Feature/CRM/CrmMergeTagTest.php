<?php

namespace Tests\Feature\CRM;

use App\Core\Messaging\Services\MergeFields;
use App\Modules\CRM\Models\CrmEmailTemplate;
use App\Modules\CRM\Models\CrmSequence;
use App\Modules\CRM\Services\CrmSequenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Which placeholder notations work, and the one that works and is wrong.
 *
 * All four notations resolve — {{first_name}}, [First Name], {first_name}, %FIRST_NAME% — which is
 * deliberate, because nobody writing an email remembers which one a system wants.
 *
 * The trap is `company_name`. It resolves cleanly to the RECIPIENT's company, so a step reading
 * "I'm Ann from {{company_name}}" tells the prospect we work for them. It reads naturally, survives
 * a test send because the tester knows which company is which, and the first person to notice is
 * the client. It was on the advertised tag list; the honest tag for our own name is
 * `sender_company`.
 */
class CrmMergeTagTest extends TestCase
{
    use DatabaseTransactions;

    private function data(): array
    {
        return [
            'first_name' => 'Nomsa', 'last_name' => 'Dlamini',
            'company' => 'Elliott International', 'title' => 'CFO',
            'sender' => 'Francois', 'sender_company' => 'Mkhaya',
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function notations(): array
    {
        return [
            'double brace' => ['{{first_name}}'],
            'square bracket' => ['[first_name]'],
            'square bracket, spaced and capitalised' => ['[First Name]'],
            'single brace' => ['{first_name}'],
            'percent' => ['%FIRST_NAME%'],
        ];
    }

    #[DataProvider('notations')]
    public function test_every_notation_a_person_might_type_resolves(string $tag): void
    {
        $this->assertSame('Nomsa', app(MergeFields::class)->apply($tag, $this->data())['text']);
    }

    public function test_company_means_the_recipient_and_sender_company_means_us(): void
    {
        $merge = app(MergeFields::class);

        $this->assertSame('Elliott International', $merge->apply('{{company}}', $this->data())['text']);
        $this->assertSame('Mkhaya', $merge->apply('{{sender_company}}', $this->data())['text']);
    }

    public function test_company_name_resolves_to_the_recipient_which_is_why_it_is_not_offered(): void
    {
        // Pinning the behaviour rather than endorsing it: MergeFields canonicalises "company name"
        // to the recipient's company for every module, and changing that would rewrite what the
        // tag means everywhere it is already used correctly.
        $this->assertSame(
            'Elliott International',
            app(MergeFields::class)->apply('{{company_name}}', $this->data())['text'],
        );

        $this->assertNotContains('company_name', CrmEmailTemplate::MERGE_TAGS);
        $this->assertContains('sender_company', CrmEmailTemplate::MERGE_TAGS);
    }

    public function test_an_unknown_tag_is_left_visible_and_reported(): void
    {
        // Left in place rather than blanked: a sentence with a gap in it reads as finished prose,
        // whereas "{{nonsense}}" on screen is unmistakable.
        $result = app(MergeFields::class)->apply('Hi {{nonsense_tag}}', $this->data());

        $this->assertStringContainsString('{{nonsense_tag}}', $result['text']);
        $this->assertNotEmpty($result['unresolved']);
    }

    public function test_preview_warns_when_a_step_uses_the_misleading_company_tag(): void
    {
        $sequence = CrmSequence::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cadence ' . Str::random(6),
            'steps' => [[
                'channel' => 'email', 'day_offset' => 0,
                'subject' => 'Exclusive Offer for {{company}}',
                'body' => 'Hi {{first_name}}, I am {{rep_name}} from {{company_name}}.',
            ]],
        ]);

        $warnings = app(CrmSequenceService::class)->preview($sequence)[0]['warnings'];

        $this->assertNotEmpty(
            array_filter($warnings, fn ($w) => str_contains($w, 'company_name')),
            'A step that tells the prospect we work for them should be flagged before it is sent.',
        );
    }

    public function test_a_step_using_the_right_tags_is_not_warned_about(): void
    {
        $sequence = CrmSequence::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Cadence ' . Str::random(6),
            'steps' => [[
                'channel' => 'email', 'day_offset' => 0,
                'subject' => 'Exclusive Offer for {{company}}',
                'body' => 'Hi {{first_name}}, I am {{rep_name}} from {{sender_company}}.',
            ]],
        ]);

        $this->assertSame([], app(CrmSequenceService::class)->preview($sequence)[0]['warnings']);
    }
}
