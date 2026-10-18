<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Models\FleetBookingInspection;
use App\Modules\Fleet\Models\FleetFuelAnomaly;
use App\Modules\Fleet\Models\FleetInspectionAnalysis;
use App\Modules\Fleet\Models\FleetVehicle;
use App\Modules\Fleet\Models\FleetVehiclePrediction;
use App\Modules\Fleet\Services\FleetAnomalyService;
use App\Modules\Fleet\Services\FleetCopilotService;
use App\Modules\Fleet\Services\FleetPredictiveService;
use App\Modules\Fleet\Services\FleetVisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class FleetAIController extends Controller
{
    public function __construct(
        private readonly FleetCopilotService   $copilot,
        private readonly FleetPredictiveService $predictive,
        private readonly FleetVisionService     $vision,
        private readonly FleetAnomalyService    $anomaly,
    ) {}

    // ── FR-4.4 AI Chat ─────────────────────────────────────────────────────────

    public function index(): Response
    {
        $predictions = FleetVehiclePrediction::with('vehicle:id,uuid,registration_number,make,model')
            ->where('predicted_at', '>=', now()->subDay())
            ->orderByDesc('risk_score')
            ->limit(5)
            ->get()
            ->map(fn($p) => [
                'vehicle'              => $p->vehicle?->registration_number . ' ' . $p->vehicle?->make . ' ' . $p->vehicle?->model,
                'vehicle_id'           => $p->vehicle_id,
                'vehicle_uuid'         => $p->vehicle?->uuid,
                'risk_score'           => $p->risk_score,
                'risk_level'           => $p->riskLevel(),
                'predicted_service_date' => $p->predicted_service_date?->format('d M Y'),
                'top_risk_factors'     => $p->top_risk_factors,
            ]);

        $anomalies = FleetFuelAnomaly::unreviewed()
            ->with('vehicle:id,uuid,registration_number')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($a) => [
                'id'           => $a->id,
                'vehicle'      => $a->vehicle?->registration_number,
                'anomaly_type' => $a->anomaly_type,
                'description'  => $a->description,
                'severity'     => $a->severity,
                'created_at'   => $a->created_at->diffForHumans(),
            ]);

        return Inertia::render('Modules/Fleet/AI/Index', [
            'providers'   => $this->copilot->availableProviders(),
            'sessions'    => $this->copilot->getSessions(),
            'predictions' => $predictions,
            'anomalies'   => $anomalies,
        ]);
    }

    // ── FR-4.4.1 Chat ─────────────────────────────────────────────────────────

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'    => 'required|string|max:5000',
            'session_id' => 'nullable|string|max:36',
            'provider'   => 'nullable|string|in:local,claude,openai,gemini,deepseek',
        ]);

        $result = $this->copilot->chat(
            $data['message'],
            $data['session_id'] ?? null,
            $data['provider'] ?? 'local'
        );

        return response()->json($result);
    }

    public function sessions(): JsonResponse
    {
        return response()->json($this->copilot->getSessions());
    }

    public function history(string $sessionId): JsonResponse
    {
        return response()->json($this->copilot->getHistory($sessionId));
    }

    public function deleteSession(string $sessionId): JsonResponse
    {
        $this->copilot->deleteSession($sessionId);
        return response()->json(['deleted' => true]);
    }

    public function providers(): JsonResponse
    {
        return response()->json($this->copilot->availableProviders());
    }

    // ── FR-4.4.6 Action tool execution (requires prior AI suggestion) ──────────

    public function executeAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|string|in:approve_booking,create_booking,mark_key_lost',
            'params' => 'required|array',
        ]);

        $result = $this->copilot->executeTool($data['action'], $data['params'], Auth::user());

        return response()->json($result);
    }

    // ── FR-4.1 Predictions ─────────────────────────────────────────────────────

    public function predictions(): JsonResponse
    {
        $data = FleetVehiclePrediction::with('vehicle:id,uuid,registration_number,make,model,year')
            ->where('predicted_at', '>=', now()->subDay())
            ->orderByDesc('risk_score')
            ->get()
            ->map(fn($p) => [
                'vehicle_id'             => $p->vehicle_id,
                'vehicle_uuid'           => $p->vehicle?->uuid,
                'vehicle'                => $p->vehicle?->registration_number . ' ' . $p->vehicle?->make . ' ' . $p->vehicle?->model,
                'risk_score'             => (float) $p->risk_score,
                'risk_level'             => $p->riskLevel(),
                'predicted_service_date' => $p->predicted_service_date?->format('d M Y'),
                'top_risk_factors'       => $p->top_risk_factors,
                'predicted_at'           => $p->predicted_at?->diffForHumans(),
            ]);

        return response()->json($data);
    }

    public function runPrediction(Request $request): JsonResponse
    {
        $vehicleId = $request->integer('vehicle_id');
        $vehicle   = FleetVehicle::findOrFail($vehicleId);

        $prediction = $this->predictive->predict($vehicle);

        return response()->json([
            'risk_score'             => (float) $prediction->risk_score,
            'risk_level'             => $prediction->riskLevel(),
            'predicted_service_date' => $prediction->predicted_service_date?->format('d M Y'),
            'top_risk_factors'       => $prediction->top_risk_factors,
        ]);
    }

    // ── FR-4.2 Damage detection ────────────────────────────────────────────────

    public function analyzePhoto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id'    => 'required|exists:fleet_bookings,id',
            'photo_path'    => 'required|string',
            'inspection_id' => 'nullable|integer',
            'provider'      => 'nullable|string|in:claude,openai',
        ]);

        $analysis = $this->vision->analyzePhoto(
            $data['booking_id'],
            $data['photo_path'],
            $data['inspection_id'] ?? null,
            $data['provider'] ?? 'openai'
        );

        return response()->json([
            'id'                  => $analysis->id,
            'damage_detected'     => $analysis->damage_detected,
            'damage_type'         => $analysis->damage_type,
            'confidence'          => $analysis->confidence,
            'bounding_box'        => $analysis->bounding_box,
            'ai_description'      => $analysis->ai_description,
            'pending_review'      => $analysis->isPendingReview(),
        ]);
    }

    public function comparePhotos(Request $request): JsonResponse
    {
        $data = $request->validate([
            'booking_id'           => 'required|exists:fleet_bookings,id',
            'checkout_photo_path'  => 'required|string',
            'checkin_photo_path'   => 'required|string',
            'provider'             => 'nullable|string|in:claude,openai',
        ]);

        $analysis = $this->vision->comparePhotos(
            $data['booking_id'],
            $data['checkout_photo_path'],
            $data['checkin_photo_path'],
            $data['provider'] ?? 'openai'
        );

        return response()->json([
            'id'                  => $analysis->id,
            'new_damage_detected' => $analysis->new_damage_detected,
            'damage_detected'     => $analysis->damage_detected,
            'damage_type'         => $analysis->damage_type,
            'confidence'          => $analysis->confidence,
            'ai_description'      => $analysis->ai_description,
            'pending_review'      => $analysis->isPendingReview(),
        ]);
    }

    // ── FR-4.2.4 Human-in-the-loop confirmation ───────────────────────────────

    public function confirmAnalysis(Request $request, FleetInspectionAnalysis $analysis): RedirectResponse
    {
        $data = $request->validate([
            'is_confirmed_correct' => 'required|boolean',
            'guard_label'          => 'required|in:confirmed_damage,no_damage,pre_existing',
        ]);

        $analysis->update([
            'confirmed_by'         => Auth::id(),
            'confirmed_at'         => now(),
            'is_confirmed_correct' => $data['is_confirmed_correct'],
            'guard_label'          => $data['guard_label'],
        ]);

        return back()->with('success', 'Damage analysis review saved.');
    }

    // ── FR-4.3 Anomaly management ──────────────────────────────────────────────

    public function anomalies(): JsonResponse
    {
        $data = FleetFuelAnomaly::with('vehicle:id,registration_number')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($a) => [
                'id'             => $a->id,
                'vehicle'        => $a->vehicle?->registration_number,
                'vehicle_id'     => $a->vehicle_id,
                'vehicle_uuid'   => $a->vehicle?->uuid,
                'anomaly_type'   => $a->anomaly_type,
                'description'    => $a->description,
                'severity'       => $a->severity,
                'expected_value' => $a->expected_value,
                'actual_value'   => $a->actual_value,
                'is_reviewed'    => $a->is_reviewed,
                'resolution'     => $a->resolution,
                'created_at'     => $a->created_at->diffForHumans(),
            ]);

        return response()->json($data);
    }

    public function reviewAnomaly(Request $request, FleetFuelAnomaly $anomaly): JsonResponse
    {
        $data = $request->validate([
            'resolution' => 'required|in:false_positive,legitimate,under_investigation',
        ]);

        $anomaly->update([
            'is_reviewed' => true,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'resolution'  => $data['resolution'],
        ]);

        return response()->json(['success' => true]);
    }
}
