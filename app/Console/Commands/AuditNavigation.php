<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Lists screens nothing opens: a page route that no hub offers, no sidebar ribbon names and no
 * link anywhere in the source refers to.
 *
 * Deliberately a command and not a test. Half the answer comes from `security_module_ribbons`,
 * which is tenant data: the development database carries 228 ribbons and a freshly migrated one
 * 58, so the same code reports a different list against each and a test would flap between them.
 * Run it against the database whose navigation you actually care about.
 *
 * It found five finished Board & Committee pages — Legal Entities, the skills matrix, the AI
 * register, the risk scanner and the news monitor — that had no card and no link, so they could
 * only be opened by typing the URL. They are on hubs now.
 */
class AuditNavigation extends Command
{
    protected $signature = 'nav:audit {--all : list the download, JSON and webhook endpoints too}';

    protected $description = 'Report page routes that no hub card, sidebar ribbon or link opens';

    public function handle(): int
    {
        $cards = $this->cardPaths();
        $nav = $this->navPaths();
        $references = $this->references();

        $this->line(sprintf(
            'hub card paths: %d   nav paths in %s: %d   route names and paths referenced in source: %d',
            count($cards), DB::connection()->getDatabaseName(), count($nav), count($references),
        ));

        $orphans = [];
        $other = [];
        $considered = 0;

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $uri = $route->uri();

            if (! $name
                || ! in_array('GET', $route->methods(), true)
                || str_contains($uri, '{')
                || str_starts_with($uri, 'api/')
                || str_starts_with($uri, '_')
                || ! str_contains($route->getActionName(), '@')) {
                continue;
            }

            $considered++;

            if (isset($cards[$uri]) || isset($nav[$uri]) || isset($references[$name]) || isset($references[$uri])) {
                continue;
            }

            if ($this->rendersAPage($route->getActionName())) {
                $orphans[$name] = $uri;
            } else {
                $other[$name] = $uri;
            }
        }

        ksort($orphans);
        ksort($other);

        $this->newLine();
        $this->line(sprintf('%d named parameterless GET routes on a controller.', $considered));

        if ($orphans === []) {
            $this->info('Every screen has a way in.');
        } else {
            $this->warn(sprintf('%d screens render a page that nothing opens:', count($orphans)));

            foreach ($orphans as $name => $uri) {
                $this->line(sprintf('   %-46s /%s', $name, $uri));
            }

            $this->newLine();
            $this->line('Each is either missing a hub card, missing a link from the screen that owns it,');
            $this->line('or reached some way this cannot see — a layout link or a tab built at runtime.');
        }

        if ($this->option('all') && $other !== []) {
            $this->newLine();
            $this->line(sprintf('%d unreferenced endpoints that are not pages (downloads, JSON, webhooks, redirects):', count($other)));

            foreach ($other as $name => $uri) {
                $this->line(sprintf('   %-46s /%s', $name, $uri));
            }
        }

        return self::SUCCESS;
    }

    /** @return array<string,true> */
    private function cardPaths(): array
    {
        $paths = [];

        foreach ($this->hubClasses() as $class) {
            foreach ($class::hubs() as $hub) {
                if (! empty($hub['path'])) {
                    $paths[ltrim($hub['path'], '/')] = true;
                }

                foreach ($hub['cards'] ?? [] as $card) {
                    $paths[ltrim((string) preg_replace('/\?.*$/', '', $card['path']), '/')] = true;
                }
            }
        }

        return $paths;
    }

    /** @return array<string,class-string> */
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

    /** @return array<string,true> */
    private function navPaths(): array
    {
        $out = [];

        foreach (['security_module_ribbons' => 'ribbon_path', 'security_modules' => 'path'] as $table => $column) {
            try {
                foreach (DB::table($table)->pluck($column) as $path) {
                    if ($path) {
                        $out[ltrim((string) $path, '/')] = true;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("Could not read {$table}: {$e->getMessage()}");
            }
        }

        return $out;
    }

    /** @return array<string,true> */
    private function references(): array
    {
        $out = [];

        foreach (['resources/js', 'app', 'routes', 'resources/views'] as $root) {
            $directory = new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS);

            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                if (! $file->isFile()
                    || ! in_array($file->getExtension(), ['vue', 'ts', 'js', 'php'], true)) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                preg_match_all('/route\(\s*[\'"`]([a-zA-Z0-9_.\-]+)[\'"`]/', $source, $named);
                preg_match_all('#[\'"`]/([a-zA-Z0-9_./\-]{2,})[\'"`]#', $source, $literal);

                foreach ([...$named[1], ...$literal[1]] as $reference) {
                    $out[$reference] = true;
                }
            }
        }

        return $out;
    }

    private function rendersAPage(string $action): bool
    {
        [$class, $method] = explode('@', $action);

        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException) {
            return false;
        }

        $file = $reflection->getDeclaringClass()->getFileName();

        if ($file === false) {
            return false;
        }

        $body = implode('', array_slice(
            file($file) ?: [],
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));

        return str_contains($body, 'Inertia::render');
    }
}
