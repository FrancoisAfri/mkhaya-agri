<?php

namespace App\Modules\SupplierManagement\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Modules\Tenancy\Traits\BelongsToCompany;

use App\Core\User\Trait\FileStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Shared\Traits\KeepsDatabaseDefaults;

class SupplierDocumentType extends Model
{
    use StoresFileBytes, KeepsDatabaseDefaults;

    use BelongsToCompany, SoftDeletes, LogsActivity, FileStorage;

    protected $table = 'supplier_document_types';

    protected $fillable = [
        'uuid', 'document_type_name', 'active', 'compulsory', 'attachment_path',
        'template_data', 'template_name', 'template_mime',
        // Library metadata: a stable code the installer matches on, who issues the document,
        // how long one is valid, and where to get it — the useful half when nothing can be
        // verified through an API.
        'code', 'authority', 'default_validity_months', 'guidance',
    ];

    protected $casts = [
        'active'     => 'boolean',
        'compulsory' => 'boolean',
        'default_validity_months' => 'integer',
    ];

    /** Keep the heavy base64 blob out of JSON/Inertia payloads. */
    protected $hidden = ['template_data'];

    public function hasTemplate(): bool
    {
        return $this->hasBytes();
    }

    public function templateResponse(bool $inline = false): \Illuminate\Http\Response
    {
        return $this->fileResponse(
            $this->template_name ?: $this->legacyEnvelopeValue('original_name') ?: 'template',
            $this->template_mime ?: $this->legacyEnvelopeValue('mime'),
            inline: $inline,
        );
    }

    protected static function booted(): void
    {
        static::creating(function (SupplierDocumentType $model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function fileBlobColumn(): ?string
    {
        return 'template_data';
    }

    protected function filePathColumn(): string
    {
        return 'template_path';
    }

    protected function fileFolder(): string
    {
        return 'supplier-templates';
    }
}
