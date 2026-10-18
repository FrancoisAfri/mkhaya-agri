<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_ai_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module', 60);
            $table->string('provider', 30);
            $table->string('prompt_hash', 64)->index();
            $table->jsonb('request_json')->nullable();
            $table->jsonb('response_json')->nullable();
            $table->integer('tokens_used')->default(0);
            $table->integer('duration_ms')->default(0);
            $table->uuid('user_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'created_at']);
            $table->index(['provider', 'created_at']);
        });

        Schema::create('ai_anomaly_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 60)->index();
            $table->uuid('entity_id')->index();
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->text('summary');
            $table->jsonb('evidence_json')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('ai_call_id')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['severity', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_anomaly_flags');
        Schema::dropIfExists('audit_ai_calls');
    }
};
