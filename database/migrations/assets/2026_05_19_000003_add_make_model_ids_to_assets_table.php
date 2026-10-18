<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('asset_make_id')->nullable()->after('make_number')
                ->constrained('asset_makes')->nullOnDelete();
            $table->foreignId('asset_model_id')->nullable()->after('asset_make_id')
                ->constrained('asset_models')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['asset_make_id']);
            $table->dropForeign(['asset_model_id']);
            $table->dropColumn(['asset_make_id', 'asset_model_id']);
        });
    }
};
