<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store in-database file bytes as base64 text rather than raw binary.
 *
 * Postgres rejects raw binary sent through a text parameter — writing a PDF
 * straight into a bytea column via Eloquent fails with
 * "invalid byte sequence for encoding UTF8". The rest of the application already
 * solves this by holding base64 in a text column (see the fleet file-storage
 * migration), so these columns are brought into line rather than inventing a
 * second approach.
 */
return new class extends Migration
{
    /** table => column */
    private array $columns = [
        'hd_service_reports'        => 'pdf',
        'fleet_job_card_images'     => 'file_data',
        'fleet_job_card_documents'  => 'file_data',
    ];

    public function up(): void
    {
        foreach ($this->columns as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                // These columns are empty in every environment (the feature has
                // never successfully written to them), so a plain type change is
                // safe and no data conversion is required.
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE text USING NULL");
            } else {
                Schema::table($table, function ($t) use ($column) {
                    $t->longText($column)->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE bytea USING NULL");
            }
        }
    }
};
