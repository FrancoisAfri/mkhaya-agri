<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Create a project's tasks from a spreadsheet.
 *
 * Typing a hundred tasks one at a time is how a plan that already exists in somebody's spreadsheet
 * fails to reach the system at all — the schedule stays in the file it was written in, and the board
 * shows a fraction of the real work. This takes that file as it is.
 *
 * ── Bad rows are reported, not fatal ────────────────────────────────────────
 *
 * A file of two hundred rows will have three with a typo in a date. Refusing the whole upload over
 * them means the user fixes one line, re-uploads, and discovers the next one — so every valid row is
 * created and the rest come back named, with the line number and the reason. The same decision
 * CoaImportService made, for the same reason.
 *
 * ── Why it goes through TaskService rather than inserting rows ──────────────
 *
 * TaskService::create() allocates the task code, inherits the WBS placement, rolls a parent's
 * progress up, and publishes pm.task.created to the outbox. An import that wrote rows directly
 * would produce tasks that look right on the board and are invisible to everything downstream.
 */
class TaskImportService
{
    /**
     * The template's columns, in the order they appear.
     *
     * Named the way somebody filling in a spreadsheet would name them — `deadline`, not
     * `planned_end`. The internal column name is a detail of our schema and no reason to make a
     * project manager learn it.
     */
    public const TEMPLATE_HEADERS = [
        'name', 'description', 'start_date', 'deadline', 'priority', 'estimated_hours', 'is_milestone',
    ];

    /**
     * A file this size is a paste accident or an export of the wrong thing.
     *
     * The cap exists because this runs inside a web request: a few hundred tasks is a real project
     * plan, and tens of thousands is somebody about to time out halfway through and not know which
     * half was created.
     */
    public const MAX_ROWS = 500;

    public function __construct(private readonly TaskService $tasks)
    {
    }

    /**
     * @return array{imported:int, skipped:array<int,array{row:int,name:string,reason:string}>, truncated:bool}
     */
    public function import(UploadedFile $file, Project $project, ?int $userId = null): array
    {
        $rows = $this->readRows($file);
        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        $imported = 0;
        $skipped = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2;   // one-based, plus the header row, so it matches what the user sees

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '' && $this->isBlankRow($row)) {
                continue;   // trailing empty lines are how spreadsheets end, not an error
            }
            if ($name === '') {
                $skipped[] = ['row' => $line, 'name' => '—', 'reason' => 'Missing task name.'];

                continue;
            }
            if (mb_strlen($name) > 190) {
                $skipped[] = ['row' => $line, 'name' => Str::limit($name, 40), 'reason' => 'Task name is longer than 190 characters.'];

                continue;
            }

            try {
                $start = $this->date($row['start_date'] ?? null);
                $deadline = $this->date($row['deadline'] ?? null);
            } catch (\InvalidArgumentException $e) {
                $skipped[] = ['row' => $line, 'name' => $name, 'reason' => $e->getMessage()];

                continue;
            }

            if ($start && $deadline && $deadline->lt($start)) {
                $skipped[] = ['row' => $line, 'name' => $name, 'reason' => 'The deadline falls before the start date.'];

                continue;
            }

            $priority = strtolower(trim((string) ($row['priority'] ?? ''))) ?: 'medium';
            if (! in_array($priority, Project::PRIORITIES, true)) {
                $skipped[] = [
                    'row' => $line, 'name' => $name,
                    'reason' => "Priority '{$priority}' is not one of: " . implode(', ', Project::PRIORITIES) . '.',
                ];

                continue;
            }

            $hours = $this->hours($row['estimated_hours'] ?? null);
            if ($hours === false) {
                $skipped[] = ['row' => $line, 'name' => $name, 'reason' => 'Estimated hours is not a number.'];

                continue;
            }

            $this->tasks->create($project, [
                'name'            => $name,
                'description'     => $this->nullable($row['description'] ?? null),
                // Stated rather than left to TaskService's default, because it is the whole point
                // of the feature: an imported task is work that has not been started yet.
                'status'          => 'not_started',
                'priority'        => $priority,
                'planned_start'   => $start,
                'planned_end'     => $deadline,
                'estimated_hours' => $hours,
                'is_milestone'    => $this->bool($row['is_milestone'] ?? null),
                'created_by'      => $userId,
            ]);

            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'truncated' => $truncated];
    }

    /**
     * The blank template, with one worked example.
     *
     * The example row is there because a header-only file leaves the person guessing what a date is
     * supposed to look like, which is the single most common reason an import comes back with every
     * row skipped. It is plainly an example and deleting it is the first instruction.
     */
    public function templateCsv(): string
    {
        $rows = [
            self::TEMPLATE_HEADERS,
            [
                'EXAMPLE — delete this row before uploading',
                'Optional. Any longer detail about the task.',
                '2026-10-01',
                '2026-10-15',
                'medium',
                '8',
                'no',
            ],
        ];

        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** @return array<int, array<string, mixed>> rows keyed by normalised header */
    private function readRows(UploadedFile $file): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $matrix = $sheet->toArray(null, true, false, false);

        if (! $matrix) {
            return [];
        }

        $headers = array_map(
            fn ($h) => Str::of((string) $h)->lower()->trim()->replace(' ', '_')->toString(),
            array_shift($matrix),
        );

        return array_map(function ($row) use ($headers) {
            $assoc = [];
            foreach ($headers as $idx => $key) {
                if ($key !== '') {
                    $assoc[$key] = $row[$idx] ?? null;
                }
            }

            return $assoc;
        }, $matrix);
    }

    /**
     * A date from a spreadsheet cell.
     *
     * Excel hands back a serial number rather than a string for anything it recognised as a date,
     * so a file saved as .xlsx and the same file saved as .csv arrive in different shapes. Both are
     * accepted, because which one the user picked in the Save As dialog is not something they
     * should have to think about.
     */
    private function date($value): ?Carbon
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                )->startOfDay();
            } catch (\Throwable) {
                throw new \InvalidArgumentException("Could not read '{$value}' as a date.");
            }
        }

        try {
            return Carbon::parse(trim((string) $value))->startOfDay();
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Could not read '" . trim((string) $value) . "' as a date. Use YYYY-MM-DD.");
        }
    }

    /** @return float|null|false  false means it was present and unreadable */
    private function hours($value)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : false;
    }

    private function bool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y'], true);
    }

    private function nullable($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @param  array<string, mixed>  $row */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
