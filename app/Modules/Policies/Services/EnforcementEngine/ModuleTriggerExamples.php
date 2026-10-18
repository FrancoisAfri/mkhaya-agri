<?php
/**
 * Drop these calls into your existing module Actions/Services to push events into PolicyOS.
 */
namespace App\Modules\Policies\Services\EnforcementEngine;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use Illuminate\Support\Facades\App;
class ModuleTriggerExamples {
    public static function leaveRequestSubmitted(int $userId, array $data): void {
        App::make(PolicyEventDispatcher::class)->dispatch(PolicyEventDTO::from([
            'module' => 'Leave', 'action' => 'leave_request.submitted',
            'actor_id' => $userId, 'subject_id' => $data['id'],
            'subject_type' => 'App\\Modules\\Leave\\Models\\LeaveRequest',
            'payload' => [
                'user_id' => $userId, 'leave_type' => $data['type'],
                'days_requested' => $data['days'], 'department_id' => $data['department_id'],
                'start_date' => $data['start_date'], 'approver_id' => $data['approver_id'],
            ],
        ]));
    }
    public static function claimCreated(int $userId, array $data): void {
        App::make(PolicyEventDispatcher::class)->dispatch(PolicyEventDTO::from([
            'module' => 'Claims', 'action' => 'claim.created',
            'actor_id' => $userId, 'subject_id' => $data['id'],
            'payload' => [
                'user_id' => $userId, 'amount' => $data['amount'],
                'currency' => 'ZAR', 'category' => $data['category'],
                'department_id' => $data['department_id'],
            ],
        ]));
    }
    public static function vehicleBooked(int $userId, array $data): void {
        App::make(PolicyEventDispatcher::class)->dispatch(PolicyEventDTO::from([
            'module' => 'Fleet', 'action' => 'vehicle.booking.created',
            'actor_id' => $userId, 'subject_id' => $data['id'],
            'payload' => [
                'user_id' => $userId, 'vehicle_id' => $data['vehicle_id'],
                'duration_days' => $data['duration_days'], 'purpose' => $data['purpose'],
            ],
        ]));
    }
}
