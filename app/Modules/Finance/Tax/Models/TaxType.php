<?php

namespace App\Modules\Finance\Tax\Models;

use Illuminate\Database\Eloquent\Model;

/** A kind of tax and how it behaves (global catalogue; not tenant data). */
class TaxType extends Model
{
    protected $table = 'tax_types';

    protected $guarded = ['id'];

    protected $casts = [
        'frequencies' => 'array', 'filing_due_rule' => 'array', 'payment_due_rule' => 'array',
        'has_return' => 'boolean', 'has_payment' => 'boolean', 'is_active' => 'boolean',
    ];

    public static function byCode(string $code): self
    {
        return static::where('code', strtoupper($code))->firstOrFail();
    }
}
