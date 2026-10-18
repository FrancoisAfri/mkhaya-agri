<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_step_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('step_id');
            $table->string('step_type')->default('parent');
            $table->string('status')->default('approved');
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['contract_id', 'step_id', 'step_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_step_statuses');
    }
};
