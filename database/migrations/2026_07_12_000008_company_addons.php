<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company add-on subscriptions (extra users, AI token packs, storage packs)
 * chosen in the Subscription Builder. Mirrors `company_modules`: each row snapshots
 * the per-unit price at the time it was added, so a later catalog change doesn't
 * silently re-price an existing company. Line total = quantity × price_monthly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_addons')) {
            Schema::create('company_addons', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('addon_code', 60);
                $t->string('type', 20);                          // user | ai_pack | storage_pack
                $t->unsignedInteger('quantity')->default(1);
                $t->decimal('price_monthly', 12, 2)->default(0); // per-unit snapshot
                $t->timestamps();
                $t->unique(['company_id', 'addon_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_addons');
    }
};
