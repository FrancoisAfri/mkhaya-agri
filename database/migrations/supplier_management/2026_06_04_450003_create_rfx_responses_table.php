<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bid / proposal responses submitted (or captured internally) against an RFx
 * event. ai_score / ai_summary hold the optional AI bid-evaluation output.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfx_responses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('rfx_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->decimal('amount', 15, 2)->nullable();
            $table->string('currency')->default('ZAR');
            $table->longText('proposal')->nullable();
            $table->decimal('ai_score', 5, 2)->nullable();
            $table->text('ai_summary')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfx_responses');
    }
};
