<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('subject');
            $table->string('from_name', 100);
            $table->string('from_email', 150);
            $table->string('reply_to', 150)->nullable();
            $table->longText('body');
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'cancelled'])->default('draft');
            $table->enum('audience_type', ['all', 'department', 'custom'])->default('all');
            $table->jsonb('audience_filter')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('open_count')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('communication_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('communication_email_campaigns')->cascadeOnDelete();
            $table->string('email', 150);
            $table->string('name', 150);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('open_token')->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'email']);
            $table->index('open_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_campaign_recipients');
        Schema::dropIfExists('communication_email_campaigns');
    }
};
