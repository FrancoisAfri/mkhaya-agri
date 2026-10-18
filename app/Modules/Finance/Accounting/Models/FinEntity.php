<?php

namespace App\Modules\Finance\Accounting\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A trading entity — the business named on a quote or invoice.
 *
 * One tenant may trade as several: Mkhaya MK, Mkhaya Move, Mkhaya Hub. Each has its own
 * letterhead, banking details and document numbering; the staff, payroll and subscription behind
 * them are shared, because they are one business.
 *
 * Everything that describes an entity lives on its FinSetting row. This model is the identity
 * those settings hang from.
 */
class FinEntity extends Model
{
    use BelongsToCompany;

    protected $table = 'fin_entities';

    protected $fillable = ['company_id', 'name', 'slug', 'is_default', 'status', 'created_by'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $entity) {
            $entity->uuid ??= (string) Str::uuid();
            $entity->slug = $entity->slug ?: static::uniqueSlug($entity->name, $entity->company_id);
        });
    }

    /**
     * A slug that is free within the tenant.
     *
     * Two entities may legitimately be called similar things — "Mkhaya Move" and "Mkhaya Move
     * Logistics" both slug to different values, but "Mkhaya Move" twice does not. The unique index
     * on (company_id, slug) would refuse the second; a counter is friendlier than an error about a
     * column nobody asked to fill in.
     */
    public static function uniqueSlug(?string $name, ?int $companyId): string
    {
        $base = Str::slug(Str::limit((string) $name, 50, '')) ?: 'entity';
        $slug = $base;
        $n = 2;

        while (static::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    public function settings(): HasMany
    {
        return $this->hasMany(FinSetting::class, 'entity_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Entities a switcher should offer: the live ones, default first, then by name. */
    public function scopeSelectable($query)
    {
        return $query->where('status', 'active')->orderByDesc('is_default')->orderBy('name');
    }
}
