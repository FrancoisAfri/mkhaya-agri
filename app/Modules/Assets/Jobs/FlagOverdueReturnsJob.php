<?php

namespace App\Modules\Assets\Jobs;

use App\Modules\Assets\Enums\AssignmentType;
use App\Modules\Assets\Models\AssetAssignment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FlagOverdueReturnsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function handle(): void
    {
        $today = now()->toDateString();

        AssetAssignment::query()
            ->where('is_current', true)
            ->whereNotNull('return_due_date')
            ->whereDate('return_due_date', '<', $today)
            ->whereIn('assignment_type', [AssignmentType::Temporary->value, 'temporary', 'loan'])
            ->with('asset:id,name,asset_number')
            ->chunkById(100, function ($assignments) {
                foreach ($assignments as $assignment) {
                    Log::warning('Overdue asset return', [
                        'assignment_id' => $assignment->id,
                        'asset_id'      => $assignment->asset_id,
                        'asset_name'    => $assignment->asset?->name,
                        'assigned_to'   => $assignment->assigned_to,
                        'due_date'      => $assignment->return_due_date?->toDateString(),
                    ]);
                }
            });
    }
}
