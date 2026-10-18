<?php

namespace App\Modules\ESign\Models;

use App\Modules\ESign\Enums\DocumentType;
use App\Modules\ESign\Enums\EnvelopeStatus;
use App\Modules\ESign\Enums\SignerStatus;
use Database\Factories\ESign\EnvelopeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Envelope extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'esign_envelopes';

    protected $fillable = [
        'title',
        'document_type',
        'message',
        'expires_at',
        'related_type',
        'related_id',
        'created_by',
        // NOT: status, document_path, signed_document_path, document_hash,
        //      sent_at, completed_at, voided_at, voided_reason, voided_by — lifecycle only
    ];

    protected $casts = [
        'status'        => EnvelopeStatus::class,
        'document_type' => DocumentType::class,
        'expires_at'    => 'datetime',
        'sent_at'       => 'datetime',
        'completed_at'  => 'datetime',
        'voided_at'     => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Envelope $envelope) {
            $envelope->uuid   = (string) Str::uuid();
            $envelope->status = EnvelopeStatus::Draft->value;
        });
    }

    protected static function newFactory(): Factory
    {
        return EnvelopeFactory::new();
    }

    public function signers()
    {
        return $this->hasMany(Signer::class)->orderBy('signing_order');
    }

    public function auditEntries()
    {
        return $this->hasMany(AuditEntry::class)->orderBy('created_at');
    }

    public function createdBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'created_by');
    }

    public function voidedBy()
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'voided_by');
    }

    public function isCompleted(): bool
    {
        return $this->signers()
            ->where('status', '!=', SignerStatus::Signed->value)
            ->doesntExist();
    }

    public function nextPendingSigner(): ?Signer
    {
        return $this->signers()
            ->whereNotIn('status', [SignerStatus::Signed->value, SignerStatus::Declined->value])
            ->orderBy('signing_order')
            ->first();
    }

    /**
     * Where each person signs on the page.
     *
     * Ordered by page then down the page, which is the order somebody filling them in encounters
     * them — a signing view that jumped around the document would be a signing view people abandon.
     */
    public function fields()
    {
        return $this->hasMany(EsignField::class, 'envelope_id')->orderBy('page')->orderBy('y');
    }
}
