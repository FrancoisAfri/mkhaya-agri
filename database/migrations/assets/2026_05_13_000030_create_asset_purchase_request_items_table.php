<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_purchase_request_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('purchase_request_id');
            $table->unsignedInteger('asset_id')->nullable();
            $table->string('asset_number', 20)->nullable();
            $table->string('serial_number')->nullable();
            $table->timestamps();

            $table->foreign('purchase_request_id')->references('id')->on('asset_purchase_requests')->cascadeOnDelete();
            $table->index(['purchase_request_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_purchase_request_items');
    }
};
