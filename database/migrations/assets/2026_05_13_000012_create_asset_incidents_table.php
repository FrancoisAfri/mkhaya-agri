<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetIncidentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_incidents', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('digital_twin_id')->nullable();
            $table->unsignedInteger('reported_by');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('low')->index();
            $table->decimal('repair_cost', 12, 2)->nullable();
            $table->boolean('is_resolved')->default(false)->index();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('occurred_at');
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('digital_twin_id')->references('id')->on('asset_digital_twins')->nullOnDelete();
            $table->index(['asset_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_incidents');
    }
}
