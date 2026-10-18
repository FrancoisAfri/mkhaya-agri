<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_fines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('driver_id')->nullable();   // auto-matched from booking log
            $table->unsignedBigInteger('booking_id')->nullable();  // booking active at offence_date
            $table->string('provider', 60)->nullable();            // intellicheck|digitraffic|paab|manual
            $table->string('provider_fine_id', 100)->nullable();   // external ID for deduplication
            $table->string('fine_number', 100)->nullable();
            $table->string('offence_code', 60)->nullable();
            $table->text('offence_description')->nullable();
            $table->timestamp('offence_date')->nullable();
            $table->string('location', 500)->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('outstanding_amount', 10, 2)->nullable();
            $table->string('issuing_authority', 100)->nullable();  // JMPD | CTMPD | SAPS | RTIA
            $table->string('status', 30)->default('outstanding');  // outstanding|paid|disputed|written_off
            $table->text('dispute_reason')->nullable();
            $table->timestamp('dispute_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('aarto_points')->nullable();
            $table->string('infringement_notice_path', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('driver_id');
            $table->index('offence_date');
            $table->index('status');
            $table->unique(['provider', 'provider_fine_id'], 'fleet_fines_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_fines');
    }
};
