<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — custom KPI builder (spec section 6). User-defined dashboard metrics:
 * an aggregate over an entity with optional filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_kpis', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('entity', 20);                 // deal|lead|contact|account|activity
            $table->string('metric', 10)->default('count'); // count|sum|avg|min|max
            $table->string('field')->nullable();          // numeric column for sum/avg/min/max
            $table->json('filters')->nullable();          // [{field,operator,value}]
            $table->string('format', 10)->default('number'); // number|currency|percent
            $table->string('color', 20)->default('primary');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_kpis');
    }
};
