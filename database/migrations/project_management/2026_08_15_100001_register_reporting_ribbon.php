<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Put the Monthly Reporting ribbon in the menu.
 *
 * Re-runs the seeder rather than inserting a security_module_ribbons row: ProjectHubs is the source
 * of truth and both the seeder and `hubs:sync` prune anything it does not declare, so a hand-written
 * row is a menu entry that disappears on the next deploy.
 *
 * Not registered in billing_modules — the add-on ships with Project Management.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\ProjectManagementModuleSeeder())->run();
    }

    public function down(): void
    {
        // Ribbons are rebuilt from ProjectHubs; nothing to reverse.
    }
};
