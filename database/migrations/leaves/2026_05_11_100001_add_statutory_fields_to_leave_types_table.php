<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            // BCEA statutory classification
            $table->string('statutory_type', 50)->nullable()->after('name')
                ->comment('annual|sick|family|maternity|paternity|parental|unpaid|special|study|overtime|custom');

            // BCEA minimum entitlement
            $table->decimal('bcea_minimum_days', 8, 2)->nullable()->after('statutory_type');

            // Payment classification
            $table->boolean('is_paid')->default(true)->after('bcea_minimum_days');

            // Carry-over rules
            $table->boolean('carry_over_enabled')->default(false)->after('is_paid');
            $table->decimal('carry_over_max_hours', 8, 2)->nullable()->after('carry_over_enabled');
            $table->integer('forfeiture_after_days')->nullable()->after('carry_over_max_hours');

            // Accrual
            $table->decimal('accrual_rate_hours_per_month', 8, 2)->nullable()->after('forfeiture_after_days');

            // Eligibility
            $table->integer('require_probation_period_days')->nullable()->after('accrual_rate_hours_per_month');
            $table->decimal('max_consecutive_hours', 8, 2)->nullable()->after('require_probation_period_days');

            // Restrictions
            $table->string('gender_restriction', 10)->nullable()->after('max_consecutive_hours')
                ->comment('null=none|male|female');
            $table->boolean('requires_medical_certificate')->default(false)->after('gender_restriction');

            // Governance
            $table->boolean('prevent_deletion')->default(false)->after('requires_medical_certificate')
                ->comment('Prevents deletion of statutory leave types');

            $table->index('statutory_type');
            $table->index('is_paid');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropIndex(['statutory_type']);
            $table->dropIndex(['is_paid']);
            $table->dropColumn([
                'statutory_type', 'bcea_minimum_days', 'is_paid', 'carry_over_enabled',
                'carry_over_max_hours', 'forfeiture_after_days', 'accrual_rate_hours_per_month',
                'require_probation_period_days', 'max_consecutive_hours', 'gender_restriction',
                'requires_medical_certificate', 'prevent_deletion',
            ]);
        });
    }
};
