<?php

namespace App\Modules\Operations\Sheq\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs the sector induction library as shared content.
 *
 * Same shape as `sheq:templates:install`: shared rows with a null company id, re-runnable, and a
 * tenant's own induction under the same code always wins. A mine gets a mining induction on day one
 * rather than a blank page, and a warehouse is never shown content about self-rescuers.
 */
class InstallInductionsCommand extends Command
{
    protected $signature = 'sheq:inductions:install
                            {--sector= : Install one sector only}
                            {--force : Reinstall even where the content is unchanged}';

    protected $description = 'Install the shared sector induction library';

    public function handle(): int
    {
        $path = resource_path('inductions/library.yaml');

        if (! is_file($path)) {
            $this->components->error("No induction library at {$path}.");

            return self::FAILURE;
        }

        $library = Yaml::parseFile($path)['inductions'] ?? [];
        $only = $this->option('sector') ? strtoupper($this->option('sector')) : null;

        $installed = 0;
        $skipped = 0;
        $rows = [];

        foreach ($library as $entry) {
            $sector = strtoupper($entry['sector'] ?? 'ALL');

            if ($only !== null && $sector !== $only) {
                continue;
            }

            $content = json_encode($entry['content'] ?? []);
            $questions = json_encode($entry['questions'] ?? []);

            $existing = DB::table('sheq_inductions')
                ->whereNull('company_id')->where('code', $entry['code'])
                ->orderByDesc('version')->first(['id', 'version', 'content', 'questions']);

            /*
             * Reinstalling unchanged content must not burn a version number — otherwise a nightly
             * install would march the version up forever and every completion would look stale.
             *
             * Compared decoded, not as strings. Both columns are `jsonb`, and Postgres normalises
             * jsonb on the way in: it reorders keys and re-spaces the text, so what comes back is
             * `{"body": "…"}` where `json_encode` wrote `{"body":"…"}`. A string comparison is
             * therefore never equal, the guard never fired, and every run published a new version
             * of all seven inductions — three runs had already produced versions 1, 2 and 3 of
             * each. Scheduling the install would have made that a new version every night.
             */
            $unchanged = $existing !== null
                && json_decode((string) $existing->content, true) == json_decode($content, true)
                && json_decode((string) $existing->questions, true) == json_decode($questions, true);

            if ($unchanged && ! $this->option('force')) {
                $skipped++;
                $rows[] = [$entry['code'], $sector, 'v'.$existing->version, 'unchanged'];

                continue;
            }

            $version = ($existing->version ?? 0) + 1;

            DB::table('sheq_inductions')->whereNull('company_id')
                ->where('code', $entry['code'])->update(['is_active' => false, 'updated_at' => now()]);

            DB::table('sheq_inductions')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => null,
                'site_id' => null,
                'code' => $entry['code'],
                'title' => $entry['title'],
                'audience' => strtoupper($entry['audience'] ?? 'VISITOR'),
                'sector' => $sector,
                'basis' => $entry['basis'] ?? null,
                'content' => $content,
                'questions' => $questions,
                'pass_mark' => $entry['pass_mark'] ?? 100,
                'nda_text' => $entry['nda_text'] ?? null,
                'requires_nda' => (bool) ($entry['requires_nda'] ?? false),
                'requires_signature' => (bool) ($entry['requires_signature'] ?? true),
                'validity_months' => $entry['validity_months'] ?? 12,
                'version' => $version,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $installed++;
            $rows[] = [$entry['code'], $sector, 'v'.$version, 'installed'];
        }

        $this->table(['Code', 'Sector', 'Version', 'Result'], $rows);
        $this->components->info("{$installed} installed, {$skipped} unchanged.");

        return self::SUCCESS;
    }
}
