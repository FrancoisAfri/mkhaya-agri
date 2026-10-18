<?php

namespace App\Modules\Finance\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Modules\Finance\Accounting\Traits\BelongsToEntity;
use App\Modules\Tenancy\Traits\BelongsToCompany;

/**
 * A customer and/or supplier. `kind` = customer | supplier | both.
 */
class FinParty extends Model
{
    use BelongsToCompany;
    use BelongsToEntity;

    protected $table = 'fin_parties';

    protected $fillable = [
        'entity_id', 'uuid', 'company_id', 'kind', 'category', 'price_list_id', 'source', 'crm_account_id', 'supplier_id', 'code',
        // Identity that works with or without Accounting; see PartyNumberService.
        'customer_number', 'debtor_code_issued_at', 'created_by_module', 'name', 'contact_name',
        'email', 'website', 'phone', 'fax', 'address', 'city', 'country', 'postal_address', 'vat_number',
        'registration_number', 'vendor_number', 'bank_name', 'bank_account', 'credit_terms_days', 'credit_limit',
        'sales_rep', 'default_discount_percent',
        'on_hold', 'is_active', 'tax_exempt', 'notes', 'language', 'do_not_contact',
    ];

    protected $casts = [
        'credit_limit'   => 'decimal:2',
        'default_discount_percent' => 'decimal:2',
        'on_hold'        => 'boolean',
        'is_active'      => 'boolean',
        'tax_exempt'     => 'boolean',
        'do_not_contact' => 'boolean',
    ];

    public const KINDS = ['customer', 'supplier', 'both'];

    protected static function booted(): void
    {
        static::creating(function (FinParty $p) {
            if (empty($p->uuid)) {
                $p->uuid = (string) Str::uuid();
            }
            // Auto-generate a party code when none is given, so quick "add customer/supplier"
            // from a dropdown never blocks on a code. Sequential per kind, tenant-scoped by RLS.
            if (empty($p->code)) {
                $prefix = $p->kind === 'supplier' ? 'SUP' : 'CUST';
                $n = static::query()->where('kind', $p->kind)->count() + 1;
                do {
                    $code = $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                    $n++;
                } while (static::query()->where('code', $code)->exists());
                $p->code = $code;
            }
        });
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(FinInvoice::class, 'party_id');
    }

    /** True when this record is owned by another module (CRM / Supplier Mgmt) and read-only here. */
    public function isSynced(): bool
    {
        return $this->source !== 'manual';
    }

    public function isCustomer(): bool
    {
        return in_array($this->kind, ['customer', 'both'], true);
    }

    public function isSupplier(): bool
    {
        return in_array($this->kind, ['supplier', 'both'], true);
    }

    public function scopeCustomers(Builder $q): Builder
    {
        return $q->whereIn('kind', ['customer', 'both']);
    }

    public function scopeSuppliers(Builder $q): Builder
    {
        return $q->whereIn('kind', ['supplier', 'both']);
    }
}
