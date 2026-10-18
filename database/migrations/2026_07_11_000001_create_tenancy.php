<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tenancy foundation (Phase A). Creates the `companies` (tenant) table, binds
 * users to a company, seeds a single default company for the existing install,
 * assigns all users + backfills finance data to it. The retrofit is additive:
 * everything already in the system becomes owned by the default company, so
 * nothing changes for current users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('slug')->unique();
            $t->string('name');
            $t->string('registration_number', 60)->nullable();
            $t->string('vat_number', 40)->nullable();
            $t->string('contact_person')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('status', 20)->default('trial');   // trial | active | suspended | cancelled
            $t->string('plan', 40)->default('standard');
            $t->decimal('monthly_total', 12, 2)->default(0);
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->string('suspended_reason')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index('status');
        });

        if (! Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->after('uuid')->index();
            });
        }

        // Seed the default company for the existing (single-tenant) install.
        $name = DB::table('company_identities')->value('company_name') ?: 'Mkhaya';
        $companyId = DB::table('companies')->insertGetId([
            'uuid'          => (string) Str::uuid(),
            'slug'          => Str::slug($name) . '-' . Str::lower(Str::random(4)),
            'name'          => $name,
            'status'        => 'active',           // existing install is a live customer, not a trial
            'plan'          => 'enterprise',
            'contact_email' => DB::table('company_identities')->value('support_email'),
            'approved_at'   => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Everyone belongs to the default company.
        DB::table('users')->update(['company_id' => $companyId]);

        // Backfill finance data to the default company so the tenant scope is seamless.
        $finTables = DB::table('information_schema.columns')
            ->where('table_schema', 'public')->where('column_name', 'company_id')
            ->where('table_name', 'like', 'fin_%')->pluck('table_name');
        foreach ($finTables as $table) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'company_id')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('company_id'));
        }
        Schema::dropIfExists('companies');
    }
};
