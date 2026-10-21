<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 2 §19: each farm's outcome measures for a period, one of them marked as the baseline the rest are measured against. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_outcome_snapshots', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->date('period_from');
            $t->date('period_to');
            $t->json('measures');
            $t->boolean('is_baseline')->default(false);
            $t->string('note', 300)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        AgriSchema::tenantRls(['agri_outcome_snapshots']);
    }

    public function down(): void
    {
        Schema::dropIfExists('agri_outcome_snapshots');
    }
};
