<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_misuse_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('custodian_user_id')->constrained('users');
            
            $table->enum('event_type', [
                'unauthorized_transfer',
                'excessive_usage',
                'location_anomaly',
                'after_hours_usage',
                'policy_violation'
            ]);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            
            $table->timestamp('detected_at');
            $table->text('description');
            $table->json('evidence')->nullable()->comment('Supporting data');
            $table->integer('confidence_score')->comment('0-100');
            
            $table->enum('status', ['flagged', 'investigating', 'resolved', 'false_positive'])->default('flagged');
            
            $table->foreignId('investigated_by_user_id')->nullable()->constrained('users');
            $table->timestamp('investigated_at')->nullable();
            $table->text('resolution_notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['asset_id', 'detected_at']);
            $table->index(['severity', 'status']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_misuse_events');
    }
};
