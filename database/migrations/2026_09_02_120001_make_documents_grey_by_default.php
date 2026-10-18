<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use App\Modules\Finance\Accounting\Models\FinDocumentTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A financial document is read for its numbers.
 *
 * Every template shipped with a saturated accent — indigo, sky, amber — and used it for the title,
 * the table header and the block carrying the total. A colour that strong sits on top of the one
 * thing on the page that matters, and none of the invoices this was asked to look like use any
 * colour at all: they are set in ink and paper, with the reader's eye drawn by weight and spacing.
 *
 * The colour is kept, not discarded. Templates simply stop using it unless somebody says to, which
 * turns "every template is the same shape in a different colour" into a real choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_document_templates', function (Blueprint $t) {
            $t->boolean('use_accent')->default(false)->after('accent_color');
        });

        /*
         * The old layout names described a colour and a font weight; the new ones describe where
         * the information goes. Rows are carried over rather than reset, so a tenant who picked
         * "Modern" keeps the document they chose under the name that now describes it.
         */
        foreach (FinDocumentTemplate::RENAMED_LAYOUTS as $was => $now) {
            DB::table('fin_document_templates')->where('layout', $was)->update(['layout' => $now]);
        }
    }

    public function down(): void
    {
        Schema::table('fin_document_templates', function (Blueprint $t) {
            $t->dropColumn('use_accent');
        });
    }
};
