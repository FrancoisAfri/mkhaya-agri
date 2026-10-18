<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI SDR sequences — multi-step email/WhatsApp cadences. Steps are stored as
 * JSON on the sequence; each enrolled contact progresses through them, and a
 * runner sends the due step. Replies are classified to stop/branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sequences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('goal')->nullable();
            $table->json('steps');                 // [{channel, day_offset, subject, body}]
            $table->boolean('active')->default(true);
            $table->boolean('stop_on_reply')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_sequence_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('crm_sequences')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->unsignedSmallInteger('current_step')->default(0);
            $table->string('status', 12)->default('active'); // active|replied|completed|stopped|bounced
            $table->string('reply_intent', 16)->nullable();  // interested|not_interested|ooo|unsubscribe|other
            $table->timestamp('next_step_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_step_at']);
            $table->unique(['sequence_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sequence_enrollments');
        Schema::dropIfExists('crm_sequences');
    }
};
