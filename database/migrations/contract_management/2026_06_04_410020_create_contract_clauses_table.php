<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract clause library: the organisation's standard ("playbook") clauses used
 * to review contracts for missing-required clauses and deviations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_clauses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable();
            $table->string('name');
            // termination|liability|payment|confidentiality|indemnity|ip|data_protection|other
            $table->string('category')->default('other');
            $table->longText('standard_text');
            $table->longText('fallback_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->text('risk_guidance')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_clauses');
    }
};
