<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('disciplinary_appeals', function (Blueprint $table) {
            $table->foreignId('appeal_hearing_id')
                ->nullable()
                ->after('hearing_id')
                ->constrained('disciplinary_hearings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('disciplinary_appeals', function (Blueprint $table) {
            $table->dropForeign(['appeal_hearing_id']);
            $table->dropColumn('appeal_hearing_id');
        });
    }
};
