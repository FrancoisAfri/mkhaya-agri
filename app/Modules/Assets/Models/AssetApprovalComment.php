<?php

namespace App\Modules\Assets\Models;

use App\Core\User\Models\User;
use App\Shared\Traits\Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetApprovalComment extends Model
{
    use Uuids;

    protected $table = 'asset_approval_comments';

    protected $fillable = [
        'uuid',
        'approval_id',
        'user_id',
        'comment',
    ];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(AssetApproval::class, 'approval_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
