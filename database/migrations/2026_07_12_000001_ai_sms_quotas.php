<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI & SMS subscription quotas. Each company gets a monthly AI-token and SMS
 * allowance from its plan (with an optional per-company override). Usage is
 * counted per calendar month; companies can top up via requests (admin-approved,
 * billed to their subscription) or instant purchases. A `quota` of 0 = unlimited.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Plan-level monthly allowances (0 = unlimited, null = fall back to config default).
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedBigInteger('ai_token_quota')->nullable()->after('max_users');
            $t->unsignedInteger('sms_quota')->nullable()->after('ai_token_quota');
        });

        // Per-company overrides (null = use the plan's allowance).
        Schema::table('companies', function (Blueprint $t) {
            $t->unsignedBigInteger('ai_token_quota')->nullable()->after('plan');
            $t->unsignedInteger('sms_quota')->nullable()->after('ai_token_quota');
        });

        // Attribute AI usage to a company (backfilled from the user's company).
        if (! Schema::hasColumn('ai_usage_log', 'company_id')) {
            Schema::table('ai_usage_log', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->index()->after('user_id');
            });
            DB::statement('UPDATE ai_usage_log SET company_id = (SELECT company_id FROM users WHERE users.id = ai_usage_log.user_id) WHERE company_id IS NULL');
        }

        // SMS delivery log — drives per-company SMS usage counting.
        if (! Schema::hasTable('sms_log')) {
            Schema::create('sms_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('recipient', 40)->nullable();
                $t->string('purpose', 40)->nullable();      // otp | notification | bulk | …
                $t->unsignedInteger('segments')->default(1);
                $t->string('status', 12)->default('sent');  // sent | failed | skipped
                $t->string('provider', 30)->nullable();
                $t->string('error', 255)->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }

        // One-off monthly top-ups (extra tokens/SMS granted for a given period).
        if (! Schema::hasTable('company_monthly_credits')) {
            Schema::create('company_monthly_credits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('type', 8);                       // ai | sms
                $t->string('period', 7)->index();            // YYYY-MM
                $t->unsignedBigInteger('amount');            // tokens or SMS segments
                $t->string('source', 20)->default('grant');  // purchase | subscription | grant
                $t->unsignedBigInteger('token_request_id')->nullable();
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'type', 'period']);
            });
        }

        // Requests / purchases for more tokens or SMS.
        if (! Schema::hasTable('token_requests')) {
            Schema::create('token_requests', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('type', 8);                       // ai | sms
                $t->unsignedBigInteger('amount');            // tokens or SMS requested
                $t->string('mode', 16)->default('request');  // instant | subscription | request(grant)
                $t->decimal('unit_price', 12, 4)->nullable(); // price per pack unit
                $t->decimal('total_price', 12, 2)->nullable();
                $t->string('status', 12)->default('pending'); // pending | granted | approved | rejected
                $t->string('note', 500)->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'status']);
            });
        }

        // Seed sensible plan allowances (0 = unlimited).
        $seed = [
            'starter'    => ['ai_token_quota' => 200000,  'sms_quota' => 100],
            'standard'   => ['ai_token_quota' => 1000000, 'sms_quota' => 500],
            'enterprise' => ['ai_token_quota' => 0,       'sms_quota' => 0],
        ];
        foreach ($seed as $code => $vals) {
            DB::table('plans')->where('code', $code)->update($vals);
        }

        // Default enforcement mode (platform admin can toggle later).
        if (Schema::hasTable('app_settings')) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => 'ai_quota_enforcement'],
                ['value' => json_encode('strict'), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['ai_token_quota', 'sms_quota']));
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['ai_token_quota', 'sms_quota']));
        if (Schema::hasColumn('ai_usage_log', 'company_id')) {
            Schema::table('ai_usage_log', fn (Blueprint $t) => $t->dropColumn('company_id'));
        }
        Schema::dropIfExists('sms_log');
        Schema::dropIfExists('company_monthly_credits');
        Schema::dropIfExists('token_requests');
    }
};
