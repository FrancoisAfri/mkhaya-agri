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
        Schema::create('policy_users', function (Blueprint $table) {
            $table->id();
            $table->integer('policy_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('read_understood')->nullable();
            $table->integer('read_not_understood')->nullable();
            $table->integer('read_not_sure')->nullable();
            $table->integer('status')->nullable();
            $table->unsignedBigInteger('date_read')->nullable(); // Unix timestamp (seconds)
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedBigInteger('date_added')->nullable(); // Unix timestamp (seconds)
            $table->string('client_documents', 255)->nullable();

            // Indexes (matching the dump)
            $table->index('client_documents');
            $table->index('date_added');
            $table->index('date_read');
            $table->index('policy_id');
            $table->index('user_id');

            // Optional foreign keys (adjust table names as needed)
            // $table->foreign('policy_id')->references('id')->on('policies')->onDelete('set null');
            // $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_users');
    }
};
