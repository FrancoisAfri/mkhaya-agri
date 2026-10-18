<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('request_number')->unique();
            $table->string('request_title');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('contract_type_id')->nullable();
            $table->string('contract_length')->nullable();
            $table->string('contract_length_unit')->nullable();
            $table->unsignedInteger('criticality')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->longText('summary')->nullable();
            $table->longText('motivation')->nullable();
            $table->string('status')->default('pending');
            $table->longText('recommendation')->nullable();
            $table->longText('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_requests');
    }
};
