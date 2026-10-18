<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\Services\MkhayaMoveShuttleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mkhaya Move public shuttle API. Service-to-service endpoint other modules (or
 * a remote deployment) call to raise a shuttle booking. Authenticated by a
 * shared bearer/header token so it does not require an interactive session.
 */
class MkhayaMoveApiController extends Controller
{
    public function __construct(private readonly MkhayaMoveShuttleService $shuttles)
    {
    }

    /** Health/credential probe used by clients to test their connection. */
    public function health(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return response()->json(['status' => 'ok', 'service' => 'mkhaya-move']);
    }

    public function bookShuttle(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'reference'          => ['nullable', 'string', 'max:120'],
            'purpose'            => ['nullable', 'string', 'max:255'],
            'destination'        => ['required', 'string', 'max:255'],
            'departure_at'       => ['required', 'date'],
            'expected_return_at' => ['required', 'date', 'after:departure_at'],
            'passenger_count'    => ['nullable', 'integer', 'min:1'],
            'requester_id'       => ['required', 'integer', 'exists:users,id'],
            'notes'              => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->shuttles->book($data);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    private function authorized(Request $request): bool
    {
        $expected = config('services.mkhaya_move.token');
        if (blank($expected)) {
            return false; // secure by default: no token configured = no remote access
        }

        $provided = $request->bearerToken() ?: $request->header('X-Mkhaya-Move-Token');

        return is_string($provided) && hash_equals($expected, $provided);
    }
}
