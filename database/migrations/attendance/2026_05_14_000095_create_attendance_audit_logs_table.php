<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');                       // clock_in, clock_out, edit, approve, override, policy_violation, discipline, roster_change, etc.
            $table->string('auditable_type')->nullable();   // model class
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();   // affected employee
            $table->unsignedBigInteger('performed_by')->nullable();  // auth user id
            $table->string('performed_by_name')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index(['employee_id', 'created_at']);
            $table->index(['performed_by', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('attendance_audit_logs'); }
};
