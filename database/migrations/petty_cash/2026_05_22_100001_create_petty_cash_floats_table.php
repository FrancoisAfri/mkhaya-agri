<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_floats', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('custodian_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('current_balance', 12, 2)->default(0);
            $table->decimal('float_limit', 12, 2);
            $table->decimal('replenishment_threshold', 12, 2)->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->string('status')->default('active'); // active, inactive, suspended
            $table->timestamp('last_reconciled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_floats');
    }
};
