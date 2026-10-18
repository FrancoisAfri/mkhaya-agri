<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_tasks', function (Blueprint $table): void {
            $table->dropForeign(['completed_by']);
            $table->dropColumn('completed_by');
        });
    }
};
