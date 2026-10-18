<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One file that has held a document's place, with the name the uploader gave it. */
class DocumentVersion extends Model
{
    use BelongsToCompany;

    protected $table = 'document_versions';

    protected $guarded = ['id'];

    protected $casts = [
        'size' => 'integer',
        'version' => 'integer',
        'major' => 'integer',
        'minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $v) => $v->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** A size somebody can read, rather than a byte count. */
    public function humanSize(): string
    {
        $bytes = (int) $this->size;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return $bytes . ' B';
    }

    /**
     * The version as people write it: 1.0, 1.1, 2.0.
     *
     * The integer `version` remains the ordering and the unique key — it is what every existing row
     * has and what "latest" is decided by. This is the label, and the two are deliberately separate
     * because a restore produces a version that is numerically after its predecessor but is a
     * revival of something earlier.
     */
    public function label(): string
    {
        return $this->major . '.' . $this->minor;
    }

    /** The version this one replaced, whatever its number. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** Set when this version exists because somebody put an older one back. */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_id');
    }

    public function isRestoration(): bool
    {
        return $this->restored_from_id !== null;
    }
}
