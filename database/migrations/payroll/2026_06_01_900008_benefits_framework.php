<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the component catalogue into a full benefits framework: tax-treatment
 * metadata + SARS source codes per component, and employer-contribution /
 * fringe-benefit totals on the payslip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bureau_components', function (Blueprint $table) {
            $table->string('sars_source_code')->nullable()->after('type'); // e.g. 3701, 3810, 4001
            $table->string('category')->nullable()->after('sars_source_code'); // medical|retirement|risk|allowance|fringe|other
            $table->boolean('affects_sdl')->default(true)->after('affects_uif');
            $table->boolean('is_pretax')->default(false)->after('affects_sdl'); // deduction reduces taxable income
        });

        Schema::table('bureau_payslips', function (Blueprint $table) {
            $table->decimal('employer_contributions', 14, 2)->default(0)->after('other_deductions');
            $table->decimal('fringe_benefits', 14, 2)->default(0)->after('employer_contributions');
        });
    }

    public function down(): void
    {
        Schema::table('bureau_payslips', fn (Blueprint $t) => $t->dropColumn(['employer_contributions', 'fringe_benefits']));
        Schema::table('bureau_components', fn (Blueprint $t) => $t->dropColumn(['sars_source_code', 'category', 'affects_sdl', 'is_pretax']));
    }
};
