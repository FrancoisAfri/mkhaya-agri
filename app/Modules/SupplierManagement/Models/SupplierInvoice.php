<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;

use App\Core\Storage\Concerns\StoresFileBytes;
use App\Core\User\Trait\FileStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SupplierInvoice extends Model
{
    use BelongsToCompany, SoftDeletes, LogsActivity, FileStorage;
    use StoresFileBytes;

    protected $table = 'supplier_invoices';

    protected $fillable = [
        'uuid',
        'invoice_number',
        'supplier_id',
        'contract_id',
        'purchase_order_id',
        'budget_id',
        'goods_received_note_id',
        'amount',
        'subtotal',
        'tax_amount',
        'currency',
        'status',
        'source',
        'match_status',
        'matched_at',
        'payment_status',
        'variance_amount',
        'reconciled_at',
        'reconciled_by',
        'budget_consumption_id',
        'ai_status',
        'ai_provider',
        'ai_model',
        'ai_extracted',
        'notes',
        'file_data', 'storage_path',
        'file_name',
        'file_mime',
        'file_size',
        'invoice_date',
        'due_date',
        'created_by',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'variance_amount' => 'decimal:2',

        'matched_at'      => 'datetime',
        'reconciled_at'   => 'datetime',
        'invoice_date' => 'date:Y-m-d',
        'due_date'     => 'date:Y-m-d'
    ];
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    protected $hidden = ['file_data'];

    protected static function booted(): void
    {
        static::creating(function (SupplierInvoice $invoice) {
            $invoice->uuid ??= (string) Str::uuid();
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\SupplierInvoiceItem::class, 'supplier_invoice_id');
    }

    public function matchResults(): HasMany
    {
        return $this->hasMany(\App\Modules\Procurement\Models\InvoiceMatchResult::class, 'supplier_invoice_id')->latest();
    }

    public function hasDocument(): bool
    {
        // hasBytes() covers the path as well as the column. Checking only file_data would report
        // "no document" for every file that has been moved to the disk, silently switching off
        // every feature gated on this — AI extraction, expiry detection, the download button.
        return $this->hasBytes();
    }

    public function documentResponse(): \Illuminate\Http\Response
    {
        // Headers, filename sanitising and the missing-file case all live in the trait, so every
        // module that adopts it gets them without each author remembering.
        return $this->fileResponse(
            $this->file_name ?: $this->legacyEnvelopeValue('original_name') ?: 'invoice',
            $this->file_mime ?: $this->legacyEnvelopeValue('mime'),
            inline: true,
        );
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn($q, $s) => $q->where('status', $s));
    }

    public function scopeOffContract(Builder $query): Builder
    {
        return $query->whereNull('contract_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('Supplier Management')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /*
     * Bytes live on the shared `documents` disk, where encryption at rest and the tenant storage
     * quota apply. `file_data` is the original column, still read until these rows are pruned.
     */

    protected function fileBlobColumn(): ?string
    {
        return 'file_data';
    }

    protected function fileFolder(): string
    {
        return 'supplier-invoices';
    }
}
