<?php

namespace App\Modules\HR\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One bulk onboarding upload and what became of it.
 *
 * `row_errors` is the useful part: the rows that were rejected, why, and which line of the
 * spreadsheet they were on, so a user can fix those rows and upload only them.
 */
class OnboardingBulkImport extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'filename',
        'total_rows',
        'created_count',
        'failed_count',
        'default_template_id',
        'row_errors',
        'status',
        'created_by',
        'company_id',
    ];

    protected $casts = [
        'row_errors' => 'array',
        'total_rows' => 'integer',
        'created_count' => 'integer',
        'failed_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $import) {
            $import->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The people this import created, so a run can be traced to its rows. */
    public function people()
    {
        return $this->hasMany(\App\Modules\HR\PreBoarding\Models\HrPerson::class, 'bulk_import_id');
    }
}
