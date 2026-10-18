<?php

namespace Database\Seeders;

use App\Core\HowTo\Services\HowToGenerator;
use Illuminate\Database\Seeder;

/**
 * Guarantees baseline how-to coverage for EVERY module + ribbon in the product so the
 * Mkhaya AI bot and the /how-to page can answer questions about any feature. Run this
 * AFTER HowToSeeder (curated content) and ModuleSeeders (modules/ribbons) — it only
 * fills gaps, so it's safe to run repeatedly and after new modules/ribbons are added.
 */
class HowToCoverageSeeder extends Seeder
{
    public function run(): void
    {
        $created = app(HowToGenerator::class)->ensureAll();

        $this->command?->info("How-to coverage: created {$created} baseline entries for uncovered ribbons.");
    }
}
