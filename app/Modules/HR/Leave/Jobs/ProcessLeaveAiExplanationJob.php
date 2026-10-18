<?php

namespace App\Modules\HR\Leave\Jobs;

use App\Modules\HR\Leave\AI\LeaveAdvisorAI;
use App\Modules\HR\Leave\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLeaveAiExplanationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(public readonly LeaveRequest $request) {}

    public function handle(LeaveAdvisorAI $ai): void
    {
        $request  = $this->request;
        $employee = $request->employee;
        $lt       = $request->leaveType;

        if (! $employee || ! $lt) {
            return;
        }

        $status  = is_string($request->status) ? $request->status : $request->status->value;
        $context = [
            'leave_type'  => $lt->name,
            'start_date'  => $request->start_date->format('Y-m-d'),
            'end_date'    => $request->end_date->format('Y-m-d'),
            'days'        => $request->working_days,
            'status'      => $status,
        ];

        // For terminal states use explainDecision for employee-friendly wording
        $explanation = in_array($status, ['approved', 'rejected', 'cancelled'])
            ? $ai->explainDecision($employee->id, array_merge($context, ['reason' => $request->notes]), $status)
            : $ai->generateBceaSummary(
                $employee->id,
                $lt->name,
                $lt->statutory_type ?? '',
                $context,
            );

        $request->update([
            'ai_explanation'  => $explanation,
            'ai_processed_at' => now(),
        ]);
    }
}
