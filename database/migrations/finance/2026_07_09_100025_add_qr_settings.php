<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-QR settings (2.2): show a scannable payment QR on invoices. Uses a
 * SnapScan merchant link if configured, otherwise a generic EFT payload with the
 * banking details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_settings', function (Blueprint $table) {
            $table->boolean('show_payment_qr')->default(true)->after('default_message');
            $table->string('snapscan_merchant', 80)->nullable()->after('show_payment_qr');
        });
    }

    public function down(): void
    {
        Schema::table('fin_settings', fn (Blueprint $table) => $table->dropColumn(['show_payment_qr', 'snapscan_merchant']));
    }
};
