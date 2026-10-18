<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-user finance dashboard layout (Phase 6.1) — widget order + hidden set. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_dashboard_prefs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->unique();
            $t->json('order')->nullable();   // ordered list of widget keys
            $t->json('hidden')->nullable();  // hidden widget keys
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_dashboard_prefs');
    }
};
