<?php

namespace App\Core\AI\Controllers;

use App\Core\AI\Services\ERPCopilotService;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AICopilotController extends Controller
{
    public function __construct(private readonly ERPCopilotService $copilot) {}

    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message'    => 'required|string|max:5000',
            'session_id' => 'nullable|string|max:50',
        ]);

        return response()->json(
            $this->copilot->chat(
                $request->input('message'),
                $request->input('session_id')
            )
        );
    }

    public function history(string $sessionId): JsonResponse
    {
        return response()->json([
            'messages' => $this->copilot->getHistory($sessionId),
        ]);
    }

    public function sessions(): JsonResponse
    {
        return response()->json([
            'sessions' => $this->copilot->getSessions(),
        ]);
    }

    public function newSession(): JsonResponse
    {
        return response()->json([
            'session_id' => Str::uuid()->toString(),
        ]);
    }

    public function deleteSession(string $sessionId): JsonResponse
    {
        $this->copilot->deleteSession($sessionId);

        return response()->json(['deleted' => true]);
    }

    public function quickStats(): JsonResponse
    {
        $today = Carbon::today();

        try {
            $employees = DB::table('hr_people')->where('status', 1)->count();
        } catch (\Throwable) {
            $employees = null;
        }

        try {
            $onLeave = DB::table('leave_applications')
                ->where('status', 1)
                ->where('start_date', '<=', $today->timestamp)
                ->where('end_date', '>=', $today->timestamp)
                ->count();
        } catch (\Throwable) {
            $onLeave = null;
        }

        try {
            $assets = DB::table('assets')->count();
        } catch (\Throwable) {
            $assets = null;
        }

        return response()->json([
            'employees' => $employees,
            'onLeave'   => $onLeave,
            'assets'    => $assets,
        ]);
    }
}
