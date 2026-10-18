<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type');                // policy, rule, clause, exception, etc.
            $table->unsignedBigInteger('auditable_id');
            $table->enum('event', ['created', 'updated', 'deleted', 'approved', 'rejected', 'published', 'archived']);
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_ip', 45)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_audit_logs');
    }
};
