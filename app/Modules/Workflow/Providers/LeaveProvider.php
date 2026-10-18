<?php

namespace App\Modules\Workflow\Providers;

use App\Core\User\Models\User;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Services\LeaveRequestService;
use App\Modules\Workflow\Contracts\WorkflowProvider;
use Illuminate\Support\Facades\DB;

/** HR leave requests on the workflow engine. Leave keeps its BCEA checks, balances and Time & Attendance sync. */
class LeaveProvider implements WorkflowProvider
{
    public function key(): string
    {
        return 'hr.leave';
    }

    public function label(): string
    {
        return 'HR — leave requests';
    }

    public function submitEvent(): ?string
    {
        return 'hr.leave.submitted';
    }

    public function fields(): array
    {
        return [
            ['key' => 'reference', 'label' => 'Request number', 'type' => 'text'],
            ['key' => 'leave_type', 'label' => 'Leave type', 'type' => 'text'],
            ['key' => 'start_date', 'label' => 'First day', 'type' => 'date'],
            ['key' => 'end_date', 'label' => 'Last day', 'type' => 'date'],
            ['key' => 'days', 'label' => 'Working days', 'type' => 'decimal'],
            ['key' => 'is_overtime', 'label' => 'Overtime (time off in lieu)', 'type' => 'yesno'],
            ['key' => 'on_behalf', 'label' => 'Lodged on the employee\'s behalf', 'type' => 'yesno'],
        ];
    }

    public function find(string $id): ?object
    {
        return ctype_digit($id) ? LeaveRequest::query()->find((int) $id) : null;
    }

    public function snapshot(object $record): array
    {
        return [
            'reference' => $record->request_number, 'leave_type' => DB::table('leave_types')->where('id', $record->leave_type_id)->value('name'),
            'start_date' => $record->start_date?->toDateString(), 'end_date' => $record->end_date?->toDateString(),
            'days' => (string) $record->working_days, 'is_overtime' => (bool) $record->is_overtime,
            'on_behalf' => $record->submitted_by && $record->submitted_by !== $this->requesterId($record),
        ];
    }

    public function reference(object $record): string
    {
        return (string) $record->request_number;
    }

    public function title(object $record): string
    {
        $name = DB::table('hr_people')->where('id', $record->employee_id)->selectRaw("trim(coalesce(first_name,'') || ' ' || coalesce(surname,'')) as n")->value('n');

        return "Leave {$record->request_number} — {$name}, " . $record->start_date?->format('j M') . ' to ' . $record->end_date?->format('j M Y') . " ({$record->working_days} days)";
    }

    public function url(object $record): ?string
    {
        return url('/leave-management/my-applications/' . $record->id);
    }

    public function requesterId(object $record): ?int
    {
        $user = DB::table('hr_people')->where('id', $record->employee_id)->value('user_id');

        return $user ? (int) $user : null;
    }

    public function ownerId(object $record): ?int
    {
        return $this->requesterId($record);
    }

    public function budgetHolderId(object $record): ?int
    {
        return null;
    }

    public function canView(User $user, object $record): bool
    {
        return $this->requesterId($record) === $user->id || $user->hasAnyRole(['administrator', 'super admin', 'hr', 'HR Manager']);
    }

    public function onOutcome(object $record, string $outcome, ?int $actorId, ?string $comment): void
    {
        app(LeaveRequestService::class)->finaliseByWorkflow($record, $outcome, $actorId, $comment);
    }
}
