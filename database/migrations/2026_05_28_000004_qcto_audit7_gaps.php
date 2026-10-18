<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap 6 — PoE certificate learner traceability
        // Certificates issued via PoE moderation must be traceable to the learner
        // even when enrollment_id is null (learnership-based portfolios).
        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->unsignedBigInteger('hr_person_id')->nullable()->after('enrollment_id')
                ->comment('HR person this certificate was issued to (for PoE-based certs without an enrollment)');
            $table->unsignedBigInteger('poe_portfolio_id')->nullable()->after('hr_person_id')
                ->comment('PoE portfolio that triggered this certificate');
        });

        // Gap 8 — WSP per-demographic beneficiary counts
        // SETA WSP submission requires a separate headcount for each of the
        // 10 official demographic groups per training item.
        Schema::create('lms_wsp_item_demographics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wsp_item_id')->constrained('lms_wsp_items')->cascadeOnDelete();
            $table->string('demographic_key', 30)
                ->comment('african_male|african_female|coloured_male|coloured_female|indian_male|indian_female|white_male|white_female|disabled|foreign');
            $table->unsignedInteger('beneficiary_count')->default(0);
            $table->unique(['wsp_item_id', 'demographic_key']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lms_wsp_item_demographics');

        Schema::table('lms_certificates', function (Blueprint $table) {
            $table->dropColumn(['hr_person_id', 'poe_portfolio_id']);
        });
    }
};
