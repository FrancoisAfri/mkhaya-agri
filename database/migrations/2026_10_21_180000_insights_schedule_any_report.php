<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Insights: any report in the catalogue can be sent on a schedule.
 *
 * A module that already schedules its own reports keeps doing so (its Insights hub card is listed);
 * every report registered in the shared catalogue (app/Shared/Insights/ReportCatalog) can be sent
 * from the category's Insights page — run as the person who set it up, so it shows only what they
 * may see, and emailed to the recipients as CSV or Excel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id');                                    // runs as this person
            $t->string('report_key', 80);                                         // catalogue key, e.g. agri:yield
            $t->string('title', 160);
            $t->jsonb('params')->nullable();                                      // the report's filters
            $t->string('period', 20)->default('last_month');                     // last_7_days | last_month | month_to_date | year_to_date
            $t->string('format', 4)->default('xlsx');                            // csv | xlsx
            $t->string('frequency', 8);                                           // daily | weekly | monthly
            $t->unsignedTinyInteger('weekday')->nullable();                       // 1 = Monday
            $t->unsignedTinyInteger('month_day')->nullable();
            $t->time('send_at')->default('07:00');
            $t->jsonb('recipients');
            $t->boolean('active')->default(true);
            $t->timestampTz('next_run_at')->nullable()->index();
            $t->timestampTz('last_run_at')->nullable();
            $t->string('last_status', 10)->nullable();                            // sent | failed
            $t->string('last_error', 1000)->nullable();
            $t->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE report_schedules ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE report_schedules ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE report_schedules FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON report_schedules');
            DB::statement("CREATE POLICY tenant_isolation ON report_schedules USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
