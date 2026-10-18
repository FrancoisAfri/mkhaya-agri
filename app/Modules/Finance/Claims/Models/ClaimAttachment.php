<?php

namespace App\Modules\Finance\Claims\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Models\User;
use App\Core\User\Trait\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Finance\Claims\Models\ClaimTravelTrip;

class ClaimAttachment extends Model
{
    use StoresFileBytes;

    use FileStorage;

    protected $table = 'claim_attachments';

    protected $fillable = [
        'claim_id', 'claim_item_id', 'claim_travel_trip_id',
        'uuid', 'original_name', 'mime', 'type', 'data', 'size',
        'attachment_type', 'uploaded_by',
    ];

    protected $hidden = ['data'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id');
    }

    public function claimItem(): BelongsTo
    {
        return $this->belongsTo(ClaimItem::class, 'claim_item_id');
    }

    public function travelTrip(): BelongsTo
    {
        return $this->belongsTo(ClaimTravelTrip::class, 'claim_travel_trip_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->size ?? 0;
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('claims.attachments.download', $this->uuid ?? $this->id);
    }

    protected function fileBlobColumn(): ?string
    {
        return 'data';
    }

    protected function fileFolder(): string
    {
        return 'claims';
    }
}
