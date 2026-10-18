<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('supplier_number')->nullable();
            $table->string('company_name');
            $table->string('trading_name')->nullable();
            $table->string('company_registration_number')->nullable();
            $table->string('tax_reference_number')->nullable();
            $table->string('vat_registration_number')->nullable();
            $table->string('bbbee_status_level')->nullable();
            $table->string('business_entity_type')->nullable();
            $table->text('physical_address')->nullable();
            $table->text('postal_address')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('email')->nullable();
            $table->string('website_url')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->string('account_type')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('payment_terms')->default('30 days');
            $table->integer('years_in_operation')->nullable();
            $table->text('conflict_of_interest_declaration')->nullable();
            $table->boolean('fraud_corruption_policy_agreement')->default(false);
            $table->boolean('public_liability_insurance')->default(false);
            $table->boolean('health_safety_compliance')->default(false);
            $table->text('references_or_client_list')->nullable();
            $table->integer('is_active')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
