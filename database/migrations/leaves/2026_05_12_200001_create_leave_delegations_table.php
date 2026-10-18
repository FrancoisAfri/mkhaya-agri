<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_delegations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete()
                ->comment('User delegating their approval authority');
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete()
                ->comment('User who receives the delegated authority');

            $table->date('start_date');
            $table->date('end_date');
            $table->string('reason', 500)->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->timestamps();

            $table->index(['delegator_id', 'is_active']);
            $table->index(['delegator_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_delegations');
    }
};
