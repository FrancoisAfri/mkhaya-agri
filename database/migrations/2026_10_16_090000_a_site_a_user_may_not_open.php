<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user site entitlement (SEC-2, TC-SEC-02).
 *
 * The derivation already existed and was already used twice — the document library's audience
 * filter and the sync pull both work out which sites a user belongs to, through
 * `company_unit_user_pivots` joined to `sheq_sites.company_unit_id`. What did not exist is the
 * control: a user with module access could open any site's permits, attendance or incidents by
 * changing a filter, because the site filter was a convenience and never an entitlement.
 *
 * Two things here, and the second is the one that matters.
 *
 * **`sheq.all_sites`** marks a user who is not restricted whatever their unit says. A group SHEQ
 * manager belongs to head office and must still see every shaft; without this the control would
 * make the role impossible and somebody would turn it off.
 *
 * **`restrict_users_to_sites`, default FALSE.** This is the part worth arguing about, so: the
 * restriction is opt-in per tenant, and it is off until a host switches it on.
 *
 * Shipping it on would be the wrong kind of correct. A unit assignment is an HR act — it is the
 * org chart, maintained for payroll and reporting reasons that have nothing to do with SHEQ — so
 * turning this on globally would instantly restrict every user who happens to sit in a unit, on
 * every existing installation, with no warning and no way for them to tell why a register had
 * emptied. And the alternative default, treating "no unit" as "no sites", is worse still: on this
 * database not one user has a unit assignment, so it would lock every person out of every screen
 * while reporting success.
 *
 * So the control exists, is enforced the moment it is switched on, and a host decides. That is
 * the honest reading of SEC-2 — it asks that site permissions prevent access, not that every
 * customer be re-segregated without being asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->boolean('restrict_users_to_sites')->default(false)
                ->after('site_risk_weights');
        });

        if (! Schema::hasTable('permissions')) {
            return;
        }

        // Guard name matched to the existing SHEQ permissions rather than assumed: a permission
        // on the wrong guard is one `can()` will never find, and the check then silently passes.
        $guard = DB::table('permissions')->where('name', 'sheq.configure')->value('guard_name')
            ?? 'web';

        DB::table('permissions')->insertOrIgnore([
            'name' => 'sheq.all_sites',
            'guard_name' => $guard,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->dropColumn('restrict_users_to_sites');
        });

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name', 'sheq.all_sites')->delete();
        }
    }
};
