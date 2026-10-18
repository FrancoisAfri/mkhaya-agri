<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Something to watch for: a brand, a website, or an account on one platform.
 *
 * A supplier is rarely mentioned by the name on its invoice. It trades under a brand, it posts as a
 * handle, and the story that matters names one of those — so monitoring the registered name alone
 * finds nothing and is mistaken for good news.
 */
class SupplierHandle extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'supplier_handles';

    protected $fillable = [
        'uuid', 'supplier_id', 'kind', 'platform', 'value', 'person_id',
        'is_active', 'added_by_source',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public const KIND_BRAND = 'brand';
    public const KIND_WEBSITE = 'website';
    public const KIND_HANDLE = 'handle';
    public const KIND_PERSON = 'person';

    protected static function booted(): void
    {
        static::creating(fn (self $handle) => $handle->uuid ??= (string) Str::uuid());
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(SupplierPerson::class, 'person_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Handles for one platform, plus the platform-agnostic ones (brands, websites). */
    public function scopeForPlatform(Builder $query, string $platform): Builder
    {
        return $query->where(fn ($q) => $q->where('platform', $platform)->orWhereNull('platform'));
    }
}
