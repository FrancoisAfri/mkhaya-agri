<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Yodlee subscription for the whole platform, and one Yodlee user per tenant beneath it.
 *
 * Asking every customer to negotiate their own aggregator contract before they could use a bank
 * feed meant most of them never would — Yodlee's production onboarding is sales-led, and an SME is
 * not going to run a procurement exercise to import their bank statement. Mkhaya holds the
 * subscription; a client links a bank and nothing else.
 *
 * ── The part that would have leaked ─────────────────────────────────────────
 *
 * Yodlee scopes accounts and transactions to a `loginName`, not to the subscription. Sharing the
 * subscription is safe. Sharing the login name would have meant every tenant listing, and pulling
 * from, every other tenant's linked bank accounts — through an API that was behaving exactly as
 * documented.
 *
 * So the credential is global and the user is not: `fin_yodlee_users` gives each company its own
 * Yodlee identity under the one subscription, unique at the database rather than by convention.
 *
 * ── Shared-row mechanics ────────────────────────────────────────────────────
 *
 * A null company_id row is the platform's, readable by every tenant — the same shape
 * ContractsTemplate and FileSpecificationField already use. That needs the RLS policy to allow the
 * null (the previous one did not, so a platform row would have installed cleanly and been invisible)
 * and the model to declare sharesGlobalRecords(), or Eloquent filters out what Postgres allows.
 *
 * A tenant row still overrides, for the occasional large customer who holds their own contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One Yodlee identity per company, under whichever subscription applies to it.
        Schema::create('fin_yodlee_users', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();

            // What Yodlee knows this tenant as. Unique platform-wide: two companies sharing one
            // login name is the leak this table exists to prevent, so the database enforces it
            // rather than the code remembering to.
            $t->string('login_name', 150)->unique();

            $t->timestamp('registered_at')->nullable();   // null until Yodlee has actually accepted it
            $t->string('last_error', 255)->nullable();
            $t->timestamps();

            $t->unique('company_id', 'fin_yodlee_users_company_uq');
        });

        $this->applyTenantIsolation('fin_yodlee_users', sharesGlobal: false);

        /*
         * Make room for the platform row.
         *
         * UNIQUE(company_id) already permits one null in Postgres, which is exactly the one global
         * row wanted — but it is worth being explicit that this is deliberate rather than an
         * accident of null handling, because a later "tidy-up" adding NOT NULL would break it.
         */
        $this->applyTenantIsolation('fin_yodlee_settings', sharesGlobal: true);

        // The per-tenant login name moves to fin_yodlee_users. Carried across first so an existing
        // tenant keeps the identity its linked banks already hang off — regenerating it would
        // orphan every one of them.
        if (Schema::hasColumn('fin_yodlee_settings', 'user_login_name')) {
            foreach (DB::table('fin_yodlee_settings')->whereNotNull('company_id')->get() as $row) {
                if (blank($row->user_login_name)) {
                    continue;
                }

                DB::table('fin_yodlee_users')->insertOrIgnore([
                    'uuid'          => (string) \Illuminate\Support\Str::uuid(),
                    'company_id'    => $row->company_id,
                    'login_name'    => $row->user_login_name,
                    'registered_at' => $row->verified_at,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }
    }

    /**
     * @param  bool  $sharesGlobal  whether a null company_id row is readable by every tenant
     */
    private function applyTenantIsolation(string $table, bool $sharesGlobal): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";

        $policy = $sharesGlobal
            // The platform's own row is visible to everyone; a tenant's row only to that tenant.
            ? "company_id IS NULL OR {$session} IS NULL OR company_id = {$session}::bigint"
            : "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_yodlee_users');

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON fin_yodlee_settings');
            DB::statement("CREATE POLICY tenant_isolation ON fin_yodlee_settings USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
