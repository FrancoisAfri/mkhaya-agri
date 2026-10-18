<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bbbee', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->unique();
            $table->integer('level')->nullable(); // 1-8
            $table->decimal('black_ownership_pct', 5, 2)->nullable();
            $table->decimal('black_women_ownership_pct', 5, 2)->nullable();
            $table->decimal('recognition_pct', 5, 2)->nullable();
            $table->date('certificate_expiry')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bbbee');
    }
};
