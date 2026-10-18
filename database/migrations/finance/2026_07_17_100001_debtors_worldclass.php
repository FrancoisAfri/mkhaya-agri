<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Debtors Manager → world-class collections. Adds collector assignment, dispute handling, a dunning
 * ladder (per-invoice step tracking), a public "pay now" token, per-customer comms preferences, and
 * the reminder-channel + dunning config on company settings. All additive & guarded so it is safe to
 * run on an existing production database (SaaS or self-hosted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('fin_invoices', 'assigned_to')) {
                $table->unsignedBigInteger('assigned_to')->nullable()->index()->comment('collector (users.id)');
            }
            if (! Schema::hasColumn('fin_invoices', 'disputed')) {
                $table->boolean('disputed')->default(false)->index();
            }
            if (! Schema::hasColumn('fin_invoices', 'dispute_reason')) {
                $table->string('dispute_reason', 500)->nullable();
            }
            if (! Schema::hasColumn('fin_invoices', 'disputed_at')) {
                $table->timestamp('disputed_at')->nullable();
            }
            if (! Schema::hasColumn('fin_invoices', 'disputed_by')) {
                $table->unsignedBigInteger('disputed_by')->nullable();
            }
            if (! Schema::hasColumn('fin_invoices', 'dunning_step')) {
                $table->unsignedInteger('dunning_step')->default(0)->comment('last dunning ladder step sent');
            }
            if (! Schema::hasColumn('fin_invoices', 'last_reminded_at')) {
                $table->timestamp('last_reminded_at')->nullable();
            }
            if (! Schema::hasColumn('fin_invoices', 'pay_token')) {
                $table->uuid('pay_token')->nullable()->unique();
            }
        });

        Schema::table('fin_parties', function (Blueprint $table) {
            if (! Schema::hasColumn('fin_parties', 'language')) {
                $table->string('language', 10)->nullable()->comment('preferred comms language, e.g. en, zu, af');
            }
            if (! Schema::hasColumn('fin_parties', 'do_not_contact')) {
                $table->boolean('do_not_contact')->default(false);
            }
        });

        Schema::table('fin_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('fin_settings', 'dunning_steps')) {
                $table->json('dunning_steps')->nullable()->comment('ladder: [{days,channel,subject,body,tone}]');
            }
            if (! Schema::hasColumn('fin_settings', 'reminder_channels')) {
                $table->json('reminder_channels')->nullable()->comment('enabled channels e.g. ["email","sms","whatsapp"]');
            }
            if (! Schema::hasColumn('fin_settings', 'payment_link_enabled')) {
                $table->boolean('payment_link_enabled')->default(true);
            }
            if (! Schema::hasColumn('fin_settings', 'reminder_grace_days')) {
                $table->unsignedInteger('reminder_grace_days')->default(2)->comment('grace before a PTP counts as broken');
            }
        });

        Schema::table('fin_collection_activities', function (Blueprint $table) {
            if (! Schema::hasColumn('fin_collection_activities', 'channel')) {
                $table->string('channel', 20)->nullable()->comment('email|sms|whatsapp|manual');
            }
            if (! Schema::hasColumn('fin_collection_activities', 'meta')) {
                $table->json('meta')->nullable();
            }
        });

        // Backfill a pay token for every existing open receivable invoice.
        DB::table('fin_invoices')
            ->where('direction', 'receivable')
            ->whereNull('pay_token')
            ->orderBy('id')
            ->chunkById(500, function ($invoices) {
                foreach ($invoices as $inv) {
                    DB::table('fin_invoices')->where('id', $inv->id)->update(['pay_token' => (string) Str::uuid()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('fin_invoices', function (Blueprint $table) {
            foreach (['assigned_to', 'disputed', 'dispute_reason', 'disputed_at', 'disputed_by', 'dunning_step', 'last_reminded_at', 'pay_token'] as $c) {
                if (Schema::hasColumn('fin_invoices', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('fin_parties', function (Blueprint $table) {
            foreach (['language', 'do_not_contact'] as $c) {
                if (Schema::hasColumn('fin_parties', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('fin_settings', function (Blueprint $table) {
            foreach (['dunning_steps', 'reminder_channels', 'payment_link_enabled', 'reminder_grace_days'] as $c) {
                if (Schema::hasColumn('fin_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('fin_collection_activities', function (Blueprint $table) {
            foreach (['channel', 'meta'] as $c) {
                if (Schema::hasColumn('fin_collection_activities', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
