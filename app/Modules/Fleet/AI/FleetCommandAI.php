<?php

namespace App\Modules\Fleet\AI;

use App\Core\AI\Base\BaseModuleAI;
use App\Shared\DTOs\AgentResponse;
use InvalidArgumentException;
use RuntimeException;

class FleetCommandAI extends BaseModuleAI
{
    protected function module(): string
    {
        return 'fleet';
    }

    /**
     * Detect fuel anomalies: fraud, leaks, inefficiency patterns.
     */
    public function detectFuelAnomalies(array $fuelRecords, array $vehicleProfiles = [], int $maxTokens = 2048): AgentResponse
    {
        $this->validateFuelRecords($fuelRecords);
        $this->validateVehicleProfiles($vehicleProfiles);

        $suspiciousRecords = $this->filterSuspiciousFuelRecords($fuelRecords, $vehicleProfiles);
        if (empty($suspiciousRecords)) {
            return new AgentResponse([
                'risk_level'          => 'low',
                'anomalies'           => [],
                'summary'             => 'No rule-based anomalies detected.',
                'estimated_loss_zar'  => 0,
            ]);
        }

        $systemPrompt = <<<'SYS'
        You are a fleet fuel fraud analyst. Detect anomalies in fuel transactions.
        Respond in valid JSON:
        {
          "risk_level": "low|medium|high|critical",
          "anomalies": [
            {
              "vehicle_id": "...",
              "transaction_id": "...",
              "type": "over_fill|ghost_fill|excessive_consumption|off_route_fill|rapid_refill|other",
              "description": "...",
              "severity": "low|medium|high",
              "recommended_action": "..."
            }
          ],
          "summary": "...",
          "estimated_loss_zar": 0
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Analyse these suspicious fuel records for fraud patterns. ' .
                'Focus on transactions flagged by rule checks.',
                context: [
                    'fuel_records'      => $suspiciousRecords,
                    'vehicle_profiles'  => $vehicleProfiles,
                ],
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Fuel anomaly detection failed: ' . $e->getMessage());
        }
    }

    /**
     * Score overall vehicle health and recommend maintenance actions.
     */
    public function scoreVehicleHealth(array $vehicle, int $maxTokens = 2048): AgentResponse
    {
        $this->validateVehicleData($vehicle);

        $systemPrompt = <<<'SYS'
        You are a predictive maintenance AI for a vehicle fleet.
        Respond in valid JSON:
        {
          "vehicle_id": "...",
          "health_score": 0,
          "status": "good|fair|at_risk|critical",
          "risk_factors": [],
          "recommended_actions": [
            {"action": "...", "urgency": "immediate|this_week|this_month", "estimated_cost_zar": 0}
          ],
          "next_service_due": "..."
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Score this vehicle\'s health and predict maintenance needs based on mileage, service history, fault codes, and usage patterns.',
                context: $vehicle,
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Vehicle health scoring failed: ' . $e->getMessage());
        }
    }

    /**
     * Profile driver risk based on trip history, incidents, and behaviour patterns.
     */
    public function profileDriverRisk(array $driver, int $maxTokens = 2048): AgentResponse
    {
        $this->validateDriverData($driver);

        $systemPrompt = <<<'SYS'
        You are a driver risk analyst. Respond in valid JSON:
        {
          "driver_id": "...",
          "risk_score": 0,
          "risk_level": "low|medium|high|critical",
          "risk_factors": [],
          "incident_patterns": [],
          "recommended_actions": []
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Analyse this driver\'s history for risk patterns: speeding, incidents, fuel anomalies, route deviations, and off-hours vehicle use.',
                context: $driver,
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Driver risk profiling failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate an incident investigation report.
     */
    public function investigateIncident(array $incident, int $maxTokens = 2048): AgentResponse
    {
        $this->validateIncidentData($incident);

        $systemPrompt = <<<'SYS'
        You are a fleet incident investigator. Produce a structured incident report in JSON format:
        {
          "incident_id": "...",
          "title": "...",
          "timeline": "...",
          "contributing_factors": [],
          "driver_history_context": "...",
          "recommendations": [],
          "severity": "low|medium|high|critical",
          "estimated_impact_zar": 0
        }
        SYS;

        try {
            return $this->ask(
                prompt: 'Write a full incident investigation report based on the provided data. Be factual, thorough, and impartial.',
                context: $incident,
                options: ['maxTokens' => $maxTokens],
                systemPrompt: $systemPrompt
            );
        } catch (RuntimeException $e) {
            return $this->errorResponse('Incident investigation failed: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateFuelRecords(array $records): void
    {
        foreach ($records as $record) {
            if (!isset($record['vehicle_id'], $record['transaction_id'], $record['liters'], $record['odometer'], $record['timestamp'])) {
                throw new InvalidArgumentException('Each fuel record must contain vehicle_id, transaction_id, liters, odometer, and timestamp.');
            }
        }
    }

    private function validateVehicleProfiles(array $profiles): void
    {
        foreach ($profiles as $profile) {
            if (!isset($profile['vehicle_id'], $profile['tank_capacity'], $profile['avg_consumption'])) {
                throw new InvalidArgumentException('Each vehicle profile must contain vehicle_id, tank_capacity, and avg_consumption.');
            }
        }
    }

    private function validateVehicleData(array $vehicle): void
    {
        if (!isset($vehicle['vehicle_id'], $vehicle['mileage'])) {
            throw new InvalidArgumentException('Vehicle data must contain vehicle_id and mileage.');
        }
    }

    private function validateDriverData(array $driver): void
    {
        if (!isset($driver['driver_id'])) {
            throw new InvalidArgumentException('Driver data must contain driver_id.');
        }
    }

    private function validateIncidentData(array $incident): void
    {
        if (!isset($incident['incident_id'], $incident['timestamp'], $incident['vehicle_id'], $incident['description'])) {
            throw new InvalidArgumentException('Incident data must contain incident_id, timestamp, vehicle_id, and description.');
        }
    }

    private function filterSuspiciousFuelRecords(array $records, array $profiles): array
    {
        $profileIndex = [];
        foreach ($profiles as $profile) {
            $profileIndex[$profile['vehicle_id']] = $profile;
        }

        $suspicious = [];
        foreach ($records as $record) {
            $vehicleId = $record['vehicle_id'];
            $profile = $profileIndex[$vehicleId] ?? null;

            if ($profile && $record['liters'] > $profile['tank_capacity']) {
                $record['_suspicious_reason'] = 'over_fill';
                $suspicious[] = $record;
            }
            // Additional rules (e.g., consumption deviation) can be added here
        }
        return $suspicious;
    }

    private function errorResponse(string $message): AgentResponse
    {
        return new AgentResponse([
            'error'   => true,
            'message' => $message,
        ]);
    }
}
