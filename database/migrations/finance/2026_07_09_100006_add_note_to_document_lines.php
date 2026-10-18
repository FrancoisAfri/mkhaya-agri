<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text note/comment per line on quotes and invoices/bills (prints on the PDF).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fin_quote_lines', 'fin_invoice_lines'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->text('note')->nullable()->after('description'));
        }
    }

    public function down(): void
    {
        foreach (['fin_quote_lines', 'fin_invoice_lines'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('note'));
        }
    }
};
