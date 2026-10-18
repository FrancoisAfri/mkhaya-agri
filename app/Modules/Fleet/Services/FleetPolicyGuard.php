<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\FleetBooking;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Policies\DTOs\EvaluationResultDTO;
use App\Modules\Policies\DTOs\PolicyEventDTO;
use App\Modules\Policies\Services\Enforcement\EnforcementActionHandler;
use App\Modules\Policies\Services\RuleEngine\RuleEvaluationEngine;
use Illuminate\Validation\ValidationException;

/**
 * Bridge between Fleet Management module actions and the global Policy rule engine.
 *
 * Covers booking lifecycle, fuel logging, maintenance scheduling, vehicle disposal,
 * incident reporting, and fleet card transactions.
 *
 * Outcomes:
 *  block     → ValidationException (action is prevented)
 *  warn      → violation recorded, warnings returned, action proceeds
 *  escalate  → violation recorded, flag returned, action proceeds
 *  log_only  → violation recorded silently, action proceeds
 *
 * EventRuleMap entries that trigger Fleet rules:
 *  module = 'Fleet'
 *  action_pattern examples:
 *    'fleet.*'
 *    'fleet.booking.*'
 *    'fleet.booking.submitted'
 *    'fleet.fuel.*'
 *    'fleet.maintenance.*'
 *    'fleet.vehicle.*'
 *    'fleet.incident.*'
 *    'fleet.card.*'
 */
class FleetPolicyGuard
{
    public function __construct(
        private readonly RuleEvaluationEngine     $ruleEngine,
        private readonly EnforcementActionHandler $enforcementHandler,
    ) {}

    /**
     * Core evaluation — run all matching policy rules against an event.
     *
     * Throws ValidationException if any rule outcome is 'block'.
     */
    public function check(PolicyEventDTO $event): array
    {
        $results = $this->ruleEngine->evaluate($event);

        $warnings           = [];
        $violationIds       = [];
        $requiresEscalation = false;

        foreach ($results as $result) {
            /** @var EvaluationResultDTO $result */
            if ($result->passed) {
                continue;
            }

            $enforced = $this->enforcementHandler->handle($result);

            if ($enforced['is_blocked'] ?? false) {
                throw ValidationException::withMessages([
                    'policy' => [$enforced['blocked_message']
                        ?? "This action is blocked by policy: {$result->rule->name}"],
                ]);
            }

            if ($enforced['requires_approval'] ?? false) {
                $requiresEscalation = true;
            }

            if (isset($enforced['violation_id'])) {
                $violationIds[] = $enforced['violation_id'];
            }

            $explanation = $enforced['explanation'] ?? null;
            if ($explanation) {
                $warnings[] = is_array($explanation)
                    ? ($explanation['summary'] ?? $result->rule->name)
                    : (string) $explanation;
            }
        }

        return [
            'warnings'            => $warnings,
            'violation_ids'       => $violationIds,
            'requires_escalation' => $requiresEscalation,
        ];
    }

    // ── FR-3 Booking lifecycle ────────────────────────────────────────────────

    /**
     * Check policies when a fleet booking is submitted.
     *
     * Useful policy rules: max trip duration, prohibited destinations, usage type restrictions,
     * personal-use vehicle limits, passenger count maximums, supporting document requirements.
     */
    public function forBookingSubmitted(
        FleetBooking $booking,
        FleetVehicle $vehicle,
        int          $actorId,
    ): array {
        $departure      = $booking->departure_at;
        $expectedReturn = $booking->expected_return_at;
        $durationHours  = $departure && $expectedReturn
            ? $departure->diffInHours($expectedReturn)
            : null;

        return $this->check(new PolicyEventDTO(
            module:      'Fleet',
            action:      'fleet.booking.submitted',
            payload:     [
                'vehicle_id'       => $vehicle->id,
                'vehicle_type'     => $vehicle->vehicle_type,
                'fuel_type'        => $vehicle->fuel_type,
                'usage_type'       => $booking->usage_type,
                'is_local_trip'    => (bool) $booking->is_local_trip,
                'passenger_count'  => $booking->passenger_count ?? 0,
                'purpose'          => $booking->purpose,
                'destination'      => $booking->destination,
                'duration_hours'   => $durationHours,
                'requester_id'     => $booking->requester_id,
                'driver_id'        => $booking->driver_id,
                'has_supporting_doc' => (bool) $booking->supporting_document_path,
            ],
            actorId:     $actorId,
            subjectId:   $booking->id,
            subjectType: FleetBooking::class,
        ));
    }

    /**
     * Check policies when a booking approval step is actioned.
     *
     * Useful policy rules: who may approve, self-approval prohibition, approval authority limits.
     */
    public function forBookingApproved(
        FleetBooking $booking,
        int          $approverUserId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Fleet',
            action:      'fleet.booking.approved',
            payload:     [
                'vehicle_id'      => $booking->vehicle_id,
                'usage_type'      => $booking->usage_type,
                'requester_id'    => $booking->requester_id,
                'approver_id'     => $approverUserId,
                'self_approval'   => $approverUserId === $booking->requester_id,
                'duration_hours'  => $booking->departure_at && $booking->expected_return_at
                    ? $booking->departure_at->diffInHours($booking->expected_return_at)
                    : null,
            ],
            actorId:     $approverUserId,
            subjectId:   $booking->id,
            subjectType: FleetBooking::class,
        ));
    }

    /**
     * Check policies when a booking is cancelled.
     *
     * Useful policy rules: late cancellation notices, minimum notice periods.
     */
    public function forBookingCancelled(
        FleetBooking $booking,
        string       $reason,
        int          $actorId,
    ): array {
        $hoursUntilDeparture = $booking->departure_at
            ? now()->diffInHours($booking->departure_at, absolute: true)
            : null;

        return $this->check(new PolicyEventDTO(
            module:      'Fleet',
            action:      'fleet.booking.cancelled',
            payload:     [
                'vehicle_id'              => $booking->vehicle_id,
                'usage_type'              => $booking->usage_type,
                'requester_id'            => $booking->requester_id,
                'hours_until_departure'   => $hoursUntilDeparture,
                'reason'                  => $reason,
            ],
            actorId:     $actorId,
            subjectId:   $booking->id,
            subjectType: FleetBooking::class,
        ));
    }

    /**
     * Check policies when a vehicle is collected / checked out.
     *
     * Useful policy rules: odometer submission requirements, collection time windows.
     */
    public function forVehicleCollected(
        FleetBooking $booking,
        int          $startOdometer,
        int          $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Fleet',
            action:      'fleet.booking.collected',
            payload:     [
                'vehicle_id'      => $booking->vehicle_id,
                'usage_type'      => $booking->usage_type,
                'start_odometer'  => $startOdometer,
                'requester_id'    => $booking->requester_id,
                'driver_id'       => $booking->driver_id,
            ],
            actorId:     $actorId,
            subjectId:   $booking->id,
            subjectType: FleetBooking::class,
        ));
    }

    /**
     * Check policies when a vehicle is returned (check-in).
     *
     * Useful policy rules: maximum km per trip, late return penalties, mandatory inspection.
     */
    public function forVehicleReturned(
        FleetBooking $booking,
        int          $endOdometer,
        ?int         $kmTravelled,
        int          $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Fleet',
            action:      'fleet.booking.returned',
            payload:     [
                'vehicle_id'      => $booking->vehicle_id,
                'usage_type'      => $booking->usage_type,
                'km_travelled'    => $kmTravelled,
                'end_odometer'    => $endOdometer,
                'requester_id'    => $booking->requester_id,
                'driver_id'       => $booking->driver_id,
                'is_overdue'      => $booking->expected_return_at
                    ? now()->gt($booking->expected_return_at)
                    : false,
                'overdue_hours'   => $booking->expected_return_at && now()->gt($booking->expected_return_at)
                    ? $booking->expected_return_at->diffInHours(now())
                    : 0,
            ],
            actorId:     $actorId,
            subjectId:   $booking->id,
            subjectType: FleetBooking::class,
        ));
    }

    // ── FR-3.5.3 Incident reporting ───────────────────────────────────────────

    /**
     * Check policies when a vehicle incident is reported.
     *
     * Useful policy rules: mandatory police case for accidents above a threshold,
     * critical incidents requiring immediate vehicle withdrawal, notification chains.
     */
    public function forIncidentReported(
        FleetBooking $booking,
        string       $incidentType,
        string       $severity,
        ?string      $location,
        int          $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Fleet',
            action:      'fleet.incident.reported',
            payload:     [
                'vehicle_id'    => $booking->vehicle_id,
                'incident_type' => $incidentType,
                'severity'      => $severity,
                'location'      => $location,
                'usage_type'    => $booking->usage_type,
                'is_critical'   => $severity === 'critical',
                'driver_id'     => $booking->driver_id,
            ],
            actorId:     $actorId,
            subjectId:   $booking->id,
            subjectType: FleetBooking::class,
        ));
    }

    // ── Fuel ─────────────────────────────────────────────────────────────────

    /**
     * Check policies when a fuel fill is logged.
     *
     * Useful policy rules: max litres per fill, spend per transaction cap,
     * cost-per-litre ceiling (prevents fraud), fill without odometer prohibited.
     */
    public function forFuelLogged(
        int    $vehicleId,
        float  $litres,
        ?float $costPerLitre,
        ?float $totalCost,
        ?int   $driverId,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:  'Fleet',
            action:  'fleet.fuel.logged',
            payload: [
                'vehicle_id'     => $vehicleId,
                'litres'         => $litres,
                'cost_per_litre' => $costPerLitre,
                'total_cost'     => $totalCost,
                'driver_id'      => $driverId,
            ],
            actorId: $actorId,
        ));
    }

    // ── Maintenance ───────────────────────────────────────────────────────────

    /**
     * Check policies when maintenance is scheduled.
     *
     * Useful policy rules: spend approval thresholds, vendor restrictions,
     * vehicle age / service interval compliance.
     */
    public function forMaintenanceScheduled(
        int     $vehicleId,
        string  $maintenanceType,
        ?float  $estimatedCost,
        int     $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:  'Fleet',
            action:  'fleet.maintenance.requested',
            payload: [
                'vehicle_id'       => $vehicleId,
                'maintenance_type' => $maintenanceType,
                'estimated_cost'   => $estimatedCost,
            ],
            actorId: $actorId,
        ));
    }

    // ── Vehicle lifecycle ──────────────────────────────────────────────────────

    /**
     * Check policies when a vehicle is disposed / written off.
     *
     * Useful policy rules: disposal approval above book value thresholds,
     * minimum age/km requirements before disposal.
     */
    public function forVehicleDisposed(
        FleetVehicle $vehicle,
        string       $disposalReason,
        int          $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:      'Fleet',
            action:      'fleet.vehicle.disposed',
            payload:     [
                'vehicle_id'       => $vehicle->id,
                'vehicle_type'     => $vehicle->vehicle_type,
                'year'             => $vehicle->year,
                'odometer_reading' => $vehicle->odometer_reading,
                'disposal_reason'  => $disposalReason,
            ],
            actorId:     $actorId,
            subjectId:   $vehicle->id,
            subjectType: FleetVehicle::class,
        ));
    }

    // ── Fleet cards ───────────────────────────────────────────────────────────

    /**
     * Check policies when a fleet card transaction is logged.
     *
     * Useful policy rules: per-transaction spend caps, merchant category restrictions,
     * weekend/after-hours transaction alerts.
     */
    public function forCardTransactionLogged(
        int    $cardId,
        int    $vehicleId,
        float  $amount,
        string $merchantCategory,
        int    $actorId,
    ): array {
        return $this->check(new PolicyEventDTO(
            module:  'Fleet',
            action:  'fleet.card.transaction.logged',
            payload: [
                'card_id'           => $cardId,
                'vehicle_id'        => $vehicleId,
                'amount'            => $amount,
                'merchant_category' => $merchantCategory,
                'is_weekend'        => now()->isWeekend(),
            ],
            actorId: $actorId,
        ));
    }
}
