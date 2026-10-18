<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_leave_rules', function (Blueprint $table) {
            $table->id();

            // Nullable FK — rule may target a specific policy document or be standalone
            $table->unsignedBigInteger('policy_id')->nullable()->index();
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->nullOnDelete()
                ->comment('Null = applies to all leave types');

            $table->string('rule_code', 100)->unique();
            $table->string('rule_name', 255);
            $table->text('rule_description')->nullable();

            $table->enum('rule_type', [
                'eligibility',
                'entitlement',
                'documentation',
                'notice_period',
                'accumulation',
                'forfeiture',
                'payout',
                'approval',
            ]);

            $table->json('rule_config')->nullable()
                ->comment('Flexible rule parameters (thresholds, days, conditions)');

            $table->unsignedSmallInteger('priority')->default(100)
                ->comment('Lower = evaluated first');

            $table->boolean('overrides_bcea')->default(false);
            $table->boolean('requires_policy_override')->default(false);
            $table->boolean('active')->default(true);

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['leave_type_id', 'active']);
            $table->index(['rule_type', 'active']);
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_leave_rules');
    }
};
