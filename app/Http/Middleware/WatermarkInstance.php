<?php

namespace App\Http\Middleware;

use App\Core\License\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps a short, non-secret instance watermark on every response. The value is
 * derived from the license (client) + server fingerprint, so each authorised
 * deployment emits a stable, identifiable marker. If the product turns up
 * running somewhere it shouldn't, the watermark traces it back to the install
 * it derives from. Cheap, passive, and impossible to spoof into another client's
 * value without that client's license + server.
 */
class WatermarkInstance
{
    public function __construct(private LicenseService $license)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $response->headers->set('X-Instance', $this->license->watermark());
        } catch (\Throwable) {
            // Never let watermarking break a response.
        }

        return $response;
    }
}
