<?php

namespace App\Modules\HR\LMS\Console;

use App\Modules\HR\LMS\Services\AgriTrainingLibrary;
use Illuminate\Console\Command;

/**
 * Installs the agricultural training library for farms that do not have it yet.
 *
 * New farms get it as they sign up; this covers everybody else — companies created before the
 * library existed, and every farm again after the library file is updated (only courses a farm is
 * missing are added, so running it twice is harmless).
 */
class InstallAgriLibraryCommand extends Command
{
    protected $signature = 'lms:install-agri-library
                            {--company= : Install for this company id only}';

    protected $description = 'Install the SAQA agricultural training library (courses, modules, certifications) for every farm.';

    public function handle(AgriTrainingLibrary $library): int
    {
        $register = $library->registerUnitStandards(refresh: true);
        $this->info("SAQA register: {$register['unit_standards']} unit standard(s) and {$register['outcomes']} outcome(s) added.");

        $results = $this->option('company')
            ? [(int) $this->option('company') => $library->installFor((int) $this->option('company'))]
            : $library->installForAll();

        $failed = 0;
        foreach ($results as $companyId => $r) {
            if (isset($r['error'])) {
                $failed++;
                $this->error("Company {$companyId}: {$r['error']}");

                continue;
            }
            $this->line("Company {$companyId}: {$r['courses']} course(s), {$r['modules']} module(s), {$r['lessons']} lesson(s), "
                ."{$r['questions']} quiz question(s), {$r['certifications']} certification(s), {$r['categories']} categor(ies) added; "
                ."{$r['moved']} course(s) moved to new categories.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
