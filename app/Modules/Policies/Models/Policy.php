<?php

namespace App\Modules\Policies\Models;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Policy extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected static function newFactory(): \Database\Factories\PolicyFactory
    {
        return \Database\Factories\PolicyFactory::new();
    }

    protected $table = 'policy';

    protected $fillable = [
        'uuid',
        'name', 'description', 'document', 'status', 'date', 'category_id',
        'refresh_month', 'slug', 'current_version_id', 'owner_id',
        'department_id', 'effective_date', 'review_date',
        'requires_attestation', 'tags'
    ];

    protected static function booted(): void
    {
        static::creating(function (self $policy): void {
            if (empty($policy->uuid)) {
                $policy->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Keep UUID as the public route key while allowing legacy numeric links to resolve.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();

        if ($field === 'uuid' && is_numeric($value)) {
            $model = $this->newQuery()->whereKey((int) $value)->first();
            if ($model) {
                return $model;
            }

            throw (new ModelNotFoundException())->setModel(static::class, [$value]);
        }

        return parent::resolveRouteBinding($value, $field);
    }

    protected $casts = [
        'date' => 'integer',           // Unix timestamp
        'refresh_month' => 'integer',
        'effective_date' => 'date:Y-m-d',
        'review_date' => 'date:Y-m-d',
        'requires_attestation' => 'boolean',
        'tags' => 'array',
        'status' => 'string',          // because you changed to enum
    ];

    protected $appends = [
        'organization_profile',
        'organization_chain',
    ];

    // Optional accessor if keeping bigint
    public function getFormattedDateAttribute()
    {
        return $this->date
            ? \Carbon\Carbon::createFromTimestamp($this->date)
            : null;
    }

    public function category()
    {
        return $this->belongsTo(PolicyCategory::class, 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function departmentUnit(): BelongsTo
    {
        return $this->belongsTo(CompanyUnit::class, 'department_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class, 'policy_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class, 'current_version_id');
    }

    public function clauses(): HasMany
    {
        return $this->hasMany(PolicyClause::class, 'policy_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PolicyRule::class, 'policy_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(PolicyUser::class, 'policy_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PolicyDocument::class, 'policy_id');
    }

    public function reviewers(): HasMany
    {
        return $this->hasMany(PolicyReviewer::class, 'policy_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(PolicyException::class, 'policy_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Policy')
            ->logOnly(['name', 'description', 'status', 'effective_date', 'review_date', 'category_id', 'owner_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn(string $eventName) => $this->getDescriptionForEvent($eventName));
    }

    protected function getDescriptionForEvent(string $eventName): string
    {
        $actor = Auth::user()?->name ?? 'System';
        $name  = $this->name ?? 'Unnamed policy';
        return match ($eventName) {
            'created' => "{$actor} created policy \"{$name}\".",
            'updated' => "{$actor} updated policy \"{$name}\".",
            'deleted' => "{$actor} deleted policy \"{$name}\".",
            default   => "{$actor} triggered \"{$eventName}\" on policy \"{$name}\".",
        };
    }

    public function getOrganizationUnitAttribute(): ?CompanyUnit
    {
        $unit = $this->relationLoaded('departmentUnit')
            ? $this->getRelation('departmentUnit')
            : $this->departmentUnit()->first(['id', 'name', 'type', 'parent_id']);

        if ($unit) {
            return $unit;
        }

        $owner = $this->relationLoaded('owner')
            ? $this->getRelation('owner')
            : $this->owner()->with('person')->first();

        if (!$owner) {
            return null;
        }

        if (method_exists($owner, 'getPrimaryUnitAttribute')) {
            $primaryUnit = $owner->primary_unit ?? $owner->primaryUnit ?? null;
            if ($primaryUnit instanceof CompanyUnit) {
                return $primaryUnit;
            }
        }

        $ownerDepartment = $owner->department ?? null;
        if ($ownerDepartment instanceof CompanyUnit) {
            return $ownerDepartment;
        }

        $ownerDivision = $owner->division ?? null;
        if ($ownerDivision instanceof CompanyUnit) {
            return $ownerDivision;
        }

        return null;
    }

    public function getOrganizationChainAttribute(): array
    {
        $unit = $this->organization_unit;
        if (!$unit) {
            return [];
        }

        $segments = [];
        $cursor = $unit;
        $guard = 0;

        while ($cursor && $guard < 12) {
            $segments[] = [
                'id' => $cursor->id,
                'name' => $cursor->name ?? 'Unknown',
                'type' => $cursor->type ?? 'unit',
            ];

            if (empty($cursor->parent_id)) {
                break;
            }

            $cursor = $cursor->relationLoaded('parent')
                ? $cursor->getRelation('parent')
                : $cursor->parent()->first(['id', 'name', 'type', 'parent_id']);

            $guard++;
        }

        return array_reverse($segments);
    }

    public function getOrganizationProfileAttribute(): ?string
    {
        $chain = $this->organization_chain;

        if (empty($chain)) {
            return null;
        }

        return collect($chain)
            ->map(fn(array $unit) => trim(($unit['name'] ?? 'Unknown') . ' (' . ($unit['type'] ?? 'unit') . ')'))
            ->implode(', ');
    }
}
