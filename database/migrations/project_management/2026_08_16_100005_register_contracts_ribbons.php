<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Put the Contracts and Contract Templates cards in the menu.
 *
 * Re-runs the seeder rather than inserting security_module_ribbons rows: ProjectHubs is the source
 * of truth and both the seeder and `hubs:sync` prune anything it does not declare, so a hand-written
 * row is a menu entry that disappears on the next deploy.
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
