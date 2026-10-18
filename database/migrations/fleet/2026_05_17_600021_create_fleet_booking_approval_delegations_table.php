<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleet_booking_approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delegator_id');  // user delegating away
            $table->unsignedBigInteger('delegate_id');   // user receiving authority
            $table->date('start_date');
            $table->date('end_date');
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('delegator_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('delegate_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('delegator_id');
            $table->index(['delegator_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_booking_approval_delegations');
    }
};
