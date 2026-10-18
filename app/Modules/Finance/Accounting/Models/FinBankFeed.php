<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

class FinBankFeed extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_bank_feeds';

    protected $fillable = [
        'entity_id', 'uuid', 'bank_account_id', 'provider', 'status', 'import_from',
        'refresh_mode', 'last_synced_at', 'external_ref', 'last_error', 'created_by',
        // Yodlee's side of the link: which provider account the user authorised, which of its
        // accounts this feed pulls, and how it should be named on screen.
        'provider_account_id', 'provider_ref', 'provider_account_name', 'provider_account_mask',
        'synced_through',
    ];

    protected $casts = [
        'import_from'    => 'date',
        'last_synced_at' => 'datetime',
        'synced_through' => 'date',
    ];

    // Yodlee is the only live provider; the demo feed has to be asked for by name.
    protected $attributes = ['status' => 'connected', 'provider' => 'yodlee', 'refresh_mode' => 'daily'];

    protected static function booted(): void
    {
        static::creating(fn (FinBankFeed $f) => $f->uuid ??= (string) Str::uuid());
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }
}
