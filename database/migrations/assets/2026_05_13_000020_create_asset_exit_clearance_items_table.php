<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_exit_clearance_items', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('clearance_id');
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('assignment_id')->nullable();
            $table->string('resolution', 20)->default('pending')->index();
            $table->unsignedInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('transfer_to_user_id')->nullable();
            $table->unsignedInteger('transfer_to_location_id')->nullable();
            $table->text('waiver_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('clearance_id')->references('id')->on('asset_exit_clearances')->cascadeOnDelete();
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->unique(['clearance_id', 'asset_id']);
            $table->index(['clearance_id', 'resolution']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_exit_clearance_items');
    }
};
