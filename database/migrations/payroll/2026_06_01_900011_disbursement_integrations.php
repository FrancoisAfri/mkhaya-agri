<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-time salary payout via a credential-driven payout provider (Stitch, Ozow,
 * Peach Disbursements). The client connects their own provider account; we
 * submit a payout batch from a finalised run. Mirrors the Mkhaya Move / travel
 * integration pattern: credentials encrypted at rest, sandbox flag, per company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bureau_payment_integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bureau_company_id')->constrained('bureau_companies')->cascadeOnDelete();
            $table->string('provider');                 // stitch | ozow | peach
            $table->string('label')->nullable();
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();        // encrypted
            $table->text('api_secret')->nullable();     // encrypted
            $table->boolean('sandbox')->default(true);
            $table->boolean('active')->default(false);
            $table->string('last_sync_status')->nullable();
            $table->string('last_sync_message')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bureau_company_id', 'provider']);
        });

        Schema::create('bureau_disbursements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bureau_pay_run_id')->constrained('bureau_pay_runs')->cascadeOnDelete();
            $table->foreignId('bureau_company_id')->constrained('bureau_companies')->cascadeOnDelete();
            $table->string('provider');
            $table->string('reference')->nullable();
            $table->string('status')->default('submitted'); // submitted|processing|paid|failed
            $table->unsignedInteger('count')->default(0);
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->json('response')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bureau_disbursements');
        Schema::dropIfExists('bureau_payment_integrations');
    }
};
