<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The column the tenth factor is stored in (CON-20).
 *
 * `permit_violations` was declared and uncountable, so there was deliberately no column for it.
 * Now that a permit records whose work it is, the count has somewhere to live — and like the
 * other nine it is stored per month rather than recomputed, because a score that re-derives
 * itself explains last March with this March's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_contractor_scores', function (Blueprint $table) {
            $table->unsignedInteger('permit_violations')->default(0)->after('competency_gaps');
        });
    }

    public function down(): void
    {
        Schema::table('sheq_contractor_scores', function (Blueprint $table) {
            $table->dropColumn('permit_violations');
        });
    }
};
