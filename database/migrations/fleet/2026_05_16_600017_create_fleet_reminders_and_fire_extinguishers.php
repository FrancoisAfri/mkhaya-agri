<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicle_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('fleet_vehicles')->onDelete('cascade');
        });

        Schema::create('fleet_fire_extinguishers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('barcode', 80)->nullable();
            $table->string('item', 150)->nullable();
            $table->text('description')->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->string('invoice_number', 80)->nullable();
            $table->string('purchase_order_number', 80)->nullable();
            $table->string('supplier', 100)->nullable();
            $table->date('date_purchased')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('status', 40)->default('active');
            $table->json('image_path')->nullable();      // DB-stored via FileStorage
            $table->json('document_path')->nullable();   // DB-stored via FileStorage
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('vehicle_id')->references('id')->on('fleet_vehicles')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_fire_extinguishers');
        Schema::dropIfExists('fleet_vehicle_reminders');
    }
};
