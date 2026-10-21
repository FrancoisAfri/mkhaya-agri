<?php

namespace App\Shared\Messaging;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** A messaging channel one organisation has set up (its WhatsApp Business number). Credentials encrypted. */
class CompanyChannel extends Model
{
    use BelongsToCompany;

    protected $table = 'company_messaging_channels';

    protected $guarded = ['id'];

    protected $hidden = ['config'];

    protected $casts = ['is_active' => 'boolean', 'last_test_at' => 'datetime'];

    public function settings(): array
    {
        try {
            return $this->config ? (json_decode(Crypt::decryptString($this->config), true) ?: []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function putSettings(array $settings): void
    {
        $this->config = Crypt::encryptString(json_encode($settings));
    }

    public static function for(string $channel, ?int $companyId = null): ?self
    {
        return static::query()->when($companyId, fn ($q) => $q->withoutGlobalScopes()->where('company_id', $companyId))->where('channel', $channel)->first();
    }
}
