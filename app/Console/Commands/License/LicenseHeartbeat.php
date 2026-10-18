<?php

namespace App\Console\Commands\License;

use App\Core\License\LicenseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client heartbeat to the licensing authority. Caches the verdict so
 * LicenseService can enforce remote revocation. Schedule it (hourly). On a
 * successful call the grace clock resets; if the authority is unreachable the
 * cached verdict is left untouched so grace counts from the last success.
 */
class LicenseHeartbeat extends Command
{
    protected $signature   = 'license:heartbeat';
    protected $description = 'Check in with the licensing authority (remote revocation)';

    public function handle(LicenseService $license): int
    {
        $url = config('license.server_url');
        if (! config('license.remote') || ! $url) {
            $this->info('Remote validation disabled — nothing to do.');
            return self::SUCCESS;
        }

        $payload = $license->status()['payload'] ?? [];

        try {
            $resp = Http::timeout(8)->acceptJson()->post(rtrim($url, '/') . '/license/validate', [
                'secret'      => config('license.remote_secret'),
                'client'      => $payload['client'] ?? null,
                'domain'      => $payload['domain'] ?? null,
                'fingerprint' => $license->fingerprint(),
            ]);
        } catch (\Throwable $e) {
            $this->warn('Authority unreachable: ' . $e->getMessage() . ' (within grace window).');
            return self::SUCCESS;
        }

        if (! $resp->ok()) {
            $this->warn('Authority returned HTTP ' . $resp->status() . ' (within grace window).');
            return self::SUCCESS;
        }

        $revoked = (bool) $resp->json('revoked', false);
        Cache::forever('license_remote_state', ['checked_at' => time(), 'revoked' => $revoked]);

        $revoked
            ? $this->error('This install is REVOKED: ' . ($resp->json('reason') ?: 'no reason given'))
            : $this->info('Heartbeat OK — install is active.');

        return self::SUCCESS;
    }
}
