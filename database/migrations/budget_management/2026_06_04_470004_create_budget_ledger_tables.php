<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Budget fund ledger: reservations (soft holds on request approval),
 * commitments (locked on PO), and consumptions (actual spend on invoice/GRN).
 * These are the source of truth for reserved / committed / actual amounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('budget_id')->index();
            $table->decimal('amount', 18, 2);
            $table->string('status')->default('active'); // active | released | committed
            $table->string('source_type')->nullable();   // e.g. purchase_request
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('reserved_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_commitments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('budget_id')->index();
            $table->decimal('amount', 18, 2);
            $table->string('status')->default('active'); // active | consumed | released
            $table->string('source_type')->nullable();   // e.g. purchase_order
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->timestamps();
        });

        Schema::create('budget_consumptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('budget_id')->index();
            $table->decimal('amount', 18, 2);
            $table->string('source_type')->nullable();   // e.g. supplier_invoice | grn
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('commitment_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_consumptions');
        Schema::dropIfExists('budget_commitments');
        Schema::dropIfExists('budget_reservations');
    }
};
