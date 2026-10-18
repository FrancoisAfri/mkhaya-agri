<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetMovementsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id');
            $table->unsignedInteger('digital_twin_id');
            $table->unsignedInteger('from_location_id')->nullable();
            $table->unsignedInteger('to_location_id')->nullable();
            $table->unsignedInteger('moved_by')->nullable();
            $table->string('reason')->nullable();
            $table->decimal('gps_lat', 10, 8)->nullable();
            $table->decimal('gps_lng', 11, 8)->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->foreign('digital_twin_id')->references('id')->on('asset_digital_twins')->cascadeOnDelete();
            $table->index(['asset_id', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_movements');
    }
}
