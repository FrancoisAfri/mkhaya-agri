<?php

namespace App\Modules\Assets\Actions\Maintenance;

use App\Modules\Assets\Events\AssetMaintenanceCompleted;
use App\Modules\Assets\Models\AssetMaintenance;
use Illuminate\Support\Facades\DB;

class CompleteMaintenanceAction
{
    public function execute(AssetMaintenance $maintenance, array $data): AssetMaintenance
    {
        return DB::transaction(function () use ($maintenance, $data) {
            $maintenance->update([
                'status'           => 'completed',
                'completed_date'   => $data['completed_date'] ?? now(),
                'work_performed'   => $data['work_performed'] ?? null,
                'parts_replaced'   => $data['parts_replaced'] ?? null,
                'cost'             => $data['cost'] ?? $maintenance->cost,
                'downtime_hours'   => $data['downtime_hours'] ?? $maintenance->downtime_hours,
                'next_due_date'    => $data['next_due_date'] ?? null,
                'notes'            => $data['notes'] ?? null,
            ]);

            // Update schedule if linked
            if ($maintenance->maintenance_schedule_id) {
                $schedule = $maintenance->schedule;
                $schedule->update([
                    'last_performed_date' => $maintenance->completed_date,
                    'next_due_date'       => $this->calculateNextDueDate($schedule),
                ]);
            }

            event(new AssetMaintenanceCompleted($maintenance));

            return $maintenance->fresh();
        });
    }

    private function calculateNextDueDate($schedule): ?\Carbon\Carbon
    {
        if (!$schedule->is_active) {
            return null;
        }

        $base = $schedule->last_performed_date ?? $schedule->start_date;

        return match ($schedule->frequency_type) {
            'daily'     => $base->addDays($schedule->frequency_value),
            'weekly'    => $base->addWeeks($schedule->frequency_value),
            'monthly'   => $base->addMonths($schedule->frequency_value),
            'quarterly' => $base->addMonths($schedule->frequency_value * 3),
            'annual'    => $base->addYears($schedule->frequency_value),
            default     => null,
        };
    }
}
