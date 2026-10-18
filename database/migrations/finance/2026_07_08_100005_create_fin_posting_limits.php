<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 (0.2) — resource-level RBAC for the ledger: "user X may post manual
 * journals to company Y, up to amount Z". Absence of a row = no cap (the
 * finance.journals.post permission still gates access). System postings
 * (payroll, procurement, …) go through the engine directly and are not limited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_posting_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id')->nullable(); // null = all companies
            $table->decimal('max_amount', 18, 2)->nullable();     // null = unlimited
            $table->boolean('can_post')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_posting_limits');
    }
};
