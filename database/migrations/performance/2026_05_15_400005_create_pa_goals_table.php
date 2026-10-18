<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->enum('level', ['organizational', 'department', 'team'])->default('organizational');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_unit_id')->nullable()->constrained('company_units')->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['draft', 'active', 'achieved', 'missed', 'cancelled'])->default('draft');
            $table->decimal('target_value', 10, 2)->nullable();
            $table->string('unit', 50)->nullable()->comment('%, count, ZAR, hours, etc.');
            $table->decimal('current_value', 10, 2)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_goals');
    }
};
