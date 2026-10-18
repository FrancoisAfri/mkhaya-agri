<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetLocationsTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code', 20)->nullable()->index();
            $table->unsignedInteger('parent_id')->nullable();
            $table->text('address')->nullable();
            $table->decimal('gps_lat', 10, 8)->nullable();
            $table->decimal('gps_lng', 11, 8)->nullable();
            $table->smallInteger('status')->default(1)->index();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('asset_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_locations');
    }
}
