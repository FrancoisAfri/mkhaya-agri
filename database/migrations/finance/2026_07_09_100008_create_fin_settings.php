<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company details for finance documents — the letterhead (name, logo, address,
 * VAT/registration), banking details and a default message printed on quotes
 * and invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->unique();
            $table->string('company_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website')->nullable();
            $table->string('vat_number', 40)->nullable();
            $table->string('registration_number', 60)->nullable();
            $table->longText('logo')->nullable(); // base64 data URI
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_account_name', 120)->nullable();
            $table->string('bank_account_number', 40)->nullable();
            $table->string('branch_code', 20)->nullable();
            $table->string('swift', 20)->nullable();
            $table->text('default_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_settings');
    }
};
