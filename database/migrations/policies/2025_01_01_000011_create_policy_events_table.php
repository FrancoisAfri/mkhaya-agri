<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_events', function (Blueprint $table) {
            $table->id();
            $table->string('module');                        // 'Leave', 'Claims', 'Fleet', 'Approvals'
            $table->string('action');                        // 'leave_request.submitted', 'claim.created', etc.
            $table->unsignedBigInteger('actor_id')->nullable()->index(); // user performing action
            $table->unsignedBigInteger('subject_id')->nullable();       // entity being acted on
            $table->string('subject_type')->nullable();      // polymorphic: App\Models\LeaveRequest etc.
            $table->json('payload');                         // full event data snapshot
            $table->string('correlation_id')->nullable()->index(); // trace grouped events
            $table->timestamps();

            $table->index(['module', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_events');
    }
};
