<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM — data import run log (spec section 12). Records every CSV import with
 * its field mapping, dedup strategy and per-run result counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('entity_type', 20);            // lead|contact|account|deal
            $table->string('file_name')->nullable();
            $table->string('source', 30)->default('csv'); // csv|bitrix24|hubspot|salesforce
            $table->json('mapping')->nullable();          // {csv_header: crm_field}
            $table->string('dedup_strategy', 12)->default('skip'); // skip|update|create
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->json('errors')->nullable();
            $table->string('status', 12)->default('completed'); // completed|failed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_imports');
    }
};
