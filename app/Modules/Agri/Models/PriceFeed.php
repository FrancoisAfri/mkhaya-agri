<?php

namespace App\Modules\Agri\Models;

use Illuminate\Support\Facades\Crypt;

/**
 * An organisation's commodity price feed (CSV or JSON at a URL), with its credentials encrypted and
 * the mapping of its columns onto market prices. JSE/SAFEX has no free API: this takes whatever
 * the organisation's data vendor, broker or co-op publishes.
 */
class PriceFeed extends AgriModel
{
    protected $table = 'agri_price_feeds';

    protected $hidden = ['credentials'];

    protected $casts = ['config' => 'array', 'last_attempt_at' => 'datetime', 'last_success_at' => 'datetime', 'next_attempt_at' => 'datetime'];

    /** @return array{type?: string, name?: string, value?: string, username?: string, password?: string} */
    public function auth(): array
    {
        try {
            return $this->credentials ? (json_decode(Crypt::decryptString($this->credentials), true) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function putAuth(?array $auth): void
    {
        $this->credentials = $auth && ($auth['type'] ?? 'none') !== 'none' ? Crypt::encryptString(json_encode($auth)) : null;
    }
}
