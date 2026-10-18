<?php

namespace App\Modules\HR\Offboarding\Models;

use App\Core\User\Models\User;
use App\Modules\Assets\Models\Assets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OffboardingAssetReturn extends Model
{
    protected $table = 'offboarding_asset_returns';

    protected $fillable = [
        'plan_id',
        'asset_id',
        'status',
        'returned_at',
        'condition',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'returned_at' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(OffboardingPlan::class, 'plan_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Assets::class, 'asset_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
