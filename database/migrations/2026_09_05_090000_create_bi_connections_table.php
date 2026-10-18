<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a tenant's warehouse connection lives, so their own BI tools can read their own data.
 *
 * The token is the whole risk here. It is a credential that can read and write a customer's
 * warehouse, so it is stored under an encrypted cast and never leaves the server — the settings
 * screen shows whether one is set and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // One row per provider today; a column rather than a table so a second warehouse
            // vendor is a value, not a migration.
            $table->string('provider', 40)->default('databricks');
            $table->string('name', 120)->nullable();

            $table->string('host', 255)->nullable();
            $table->string('http_path', 255)->nullable();
            $table->string('catalog', 120)->nullable();
            $table->string('schema_name', 120)->nullable();

            // Encrypted at rest by the model's cast. Nullable so a connection can be part-configured
            // without holding a credential in a half-finished state.
            $table->text('credentials')->nullable();

            $table->boolean('is_active')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_error', 500)->nullable();
            $table->timestamp('last_exported_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // One live connection per provider per tenant: two would make "export" ambiguous.
            $table->unique(['company_id', 'provider', 'deleted_at'], 'bi_connections_unique');
        });

        // Tenant isolation at the database, like every other table that holds customer data —
        // and this one holds a credential, so it is the last place to rely on the application
        // remembering to scope a query.
        DB::statement('ALTER TABLE bi_connections ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bi_connections FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON bi_connections
            USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = (NULLIF(current_setting('app.company_id', true), ''))::bigint
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE bi_connections
            ALTER COLUMN company_id
            SET DEFAULT (NULLIF(current_setting('app.company_id', true), ''))::bigint
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_connections');
    }
};
