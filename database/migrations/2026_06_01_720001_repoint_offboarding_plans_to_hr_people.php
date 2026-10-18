<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offboarding was wired to the (empty) `hr_persons` pre-boarding table, so no
 * real employees ever appeared. Repoint the plan FK to `hr_people`, which holds
 * the live employee records used by the rest of the HR module.
 *
 * Safe to run: there are no existing offboarding plans to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offboarding_plans')) {
            return;
        }

        Schema::table('offboarding_plans', function (Blueprint $table) {
            $table->dropForeign('offboarding_plans_hr_person_id_foreign');
        });

        Schema::table('offboarding_plans', function (Blueprint $table) {
            $table->foreign('hr_person_id')
                ->references('id')->on('hr_people')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offboarding_plans')) {
            return;
        }

        Schema::table('offboarding_plans', function (Blueprint $table) {
            $table->dropForeign('offboarding_plans_hr_person_id_foreign');
        });

        Schema::table('offboarding_plans', function (Blueprint $table) {
            $table->foreign('hr_person_id')
                ->references('id')->on('hr_persons')
                ->cascadeOnDelete();
        });
    }
};
