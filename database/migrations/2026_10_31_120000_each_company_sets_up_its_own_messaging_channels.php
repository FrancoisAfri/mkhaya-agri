<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging channels each organisation sets up for itself.
 *
 * SMS already is per company (sms_configurations). WhatsApp was not: its Cloud API credentials sat
 * in one installation-wide CRM setting, so every tenant would have sent from the same number. This
 * keeps each company's WhatsApp Business number, access token and the approved template its alerts
 * go out on (a business may only start a WhatsApp conversation with a template), encrypted at rest.
 * CRM, the Command Centre and anything else that sends WhatsApp read it from here first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_messaging_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $t->string('channel', 16);                                    // whatsapp
            $t->string('provider', 30)->default('meta_cloud');
            $t->text('config')->nullable();                               // encrypted JSON: phone_number_id, token, base_url, business_account_id
            $t->string('alert_template', 120)->nullable();                // approved template for alerts
            $t->string('template_language', 12)->default('en');
            $t->boolean('is_active')->default(false);
            $t->timestampTz('last_test_at')->nullable();
            $t->string('last_test_status', 255)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'channel']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE company_messaging_channels ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE company_messaging_channels ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE company_messaging_channels FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON company_messaging_channels USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_messaging_channels');
    }
};
