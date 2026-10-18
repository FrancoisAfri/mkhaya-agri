<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant single sign-on and SCIM provisioning (§9.1).
 *
 * Enterprise customers do not want a second password directory. Their objection is not convenience:
 * when someone leaves, HR disables the account in Entra or Okta, and every system that holds its own
 * credentials keeps letting that person in. In a SHEQ context that is a permit approver who no
 * longer works there. SSO makes the identity provider the single source of truth for who exists;
 * SCIM makes it the source of truth for who is still allowed in.
 *
 * Both are per tenant, not global. A platform serving fifty employers has fifty identity providers,
 * and a global configuration would let one customer's directory authenticate into another's data.
 *
 * Two tables:
 *
 * - **sso_connections** — one identity provider per company, matched to users by email domain.
 * - **scim_tokens** — the bearer credentials a provider uses to push user changes, stored hashed
 *   because a SCIM token can create and disable accounts and is therefore as sensitive as a
 *   password.
 *
 * OIDC only. SAML would need a signing/metadata stack this codebase does not have, and every
 * provider worth supporting — Entra ID, Okta, Google Workspace — speaks OIDC. Adding a half-working
 * SAML path would be worse than not offering it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // One provider per company. A tenant with two directories has a merger problem, not a
            // software problem, and modelling it would double the routing ambiguity below.
            $table->unsignedBigInteger('company_id')->unique();

            $table->string('provider', 30)->default('oidc');
            $table->string('display_name', 120)->nullable();

            $table->string('client_id', 255);
            $table->text('client_secret');

            /**
             * The issuer. Endpoints are read from its discovery document rather than stored, so a
             * provider rotating a URL does not silently break every login until somebody notices.
             */
            $table->string('issuer', 255);

            /**
             * Email domains this connection owns, e.g. ["acme.co.za"].
             *
             * This is how a login is routed to the right tenant from one shared login page, and it
             * is also a security boundary: a token from Acme's provider carrying an address at
             * another domain must not be accepted, or a misconfigured IdP becomes a way into
             * somebody else's company.
             */
            $table->jsonb('email_domains');

            /**
             * Create a user on first successful login.
             *
             * Off by default. On, an employer's whole directory can walk into the product the first
             * time each person clicks through — which is usually what they want with SCIM off, and
             * occasionally a nasty surprise. It should be a decision, not an inherited default.
             */
            $table->boolean('jit_provisioning')->default(false);

            /** What a JIT-created user gets. Null means no role: they can sign in and see nothing. */
            $table->string('default_role', 60)->nullable();

            /**
             * Refuse password login for this company's users once SSO works.
             *
             * The point of SSO is that disabling someone centrally locks them out everywhere. A
             * local password that still works defeats it entirely — so this is the setting that
             * turns SSO from a convenience into a control.
             */
            $table->boolean('enforce_sso')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scim_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('name', 120);

            /**
             * SHA-256 of the token, never the token.
             *
             * A SCIM credential can create accounts and disable them. Storing it reversibly would
             * mean a database read yields the ability to provision an administrator into a tenant.
             */
            $table->string('token_hash', 64)->unique();
            $table->string('token_hint', 20);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            // Which provider identity this account belongs to. `external_id` is the IdP's stable
            // subject — never the email, which people change on marriage and which providers
            // reassign.
            $table->string('sso_provider', 30)->nullable()->after('password');
            $table->string('external_id', 255)->nullable()->after('sso_provider');
            $table->timestamp('sso_last_login_at')->nullable()->after('external_id');

            // Set by SCIM when the directory deactivates someone, so the reason a person cannot
            // sign in is visible rather than mysterious.
            $table->timestamp('deprovisioned_at')->nullable()->after('sso_last_login_at');

            $table->index(['sso_provider', 'external_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            foreach (['sso_connections', 'scim_tokens'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['sso_provider', 'external_id']);
            $table->dropColumn(['sso_provider', 'external_id', 'sso_last_login_at', 'deprovisioned_at']);
        });

        Schema::dropIfExists('scim_tokens');
        Schema::dropIfExists('sso_connections');
    }
};
