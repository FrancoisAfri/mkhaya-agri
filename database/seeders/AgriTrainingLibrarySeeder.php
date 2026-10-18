<?php

namespace Database\Seeders;

use App\Modules\HR\LMS\Services\AgriTrainingLibrary;
use Illuminate\Database\Seeder;

/**
 * Installs the SAQA agricultural training library for every company that exists at seed time
 * (on a fresh install, the default company). Companies created later get it from the provisioner.
 */
class AgriTrainingLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $library = app(AgriTrainingLibrary::class);
        $register = $library->registerUnitStandards();
        $results = $library->installForAll();

        $courses = array_sum(array_map(fn ($r) => $r['courses'] ?? 0, $results));
        $this->command?->info("Agricultural training library: {$register['unit_standards']} unit standard(s) registered, {$courses} course(s) installed across ".count($results).' company(ies).');
    }
}
