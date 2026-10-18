<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment gateways (PayFast / PayGate / Ozow — SA gateways with Instant EFT) and
 * a payments ledger. A platform admin configures/enables gateways and marks one
 * active; companies check out subscription charges and AI/SMS top-ups through it.
 * Credentials are stored encrypted (cast on the model).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_gateways')) {
            Schema::create('payment_gateways', function (Blueprint $t) {
                $t->id();
                $t->string('code', 20)->unique();      // payfast | paygate | ozow
                $t->string('label', 60);
                $t->boolean('is_enabled')->default(false);
                $t->boolean('is_active')->default(false); // the one companies pay through
                $t->string('mode', 8)->default('test');   // test | live
                $t->text('credentials')->nullable();      // encrypted json
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('gateway', 20);
                $t->string('purpose', 20);                 // subscription | topup_ai | topup_sms
                $t->decimal('amount', 12, 2);
                $t->string('currency', 3)->default('ZAR');
                $t->string('status', 12)->default('pending'); // pending | paid | failed | cancelled
                $t->string('our_ref', 64)->unique();
                $t->string('gateway_ref', 120)->nullable();
                $t->json('meta')->nullable();              // e.g. {type:ai, amount:100000, packs:1}
                $t->timestamp('paid_at')->nullable();
                $t->timestamps();
                $t->index(['company_id', 'status']);
            });
        }

        // Seed the three supported gateways (disabled until credentials are entered).
        foreach ([['payfast', 'PayFast'], ['paygate', 'PayGate'], ['ozow', 'Ozow']] as [$code, $label]) {
            \Illuminate\Support\Facades\DB::table('payment_gateways')->updateOrInsert(
                ['code' => $code],
                ['label' => $label, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_gateways');
    }
};
