<?php

namespace Tests\Feature\DocumentManagement;

use Tests\TestCase;

/**
 * Every page this module renders must exist where the front end looks for it.
 *
 * The resolver in app.ts builds `./{name}.vue` relative to resources/js and throws "Page not found"
 * when it misses. Four pages in this module were rendered as `DocumentManagement/Pages/...` while
 * the files sit under `resources/js/Modules/...`, so every one of them was a blank screen — and
 * nothing caught it, because a controller returning the wrong component name is a perfectly valid
 * response until a browser tries to render it.
 *
 * This is the cheap permanent guard against that: it reads the render calls out of the source and
 * checks each one against the filesystem, which is exactly what the resolver does at runtime.
 */
class PageComponentsResolveTest extends TestCase
{
    public function test_every_rendered_page_component_exists_on_disk(): void
    {
        $module = base_path('app/Modules/Operations/DocumentManagement');
        $pages = resource_path('js');

        $rendered = [];

        foreach ($this->phpFiles($module) as $file) {
            preg_match_all("/Inertia::render\(\s*'([^']+)'/", (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $component) {
                $rendered[$component] = basename($file);
            }
        }

        $this->assertNotEmpty($rendered, 'No rendered components found — this test would pass vacuously.');

        $missing = [];

        foreach ($rendered as $component => $source) {
            if (! is_file($pages . '/' . $component . '.vue')) {
                $missing[] = "{$component} (rendered by {$source})";
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These components are rendered but the front end cannot resolve them:\n  " . implode("\n  ", $missing),
        );
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
