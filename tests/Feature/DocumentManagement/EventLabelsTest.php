<?php

namespace Tests\Feature\DocumentManagement;

use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use Tests\TestCase;

/**
 * Every action the module records has to be sayable in both places it is shown.
 *
 * There are two shapes on purpose. A document's own timeline shows the action standing alone
 * ("Legal hold placed"), while the repository-wide trail shows it following a name ("Sarah placed a
 * legal hold on …"). They are not interchangeable: reusing the sentence fragment on the timeline
 * leaves a dangling "on", and reusing the standalone label in the feed reads as a headline dropped
 * mid-sentence.
 *
 * Twenty-four actions were added over the life of this module with neither label written, so the
 * timeline rendered raw keys like document_management.event_hold_placed. This is the guard against
 * adding a twenty-fifth the same way.
 */
class EventLabelsTest extends TestCase
{
    /** @return list<string> */
    private function actions(): array
    {
        // Declared on DocumentEvent itself: getConstants() also returns Eloquent's own CREATED_AT
        // and UPDATED_AT, which are column names rather than actions and have no label anywhere.
        return collect((new \ReflectionClass(DocumentEvent::class))->getReflectionConstants())
            ->filter(fn (\ReflectionClassConstant $c) => $c->getDeclaringClass()->getName() === DocumentEvent::class)
            ->map(fn (\ReflectionClassConstant $c) => $c->getValue())
            ->filter(fn ($value) => is_string($value))
            ->values()
            ->all();
    }

    private function english(): array
    {
        $path = resource_path('js/utils/locales/modules/en.json');

        return json_decode((string) file_get_contents($path), true)['document_management'] ?? [];
    }

    public function test_every_action_has_a_label_for_a_documents_own_timeline(): void
    {
        $strings = $this->english();

        $missing = array_values(array_filter(
            $this->actions(),
            fn (string $action) => ! isset($strings['event_' . $action]),
        ));

        $this->assertSame([], $missing, 'These actions would render as raw keys on a document: ' . implode(', ', $missing));
    }

    public function test_every_action_has_a_label_for_the_activity_feed(): void
    {
        $events = $this->english()['events'] ?? [];

        $missing = array_values(array_filter(
            $this->actions(),
            fn (string $action) => ! isset($events[$action]),
        ));

        $this->assertSame([], $missing, 'These actions would render as raw keys in the trail: ' . implode(', ', $missing));
    }

    public function test_the_two_shapes_are_not_the_same_string(): void
    {
        /*
         * They read differently by design, and the moment somebody fills one from the other the
         * dangling "on" comes back. A handful are legitimately identical — "Signed", "Approved" —
         * so this checks that the two sets have not been made wholesale copies of each other.
         */
        $strings = $this->english();
        $events = $strings['events'] ?? [];

        $identical = 0;

        foreach ($this->actions() as $action) {
            if (($strings['event_' . $action] ?? null) === ($events[$action] ?? null)) {
                $identical++;
            }
        }

        $this->assertLessThan(
            count($this->actions()) / 2,
            $identical,
            'The timeline labels look like a copy of the feed labels, which is how the dangling "on" got in.',
        );
    }
}
