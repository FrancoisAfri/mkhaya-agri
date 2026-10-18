<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sourcing / RFx events: a request for quotation (rfq), proposal (rfp) or
 * information (rfi) sent to a set of invited suppliers. Responses are evaluated
 * (optionally with AI assistance) and a supplier may be awarded the event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfx_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('rfx_number')->unique();
            $table->string('title');
            $table->string('type'); // rfq|rfp|rfi
            $table->text('description')->nullable();
            $table->string('status')->default('draft'); // draft|published|closed|awarded|cancelled
            $table->date('closing_date')->nullable();
            $table->unsignedBigInteger('awarded_supplier_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfx_events');
    }
};
