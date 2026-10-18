<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phi_access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('field', 100);
            $table->unsignedBigInteger('accessed_by')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('accessed_at')->useCurrent();

            $table->index(['subject_type', 'subject_id'], 'phi_logs_subject_idx');
            $table->index('accessed_by', 'phi_logs_user_idx');
            $table->index('accessed_at', 'phi_logs_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phi_access_logs');
    }
};
