<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leave_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('hr_id')->nullable();
            $table->string('action', 255)->nullable();
            $table->smallInteger('description_action')->nullable();
            $table->unsignedInteger('leave_type_id')->nullable();
            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();
            $table->unsignedBigInteger('action_date')->nullable();
            $table->unsignedInteger('added_by')->nullable();
            $table->string('added_by_name', 255)->nullable();
            $table->float('transcation', 53)->nullable();
            $table->float('previous_balance', 53)->nullable();
            $table->float('current_balance', 53)->nullable();
            $table->string('description', 5000)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_history');
    }
};
