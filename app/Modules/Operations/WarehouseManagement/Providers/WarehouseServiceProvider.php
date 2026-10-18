<?php

namespace App\Modules\Operations\WarehouseManagement\Providers;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\WarehouseManagement\Policies\ItemPolicy;
use App\Modules\Operations\WarehouseManagement\Services\PutawayTasks;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use App\Modules\Operations\WarehouseManagement\Policies\WarehousePolicy;
use App\Modules\Operations\WarehouseManagement\Console\ReplenishCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires Warehouse Management & Intelligence into the application.
 *
 * Its own provider rather than lines in AppServiceProvider, so the module's wiring lives with the
 * module and can be read in one place.
 */
class WarehouseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Migrations run in filename order across every path, so this folder needs no position.
        $this->loadMigrationsFrom(database_path('migrations/warehouse'));
        $this->loadRoutesFrom(base_path('routes/api/warehouse.php'));

        Gate::policy(FinWarehouse::class, WarehousePolicy::class);
        Gate::policy(FinStockItem::class, ItemPolicy::class);

        $this->putAwayWhatInspectionAccepts();
        $this->actOnWarehouseInspections();

        // WMS-170..173: events in the outbox as records are written; ERP messages on Manufacturing's hub.
        app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseEventSources::class)->listen();
        // WMS-203: the audit ledger hears transactions, configuration and failures from the records themselves.
        app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAuditHooks::class)->listen();
        $this->callAfterResolving(\App\Modules\Operations\Manufacturing\Services\IntegrationHub::class,
            fn ($hub) => app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseErp::class)->register($hub));
        \Illuminate\Support\Facades\RateLimiter::for('warehouse-api', fn (\Illuminate\Http\Request $r) => \Illuminate\Cache\RateLimiting\Limit::perMinute((int) config('warehouse.api_per_minute', 300))
            ->by('wms:'.(($t = $r->user()?->currentAccessToken()) instanceof \Laravel\Sanctum\PersonalAccessToken ? 't'.$t->id : 'u'.($r->user()?->id ?? $r->ip()))));

        if ($this->app->runningInConsole()) {
            $this->commands([ReplenishCommand::class, \App\Modules\Operations\WarehouseManagement\Console\FulfilmentCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\CountsCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\ExpiryCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\ReconcileCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\ProtectCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\OutboxCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\NotifyCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\RetentionCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\InstallRolesCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\VerifyAuditCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\VerifyLedgerCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\HealthCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\BenchmarkCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\VerifyRestoreCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\QaEvidenceCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\KpiSnapshotCommand::class,
                \App\Modules\Operations\WarehouseManagement\Console\HandoverDocsCommand::class]);
        }

        // Pick faces are topped up through the shift, not once a night.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('wms:replenish')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
            // Deadlines are measured in hours; checking every ten minutes warns while it still helps.
            $schedule->command('wms:fulfilment')->everyTenMinutes()->withoutOverlapping()->onOneServer();
            // Counts open before the morning shift, so the list is waiting when the counters arrive.
            $schedule->command('wms:counts')->dailyAt('05:00')->withoutOverlapping()->onOneServer();
            $schedule->command('wms:expiry')->dailyAt('05:30')->withoutOverlapping()->onOneServer();
            // After the day's postings and before anybody opens the books in the morning.
            $schedule->command('wms:reconcile')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
            $schedule->command('wms:protect')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
            // Subscribers hear within a minute; retries back off from there.
            $schedule->command('wms:outbox')->everyMinute()->withoutOverlapping()->onOneServer();
            // Alerts, their reminders, escalations and retries; quarter-hourly keeps a deadline warning useful.
            $schedule->command('wms:notify')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
            // Retention and personal information, and a nightly check that the audit chain is whole.
            $schedule->command('wms:retention')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
            $schedule->command('wms:verify-audit')->dailyAt('04:30')->withoutOverlapping()->onOneServer();
            $schedule->command('wms:verify-ledger')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
            // §33: last month's KPIs, once it has ended.
            $schedule->command('wms:kpi-snapshot')->monthlyOn(1, '06:00')->withoutOverlapping()->onOneServer();

            // WMS-212: every warehouse command leaves a heartbeat the health checks read.
            foreach ($schedule->events() as $event) {
                if (preg_match("/'?(wms:[a-z-]+)'?/", (string) $event->command, $m) && $m[1] !== 'wms:verify-ledger') {
                    $event->before(fn () => \App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth::started($m[1]))
                        ->onSuccess(fn () => \App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth::finished($m[1], true))
                        ->onFailure(fn () => \App\Modules\Operations\WarehouseManagement\Services\WarehouseHealth::finished($m[1], false));
                }
            }
        });
    }

    /**
     * Stock received for inspection waits, unbinned and held, until Manufacturing's inspection
     * decides it. When that decision releases it, the warehouse needs a put-away task -- and the
     * receipt needs to say the inspection passed or failed. Listened for here rather than written
     * into Manufacturing, which has no reason to know what a put-away is.
     */
    /**
     * An inspection the warehouse opened (a return, a hold in a bin) carries no held status, so
     * deciding it moves nothing in Manufacturing; the warehouse carries the decision out. A refusal
     * -- a recalled item cannot go back to available -- is raised as the domain error the
     * inspection screens already show, and rolls the decision back with it.
     */
    private function actOnWarehouseInspections(): void
    {
        Inspection::updated(function (Inspection $inspection) {
            if (! $inspection->wasChanged('disposition') || $inspection->disposition === null
                || ! in_array($inspection->source_type, ['WMS_RETURN', 'WMS_STORAGE', 'WMS_ORDER'], true)) {
                return;
            }
            try {
                app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseQuality::class)->decided($inspection);
            } catch (\RuntimeException $e) {
                throw new \DomainException($e->getMessage(), 0, $e);
            }
        });
    }

    private function putAwayWhatInspectionAccepts(): void
    {
        Inspection::updated(function (Inspection $inspection) {
            // Finished goods passed by their final inspection go to stock locations (WMS-163).
            if ($inspection->wasChanged('disposition') && $inspection->stage === InspectionPlan::FINAL && $inspection->production_order_id
                && in_array($inspection->disposition, [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT], true) && $inspection->warehouse_id && $inspection->stock_item_id) {
                $warehouse = FinWarehouse::query()->find($inspection->warehouse_id);
                if ($warehouse?->is_wms_managed) {
                    app(PutawayTasks::class)->create($warehouse, FinStockItem::query()->findOrFail($inspection->stock_item_id), (float) $inspection->qty,
                        ['lot_id' => $inspection->lot_id, 'from_bin_id' => null, 'source_type' => 'PRODUCTION_ORDER', 'source_id' => $inspection->production_order_id]);
                }

                return;
            }
            if (! $inspection->wasChanged('disposition') || $inspection->stage !== InspectionPlan::INCOMING || ! $inspection->receipt_reference) {
                return;
            }
            $grn = GoodsReceivedNote::query()->where('grn_number', $inspection->receipt_reference)->where('company_id', $inspection->company_id)->first();
            if (! $grn) {
                return;
            }

            $accepted = in_array($inspection->disposition, [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT], true);
            $grn->update(['inspection_status' => $accepted ? 'PASSED' : 'FAILED']);

            if ($accepted && $inspection->warehouse_id && $inspection->stock_item_id) {
                app(PutawayTasks::class)->create(
                    FinWarehouse::query()->findOrFail($inspection->warehouse_id),
                    FinStockItem::query()->findOrFail($inspection->stock_item_id),
                    (float) $inspection->qty,
                    ['lot_id' => $inspection->lot_id, 'from_bin_id' => null, 'source_type' => 'GRN', 'source_id' => $grn->id],
                );
            }
        });
    }
}
