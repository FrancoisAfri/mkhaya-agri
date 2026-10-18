<?php

namespace App\Console\Commands\License;

use Illuminate\Console\Command;

/**
 * Generate an Ed25519 signing keypair. Run ONCE, offline. The public key goes
 * into the app (LICENSE_PUBLIC_KEY); the private key stays with you and is used
 * only by `license:issue`. Never commit or deploy the private key.
 */
class LicenseKeygen extends Command
{
    protected $signature   = 'license:keygen';
    protected $description = 'Generate an Ed25519 license signing keypair (run once, offline)';

    public function handle(): int
    {
        $keypair = sodium_crypto_sign_keypair();
        $public  = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret  = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $this->info('License keypair generated.');
        $this->newLine();
        $this->line('PUBLIC KEY  — embed in the app (.env on each install):');
        $this->line('  LICENSE_PUBLIC_KEY=' . $public);
        $this->newLine();
        $this->line('PRIVATE KEY — keep SECRET, offline. Used only to issue licenses:');
        $this->line('  LICENSE_SIGNING_KEY=' . $secret);
        $this->newLine();
        $this->warn('Do NOT commit or deploy the private key. Anyone with it can forge licenses.');

        return self::SUCCESS;
    }
}
