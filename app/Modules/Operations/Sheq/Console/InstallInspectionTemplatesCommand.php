<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Services\InspectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs the standard inspection template library.
 *
 *   php artisan sheq:templates:install
 *   php artisan sheq:templates:install --industry=MINING
 *
 * Installed with a NULL company, so they are shared across every tenant: the checklist for a fire
 * extinguisher does not differ by customer, and twenty tenants each authoring their own version of
 * it is twenty chances to leave out the service date.
 *
 * Re-running is safe. `publishTemplate` versions rather than overwrites, so a corrected question
 * ships as version 2 and the inspections already completed against version 1 still cite the text
 * that was actually asked — which is the whole reason inspections reference a template version.
 */
class InstallInspectionTemplatesCommand extends Command
{
    protected $signature = 'sheq:templates:install
                            {--industry= : Only templates for this industry (or ALL)}
                            {--path= : Override the library file}';

    protected $description = 'Install the standard SHEQ inspection template library';

    public function handle(InspectionService $inspections): int
    {
        $path = $this->option('path') ?: resource_path('inspection-templates/library.yaml');

        if (! is_readable($path)) {
            $this->components->error("No template library at {$path}");

            return self::FAILURE;
        }

        $library = Yaml::parseFile($path)['templates'] ?? [];
        $only = $this->option('industry') ? strtoupper($this->option('industry')) : null;

        $installed = 0;
        $unchanged = 0;

        foreach ($library as $definition) {
            if ($only !== null && strtoupper($definition['industry'] ?? '') !== $only) {
                continue;
            }

            $schema = ['sections' => array_map(fn ($section) => [
                'title' => $section['title'],
                'questions' => array_map(fn ($question, $index) => [
                    // Deterministic ids: re-installing must not renumber questions, or the answers
                    // already recorded against them would point at the wrong question.
                    'id' => $this->questionId($section['title'], $index),
                    'text' => $question['text'],
                    'type' => $question['type'],
                    'weight' => $question['weight'] ?? 1,
                    'critical' => (bool) ($question['critical'] ?? false),
                ], $section['questions'], array_keys($section['questions'])),
            ], $definition['sections'])];

            $current = DB::table('sheq_inspection_templates')
                ->whereNull('company_id')->where('code', $definition['code'])
                ->orderByDesc('version')->first(['schema', 'status']);

            // Nothing changed since the last install — publishing again would burn a version
            // number and retire an identical template for no reason.
            if ($current !== null && $current->status === 'PUBLISHED'
                && json_decode((string) $current->schema, true) == $schema) {
                $unchanged++;

                continue;
            }

            $result = $inspections->publishTemplate(null, [
                'code' => $definition['code'],
                'title' => $definition['title'],
                'subject_type' => $definition['subject_type'] ?? 'SITE',
                'discipline' => $definition['discipline'] ?? null,
                'scoring_mode' => $definition['scoring_mode'] ?? 'PERCENTAGE',
                'pass_threshold' => $definition['pass_threshold'] ?? null,
                // Where the questions came from, so a practitioner reviewing them can check the
                // source rather than guess at it.
                'pack_source' => $definition['basis'] ?? null,
                'schema' => $schema,
            ]);

            $this->components->twoColumnDetail(
                sprintf('%-14s %s', $definition['industry'], $definition['code']),
                'v'.$result['version']
            );

            $installed++;
        }

        $this->components->info(
            "Template library: {$installed} installed or updated, {$unchanged} already current."
        );

        return self::SUCCESS;
    }

    /** Stable across re-installs: derived from the section and position, never from a counter. */
    private function questionId(string $sectionTitle, int $index): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $sectionTitle));

        return trim($slug, '_').'_'.($index + 1);
    }
}
