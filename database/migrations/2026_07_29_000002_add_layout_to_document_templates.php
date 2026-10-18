<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richer template styling, matching what Sage / Xero / QuickBooks offer: page orientation
 * (portrait / landscape), page size (A4 / Letter), a font family, and a layout preset
 * (classic / modern / bold / minimal) that changes the header treatment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_document_templates', function (Blueprint $t) {
            $t->string('orientation', 10)->default('portrait')->after('accent_color'); // portrait | landscape
            $t->string('page_size', 10)->default('a4')->after('orientation');           // a4 | letter
            $t->string('font', 20)->default('sans')->after('page_size');                // sans | serif | mono
            $t->string('layout', 20)->default('classic')->after('font');                // classic | modern | bold | minimal
        });
    }

    public function down(): void
    {
        Schema::table('fin_document_templates', function (Blueprint $t) {
            $t->dropColumn(['orientation', 'page_size', 'font', 'layout']);
        });
    }
};
