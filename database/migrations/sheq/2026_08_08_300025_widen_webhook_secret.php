<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sheq_webhooks.secret` could not hold an encrypted secret.
 *
 * The column was sized for the secret itself (~54 characters) but stores the encrypted form, which
 * Laravel emits as base64-encoded JSON carrying the IV, the ciphertext and a MAC — around 300
 * characters for this payload, and variable. Every attempt to create a webhook subscription failed
 * on insert.
 *
 * Widened to text rather than to a larger varchar: the encrypted length depends on the cipher and
 * on the key, so any fixed bound is a guess that a future config change invalidates — and the
 * failure mode is a subscription that cannot be created at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_webhooks', function (Blueprint $table) {
            $table->text('secret')->change();
        });
    }

    public function down(): void
    {
        Schema::table('sheq_webhooks', function (Blueprint $table) {
            $table->string('secret', 255)->change();
        });
    }
};
