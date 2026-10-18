<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamping the copies that leave the building.
 *
 * Per share rather than only per company, because the decision belongs to the person sending it: a
 * contract going to an auditor wants a stamp, a brochure going to a client does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_shares', function (Blueprint $table) {
            $table->boolean('watermark')->default(false);
        });

        Schema::table('document_settings', function (Blueprint $table) {
            // What the box is set to when somebody creates a share. An organisation that wants
            // everything stamped should not rely on each person remembering.
            $table->boolean('watermark_shares_by_default')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('document_shares', fn (Blueprint $table) => $table->dropColumn('watermark'));
        Schema::table('document_settings', fn (Blueprint $table) => $table->dropColumn('watermark_shares_by_default'));
    }
};
