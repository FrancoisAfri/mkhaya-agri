<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_behavior_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            
            $table->enum('action_type', ['access', 'usage', 'transfer', 'modification', 'checkout', 'checkin']);
            $table->timestamp('action_timestamp');
            
            $table->foreignId('location_id')->nullable()->constrained('asset_locations');
            $table->string('ip_address')->nullable();
            $table->string('device_info')->nullable();
            $table->integer('duration_minutes')->nullable();
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index(['asset_id', 'action_timestamp']);
            $table->index(['user_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_behavior_logs');
    }
};
