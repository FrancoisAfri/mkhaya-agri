<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('contract_number')->unique();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->string('contract_status')->default('draft');
            $table->string('approval_status')->default('pending');
            $table->string('payment_status')->default('pending');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('contract_value', 15, 2)->nullable();
            $table->decimal('final_contract_value', 15, 2)->nullable();
            $table->string('periodicity')->nullable();
            // FKs as unsigned big integers, nullable, WITHOUT cross-module constraints.
            $table->unsignedBigInteger('contract_type_id')->nullable();
            $table->unsignedBigInteger('contract_category_id')->nullable();
            $table->unsignedBigInteger('contract_request_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('current_step_id')->nullable();
            $table->longText('our_recommendation')->nullable();
            $table->longText('rejection_reason')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_ip')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('contract_status');
            $table->index('contract_type_id');
            $table->index('contract_category_id');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
