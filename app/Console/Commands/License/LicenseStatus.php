<?php

namespace App\Console\Commands\License;

use App\Core\License\LicenseService;
use Illuminate\Console\Command;

/**
 * Show the current license verdict on this install — useful for support and to
 * confirm a freshly-deployed license validates before it bites users.
 */
class LicenseStatus extends Command
{
    protected $signature   = 'license:status';
    protected $description = 'Show the current license status on this install';

    public function handle(LicenseService $license): int
    {
        $status = $license->status();

        if (! $status['enforced']) {
            $this->warn('Licensing is DORMANT (no public key configured) — all modules allowed.');
            return self::SUCCESS;
        }

        $status['valid']
            ? $this->info('License VALID.')
            : $this->error('License INVALID — reason: ' . $status['reason']);

        $p = $status['payload'] ?? [];
        $this->table(['Field', 'Value'], [
            ['reason', $status['reason']],
            ['client', $p['client'] ?? '—'],
            ['domain', $p['domain'] ?? '—'],
            ['modules', isset($p['modules']) ? implode(', ', $p['modules']) : '—'],
            ['max_users', $p['max_users'] ?? '—'],
            ['expires_at', $p['expires_at'] ?? '—'],
            ['this server fp', substr($license->fingerprint(), 0, 16) . '…'],
        ]);

        return $status['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
