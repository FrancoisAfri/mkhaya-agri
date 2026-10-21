<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Services\Agriculture\Gateways;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The farm gateway API: readings in, commands out, results back. No session; the gateway names
 * itself by its id and proves it with its secret (Authorization: Bearer …). Everything it does runs
 * inside its own organisation.
 */
class ProductionGatewayController extends Controller
{
    public function __construct(private readonly Gateways $gateways, private readonly TenantContext $tenants) {}

    public function readings(Request $request, string $uuid): JsonResponse
    {
        return $this->as($request, $uuid, function ($gateway) use ($request) {
            $rows = $request->input('readings');
            if (! is_array($rows) || ! $rows) {
                return response()->json(['error' => 'Send "readings": a list of {device, message_id, value, measured_at}.'], 422);
            }

            return response()->json($this->gateways->receive($gateway, $rows));
        });
    }

    public function commands(Request $request, string $uuid): JsonResponse
    {
        return $this->as($request, $uuid, fn ($gateway) => response()->json(['commands' => $this->gateways->outbox($gateway)]));
    }

    public function report(Request $request, string $uuid, string $command): JsonResponse
    {
        return $this->as($request, $uuid, function ($gateway) use ($request, $command) {
            $c = $this->gateways->report($gateway, $command, (string) $request->input('state'), $request->input('detail'));

            return response()->json(['id' => $c->uuid, 'status' => $c->status]);
        });
    }

    private function as(Request $request, string $uuid, callable $fn): JsonResponse
    {
        $gateway = $this->gateways->authenticate($uuid, $request->bearerToken());
        if (! $gateway) {
            return response()->json(['error' => 'Unknown gateway or wrong secret.'], 401);
        }
        try {
            return $this->tenants->run((int) $gateway->company_id, fn () => $fn($gateway));
        } catch (ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->implode(' ')], 422);
        }
    }
}
