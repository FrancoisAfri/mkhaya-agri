<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-module quota columns the Hub added with its Content Marketing billing migration.
 *
 * Nothing in Mkhaya Agri sells against them, but the platform's module-pricing screen still reads
 * and saves both fields for every module, so they have to exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_modules')) {
            return;
        }

        Schema::table('billing_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('billing_modules', 'websites_per_month')) {
                $table->unsignedInteger('websites_per_month')->nullable();
            }
            if (! Schema::hasColumn('billing_modules', 'backlinks_per_month')) {
                $table->unsignedInteger('backlinks_per_month')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_modules')) {
            Schema::table('billing_modules', fn (Blueprint $t) => $t->dropColumn(['websites_per_month', 'backlinks_per_month']));
        }
    }
};
