<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email schedules for BI dashboards (copied from Mkhaya Sales' report_schedules): the dashboard is
 * built as the schedule's owner — so their subscription and access still apply — and mailed as an
 * Excel workbook (one sheet per visual) or a CSV of the first visual.
 */
return new class extends Migration
{
    private const SESSION = "NULLIF(current_setting('app.company_id', true), '')";

    public function up(): void
    {
        Schema::create('bi_schedules', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('dashboard', 80);                          // a dashboard uuid or "t:<template key>"
            $t->string('name', 150);
            $t->string('format', 10)->default('xlsx');           // xlsx | csv
            $t->string('period', 30)->default('last_month');     // see BIScheduler::PERIODS
            $t->string('frequency', 10);                         // daily | weekdays | weekly | monthly
            $t->unsignedTinyInteger('weekday')->nullable();      // 1 = Monday
            $t->unsignedTinyInteger('month_day')->nullable();
            $t->string('send_time', 5)->default('07:00');
            $t->jsonb('recipients');
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_sent_at')->nullable();
            $t->string('last_status', 20)->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'dashboard']);
            $t->index('is_active');
        });
        $s = self::SESSION;
        DB::statement("ALTER TABLE bi_schedules ALTER COLUMN company_id SET DEFAULT {$s}::bigint");
        DB::statement('ALTER TABLE bi_schedules ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE bi_schedules FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_isolation ON bi_schedules USING ({$s} IS NULL OR company_id = {$s}::bigint) WITH CHECK ({$s} IS NULL OR company_id = {$s}::bigint)");
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_schedules');
    }
};
