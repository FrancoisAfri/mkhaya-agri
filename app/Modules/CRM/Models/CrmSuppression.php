<?php

namespace App\Modules\CRM\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSuppression extends Model
{
    protected $table = 'crm_suppressions';

    protected $fillable = ['channel', 'address', 'reason', 'campaign_id', 'created_by'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Is this address suppressed for the channel? */
    public static function isSuppressed(string $channel, ?string $address): bool
    {
        if (! $address) return false;

        return static::where('channel', $channel)
            ->where('address', mb_strtolower(trim($address)))
            ->exists();
    }

    public static function suppress(string $channel, string $address, string $reason = 'unsubscribed', ?int $campaignId = null, ?int $userId = null): void
    {
        static::updateOrCreate(
            ['channel' => $channel, 'address' => mb_strtolower(trim($address))],
            ['reason' => $reason, 'campaign_id' => $campaignId, 'created_by' => $userId]
        );
    }
}
