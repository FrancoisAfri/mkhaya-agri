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
        if (!Schema::hasTable('policy_refreshed')) {
            Schema::create('policy_refreshed', function (Blueprint $table) {
                $table->id(); // auto-incrementing primary key
                $table->integer('policy_id')->nullable();
                $table->integer('hr_id')->nullable();
                $table->integer('status')->nullable();
                $table->unsignedBigInteger('date_refreshed')->nullable(); // Unix timestamp (seconds)
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index('date_refreshed');
                $table->index('hr_id');
                $table->index('policy_id');
            });
        }
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_refreshed');
    }
};
