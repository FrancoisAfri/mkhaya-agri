<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Services\Bureau\DisbursementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound payout-provider webhook: providers POST batch status updates here.
 * Authenticated by a shared secret (config services.payroll_payouts.webhook_secret)
 * so it can run without an interactive session.
 */
class PayoutWebhookController extends Controller
{
    public function __construct(private readonly DisbursementService $payouts)
    {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $expected = config('services.payroll_payouts.webhook_secret');
        $given = $request->bearerToken() ?: $request->header('X-Payout-Token');
        if (blank($expected) || ! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'reference' => ['required', 'string'],
            'status'    => ['required', 'string'],
        ]);
        $updated = $this->payouts->applyWebhookStatus($provider, $data['reference'], $data['status'], $request->all());

        return response()->json(['matched' => (bool) $updated, 'status' => $updated?->status]);
    }
}
