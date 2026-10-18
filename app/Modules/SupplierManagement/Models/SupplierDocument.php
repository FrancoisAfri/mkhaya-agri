<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Trait\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierDocument extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity, FileStorage;
    use StoresFileBytes;

    protected $table = 'supplier_documents';

    protected $fillable = [
        'supplier_id', 'document_type_id', 'attachment_path', 'expiry_date',
        'file_data', 'storage_path', 'file_name', 'file_mime', 'file_size',
    ];

    protected $casts = [
        'expiry_date' => 'date',
    ];

    /** Keep the heavy base64 blob out of JSON/Inertia payloads. */
    protected $hidden = ['file_data'];

    public function hasDocument(): bool
    {
        // hasBytes() covers the path as well as the column. Checking only file_data would report
        // "no document" for every file that has been moved to the disk, silently switching off
        // every feature gated on this — AI extraction, expiry detection, the download button.
        return $this->hasBytes();
    }

    /** Decode the stored base64 JSON blob and return a binary download response. */
    public function documentResponse(bool $inline = false): \Illuminate\Http\Response
    {
        // Headers, filename sanitising and the missing-file case all live in the trait, so every
        // module that adopts it gets them without each author remembering.
        return $this->fileResponse(
            $this->file_name ?: $this->legacyEnvelopeValue('original_name') ?: 'document',
            $this->file_mime ?: $this->legacyEnvelopeValue('mime'),
            inline: $inline,
        );
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(SupplierDocumentType::class, 'document_type_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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
        return 'suppliers';
    }
}
