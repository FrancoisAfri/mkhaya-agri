<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two text fields the client's document has that the first cut collapsed.
 *
 * `project_status_notes` — reading their Word version properly, the Project Status column is not a
 * single word. It is a bullet list beside the status: "June invoice submitted but not paid",
 * "Minor work outstanding such as pump station, railway cross, and testing", "Contractor not on
 * site". So the column is a *type* plus a running commentary, and squeezing it into one selectable
 * value threw away most of what the column says. The type stays in `project_status`; the bullets
 * live here, and there can be as many as the month needs.
 *
 * `general_comment` — a note about the report as a whole rather than about any one project, which
 * the meeting needs somewhere to put.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_monthly_reports', function (Blueprint $t) {
            // Long text, and line breaks and bullet characters are preserved exactly as typed —
            // the same treatment as `challenges`, because it is the same kind of column.
            $t->text('project_status_notes')->nullable()->after('project_status');
        });

        Schema::table('pm_reporting_periods', function (Blueprint $t) {
            $t->text('general_comment')->nullable()->after('meeting_date');
        });
    }

    public function down(): void
    {
        Schema::table('pm_monthly_reports', fn (Blueprint $t) => $t->dropColumn('project_status_notes'));
        Schema::table('pm_reporting_periods', fn (Blueprint $t) => $t->dropColumn('general_comment'));
    }
};
