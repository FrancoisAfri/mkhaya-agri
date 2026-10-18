<?php

namespace Tests\Feature\Sheq;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `(array) $row + ['column' => ...]` silently does nothing.
 *
 * PHP's array union operator keeps the LEFT value wherever both sides name a key. So the very
 * common shape
 *
 *     ->map(fn ($r) => (array) $r + ['evidence' => json_decode($r->evidence, true)])
 *
 * decodes the JSON, throws the result away, and ships the raw string. Nothing fails. The page
 * receives a string where it expects a list, `v-for` over it iterates characters or renders
 * nothing, and a cast to int never applies.
 *
 * Seven of these were live in this module at once: the permit screen's precondition and closure
 * checks, a permit worker's competency snapshot, an emergency plan's assembly points, the
 * contractor's gate-event reasons, injury days lost and restricted, the safety-alert read count,
 * the mobile API's inspection-template schema, and the AI insights' evidence. Every one of them
 * had been reviewed and none of them had been noticed, because there is nothing to notice — the
 * code reads as though it works.
 *
 * This test is the only thing that can see it, so it walks the source rather than any one
 * behaviour: a behavioural test would catch whichever instance somebody thought to write a test
 * for, which is never the one that is wrong.
 */
class ArrayUnionTest extends TestCase
{
    public function test_no_cast_row_union_overwrites_a_column_it_also_selects(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path('Modules/Operations/Sheq')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = $file->getContents();

            /*
             * Every column name the file reads out of the database: the quoted strings inside a
             * `get([...])` / `first([...])` list, and every `as alias` in a raw or aliased
             * select. Deliberately generous — a false positive here is a one-line change to
             * `array_merge`, which is correct anyway and costs nothing.
             */
            preg_match_all("/'[a-z_.]*\b([a-z_][a-z0-9_]*)'(?=\s*[,\]])/", $source, $quoted);
            preg_match_all("/\bas\s+([a-z_][a-z0-9_]*)'/", $source, $aliased);

            $selected = array_flip(array_merge($quoted[1], $aliased[1]));

            preg_match_all("/\(array\)\s*\\\$\w+\s*\+\s*\[(.*?)\]/s", $source, $unions,
                PREG_OFFSET_CAPTURE);

            foreach ($unions[1] as $index => [$body, $offset]) {
                preg_match_all("/'([a-z_][a-z0-9_]*)'\s*=>/", $body, $keys);

                $clash = array_values(array_intersect($keys[1], array_keys($selected)));

                if ($clash === []) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $unions[0][$index][1]), "\n") + 1;

                $offenders[] = sprintf('%s:%d overwrites %s with `+`',
                    str_replace(app_path().'/', '', $file->getPathname()), $line,
                    implode(', ', $clash));
            }
        }

        /*
         * The exemptions, each one checked by hand against its own select list. They are here
         * rather than loosening the pattern, because the pattern is what catches the next one.
         */
        $known = [
            // `subject_type` is not selected; the row is id/uuid/company_id/name.
            'Modules/Operations/Sheq/Services/AccessPassService.php',
            // `complete`, `missing`, `expired`, `evidence_score` are computed, not columns.
            'Modules/Operations/Sheq/Http/Controllers/AuditController.php',
            // `site_name`/`project_name` are resolved from maps; the worker row has only the ids.
            'Modules/Operations/Sheq/Http/Controllers/ContractorController.php',
            // `controls` is a grouped collection, not a column on the risk line.
            'Modules/Operations/Sheq/Http/Controllers/RiskAssessmentController.php',
            // `expired` is derived from `valid_until`; it is not selected.
            'Modules/Operations/Sheq/Services/SupplierOnboardingService.php',
            // Only the explanatory comment mentions the column name.
            'Modules/Operations/Sheq/Services/ActivityService.php',
        ];

        $real = array_values(array_filter($offenders, function (string $line) use ($known) {
            foreach ($known as $file) {
                if (str_starts_with($line, $file)) {
                    return false;
                }
            }

            return true;
        }));

        $this->assertSame([], $real,
            "These use `+` to overwrite a key the row already carries, so the right-hand value "
            ."is discarded and the raw column value ships instead. Use array_merge():\n"
            .implode("\n", $real));
    }
}
