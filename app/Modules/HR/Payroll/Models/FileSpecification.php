<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One version of one outbound file layout.
 *
 * Versioned by the date it takes effect rather than replaced in place, because a bank or SARS asked
 * to re-accept a file for an old period wants it in the format that was current when the period was.
 * Overwriting last year's layout to add this year's field quietly makes every reprint wrong.
 */
class FileSpecification extends Model
{
    use BelongsToCompany;

    protected $table = 'file_specifications';

    public const FORMAT_FIXED = 'fixed';
    public const FORMAT_DELIMITED = 'delimited';

    public const HEADER = 'header';
    public const DETAIL = 'detail';
    public const TRAILER = 'trailer';

    protected $guarded = ['id'];

    protected $casts = [
        'version'        => 'integer',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'pad_to_length'  => 'boolean',
        'is_active'      => 'boolean',
    ];

    /**
     * A null company_id is a layout shipped with the product, shared by every tenant.
     *
     * Paired with the null allowance on the `tenant_isolation` policy in the migration. Both are
     * required: the scope decides what Eloquent asks for and the policy decides what Postgres
     * returns, and a layout visible to neither is a layout that installs cleanly and shows nothing.
     */
    public static function sharesGlobalRecords(): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        static::creating(fn (self $spec) => $spec->uuid ??= (string) Str::uuid());
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FileSpecificationField::class, 'file_specification_id')
            ->orderBy('record_type')
            ->orderBy('sequence');
    }

    /**
     * The layout to use for a file covering this date.
     *
     * A tenant's own version wins over the shipped one of the same code, so a client with a bank
     * that wants an extra reference field gets theirs without anybody else's file changing.
     */
    public static function resolve(string $code, Carbon|string|null $asAt = null): ?self
    {
        $date = Carbon::parse($asAt ?? now())->toDateString();

        return static::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            // Own before shipped, then the newest version of whichever won.
            ->orderByRaw('company_id IS NULL')
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->with('fields')
            ->first();
    }

    /** Is this one of the shipped layouts rather than something this tenant authored? */
    public function isShared(): bool
    {
        return $this->company_id === null;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
