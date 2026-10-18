<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->integer('step_number')->nullable();
            $table->string('step_name')->nullable();
            $table->double('max_amount')->nullable();
            $table->integer('approval_type')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->unsignedBigInteger('parent_step_id')->nullable();
            $table->smallInteger('status')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_approval_steps');
    }
};
