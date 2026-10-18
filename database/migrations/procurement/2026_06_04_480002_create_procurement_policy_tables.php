<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement policy engine: configurable rules (approval thresholds, quotation
 * thresholds, restrictions), plus violation / exception / enforcement logging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_policy_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('type'); // approval_threshold|quotation_threshold|item_restriction|supplier_restriction|self_approval|budget_required|emergency_justification|duplicate|split
            $table->decimal('min_amount', 18, 2)->nullable();
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->string('role')->nullable();          // required approver role (approval_threshold)
            $table->json('params')->nullable();          // e.g. {category:'IT', quotes:3, days:7}
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('procurement_policy_violations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->index();
            $table->string('rule_type');
            $table->string('severity')->default('warning'); // info|warning|block
            $table->string('message');
            $table->json('context')->nullable();
            $table->string('status')->default('open'); // open|exception_requested|waived|resolved
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_policy_exceptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->index();
            $table->string('rule_type');
            $table->text('reason');
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('procurement_policy_enforcement_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->index();
            $table->string('rule_type');
            $table->string('outcome'); // pass|fail|exception
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_policy_enforcement_logs');
        Schema::dropIfExists('procurement_policy_exceptions');
        Schema::dropIfExists('procurement_policy_violations');
        Schema::dropIfExists('procurement_policy_rules');
    }
};
