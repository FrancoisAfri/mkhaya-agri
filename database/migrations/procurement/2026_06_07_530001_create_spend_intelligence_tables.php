<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI procurement & spend intelligence: discrete findings (fraud/leakage/savings/
 * forecast) and composite risk scores for suppliers/budgets/the overall function.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_insights', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type')->index();      // duplicate|split|inflated_price|off_contract|emergency_abuse|favoritism|budget_leakage|dependency|saving|forecast
            $table->string('category')->default('risk'); // risk|saving|forecast
            $table->string('severity')->default('low');  // low|medium|high|critical
            $table->decimal('score', 6, 2)->default(0);  // 0-100 confidence/impact weight
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('entity_type')->nullable();   // supplier|budget|purchase_order|supplier_invoice|purchase_request|user
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->decimal('amount_impact', 18, 2)->default(0);
            $table->string('signature')->index();        // dedupe key
            $table->string('status')->default('open');   // open|reviewed|dismissed|actioned
            $table->boolean('ai_generated')->default(false);
            $table->text('data')->nullable();            // json evidence
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type');  // supplier|budget|overall
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('label')->nullable();
            $table->decimal('score', 6, 2)->default(0); // 0-100, higher = riskier
            $table->string('band')->default('low');     // low|medium|high|critical
            $table->text('factors')->nullable();        // json
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_risk_scores');
        Schema::dropIfExists('procurement_insights');
    }
};
