<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a contractor must produce before anybody works, decided per site.
 *
 * `sheq_settings.contractor_required_documents` has always been the company-wide list and there
 * has never been a screen to set it. One list for the whole company is also the wrong shape: a
 * refinery demanding a permit-to-work procedure and a medical certificate has no business
 * demanding the same of a contractor painting an office, and a safety manager who raises the bar
 * for the refinery today raises it everywhere.
 *
 * NULL on a site means "whatever the company says", which is different from an empty list — an
 * empty list is a site that has been asked and demands nothing. Those two have to stay
 * distinguishable or every new site would silently start demanding nothing, which is the
 * opposite of the deny-by-default this register is built on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_sites', function (Blueprint $table) {
            $table->json('contractor_required_documents')->nullable()
                ->after('geofence_radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('sheq_sites', function (Blueprint $table) {
            $table->dropColumn('contractor_required_documents');
        });
    }
};
