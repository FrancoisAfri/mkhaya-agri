<?php

namespace App\Console\Commands\License;

use App\Core\License\LicenseRegistry;
use Illuminate\Console\Command;

/**
 * Manage the authority registry / kill-switch. Run on the vendor-hosted
 * authority instance only.
 *
 *   license:registry list
 *   license:registry register --client="Acme" --domain=hub.acme.co.za --fingerprint=<fp>
 *   license:registry revoke --fingerprint=<fp> --reason="non-payment"
 *   license:registry restore --fingerprint=<fp>
 */
class LicenseRegistryCommand extends Command
{
    protected $signature = 'license:registry
        {action : list|register|revoke|restore}
        {--client=}
        {--domain=}
        {--fingerprint=}
        {--reason=}';

    protected $description = 'Manage the licensing authority registry (vendor only)';

    public function handle(): int
    {
        return match ($this->argument('action')) {
            'list'     => $this->list(),
            'register' => $this->register(),
            'revoke'   => $this->setRevoked(true),
            'restore'  => $this->setRevoked(false),
            default    => $this->bail('Unknown action. Use list|register|revoke|restore.'),
        };
    }

    private function list(): int
    {
        $rows = LicenseRegistry::orderBy('client')->get()->map(fn ($r) => [
            $r->client, $r->domain, substr($r->fingerprint, 0, 16) . '…',
            $r->revoked ? 'REVOKED' : 'active', $r->last_seen_at?->diffForHumans() ?? 'never',
        ]);
        $this->table(['Client', 'Domain', 'Fingerprint', 'State', 'Last seen'], $rows);
        return self::SUCCESS;
    }

    private function register(): int
    {
        $fp = $this->option('fingerprint');
        if (! $fp) return $this->bail('--fingerprint is required.');

        LicenseRegistry::updateOrCreate(
            ['fingerprint' => $fp],
            ['client' => (string) $this->option('client'), 'domain' => $this->option('domain'), 'revoked' => false],
        );
        $this->info('Registered.');
        return self::SUCCESS;
    }

    private function setRevoked(bool $revoked): int
    {
        $fp = $this->option('fingerprint');
        if (! $fp) return $this->bail('--fingerprint is required.');

        $record = LicenseRegistry::where('fingerprint', $fp)->first();
        if (! $record) return $this->bail('No registry entry for that fingerprint.');

        $record->update(['revoked' => $revoked, 'revoked_reason' => $revoked ? (string) $this->option('reason') : null]);
        $this->info($revoked ? 'Revoked. Clients hard-stop on their next heartbeat.' : 'Restored.');
        return self::SUCCESS;
    }

    private function bail(string $msg): int
    {
        $this->error($msg);
        return self::FAILURE;
    }
}
