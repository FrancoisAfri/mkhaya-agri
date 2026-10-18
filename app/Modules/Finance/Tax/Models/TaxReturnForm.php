<?php

namespace App\Modules\Finance\Tax\Models;

use Illuminate\Database\Eloquent\Model;

/** A return form and its field mapping, versioned (global; not tenant data). */
class TaxReturnForm extends Model
{
    protected $table = 'tax_return_forms';

    protected $guarded = ['id'];

    protected $casts = ['fields' => 'array', 'evidence' => 'array', 'effective_from' => 'date', 'effective_to' => 'date'];

    /** The version of a form in force on a date. */
    public static function forDate(string $code, \DateTimeInterface|string $date): ?self
    {
        $day = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return static::where('code', $code)->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->orderByDesc('version')->first();
    }
}
