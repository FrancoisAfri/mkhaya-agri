<?php

namespace Tests\Feature\Navigation;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A hub card is the only way into most screens, so the two ways it can be wrong both matter.
 *
 * A card pointing at a path with no route is a dead tile on a module's landing page. A screen
 * with no card is the opposite and worse: a finished, translated feature that nobody can reach,
 * because nothing links to it and the hub does not offer it. Five Board & Committee pages were
 * in that state — Legal Entities, the board skills matrix, the AI register, the governance risk
 * scanner and the news monitor, 94 to 147 lines each, fully translated into twelve languages,
 * openable only by typing the URL.
 */
class HubCardTest extends TestCase
{
    /** @return array<string,class-string> every class that defines hubs */
    private function hubClasses(): array
    {
        $found = \App\Support\Hubs\HubRegistry::all();
        $directory = new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), 'Hubs.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! preg_match('/namespace\s+([^;]+);/', $source, $ns)
                || ! preg_match('/\bclass\s+(\w+)/', $source, $cls)
                || ! str_contains($source, 'function hubs(')) {
                continue;
            }

            $class = trim($ns[1]).'\\'.$cls[1];

            if (class_exists($class)
                && ! (new \ReflectionClass($class))->isAbstract()
                && ! in_array($class, $found, true)) {
                $found[$cls[1]] = $class;
            }
        }

        return $found;
    }


    /** A path that a parameterised GET route answers (insights/{category}), other than the 404 fallback. */
    private function routes(string $path): bool
    {
        try {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($path, 'GET'));

            return ! $route->isFallback;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return false;
        }
    }

    public function test_every_hub_card_opens_a_route_that_exists(): void
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $uris[$route->uri()] = true;
            }
        }

        $dead = [];
        $checked = 0;

        foreach ($this->hubClasses() as $class) {
            foreach ($class::hubs() as $group => $hub) {
                foreach ($hub['cards'] ?? [] as $card) {
                    $checked++;
                    $path = ltrim((string) preg_replace('/\?.*$/', '', $card['path']), '/');

                    if (! isset($uris[$path]) && ! $this->routes('/'.$path)) {
                        $short = substr($class, (int) strrpos($class, '\\') + 1);
                        $dead[] = "{$short}/{$group}: the '{$card['key']}' card opens /{$path}, which has no GET route";
                    }
                }
            }
        }

        $this->assertGreaterThan(600, $checked, 'Hub cards were not found, so this test is checking nothing.');

        sort($dead);

        $this->assertSame([], $dead, "These hub cards are dead tiles:\n  ".implode("\n  ", $dead)."\n");
    }
    /**
     * Component names that render nothing, and why that is accepted.
     *
     * Anything new has to be added here on purpose. A name with no file is not a soft failure:
     * the Inertia resolver in app.ts ends with `throw new Error("Page not found: " + name)`.
     */
    private const NO_COMPONENT = [
        'Modules/QuickAccess/Index' => 'GET /quick-access predates the dashboard modal that replaced it; the route is unreferenced and the page was never built',
    ];

    public function test_every_rendered_component_exists(): void
    {
        $available = [];

        // The roots app.ts globs, minus the two it excludes as infrastructure.
        foreach (['Modules', 'Policies', 'HR', 'Pages', 'Tenancy', 'Reseller', 'Core'] as $root) {
            $base = base_path("resources/js/{$root}");

            if (! is_dir($base)) {
                continue;
            }

            $directory = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);

            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'vue') {
                    continue;
                }

                $relative = str_replace(base_path('resources/js').'/', '', $file->getPathname());
                $relative = substr($relative, 0, -4);

                if (str_starts_with($relative, 'Core/Layouts/') || str_starts_with($relative, 'Core/Components/')) {
                    continue;
                }

                // The resolver falls back to a case-insensitive match, so compare that way.
                $available[strtolower($relative)] = true;
            }
        }

        // Pages imported statically in app.ts can live anywhere.
        $app = (string) file_get_contents(base_path('resources/js/app.ts'));
        $start = strpos($app, 'explicitPages');
        $explicit = [];

        if ($start !== false) {
            $block = substr($app, $start, (int) strpos($app, '}', (int) strpos($app, '{', $start)) - $start);
            preg_match_all('/[\'"]([A-Za-z0-9_\/\-]+)[\'"]\s*:/', $block, $matches);
            $explicit = array_fill_keys($matches[1], true);
        }

        $rendered = [];
        $directory = new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS);

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            preg_match_all('/Inertia::render\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

            foreach ($matches[1] as $name) {
                $rendered[$name] ??= str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertGreaterThan(900, count($rendered),
            'Far fewer rendered components were found than this app has, so the scan is not working.');

        $missing = [];

        foreach ($rendered as $name => $file) {
            if (isset($available[strtolower($name)])
                || isset($explicit[$name])
                || array_key_exists($name, self::NO_COMPONENT)) {
                continue;
            }

            $missing[] = "{$name}  (rendered by {$file}) — the resolver throws Page not found";
        }

        sort($missing);

        $this->assertSame([], $missing,
            "These routes render a component that does not exist:\n  ".implode("\n  ", $missing)."\n");
    }

}
