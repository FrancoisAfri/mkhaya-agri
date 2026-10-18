<?php

namespace App\Console\Commands\Compliance;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Console\Command;

/**
 * POPIA §23 — Right of Access.
 *
 * Exports all personal information held for a data subject as JSON.
 * Usage: php artisan compliance:export-subject employee <uuid>
 *        php artisan compliance:export-subject candidate <uuid>
 */
class ExportDataSubjectCommand extends Command
{
    protected $signature = 'compliance:export-subject
                            {type : Subject type (employee|candidate)}
                            {uuid : The subject UUID}
                            {--output= : Write to file instead of stdout}';

    protected $description = 'POPIA §23 — export all personal data held for a subject';

    private const MODELS = [
        'employee'  => HRPerson::class,
        'candidate' => Candidate::class,
    ];

    public function handle(): int
    {
        $type  = $this->argument('type');
        $uuid  = $this->argument('uuid');
        $model = self::MODELS[$type] ?? null;

        if (!$model) {
            $this->error("Unknown subject type '{$type}'. Use: employee, candidate");
            return self::FAILURE;
        }

        $subject = $model::where('uuid', $uuid)->first();

        if (!$subject) {
            $this->error("No {$type} found with UUID {$uuid}");
            return self::FAILURE;
        }

        $phiFields = method_exists($subject, 'getPhiFieldNames')
            ? $subject->getPhiFieldNames()
            : [];

        $export = [
            'generated_at'    => now()->toIso8601String(),
            'subject_type'    => $type,
            'uuid'            => $uuid,
            'phi_fields'      => $phiFields,
            'personal_data'   => $subject->only(array_merge(['id', 'uuid'], $phiFields)),
            'consents'        => method_exists($subject, 'consents')
                ? $subject->consents()->get()->toArray()
                : [],
        ];

        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($output = $this->option('output')) {
            file_put_contents($output, $json);
            $this->info("Exported to {$output}");
        } else {
            $this->line($json);
        }

        $this->info("Export complete — {$type} {$uuid}");
        return self::SUCCESS;
    }
}
