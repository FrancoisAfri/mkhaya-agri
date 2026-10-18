<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->smallInteger('status')->nullable();
            $table->string('description')->nullable();

            $table->smallInteger('special_leave')->nullable();
            $table->smallInteger('doc_required')->nullable();
            $table->smallInteger('notes_required')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('doc_required');
            $table->index('notes_required');
            $table->index('special_leave');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
