<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_utilization_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->date('log_date');
            
            $table->decimal('available_hours', 8, 2);
            $table->decimal('utilized_hours', 8, 2);
            $table->decimal('idle_hours', 8, 2);
            $table->decimal('maintenance_hours', 8, 2)->default(0);
            
            $table->decimal('utilization_rate', 5, 2)->comment('Percentage');
            $table->integer('productivity_score')->comment('0-100');
            
            $table->timestamps();
            
            $table->unique(['asset_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_utilization_logs');
    }
};
