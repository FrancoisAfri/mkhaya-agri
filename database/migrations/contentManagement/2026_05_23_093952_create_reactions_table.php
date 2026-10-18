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
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('content_id')
                ->nullable()
                ->constrained('contents')
                ->cascadeOnDelete();

            $table->foreignId('employee_spotlight_id')
                ->nullable()
                ->constrained('employee_spotlights')
                ->cascadeOnDelete();

            $table->boolean('like')->default(false);

            $table->text('comment')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
