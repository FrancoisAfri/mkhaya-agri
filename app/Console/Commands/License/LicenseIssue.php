<?php

namespace App\Console\Commands\License;

use App\Core\License\LicenseService;
use Illuminate\Console\Command;

/**
 * Issue (sign) a license with the PRIVATE key. Run offline by the vendor only.
 * Produces a license.json the client drops into storage/app on their install.
 *
 * Example:
 *   php artisan license:issue --client="Acme" --domain=hub.acme.co.za \
 *     --modules=crm,board_committee --days=365 --fingerprint=<server fp> \
 *     --max-users=50 --out=acme-license.json
 *
 * The private key is read from --key or the LICENSE_SIGNING_KEY env var.
 */
class LicenseIssue extends Command
{
    protected $signature = 'license:issue
        {--client= : Client / customer name}
        {--domain= : Bound domain, e.g. hub.acme.co.za}
        {--fingerprint= : Bound server fingerprint (from license:fingerprint)}
        {--modules=* : Allowed module code_names, or "*" for all}
        {--days=365 : Validity in days}
        {--max-users=0 : Max users (0 = unlimited)}
        {--key= : Base64 private key (defaults to LICENSE_SIGNING_KEY env)}
        {--out=license.json : Output file path}';

    protected $description = 'Sign a license file with the private key (vendor, offline)';

    public function handle(): int
    {
        $secretB64 = $this->option('key') ?: env('LICENSE_SIGNING_KEY');
        if (! $secretB64) {
            $this->error('No private key. Pass --key=<base64> or set LICENSE_SIGNING_KEY.');
            return self::FAILURE;
        }
        $secret = base64_decode((string) $secretB64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('Invalid private key.');
            return self::FAILURE;
        }

        $modules = $this->option('modules') ?: ['*'];
        $days    = max(1, (int) $this->option('days'));

        $payload = [
            'client'      => (string) $this->option('client'),
            'domain'      => (string) $this->option('domain'),
            'fingerprint' => (string) $this->option('fingerprint'),
            'modules'     => array_values($modules),
            'max_users'   => (int) $this->option('max-users'),
            'issued_at'   => gmdate('c'),
            'expires_at'  => gmdate('c', time() + $days * 86400),
        ];

        $signature = sodium_crypto_sign_detached(LicenseService::canonical($payload), $secret);

        $license = ['payload' => $payload, 'signature' => base64_encode($signature)];
        $out     = (string) $this->option('out');
        file_put_contents($out, json_encode($license, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info("License written to {$out}");
        $this->table(['Field', 'Value'], [
            ['client', $payload['client']],
            ['domain', $payload['domain'] ?: '(any)'],
            ['fingerprint', $payload['fingerprint'] ? substr($payload['fingerprint'], 0, 16) . '…' : '(any)'],
            ['modules', implode(', ', $payload['modules'])],
            ['expires_at', $payload['expires_at']],
        ]);
        $this->line('Deliver it to the client as storage/app/license.json on their install.');

        return self::SUCCESS;
    }
}
