<?php

use App\Modules\Operations\ProjectManagement\Services\TeamService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move project members off role keys that granted them nothing.
 *
 * A member's permissions come from `pm_roles`, looked up by `role_key`. That table is seeded from
 * RoleCatalog::builtIn(). TeamService offered a different list, and four of its seven keys —
 * planner, supervisor, subcontractor, observer — appeared in no catalogue role, so anybody holding
 * one resolved to no permissions at all while the screen showed them as a Supervisor.
 *
 * This migration fixes the damaging one. The contractor portal selects members with
 * `role_key = 'contractor'` (PortalController, RoleCatalog::EXTERNAL_ROLES), so the one role a
 * subcontractor could actually be given was the one that kept them out of the portal built for
 * them — which is why that portal has been empty for external users. The two were never different
 * things, so the rename loses nothing; the displayed label still reads "Subcontractor".
 *
 * planner, supervisor and observer are deliberately left alone. Each needs a permission set
 * decided by somebody who knows what those people may do, and inventing one in a migration would
 * be granting real access on a guess. They are recorded in TeamService::KNOWN_ORPHANED_ROLES and
 * pinned by RoleVocabularyTest so the gap stays visible.
 *
 * Irreversible by choice. down() would put members back onto a key that grants nothing, which is
 * the defect rather than a previous state worth restoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (TeamService::RENAMED_ROLES as $old => $new) {
            // Not tenant-scoped: every company's rows carry the same defect and RLS is inert for
            // the migration connection, so one statement fixes the estate.
            DB::table('pm_project_members')
                ->where('role_key', $old)
                ->update(['role_key' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Deliberately empty. See the class docblock.
    }
};
