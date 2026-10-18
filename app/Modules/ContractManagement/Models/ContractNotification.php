<?php

namespace App\Modules\ContractManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContractNotification extends Model
{
    use BelongsToCompany;

    protected $table = 'contract_notifications';

    protected $fillable = [
        'uuid',
        'contract_id',
        'type',
        'message',
        'notification_date',
        'target_date',
        'interval',
        'is_read',
    ];

    protected $casts = [
        'notification_date' => 'datetime',
        'target_date' => 'datetime',
        'is_read' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ContractNotification $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }
}
