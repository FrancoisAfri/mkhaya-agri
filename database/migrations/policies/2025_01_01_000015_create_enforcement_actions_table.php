<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enforcement_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('violation_id')->nullable()->index();
            $table->unsignedBigInteger('rule_id')->index();
            $table->unsignedBigInteger('event_id')->nullable()->index();
            $table->enum('action_taken', ['blocked', 'allowed_with_warning', 'escalated', 'approval_requested', 'logged']);
            $table->string('message_delivered')->nullable();      // actual message shown to user
            $table->unsignedBigInteger('escalated_to')->nullable(); // user/role escalated to
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enforcement_actions');
    }
};
