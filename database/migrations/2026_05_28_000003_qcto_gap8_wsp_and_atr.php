<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap 8 — WSP SDL/PAYE/SIC employer identification fields
        // Required on every formal SETA WSP submission cover page.
        Schema::table('lms_wsp_plans', function (Blueprint $table) {
            $table->string('sdl_number', 30)->nullable()->after('notes')
                ->comment('Skills Development Levy number from SARS');
            $table->string('paye_reference', 30)->nullable()->after('sdl_number')
                ->comment('PAYE reference number from SARS EMP201');
            $table->string('sic_code', 10)->nullable()->after('paye_reference')
                ->comment('Standard Industrial Classification code');
        });

        // Gap 7 — ATR provider accreditation number column
        Schema::table('lms_atr_items', function (Blueprint $table) {
            $table->string('provider_accreditation_number', 80)->nullable()->after('training_provider')
                ->comment('SETA accreditation number of the training provider');
        });

        // Gap 2 — WE component credits on learnerships
        Schema::table('lms_learnerships', function (Blueprint $table) {
            $table->decimal('we_credits', 6, 1)->nullable()->after('we_hours_required')
                ->comment('NQF credits allocated to the work experience component');
        });
    }

    public function down(): void
    {
        Schema::table('lms_learnerships', function (Blueprint $table) {
            $table->dropColumn('we_credits');
        });

        Schema::table('lms_atr_items', function (Blueprint $table) {
            $table->dropColumn('provider_accreditation_number');
        });

        Schema::table('lms_wsp_plans', function (Blueprint $table) {
            $table->dropColumn(['sdl_number', 'paye_reference', 'sic_code']);
        });
    }
};
