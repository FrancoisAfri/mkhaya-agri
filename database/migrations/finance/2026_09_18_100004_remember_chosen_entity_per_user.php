<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The entity a user last worked in, remembered past the end of the session.
 *
 * Chosen once and it holds "all over the module until the user changes it" — which includes
 * tomorrow morning. A session cookie forgets overnight and would drop somebody back into Mkhaya MK
 * after a week of working in Mkhaya Move, which is exactly the kind of quiet switch that gets a
 * payment captured against the wrong books.
 *
 * `nullOnDelete`: an archived entity that is later removed leaves the user with no preference
 * rather than a dangling id, and they land on the tenant's default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'fin_entity_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('fin_entity_id')->nullable()->constrained('fin_entities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'fin_entity_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['fin_entity_id']);
            $table->dropColumn('fin_entity_id');
        });
    }
};
