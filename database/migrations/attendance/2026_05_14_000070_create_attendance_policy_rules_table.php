<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policy_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category'); // lateness|overtime|shift|remote_work|missed_clocking|consecutive_hours
            $table->unsignedInteger('threshold');
            $table->string('threshold_unit')->default('count'); // count|hours|percentage|days
            $table->unsignedInteger('period_days')->default(30);
            $table->string('severity')->default('medium'); // low|medium|high|critical
            $table->string('action')->default('warn');     // warn|block|escalate|require_approval
            $table->boolean('auto_escalate')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policy_rules');
    }
};
