<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group consolidation (Phase 6.4). A group aggregates member companies' trial
 * balances with FX translation, intercompany elimination and minority interest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_groups', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('name');
            $t->char('base_currency', 3)->default('ZAR');
            $t->json('intercompany_codes')->nullable(); // account codes eliminated on consolidation
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('fin_group_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id');
            $t->unsignedBigInteger('company_id');
            $t->string('name');
            $t->decimal('ownership_pct', 6, 2)->default(100);
            $t->decimal('fx_rate', 18, 6)->default(1);       // to group base currency
            $t->string('fx_method', 12)->default('closing'); // closing | average | historic
            $t->boolean('is_parent')->default(false);
            $t->timestamps();
            $t->unique(['group_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_group_members');
        Schema::dropIfExists('fin_groups');
    }
};
