<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A client company (employer) managed by a payroll-bureau agent.
        Schema::create('bureau_companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete(); // owning agent
            $table->string('name');
            $table->string('trading_name')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('paye_reference')->nullable();
            $table->string('uif_reference')->nullable();
            $table->string('sdl_reference')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('address')->nullable();
            $table->string('pay_frequency')->default('monthly'); // monthly|fortnightly|weekly
            $table->unsignedSmallInteger('tax_year')->nullable(); // SARS year of assessment, e.g. 2026
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['agent_id', 'is_active']);
        });

        // Bureau team: extra users who may work on a company, beyond the owner.
        Schema::create('bureau_company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bureau_company_id')->constrained('bureau_companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('processor'); // admin|processor|viewer
            $table->timestamps();

            $table->unique(['bureau_company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bureau_company_user');
        Schema::dropIfExists('bureau_companies');
    }
};
