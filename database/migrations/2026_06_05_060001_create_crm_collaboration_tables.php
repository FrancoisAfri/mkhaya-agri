<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — collaboration layer (spec section 10): internal approval flows
 * (discount / deal approvals) and in-app notifications (incl. @mentions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('deal_id')->nullable()->constrained('crm_deals')->cascadeOnDelete();
            $table->string('type', 20)->default('deal');   // discount|deal|custom
            $table->string('title');
            $table->json('context')->nullable();            // {amount, discount_pct, ...}
            $table->decimal('amount', 16, 2)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('pending'); // pending|approved|rejected|cancelled
            $table->text('request_note')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->json('mentioned')->nullable();          // [user_id,...]
            $table->timestamps();

            $table->index(['approver_id', 'status']);
            $table->index(['requested_by', 'status']);
        });

        Schema::create('crm_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);                     // approval_requested|approval_decided|mention
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();             // in-app path
            $table->string('entity_type', 20)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notifications');
        Schema::dropIfExists('crm_approvals');
    }
};
