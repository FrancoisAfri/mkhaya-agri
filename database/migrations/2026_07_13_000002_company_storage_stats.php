<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached per-company DATABASE data footprint (bytes of relational data across
 * every tenant-scoped table), refreshed by the nightly `storage:recompute`
 * command. Computing this live would mean scanning ~1000 tables per page load,
 * so we snapshot it here and the storage meter reads it (plus live file bytes
 * from tenant_files) for each company's true "everything in our DB" usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_storage_stats')) {
            return;
        }

        Schema::create('company_storage_stats', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->unsignedBigInteger('data_bytes')->default(0);   // relational data (allocated)
            $t->timestamp('computed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_storage_stats');
    }
};
