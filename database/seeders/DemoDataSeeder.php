<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Demo / sample data — for development, testing and demonstrations only.
 *
 * NEVER intended for production. {@see DatabaseSeeder} auto-runs this only when
 * APP_ENV is not "production". It contains fictional records: a sample company
 * identity & org structure, sample vehicles/drivers/bookings, sample assets,
 * appraisals, governance meetings, courses, payroll runs and BI demo data.
 *
 * To load it explicitly: `php artisan db:seed --class=DemoDataSeeder`.
 */
class DemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Sample company identity & org structure (real deployments use their own)
        $this->call(CompanyIdentitySeeder::class);
        $this->call(CompanyOrganizationSeeder::class);

        // Sample fleet vehicles, drivers and bookings
        $this->call(FleetSeeder::class);
        $this->call(FleetDemoSeeder::class);

        // Sample assets & licenses
        $this->call(AssetLicenseSeeder::class);
        $this->call(AssetLicenseDatesSeeder::class);
        $this->call(AssetTestDataSeeder::class);

        // Sample performance, governance and learning data
        $this->call(PerformanceTestDataSeeder::class);
        $this->call(GovernanceTestDataSeeder::class);
        $this->call(LmsTestDataSeeder::class);

        // Sample payroll runs
        $this->call(PayrollPhase1Seeder::class);
        $this->call(PayrollPhase2Seeder::class);

        // Cross-module BI demo data (depends on the above — keep last)
        $this->call(BIDemoSeeder::class);
    }
}
