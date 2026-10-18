<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('module', ['claims', 'petty_cash', 'loans', 'advances', 'policy', 'approval']);
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('performed_by')->index();
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->text('description');
            $table->string('ip_address', 45)->nullable();
            $table->string('device')->nullable();
            $table->string('browser')->nullable();
            $table->json('metadata')->nullable();
            $table->enum('risk_level', ['none', 'low', 'medium', 'high', 'critical'])->default('none');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('performed_by')->references('id')->on('users')->onDelete('restrict');
            $table->index(['module', 'action']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_activity_logs');
    }
};
