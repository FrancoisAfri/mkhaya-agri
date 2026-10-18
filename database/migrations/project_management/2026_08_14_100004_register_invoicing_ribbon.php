<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Put the Project Invoicing ribbon in the menu.
 *
 * ProjectHubs is the source of truth for navigation, and the seeder upserts every ribbon from
 * `navRibbons()` and prunes the ones that are no longer declared. So this re-runs the seeder rather
 * than inserting a `security_module_ribbons` row by hand — a hand-inserted ribbon is deleted the
 * next time either the seeder or `php artisan hubs:sync` runs, which is a page that works until
 * somebody deploys and then silently disappears from the menu.
 *
 * The add-on is not registered in `billing_modules`. It ships with Project Management at no extra
 * cost; separation from core is the ribbon and its own tables, not a paywall. `allowsNavModule()`
 * only hides codes that are sellable, so a non-sellable sub-area is never hidden by entitlement.
 *
 * Nothing to reverse: ribbons are rebuilt from ProjectHubs on every run.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new \Database\Seeders\ProjectManagementModuleSeeder())->run();
    }

    public function down(): void
    {
        // Deliberately empty — see the class docblock.
    }
};
