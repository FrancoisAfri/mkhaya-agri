<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Physical audit / stock-take tables for the Asset Management module.
 *
 * These are NEW tables created after the Postgres RLS blanket migration, so
 * they are NOT covered by row-level security. Tenant isolation is enforced at
 * the app layer via the BelongsToCompany trait on the models (company_id column
 * + CompanyScope global scope). Each table carries a nullable, indexed
 * company_id so the scope/auto-stamp can operate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('name');
            $table->unsignedBigInteger('location_id')->nullable();
            // open | completed | cancelled
            $table->string('status')->default('open');
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('expected_count')->default(0);
            $table->integer('found_count')->default(0);
            $table->integer('missing_count')->default(0);
            $table->integer('unexpected_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('asset_audit_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('audit_id')->index();
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('scanned_code')->nullable();
            // expected | found | missing | unexpected
            $table->string('status');
            $table->unsignedBigInteger('scanned_by')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audit_lines');
        Schema::dropIfExists('asset_audits');
    }
};
