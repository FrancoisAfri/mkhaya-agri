<?php

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-off professional or support service with a price. `price_type` describes
 * how the figure reads: free | fixed | from | hourly | daily | monthly | quote.
 * `price` is null for quote-only services. Category ∈ professional | support.
 */
class ServiceOffering extends Model
{
    protected $table = 'service_offerings';

    protected $fillable = ['category', 'name', 'price', 'price_type', 'unit', 'note', 'sort', 'is_active'];

    protected $casts = [
        'price' => 'decimal:2',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Human-readable price label, e.g. "From R2 500", "R950/hour", "FREE", "Quote". */
    public function label(): string
    {
        $money = fn ($n) => 'R'.number_format((float) $n, 0, '.', ' ');

        return match ($this->price_type) {
            'free' => 'FREE',
            'quote' => 'Quote',
            'from' => 'From '.$money($this->price).($this->unit ? '/'.$this->unit : ''),
            'hourly' => $money($this->price).'/hour',
            'daily' => $money($this->price).'/day',
            'monthly' => $money($this->price).'/month',
            default => $money($this->price),
        };
    }
}
