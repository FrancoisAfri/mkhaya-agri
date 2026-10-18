<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Path columns for the last image columns: profile pictures, logos and signature images.
 *
 * These were left out earlier because they are rendered inline as data URIs rather than downloaded,
 * so moving the bytes without changing the rendering would add a read per image per page. The
 * rendering changes with them — the payload carries a URL instead of an embedded image — so a
 * listing of two hundred people stops shipping two hundred base64 blobs in one response.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'hr_people'              => 'profile_pic_path',
        'hr_people_changes'      => 'profile_pic_path',
        'contacts_contacts'      => 'profile_pic_path',
        'content_websites'       => 'logo_path',
        'fin_settings'           => 'logo_path',
        'tender_branding'        => 'logo_path',
        'esign_signers'          => 'signature_path',
        'pm_contract_signatures' => 'signature_path',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, fn (Blueprint $t) => $t->string($column, 512)->nullable());
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
