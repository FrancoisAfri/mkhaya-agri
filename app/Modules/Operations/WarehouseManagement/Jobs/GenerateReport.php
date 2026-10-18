<?php

namespace App\Modules\Operations\WarehouseManagement\Jobs;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Notifications\ReportReadyNotification;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseReports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** A large warehouse export, built in the background; the requester is told when it is ready (WMS-185). */
class GenerateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly int $runId) {}

    public function handle(WarehouseReports $reports): void
    {
        $run = DB::table('wms_report_runs')->where('id', $this->runId)->first();
        if (! $run || $run->status === 'COMPLETE') {
            return;
        }
        $user = User::query()->withoutGlobalScopes()->find($run->requested_by);
        try {
            $run = $reports->generate($run);
            $user?->notify(new ReportReadyNotification($run->uuid, $run->title, (int) $run->rows, true));
        } catch (\Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                $user?->notify(new ReportReadyNotification($run->uuid, $run->title, 0, false));
            }
            throw $e;
        }
    }
}
