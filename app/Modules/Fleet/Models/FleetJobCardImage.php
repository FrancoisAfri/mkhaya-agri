<?php

namespace App\Modules\Fleet\Models;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** A photo attached to a job card, stored in the database like the rest of fleet's files. */
class FleetJobCardImage extends Model
{
    use StoresFileBytes;

    use SoftDeletes, BelongsToCompany;

    protected $table = 'fleet_job_card_images';

    protected $fillable = [
        'uuid', 'company_id', 'job_card_id', 'caption',
        'file_name', 'mime_type', 'file_size', 'file_data', 'created_by',
    ];

    /** Never ship the binary in a listing payload. */
    protected $hidden = ['file_data'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->uuid ??= (string) Str::uuid());
    }

    public function jobCard(): BelongsTo { return $this->belongsTo(FleetJobCard::class, 'job_card_id'); }

    public function getRouteKeyName(): string { return 'uuid'; }

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'fleet/job-cards';
    }
}
