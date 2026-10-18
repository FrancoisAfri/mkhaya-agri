<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether hours booked on a project have to be approved before they count.
 *
 * Approval is right for a job billed to a client, where an hour is money and somebody signs for it.
 * It is friction on an internal project, where the same routing sends a request to a manager who
 * approves everything on sight — and a queue that is always rubber-stamped teaches people that
 * queues are rubber stamps, which is worse than not having one.
 *
 * Defaults to true so nothing changes for projects that already exist: an installation that has been
 * routing timesheets for approval keeps doing exactly that until somebody decides otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_projects', function (Blueprint $table) {
            $table->boolean('timesheet_approval_required')->default(true)->after('is_billable');
        });
    }

    public function down(): void
    {
        Schema::table('pm_projects', function (Blueprint $table) {
            $table->dropColumn('timesheet_approval_required');
        });
    }
};
