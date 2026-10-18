<?php

namespace App\Modules\Assets\Jobs;

use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckMaintenanceOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function handle(): void
    {
        $today = now()->toDateString();

        AssetMaintenanceSchedule::query()
            ->where('status', 'scheduled')
            ->whereDate('due_date', '<', $today)
            ->with('asset:id,name,asset_number')
            ->chunkById(200, function ($schedules) {
                foreach ($schedules as $schedule) {
                    Log::warning('Maintenance schedule overdue', [
                        'schedule_id' => $schedule->id,
                        'asset_id'    => $schedule->asset_id,
                        'asset_name'  => $schedule->asset?->name,
                        'due_date'    => $schedule->due_date?->toDateString(),
                    ]);
                }
            });
    }
}
