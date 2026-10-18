<?php

namespace App\Modules\Operations\WarehouseManagement\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Evidence for the acceptance matrix (Part D §31). Runs every test the matrix cites, reads the
 * JUnit log, and writes docs/warehouse/qa-matrix.md: per case, its setup, steps, expected result,
 * the actual result of each test (with time and assertions), the evidence and a pass/fail status.
 * A development and CI tool: it needs the test suite, so it is not available in production.
 */
class QaEvidenceCommand extends Command
{
    protected $signature = 'wms:qa-evidence {--bootstrap= : PHPUnit bootstrap to use} {--junit= : Read this JUnit file instead of running the tests}
        {--out=docs/warehouse/qa-matrix.md}';

    protected $description = 'Run the acceptance-matrix tests and write the evidence document';

    public function handle(): int
    {
        $matrix = 'Tests\\Feature\\Warehouse\\QaMatrix';
        if (! class_exists($matrix)) {
            $this->error('The test suite is not installed here (composer install --dev).');

            return self::FAILURE;
        }
        $cases = $matrix::cases();
        $tests = collect($cases)->flatMap(fn ($c) => $c['tests'])->unique()->values();
        $junits = $this->option('junit') ? [$this->option('junit')] : [];
        if ($junits === []) {
            // One PHPUnit process per test class, as the suites are run: static state one module leaves
            // behind (Finance's entity context, for one) cannot leak into another's tests.
            $byClass = $tests->groupBy(fn ($t) => explode('::', $t)[0]);
            $env = $this->testEnvironment();
            $this->info("Running {$tests->count()} tests in {$byClass->count()} classes…");
            foreach ($byClass as $class => $methods) {
                $junit = storage_path('app/qa-junit-'.md5($class).'.xml');
                $file = str_replace(base_path().'/', '', (new \ReflectionClass($class))->getFileName());
                $filter = '/::('.$methods->map(fn ($t) => preg_quote(explode('::', $t)[1], '/'))->implode('|').')$/';
                $cmd = array_merge([PHP_BINARY, '-d', 'memory_limit=3G', base_path('vendor/bin/phpunit')],
                    $this->option('bootstrap') ? ['--bootstrap', $this->option('bootstrap')] : [], ['--log-junit', $junit, '--filter', $filter, $file]);
                (new Process($cmd, base_path(), $env, null, 1800))->run();
                $this->output->write(is_file($junit) ? '.' : 'x');
                $junits[] = $junit;
            }
            $this->newLine();
        }
        $results = [];
        foreach ($junits as $j) {
            if (is_file($j)) {
                $results += $this->read($j);
                if (! $this->option('junit')) {
                    @unlink($j);
                }
            }
        }
        [$doc, $failed, $pending] = $this->document($cases, $results);
        file_put_contents(base_path($this->option('out')), $doc);
        $this->info("Wrote {$this->option('out')}: ".(count($cases) - $failed)." of ".count($cases)." cases pass their automated tests; {$failed} fail; {$pending} need a drill or benchmark on staging.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * This command runs inside artisan, which has put .env into the environment; a child PHPUnit
     * would inherit APP_ENV=local and the rest, and phpunit.xml does not override what is already
     * set. Unset whatever still has its .env value, keeping deliberate overrides from the shell
     * (such as a separate test database).
     *
     * @return array<string, false>
     */
    private function testEnvironment(): array
    {
        $file = base_path('.env');
        if (! is_file($file)) {
            return [];
        }
        $out = [];
        foreach (\Dotenv\Dotenv::parse((string) file_get_contents($file)) as $key => $value) {
            if (getenv($key) !== false && (string) getenv($key) === (string) $value) {
                $out[$key] = false;
            }
        }

        return $out;
    }

    /** @return array<string, array{status: string, time: float, assertions: int, file: string, line: int, message: ?string}> */
    private function read(string $junit): array
    {
        $out = [];
        $xml = simplexml_load_file($junit);
        foreach ($xml->xpath('//testcase') as $tc) {
            $name = (string) $tc['class'].'::'.(string) $tc['name'];
            $fail = $tc->failure ?? $tc->error ?? null;
            $out[$name] = ['status' => isset($tc->failure) || isset($tc->error) ? 'FAIL' : (isset($tc->skipped) ? 'SKIPPED' : 'PASS'), 'time' => round((float) $tc['time'], 2),
                'assertions' => (int) $tc['assertions'], 'file' => str_replace(base_path().'/', '', (string) $tc['file']), 'line' => (int) $tc['line'],
                'message' => $fail ? mb_substr(trim((string) $fail), 0, 300) : null];
        }

        return $out;
    }

    private function document(array $cases, array $results): array
    {
        $failed = 0;
        $pending = 0;
        $rows = [];
        $sections = [];
        foreach ($cases as $id => $c) {
            $runs = collect($c['tests'])->mapWithKeys(fn ($t) => [$t => $results[$t] ?? ['status' => 'NOT RUN', 'time' => 0, 'assertions' => 0, 'file' => '', 'line' => 0, 'message' => null]]);
            $ok = $runs->every(fn ($r) => $r['status'] === 'PASS');
            $needsStaging = array_values(array_diff($c['evidence'], ['TEST']));
            $status = ! $ok ? 'FAIL' : ($needsStaging ? 'PASS (automated) — '.implode(' and ', $needsStaging).' on staging outstanding' : 'PASS');
            $failed += $ok ? 0 : 1;
            $pending += $needsStaging ? 1 : 0;
            $rows[] = "| {$id} | {$c['scenario']} | {$c['required']} | ".count($c['tests'])." | {$status} |";
            $evidence = $runs->map(fn ($r, $t) => '- `'.class_basename(explode('::', $t)[0]).'::'.explode('::', $t)[1]."` — **{$r['status']}**".($r['file'] ? " ({$r['file']}:{$r['line']}, {$r['assertions']} assertions, {$r['time']} s)" : '').($r['message'] ? "\n  > ".str_replace("\n", ' ', $r['message']) : ''))->implode("\n");
            $extra = collect($needsStaging)->map(fn ($k) => $k === 'BENCHMARK' ? '- Benchmark: `php artisan wms:benchmark --doc=docs/warehouse/performance-results.md` on the deployment architecture (latest local run: docs/warehouse/performance-results.md).'
                : '- Drill: performed by a person on staging. Record date, who, outcome: ____')->implode("\n");
            $sections[] = "### {$id} — {$c['scenario']}\n\n**Setup.** {$c['setup']}\n\n**Steps.** {$c['steps']}\n\n**Expected.** {$c['required']}\n\n**Actual.** "
                .($ok ? 'Every automated test for this case passed.' : 'At least one test failed; see below.')."\n\n**Evidence.**\n{$evidence}".($extra ? "\n{$extra}" : '')."\n\n**Status.** {$status}\n";
        }
        $doc = "# Warehouse acceptance matrix — evidence\n\nGenerated ".now()->toDayDateTimeString()." by `php artisan wms:qa-evidence`. The cases are Part D §31 of the specification; "
            ."`tests/Feature/Warehouse/QaMatrix.php` maps each to the tests that prove it, and `QaMatrixTest` fails if a cited test disappears.\n\n"
            ."A case passes only on its automated tests here. Benchmarks and drills marked outstanding must be run on the deployment architecture before release (Part D release gates).\n\n"
            ."| ID | Scenario | Required result | Tests | Status |\n|---|---|---|---|---|\n".implode("\n", $rows)."\n\n".implode("\n", $sections);

        return [$doc, $failed, $pending];
    }
}
