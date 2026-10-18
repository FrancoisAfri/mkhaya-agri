<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An opaque pass for a contractor and for each of their people (CON-11, CON-13).
 *
 * The token is the whole of what a QR code carries. Nothing else goes in it — not an ID number,
 * not a medical outcome, not a certificate, not a line of the safety file. Two reasons, and the
 * second is the one that matters more:
 *
 * **A QR code is not a secret.** It is printed on a card, photographed, forwarded on WhatsApp and
 * left on a dashboard. Anything encoded in it is published. A worker's ID number inside a QR is an
 * ID number on a photocopier.
 *
 * **A static QR lies.** Encode "COID valid until March" and the code keeps saying that in April.
 * The token resolves to a live check instead, so the same printed card reads APPROVED today and
 * DENIED tomorrow when the insurance lapses — which is the only behaviour worth having at a gate.
 *
 * Rotatable, because a card is lost and a dismissed worker keeps theirs. Rotation issues a new
 * token and the old card stops resolving, with the previous token's retirement on the record so
 * "my card stopped working" has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sheq_contractors', 'sheq_contractor_workers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'qr_token')) {
                    /*
                     * 64 characters of randomness, unique across the estate.
                     *
                     * Not derived from the record's id or uuid: a token anybody can compute from a
                     * visible identifier is not a credential, and the verification endpoint has to
                     * be reachable without a login for a guard holding a phone at a boom.
                     */
                    $blueprint->string('qr_token', 64)->nullable()->unique();
                }

                if (! Schema::hasColumn($table, 'qr_issued_at')) {
                    $blueprint->timestampTz('qr_issued_at')->nullable();
                }

                if (! Schema::hasColumn($table, 'qr_rotated_at')) {
                    $blueprint->timestampTz('qr_rotated_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['sheq_contractors', 'sheq_contractor_workers'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                foreach (['qr_token', 'qr_issued_at', 'qr_rotated_at'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
