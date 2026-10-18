<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('policy_violation_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('violation_id')
                ->constrained('policy_violations')
                ->onDelete('cascade');
            $table->foreignId('recipient_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Who received the notification
            // user: The person who triggered the violation
            // manager: Their manager (for escalation)
            // policy_owner: The person who owns the policy
            // compliance_team: HR/Compliance team members
            $table->enum('recipient_type', ['user', 'manager', 'policy_owner', 'compliance_team']);

            // Type of notification sent
            // warning: Action was allowed but flagged
            // escalation: Action requires approval
            // critical: Critical severity violation
            // alert: High severity monitoring alert
            $table->enum('notification_type', ['warning', 'escalation', 'critical', 'alert', 'info']);

            // Notification channels used
            $table->json('channels')->default('["database", "mail"]'); // Can include: database, mail, sms, slack, teams

            // Status of notification
            // sent: Successfully sent
            // read: User has viewed the notification
            // acknowledged: User acknowledged (marked as read)
            // actioned: User has taken action (approved/rejected/appealed)
            $table->enum('status', ['sent', 'read', 'acknowledged', 'actioned', 'failed'])->default('sent');

            // Content stored for reference
            $table->string('title');
            $table->text('message');
            $table->integer('severity_score')->unsigned();

            // Tracking
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->json('metadata')->nullable(); // Extra data like action taken, reason, etc.

            $table->timestamps();

            // Indexes
            $table->index('violation_id');
            $table->index('recipient_id');
            $table->index('recipient_type');
            $table->index('status');
            $table->index('severity_score');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_violation_notifications');
    }
};
