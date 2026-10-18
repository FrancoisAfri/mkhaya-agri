<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->index();
            $table->enum('type', [
                'block',
                'allow_with_warning',
                'require_approval',
                'send_notification',
                'escalate_to_role',
                'log_violation',
                'trigger_workflow',
            ]);
            $table->json('config')->nullable();              // role to escalate to, notification template, etc.
            $table->string('message_template')->nullable();  // user-facing message
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('rule_id')->references('id')->on('policy_rules')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_actions');
    }
};
