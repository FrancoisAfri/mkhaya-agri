<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The farm hierarchy (spec §3): organisation → farm → fields and locations, and who may reach which
 * farm.
 *
 * The organisation is the tenant company. Everything here carries company_id under the same
 * row-level security every other module uses, so one agricultural business can never read another's
 * farms however a query is written; farm access then narrows inside the company.
 *
 * Nothing is physically deleted once it has history: a farm or field is archived, and a membership
 * is suspended, so the records that point at them keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('code', 30);
            $table->string('name', 150);
            // crop | livestock | mixed | horticulture | dairy | poultry | game | aquaculture | other
            $table->string('farm_type', 30)->default('mixed');
            $table->string('location', 200)->nullable(); // district / nearest town, as people say it
            $table->text('address')->nullable();
            $table->decimal('area', 14, 4)->nullable();
            $table->string('area_unit', 10)->default('ha'); // ha | acre | m2
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->jsonb('boundary')->nullable(); // optional GeoJSON polygon
            $table->string('timezone', 60)->default('Africa/Johannesburg');
            $table->char('currency', 3)->default('ZAR');
            $table->string('status', 12)->default('active'); // active | inactive | archived
            $table->timestamp('archived_at')->nullable();
            // Links into the shared platform, filled as each module is wired to farms.
            $table->unsignedBigInteger('company_unit_id')->nullable();   // budgets & people by unit
            $table->unsignedBigInteger('asset_location_id')->nullable(); // machinery on the farm
            $table->unsignedBigInteger('warehouse_id')->nullable();      // default store
            $table->jsonb('settings')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index(['company_id', 'status']);
        });
        // Codes are unique per organisation, ignoring case: "NORTH" and "north" are the same farm.
        DB::statement('CREATE UNIQUE INDEX farms_company_code_unique ON farms (company_id, lower(code))');

        Schema::create('farm_fields', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('farm_id')->constrained('farms')->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            // field | paddock | camp | orchard | vineyard | greenhouse | tunnel | nursery | pasture | pond | other
            $table->string('land_use_type', 20)->default('field');
            $table->decimal('area', 14, 4)->nullable();
            $table->string('area_unit', 10)->default('ha');
            $table->string('soil_type', 60)->nullable();
            $table->string('irrigation', 30)->nullable(); // dryland | centre_pivot | drip | sprinkler | flood | other
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->jsonb('boundary')->nullable();
            $table->string('status', 12)->default('active'); // active | inactive | archived
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'farm_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX farm_fields_farm_code_unique ON farm_fields (farm_id, lower(code))');

        Schema::create('farm_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('farm_id')->constrained('farms')->restrictOnDelete();
            $table->string('name', 150);
            // camp | kraal | store | shed | building | dam | borehole | packhouse | house | other
            $table->string('location_type', 20)->default('other');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->jsonb('boundary')->nullable();
            $table->string('status', 12)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'farm_id']);
        });

        // Who may work on which farm. A user with no active membership and without the "all farms"
        // permission sees no farm at all (spec 3.4): access is granted, never assumed.
        Schema::create('farm_memberships', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('farm_id')->constrained('farms')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // manager | supervisor | worker | finance | viewer
            $table->string('role', 20)->default('worker');
            $table->string('status', 12)->default('active'); // active | suspended
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->unsignedBigInteger('suspended_by')->nullable();
            $table->timestamps();

            $table->unique(['farm_id', 'user_id']);
            $table->index(['company_id', 'user_id', 'status']);
        });

        $this->applyTenantRls(['farms', 'farm_fields', 'farm_locations', 'farm_memberships']);
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_memberships');
        Schema::dropIfExists('farm_locations');
        Schema::dropIfExists('farm_fields');
        Schema::dropIfExists('farms');
    }

    /** Same tenant isolation as every other module: company_id defaults from, and is checked against, the session. */
    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
