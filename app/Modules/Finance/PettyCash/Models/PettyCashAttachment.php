<?php

namespace App\Modules\Finance\PettyCash\Models;

use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PettyCashAttachment extends Model
{
    protected $table = 'petty_cash_attachments';

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'uuid', 'request_id', 'reconciliation_id', 'uploaded_by',
        'file_name', 'original_name', 'file_path', 'file_size', 'mime_type', 'attachment_type',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PettyCashRequest::class, 'request_id');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(PettyCashReconciliation::class, 'reconciliation_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }
}
