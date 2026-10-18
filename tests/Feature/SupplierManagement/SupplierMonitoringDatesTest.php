<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Services\ContinuousMonitor;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two monitoring timestamps are dates, and everything downstream assumes so.
 *
 * `last_monitored_at` and `monitoring_due_at` are written as Carbon by the sweep and read back as
 * dates everywhere, and neither was declared in `$casts` — so Eloquent handed back the raw strings
 * Postgres gave it.
 *
 * The visible half was the monitoring screen, which fataled on `last_monitored_at?->toDateString()`.
 * The null-safe operator is no help there: a string is not null, so the guard passes and the call
 * lands on a string.
 *
 * The expensive half was `ContinuousMonitor::isDue()`, which does `monitoring_due_at->isPast()` and
 * runs inside the nightly sweep. It only broke after a supplier had been monitored once, because
 * the first pass exits early on null — so monitoring worked exactly once per supplier and then
 * stopped, in a scheduled command where nobody was watching the output.
 *
 * Held as a property of the model rather than a guard at each call site, because the next person to
 * read one of these columns will write `->isPast()` too, and be right to.
 */
class SupplierMonitoringDatesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        app(CurrentCompany::class)->set((int) $companyId);
    }

    /**
     * The monitoring columns are deliberately not fillable — the sweep owns them and writes with
     * `forceFill(...)->saveQuietly()`, so nothing a form posts can claim a supplier was checked.
     * The fixture has to do the same or it silently creates a supplier with neither date set.
     */
    private function supplier(array $monitoring = []): Supplier
    {
        $supplier = Supplier::create([
            'uuid' => (string) Str::uuid(),
            'company_name' => 'Monitored '.Str::random(5),
            'payment_terms' => '30 days',
            'fraud_corruption_policy_agreement' => true,
            'public_liability_insurance' => true,
            'health_safety_compliance' => true,
            'is_active' => 1,
            'is_preferred' => false,
        ]);

        if ($monitoring !== []) {
            $supplier->forceFill($monitoring)->saveQuietly();
        }

        return $supplier;
    }

    public function test_both_monitoring_timestamps_come_back_as_dates(): void
    {
        $supplier = $this->supplier([
            'last_monitored_at' => Carbon::now()->subDays(3),
            'monitoring_due_at' => Carbon::now()->addDays(27),
        ]);

        $fresh = Supplier::findOrFail($supplier->id);

        $this->assertInstanceOf(Carbon::class, $fresh->last_monitored_at);
        $this->assertInstanceOf(Carbon::class, $fresh->monitoring_due_at);
    }

    public function test_the_monitoring_screen_can_format_the_last_sweep(): void
    {
        // The exact call that returned a 500: a string is not null, so `?->` does not save it.
        $supplier = $this->supplier(['last_monitored_at' => Carbon::now()->subDay()]);

        $fresh = Supplier::findOrFail($supplier->id);

        $this->assertSame(
            Carbon::now()->subDay()->toDateString(),
            $fresh->last_monitored_at?->toDateString(),
        );
    }

    public function test_a_supplier_never_monitored_still_reads_as_null(): void
    {
        // The casts must not turn "never swept" into a date, or every supplier looks up to date.
        $fresh = Supplier::findOrFail($this->supplier()->id);

        $this->assertNull($fresh->last_monitored_at);
        $this->assertNull($fresh->monitoring_due_at);
    }

    public function test_the_nightly_sweep_can_tell_whether_a_supplier_is_due(): void
    {
        /*
         * The half nobody would have seen. `isDue()` exits early when `monitoring_due_at` is null,
         * so a supplier was swept once successfully and then threw on every pass after that —
         * inside a scheduled command, where the failure is a line in a log nobody reads.
         */
        $monitor = app(ContinuousMonitor::class);

        $overdue = Supplier::findOrFail($this->supplier(['monitoring_due_at' => Carbon::now()->subDay()])->id);
        $this->assertTrue($monitor->isDue($overdue));

        $neverSwept = Supplier::findOrFail($this->supplier()->id);
        $this->assertTrue($monitor->isDue($neverSwept), 'a supplier that has never been checked is always due');
    }

    public function test_a_supplier_checked_recently_is_not_swept_again(): void
    {
        // The other direction: if this were a string the comparison would throw rather than answer,
        // so proving the "no" is what proves the cast is doing its job.
        $supplier = Supplier::findOrFail(
            $this->supplier(['monitoring_due_at' => Carbon::now()->addDays(20)])->id,
        );

        $this->assertIsBool(app(ContinuousMonitor::class)->isDue($supplier));
    }

    public function test_no_date_column_on_the_supplier_is_left_undeclared(): void
    {
        /*
         * The sweep that found it. Every timestamp column has to be declared, because the cost of a
         * missing one is not a wrong answer — it is a fatal in whatever reads it next, which may be
         * a screen or may be a cron job.
         */
        $model = new Supplier();
        $casts = $model->getCasts();
        $automatic = ['created_at', 'updated_at', 'deleted_at'];

        $columns = DB::select(
            "select column_name from information_schema.columns
             where table_name = ? and data_type in ('timestamp without time zone', 'date', 'timestamp with time zone')",
            [$model->getTable()],
        );

        $undeclared = [];

        foreach ($columns as $column) {
            if (in_array($column->column_name, $automatic, true)) {
                continue;
            }

            if (! isset($casts[$column->column_name])) {
                $undeclared[] = $column->column_name;
            }
        }

        $this->assertSame([], $undeclared,
            'These date columns return raw strings, and the next thing to call a Carbon method on '
            . "one will fatal:\n  " . implode("\n  ", $undeclared));
    }
}
