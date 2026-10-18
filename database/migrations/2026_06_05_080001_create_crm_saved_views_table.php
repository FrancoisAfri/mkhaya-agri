<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — saved views / segments (spec section 2, usability & adoption).
 * Named, reusable filter sets per user, optionally shared with the team.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_saved_views', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity', 20);                 // lead|deal|contact|account|activity
            $table->string('name');
            $table->json('filters')->nullable();          // [{field,operator,value}]
            $table->boolean('is_shared')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['entity', 'user_id']);
            $table->index(['entity', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_saved_views');
    }
};
