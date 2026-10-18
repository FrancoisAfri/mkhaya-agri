<?php

namespace App\Console\Commands\Fleet;

use Illuminate\Console\Command;

class FleetGenerateVapidCommand extends Command
{
    protected $signature   = 'fleet:generate-vapid';
    protected $description = 'Generate VAPID key pair for Web Push notifications (FR-5.1)';

    public function handle(): int
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            $this->error('openssl_pkey_new failed. Ensure OpenSSL EC support is enabled.');
            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($key);
        openssl_pkey_export($key, $pem);

        $publicRaw  = $details['ec']['x'] . $details['ec']['y'];
        // Uncompressed point format: 0x04 prefix + x + y
        $public  = rtrim(strtr(base64_encode("\x04" . $publicRaw), '+/', '-_'), '=');

        // Extract raw private scalar d
        $private = rtrim(strtr(base64_encode($details['ec']['d']), '+/', '-_'), '=');

        $this->info('VAPID keys generated. Add these to your .env file:');
        $this->line('');
        $this->line("VAPID_PUBLIC_KEY={$public}");
        $this->line("VAPID_PRIVATE_KEY={$private}");
        $this->line('VAPID_SUBJECT=mailto:admin@mkhaya.co.za');
        $this->line('');
        $this->warn('Keep the private key secret — never commit it to version control.');

        return self::SUCCESS;
    }
}
