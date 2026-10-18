<?php

namespace App\Modules\HR\Payroll\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One field of one line of a file layout.
 *
 * `position` is 1-based because that is how every vendor document numbers it, and a person
 * transcribing a spec at eleven at night should not have to subtract one on every line. The
 * renderer does the subtraction once.
 */
class FileSpecificationField extends Model
{
    use BelongsToCompany;

    protected $table = 'file_specification_fields';

    public const TEXT = 'text';
    public const NUMBER = 'number';
    /** An amount written in whole cents with no separator, which is what most bank files want. */
    public const CENTS = 'cents';
    public const DATE = 'date';

    protected $guarded = ['id'];

    protected $casts = [
        'sequence'  => 'integer',
        'position'  => 'integer',
        'length'    => 'integer',
        'decimals'  => 'integer',
        'uppercase' => 'boolean',
        'required'  => 'boolean',
    ];

    public static function sharesGlobalRecords(): bool
    {
        return true;
    }

    protected static function booted(): void
    {
        static::creating(fn (self $field) => $field->uuid ??= (string) Str::uuid());
    }

    public function specification(): BelongsTo
    {
        return $this->belongsTo(FileSpecification::class, 'file_specification_id');
    }

    /**
     * What to pad with, if nobody said.
     *
     * Numbers pad with zeros and text with spaces, which is the convention every fixed-width banking
     * format follows. Getting it the other way round produces a file that looks right and is
     * rejected — " 1234" is not the amount 001234 to a parser reading columns.
     */
    public function padCharacter(): string
    {
        if (filled($this->pad_char)) {
            return $this->pad_char;
        }

        return in_array($this->type, [self::NUMBER, self::CENTS], true) ? '0' : ' ';
    }

    /** Numbers are right-aligned, text left-aligned — again, the banking convention. */
    public function padSide(): string
    {
        if (filled($this->pad_side)) {
            return $this->pad_side;
        }

        return in_array($this->type, [self::NUMBER, self::CENTS], true) ? 'left' : 'right';
    }
}
