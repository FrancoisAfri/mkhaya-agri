<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic, per-tenant financial years. Adds a fin_fiscal_years table (each company can run its own
 * fiscal calendar — Jan–Dec, Mar–Feb, Aug–Jul or any start month), a fiscal_year_start_month on
 * company settings, and links each fiscal period to its year (+ a display label and a pre-close
 * checklist). Backfills a fiscal year for every existing set of periods so nothing breaks. Additive
 * and guarded — safe on production (SaaS or self-hosted).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fin_fiscal_years')) {
            Schema::create('fin_fiscal_years', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('label');                       // e.g. "FY2026/2027"
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 20)->default('open'); // open | closed | archived
                $table->boolean('is_current')->default(false);
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'status']);
            });
        }

        Schema::table('fin_fiscal_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('fin_fiscal_periods', 'fiscal_year_id')) {
                $table->unsignedBigInteger('fiscal_year_id')->nullable()->index();
            }
            if (! Schema::hasColumn('fin_fiscal_periods', 'label')) {
                $table->string('label')->nullable();           // e.g. "Aug 2026"
            }
            if (! Schema::hasColumn('fin_fiscal_periods', 'checklist')) {
                $table->json('checklist')->nullable();          // manual pre-close ticks
            }
        });

        Schema::table('fin_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('fin_settings', 'fiscal_year_start_month')) {
                $table->unsignedTinyInteger('fiscal_year_start_month')->default(1); // 1=Jan … 12=Dec
            }
        });

        $this->backfill();
    }

    /** Create a fiscal-year row for every existing (company, fiscal_year) group and link periods. */
    private function backfill(): void
    {
        $groups = DB::table('fin_fiscal_periods')
            ->select('company_id', 'fiscal_year')
            ->selectRaw('MIN(start_date) AS s, MAX(end_date) AS e, MIN(start_date) AS first_start')
            ->groupBy('company_id', 'fiscal_year')->get();

        foreach ($groups as $g) {
            $exists = DB::table('fin_fiscal_years')
                ->where('company_id', $g->company_id)
                ->whereDate('start_date', $g->s)->exists();
            if ($exists) {
                continue;
            }

            $start = \Illuminate\Support\Carbon::parse($g->s);
            $end = \Illuminate\Support\Carbon::parse($g->e);
            $label = $start->month === 1
                ? 'FY' . $start->year
                : 'FY' . $start->year . '/' . $end->year;

            // status: closed if every period is hard_close/locked
            $open = DB::table('fin_fiscal_periods')
                ->where('company_id', $g->company_id)->where('fiscal_year', $g->fiscal_year)
                ->whereIn('status', ['open', 'soft_close'])->count();

            $yearId = DB::table('fin_fiscal_years')->insertGetId([
                'company_id' => $g->company_id,
                'label'      => $label,
                'start_date' => $start->toDateString(),
                'end_date'   => $end->toDateString(),
                'status'     => $open > 0 ? 'open' : 'closed',
                'is_current' => $start->lte(now()) && $end->gte(now()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Link periods + set a display label per period.
            DB::table('fin_fiscal_periods')
                ->where('company_id', $g->company_id)->where('fiscal_year', $g->fiscal_year)
                ->orderBy('period_no')->get(['id', 'start_date'])
                ->each(fn ($p) => DB::table('fin_fiscal_periods')->where('id', $p->id)->update([
                    'fiscal_year_id' => $yearId,
                    'label' => \Illuminate\Support\Carbon::parse($p->start_date)->format('M Y'),
                ]));

            // Derive the company's fiscal start month from period 1.
            $firstMonth = $start->month;
            DB::table('fin_settings')->where('company_id', $g->company_id)
                ->update(['fiscal_year_start_month' => $firstMonth]);
        }
    }

    public function down(): void
    {
        Schema::table('fin_fiscal_periods', function (Blueprint $table) {
            foreach (['fiscal_year_id', 'label', 'checklist'] as $c) {
                if (Schema::hasColumn('fin_fiscal_periods', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('fin_settings', function (Blueprint $table) {
            if (Schema::hasColumn('fin_settings', 'fiscal_year_start_month')) {
                $table->dropColumn('fiscal_year_start_month');
            }
        });
        Schema::dropIfExists('fin_fiscal_years');
    }
};
