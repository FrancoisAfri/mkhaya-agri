<?php

namespace Tests\Feature\DocumentManagement;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every filtered slice of the library has to say which slice it is.
 *
 * All of them rendered as "Documents", with the same upload box and the same four totals, so
 * somebody following a link to Awaiting approval saw the page they had just come from. The filter
 * was working the whole time; the page never said what it was showing, which is indistinguishable
 * from a broken link.
 *
 * This guards the next one: a slice can be given a route and a filter and still be forgotten here.
 */
class ViewHeadingsTest extends TestCase
{
    /** The screens that are simply "the documents" and keep the ordinary heading. */
    private const PLAIN = ['index', 'upload', 'folders', 'library'];

    /** @return list<string> */
    private function filteredViews(): array
    {
        return collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'document-management.')
                && str_contains($r->getActionName(), 'DocumentController@index'))
            ->map(fn ($r) => Str::after($r->getName(), 'document-management.'))
            ->reject(fn (string $key) => in_array($key, self::PLAIN, true))
            ->unique()
            ->values()
            ->all();
    }

    private function english(): array
    {
        $path = resource_path('js/utils/locales/modules/en.json');

        return json_decode((string) file_get_contents($path), true)['document_management'] ?? [];
    }

    public function test_every_filtered_view_has_a_heading_and_a_line_saying_what_it_shows(): void
    {
        $strings = $this->english();
        $missing = [];

        foreach ($this->filteredViews() as $key) {
            if (! isset($strings['view_' . $key])) {
                $missing[] = "view_{$key}";
            }

            // The hint matters as much as the title: "Expired" alone does not say these are still
            // on file, which is the thing somebody needs to know before acting on the list.
            if (! isset($strings['view_' . $key . '_hint'])) {
                $missing[] = "view_{$key}_hint";
            }
        }

        $this->assertSame([], $missing, 'These views would render an untranslated key as their heading: ' . implode(', ', $missing));
    }

    public function test_there_are_filtered_views_to_check(): void
    {
        // Without this the test above passes on an empty list and guards nothing.
        $this->assertNotEmpty($this->filteredViews());
    }
}
