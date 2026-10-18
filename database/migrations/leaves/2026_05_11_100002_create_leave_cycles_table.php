<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('cycle_type', ['annual', 'monthly'])->default('annual');
            $table->unsignedSmallInteger('year');
            $table->enum('status', ['pending', 'active', 'closed'])->default('pending');
            $table->boolean('auto_advance')->default(true)
                ->comment('Automatically advance to next cycle on end_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['year', 'status']);
            $table->index(['start_date', 'end_date']);
            $table->index('cycle_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_cycles');
    }
};
