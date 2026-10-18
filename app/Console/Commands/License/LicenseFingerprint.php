<?php

namespace App\Console\Commands\License;

use App\Core\License\LicenseService;
use Illuminate\Console\Command;

/**
 * Print this server's fingerprint and domain. Run it on the target install and
 * send the values to the vendor, who issues a license bound to them.
 */
class LicenseFingerprint extends Command
{
    protected $signature   = 'license:fingerprint';
    protected $description = 'Show this server fingerprint and domain for license binding';

    public function handle(LicenseService $license): int
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: gethostname();

        $this->info('Provide these to your provider to issue a bound license:');
        $this->line('  domain      = ' . $domain);
        $this->line('  fingerprint = ' . $license->fingerprint());

        return self::SUCCESS;
    }
}
