<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete users. A user is referenced by many tables (journals, audit logs,
 * module access, …); a hard delete violates those foreign keys (the 500 on
 * "delete user"). Soft-deleting removes them from the app while preserving
 * referential integrity and history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
