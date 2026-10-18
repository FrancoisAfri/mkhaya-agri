<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_persons', function (Blueprint $table) {
            $table->unique('contract_id', 'hr_persons_contract_id_unique');
            $table->foreignId('onboarding_plan_id')->nullable()->after('contract_id'); // active plan FK (nullable, no constraint for now)
        });
    }

    public function down(): void
    {
        Schema::table('hr_persons', function (Blueprint $table) {
            $table->dropUnique('hr_persons_contract_id_unique');
            $table->dropColumn('onboarding_plan_id');
        });
    }
};
