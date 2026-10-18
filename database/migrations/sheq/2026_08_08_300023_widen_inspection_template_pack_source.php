<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pack_source` holds the legal basis for a template's questions, and 60 characters was not enough
 * for one.
 *
 * "OHS Act s8; General Safety Regulations 2 (workplace condition)" is 61. Truncating it would strip
 * exactly the part a practitioner needs when checking whether the checklist actually reflects the
 * regulation it claims to — so the column grows rather than the citation shrinking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_inspection_templates', function (Blueprint $table) {
            $table->string('pack_source', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sheq_inspection_templates', function (Blueprint $table) {
            $table->string('pack_source', 60)->nullable()->change();
        });
    }
};
