<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_anomaly_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->text('description');
            $table->string('anomaly_type', 100);
            $table->enum('threshold_type', ['percentage', 'fixed', 'count'])->default('percentage');
            $table->decimal('threshold_value', 10, 4)->default(15);
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('action_on_breach', ['flag', 'warn', 'block', 'escalate'])->default('flag');
            $table->enum('applies_to', ['run', 'item', 'employee'])->default('item');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_anomaly_rules');
    }
};
