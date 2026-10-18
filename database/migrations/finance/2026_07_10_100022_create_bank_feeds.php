<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank feeds (Phase 3.2 direct feeds). A feed links a provider to a bank account
 * and pulls transactions into statement lines automatically, ready to reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_feeds', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('bank_account_id');
            $t->string('provider', 30)->default('demo');
            $t->string('status', 20)->default('connected'); // connected | error | disconnected
            $t->date('import_from')->nullable();
            $t->string('refresh_mode', 12)->default('login'); // login | daily | manual
            $t->timestamp('last_synced_at')->nullable();
            $t->string('external_ref', 120)->nullable();
            $t->string('last_error', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_feeds');
    }
};
