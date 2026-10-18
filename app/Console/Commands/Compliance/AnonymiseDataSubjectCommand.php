<?php

namespace App\Console\Commands\Compliance;

use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Recruitment\Models\Candidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * POPIA §24 — Right to Erasure (with HPCSA retention check).
 *
 * Nullifies PHI fields for a data subject. Blocked if HPCSA 5-year retention
 * window has not yet elapsed, unless --force is passed.
 *
 * Usage: php artisan compliance:anonymise-subject employee <uuid>
 *        php artisan compliance:anonymise-subject candidate <uuid> --force
 */
class AnonymiseDataSubjectCommand extends Command
{
    protected $signature = 'compliance:anonymise-subject
                            {type : Subject type (employee|candidate)}
                            {uuid : The subject UUID}
                            {--force : Bypass retention-window check}';

    protected $description = 'POPIA §24 — anonymise (nullify) PHI fields for a data subject';

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

        // HPCSA / legal retention check
        if (!$this->option('force') && method_exists($subject, 'retentionWindowElapsed')) {
            if (!$subject->retentionWindowElapsed()) {
                $this->error(
                    "Retention window has not elapsed for this {$type}. " .
                    "Use --force to override (requires documented legal justification)."
                );
                return self::FAILURE;
            }
        }

        $phiFields = method_exists($subject, 'getPhiFieldNames')
            ? $subject->getPhiFieldNames()
            : [];

        if (empty($phiFields)) {
            $this->warn("No PHI fields declared on {$model} — nothing to anonymise.");
            return self::SUCCESS;
        }

        $this->warn("This will permanently nullify the following PHI fields for {$type} {$uuid}:");
        $this->line(implode(', ', $phiFields));

        if (!$this->confirm('Proceed?')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $nulls = array_fill_keys($phiFields, null);
        $subject->update($nulls);

        Log::channel('single')->info('PHI anonymised', [
            'subject_type' => $type,
            'uuid'         => $uuid,
            'fields'       => $phiFields,
            'forced'       => $this->option('force'),
            'operator'     => get_current_user(),
        ]);

        $this->info("PHI anonymised for {$type} {$uuid}. Fields cleared: " . implode(', ', $phiFields));
        return self::SUCCESS;
    }
}
