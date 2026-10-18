<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers invited to participate in an RFx event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfx_invitations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rfx_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('status')->default('invited'); // invited|responded|declined
            $table->timestamp('invited_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfx_invitations');
    }
};
