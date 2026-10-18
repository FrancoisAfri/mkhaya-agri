<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Trait\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PurchaseRequestAttachment extends Model
{
    use BelongsToCompany, FileStorage;
    use StoresFileBytes;

    protected $table = 'purchase_request_attachments';

    protected $fillable = [
        'uuid', 'purchase_request_id', 'file_data', 'storage_path', 'file_name', 'file_mime', 'file_size', 'uploaded_by',
    ];

    protected $hidden = ['file_data'];

    protected static function booted(): void
    {
        static::creating(fn (PurchaseRequestAttachment $m) => $m->uuid ??= (string) Str::uuid());
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function documentResponse(): \Illuminate\Http\Response
    {
        // Headers, filename sanitising and the missing-file case all live in the trait, so every
        // module that adopts it gets them without each author remembering.
        return $this->fileResponse(
            $this->file_name ?: $this->legacyEnvelopeValue('original_name') ?: 'attachment',
            $this->file_mime ?: $this->legacyEnvelopeValue('mime'),
            inline: false,
        );
    }

    /*
     * Bytes live on the shared `documents` disk, where encryption at rest and the tenant storage
     * quota apply. `file_data` is the original column, still read until these rows are pruned.
     */

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'purchase-requests';
    }
}
