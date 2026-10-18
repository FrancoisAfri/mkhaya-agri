<?php

use App\Modules\Tenancy\Support\SoleCompany;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Reunite rows saved with no company with the company that made them.
 *
 * This ships as a migration rather than a command to run by hand because the installations that
 * need it cannot tell that they do. The symptom is an empty screen: the records were saved, the
 * save reported success, and row-level security then hid them from the only tenant that could ever
 * have wanted them. Nobody reads that as data loss, so nobody goes looking for a repair tool —
 * they report that the feature does not work, which is also true.
 *
 * It found a content calendar of planned topics that a scheduled runner had been sweeping past
 * every five minutes, correctly finding nothing owed. Three thousand rows across a dozen modules
 * were in the same state on the same installation.
 *
 * Safe to run anywhere: it acts only where the owner is not a guess — a single-tenant installation
 * with exactly one company — and does nothing at all on a multi-tenant one, where assigning a
 * stranger's orphans to a customer would be far worse than leaving them lost. Idempotent, because
 * once a row has an owner it is no longer selected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (SoleCompany::id() === null) {
            // Multi-tenant, or no company yet. Nothing here can be inferred safely.
            return;
        }

        Artisan::call('tenancy:adopt-orphans');
    }

    public function down(): void
    {
        // Deliberately empty. Reversing this means restoring rows to a state in which no tenant
        // could see them, and the record of which rows those were is exactly what the migration
        // consumed. A rollback that re-hid a customer's live data would be the more damaging
        // direction of the two.
    }
};
