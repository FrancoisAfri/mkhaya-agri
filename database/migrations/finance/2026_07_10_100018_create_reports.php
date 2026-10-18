<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved custom report definitions (Phase 6.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_reports', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->string('name');
            $t->string('source', 20)->default('gl');
            $t->json('dimensions')->nullable();
            $t->json('measures')->nullable();
            $t->json('filters')->nullable();
            $t->boolean('is_shared')->default(false);
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->timestamps();
            $t->index(['owner_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_reports');
    }
};
