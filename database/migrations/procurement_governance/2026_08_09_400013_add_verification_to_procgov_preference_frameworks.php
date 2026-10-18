<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who confirmed a preference framework, and when (§1.6, §7).
 *
 * `is_verified` on its own says a table was confirmed but not by whom, which is the half of the
 * claim that matters. A preference table decides awards; when a losing bidder asks on what authority
 * the points were set, "someone ticked a box at some point" is not an answer. These two columns make
 * the confirmation attributable, and the audit ledger records the act itself.
 *
 * Deliberately nullable and deliberately cleared whenever the framework is edited afterwards: a
 * confirmation applies to the numbers that were confirmed, not to the framework's name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procgov_preference_frameworks', function (Blueprint $t) {
            $t->foreignId('verified_by')->nullable()->after('is_verified')->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable()->after('verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('procgov_preference_frameworks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('verified_by');
            $t->dropColumn('verified_at');
        });
    }
};
