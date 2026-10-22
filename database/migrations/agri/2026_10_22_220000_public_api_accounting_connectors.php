<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two ways out of the farm, built together:
 *
 *  A. The public farm API (v1). An organisation administrator issues an API client: a Sanctum token
 *     with scoped abilities and an expiry, bound to one company and acting as its own named service
 *     user, so row-level security, farm access and the audit all apply to it as to a person. Every
 *     call is logged (agri_api_calls); a POST may carry an Idempotency-Key (agri_api_idempotency);
 *     outside systems subscribe to farm events by webhook (agri_webhook_subscriptions), delivered
 *     signed and retried through the integration log.
 *  B. Accounting connectors for Xero, QuickBooks Online and Sage Business Cloud Accounting: one
 *     OAuth connection per organisation and provider with its tokens encrypted
 *     (agri_accounting_connections), the mapping of our accounts, tax codes, farm dimensions,
 *     contacts and items onto theirs (agri_accounting_mappings), and every record pushed or pulled
 *     with its external id and last error (agri_accounting_links). The organisation's book of
 *     record (our own ledger, or one of the connected systems) is on agri_org_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── A ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_api_clients', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('name', 120);
            $t->string('description', 500)->nullable();
            $t->unsignedBigInteger('service_user_id');                   // the named user the token acts as
            $t->unsignedBigInteger('token_id')->nullable();              // personal_access_tokens.id; null once revoked
            $t->string('token_hint', 16)->nullable();                    // the token's first characters, to recognise it
            $t->jsonb('abilities');                                      // ["farms:read", "activities:write", …]
            $t->jsonb('farm_ids')->nullable();                           // null: every farm; else the farms it may reach
            $t->unsignedInteger('rate_per_minute')->default(120);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('revoked_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'revoked_at']);
            $t->unique('token_id');
        });

        Schema::create('agri_api_calls', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('method', 8);
            $t->string('path', 300);
            $t->string('route', 120)->nullable();
            $t->unsignedSmallInteger('status');
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('ip', 45)->nullable();
            $t->boolean('replayed')->default(false);
            $t->string('error', 300)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['company_id', 'created_at']);
            $t->index(['company_id', 'client_id', 'created_at']);
        });

        Schema::create('agri_api_idempotency', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('client_id');
            $t->string('key', 120);
            $t->string('method', 8);
            $t->string('path', 300);
            $t->string('request_hash', 64);
            $t->unsignedSmallInteger('response_status')->nullable();
            $t->longText('response_body')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['company_id', 'client_id', 'key']);
        });

        Schema::create('agri_webhook_subscriptions', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('client_id')->nullable();             // made through the API by this client, or on the screen
            $t->string('name', 120);
            $t->string('url', 500);
            $t->jsonb('events');                                         // ["harvest.recorded", "lot.dispatched", "alert.raised"]
            $t->text('secret');                                          // encrypted; the HMAC-SHA256 key
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_delivery_at')->nullable();
            $t->unsignedSmallInteger('last_status')->nullable();
            $t->unsignedInteger('deliveries')->default(0);
            $t->unsignedInteger('failures')->default(0);
            $t->string('last_error', 300)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'is_active']);
        });

        // ── B ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_accounting_connections', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('provider', 12);                                  // xero | quickbooks | sage
            $t->string('status', 20)->default('disconnected');           // needs_registration | disconnected | choose_tenant | connected | error
            $t->string('region', 8)->nullable();                         // Sage: uk, ie, us, ca, es, fr, de (South Africa is a different API)
            $t->string('tenant_id', 120)->nullable();                    // Xero tenant, QuickBooks realm, Sage business
            $t->string('tenant_name', 200)->nullable();
            $t->jsonb('tenants')->nullable();                            // what the provider offered after connecting [{id, name}]
            $t->text('tokens')->nullable();                              // encrypted {access_token, refresh_token, expires_at, refresh_expires_at, scope}
            $t->string('oauth_state', 64)->nullable();                   // sha256 of the state sent to the provider
            $t->timestamp('oauth_state_expires_at')->nullable();
            $t->jsonb('catalog')->nullable();                            // their chart, tax rates, tracking, items as last pulled
            $t->jsonb('settings')->nullable();                           // {push_invoices, push_bills, push_journals, pull_payments, default_item, tracking}
            $t->timestamp('connected_at')->nullable();
            $t->unsignedBigInteger('connected_by')->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamp('payments_pulled_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'provider']);
        });

        Schema::create('agri_accounting_mappings', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('connection_id')->constrained('agri_accounting_connections')->cascadeOnDelete();
            $t->string('kind', 12);                                      // account | tax | tracking | contact | item
            $t->string('local_key', 120);                                // account code, tax code, farm:12 / field:3 / enterprise:crop_plan:5, party id
            $t->string('local_label', 200)->nullable();
            $t->string('external_id', 120)->nullable();
            $t->string('external_code', 120)->nullable();
            $t->string('external_name', 200)->nullable();
            $t->jsonb('extra')->nullable();                              // tracking: {category_id, category, option}; contact: {matched_by}
            $t->timestamps();
            $t->unique(['connection_id', 'kind', 'local_key']);
        });

        Schema::create('agri_accounting_links', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('connection_id')->constrained('agri_accounting_connections')->cascadeOnDelete();
            $t->string('record_type', 12);                               // invoice | bill | journal | payment
            $t->unsignedBigInteger('local_id');
            $t->string('local_ref', 120)->nullable();                    // INV-0001, JNL-0004 …
            $t->string('direction', 4)->default('out');                  // out | in
            $t->string('status', 10)->default('pending');                // pending | synced | failed
            $t->string('external_id', 120)->nullable();
            $t->string('external_ref', 120)->nullable();
            $t->unsignedBigInteger('event_id')->nullable();              // agri_integration_events: the message that carries it, retryable
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error', 1000)->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'record_type', 'local_id', 'direction']);
            $t->index(['connection_id', 'record_type', 'external_id']);
            $t->index(['company_id', 'status']);
        });

        Schema::table('agri_org_settings', function (Blueprint $t) {
            $t->string('book_of_record', 12)->default('internal');      // internal | xero | quickbooks | sage
            $t->timestamp('book_of_record_changed_at')->nullable();
            $t->unsignedBigInteger('book_of_record_changed_by')->nullable();
        });

        AgriSchema::tenantRls(['agri_api_clients', 'agri_api_calls', 'agri_api_idempotency', 'agri_webhook_subscriptions',
            'agri_accounting_connections', 'agri_accounting_mappings', 'agri_accounting_links']);
    }

    public function down(): void
    {
        Schema::table('agri_org_settings', fn (Blueprint $t) => $t->dropColumn(['book_of_record', 'book_of_record_changed_at', 'book_of_record_changed_by']));
        foreach (['agri_accounting_links', 'agri_accounting_mappings', 'agri_accounting_connections', 'agri_webhook_subscriptions',
            'agri_api_idempotency', 'agri_api_calls', 'agri_api_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
