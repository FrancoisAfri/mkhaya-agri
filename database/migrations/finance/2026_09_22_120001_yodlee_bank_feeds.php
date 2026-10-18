<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Live bank feeds through Yodlee.
 *
 * `fin_bank_feeds` was built for a mock: a provider name, a made-up external_ref, and a synthesiser
 * that invented plausible transactions. Everything a real aggregator needs to resume a conversation
 * with a bank — which Yodlee user the link belongs to, which provider account the person authorised,
 * which of that account's sub-accounts maps to our bank account, and how far the last pull got — had
 * nowhere to live.
 *
 * ── Credentials are per tenant, not per installation ────────────────────────
 *
 * A Yodlee subscription belongs to the customer, not to us: they hold the contract, they pay for the
 * calls, and their end users' bank credentials sit behind it. One shared key across tenants would
 * make every customer's bank data reachable with one leaked secret, and would make usage
 * unattributable when the invoice arrives.
 *
 * Stored encrypted at rest (AES-256 via APP_KEY) and hidden from serialisation, the same way
 * MarketplaceIntegration keeps its provider keys — a database dump, a read replica or a platform
 * admin must not yield a working bank-data credential.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_yodlee_settings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable();

            // sandbox | development | production — chooses the base URL below, and is shown on the
            // screen, because "why is no real money appearing" is almost always this.
            $t->string('environment', 20)->default('sandbox');
            $t->string('base_url', 200)->nullable();
            $t->string('fastlink_url', 200)->nullable();

            // clientId / secret from the Yodlee dashboard, and the admin login name the subscription
            // was issued with. Encrypted by the model cast.
            $t->text('credentials')->nullable();

            // Yodlee identifies each end user by a loginName we choose. One per tenant, so a
            // customer's linked banks stay theirs and can be deleted with them.
            $t->string('user_login_name', 150)->nullable();

            $t->boolean('is_active')->default(false);
            $t->timestamp('verified_at')->nullable();      // last time credentials actually worked
            $t->string('last_error', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();

            $t->unique(['company_id'], 'fin_yodlee_settings_company_uq');
        });

        Schema::table('fin_bank_feeds', function (Blueprint $t) {
            // What the user authorised at their bank — one provider account can carry several
            // accounts (a cheque and a savings on the same login).
            $t->string('provider_account_id', 60)->nullable()->after('external_ref');
            // The specific Yodlee account this feed pulls, mapped to our bank account.
            $t->string('provider_ref', 60)->nullable()->after('provider_account_id');
            // Name and mask as Yodlee reported them, so the screen can say "Cheque ••1234" rather
            // than an opaque id, and a mismatched mapping is visible before it imports anything.
            $t->string('provider_account_name', 120)->nullable()->after('provider_ref');
            $t->string('provider_account_mask', 20)->nullable()->after('provider_account_name');

            // How far the last successful pull reached. Separate from last_synced_at, which says
            // when we asked: a failed refresh moves one and not the other.
            $t->date('synced_through')->nullable()->after('last_synced_at');

            $t->index('provider_ref');
        });

        $this->applyTenantIsolation('fin_yodlee_settings');

        // Salt Edge and Stitch were never implemented — selecting either produced "connector not
        // configured" and an error status. Any feed left on one is disconnected rather than
        // silently re-pointed at Yodlee, which would claim a bank link that nobody authorised.
        DB::table('fin_bank_feeds')
            ->whereIn('provider', ['saltedge', 'stitch'])
            ->update([
                'status'     => 'disconnected',
                'last_error' => 'This provider was removed. Reconnect the account through Yodlee.',
                'updated_at' => now(),
            ]);
    }

    /** The same row-level isolation every other tenant table carries. */
    private function applyTenantIsolation(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        Schema::table('fin_bank_feeds', function (Blueprint $t) {
            $t->dropIndex(['provider_ref']);
            $t->dropColumn([
                'provider_account_id', 'provider_ref',
                'provider_account_name', 'provider_account_mask', 'synced_through',
            ]);
        });

        Schema::dropIfExists('fin_yodlee_settings');
    }
};
