<?php

namespace App\Modules\SupplierManagement\Models;

use App\Modules\SupplierManagement\DueDiligence\Services\FieldComparator;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A person attached to a supplier: director, member, shareholder, ultimate beneficial owner, trustee
 * or other key person — and, for the ones that matter, a screening subject.
 *
 * The identifiers are the reason this model exists. Screening a bare name produces either no hits or
 * every hit, so an ID number, nationality and date of birth are what make a sanctions or PEP result
 * mean anything. They are also personal information about a real person, so they are encrypted at
 * rest, masked when shown, and — a rule the AI-facing code has to honour rather than this model —
 * never placed in a prompt.
 */
class SupplierPerson extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'supplier_persons';

    protected $fillable = [
        'uuid', 'supplier_id', 'first_name', 'surname', 'full_name', 'normalised_name',
        'previous_names', 'id_type', 'id_number', 'nationality', 'date_of_birth',
        'role', 'position', 'appointed_at', 'resigned_at', 'ownership_percentage',
        'tel', 'email', 'source', 'is_screening_subject', 'notes', 'created_by',
        // POPIA: why this person is held, for what, and until when.
        'lawful_basis', 'collection_purpose', 'identifiers_captured_at', 'purge_after', 'identifiers_purged_at',
    ];

    protected $casts = [
        'previous_names' => 'array',
        // Encrypted rather than hashed: a screening provider needs the value, so it has to be
        // recoverable — but a database dump must not hand over a roster of ID numbers.
        'id_number' => 'encrypted',
        'date_of_birth' => 'date',
        'appointed_at' => 'date',
        'resigned_at' => 'date',
        'ownership_percentage' => 'decimal:3',
        'is_screening_subject' => 'boolean',
        'identifiers_captured_at' => 'datetime',
        'purge_after' => 'date',
        'identifiers_purged_at' => 'datetime',
    ];

    protected $hidden = ['id_number'];

    public const ROLES = ['director', 'member', 'shareholder', 'ubo', 'key_person', 'trustee'];

    protected static function booted(): void
    {
        static::creating(function (self $person) {
            $person->uuid ??= (string) Str::uuid();
        });

        // Name fields are kept in agreement on every write, so nothing downstream has to guess which
        // of the three is authoritative.
        static::saving(function (self $person) {
            // POPIA s13: the purpose is recorded when the information is collected, not reconstructed
            // afterwards. Defaulted rather than demanded, so no caller can create a person with no
            // basis on record at all.
            $person->lawful_basis ??= 'legitimate_interest';
            $person->collection_purpose ??= 'Supplier verification and screening';

            if ($person->isDirty('id_number') && filled($person->id_number)) {
                $person->identifiers_captured_at ??= now();
            }

            $person->full_name = $person->full_name
                ?: trim(implode(' ', array_filter([$person->first_name, $person->surname])));

            $person->normalised_name = $person->full_name
                ? app(FieldComparator::class)->normalisePersonName($person->full_name)
                : null;
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function verifications()
    {
        return $this->hasMany(SupplierVerification::class, 'subject_id')
            ->where('subject_type', 'person');
    }

    public function scopeScreenable(Builder $query): Builder
    {
        // Someone who has resigned is not screened as a current officer — but their history stays,
        // because "who was a director when this contract was awarded" is a question that gets asked.
        return $query->where('is_screening_subject', true)->whereNull('resigned_at');
    }

    public function scopeRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    public function name(): string
    {
        return $this->full_name ?: trim(implode(' ', array_filter([$this->first_name, $this->surname]))) ?: '—';
    }

    /**
     * The identifier as it should appear on a screen: last four characters only.
     *
     * Enough for a person to confirm they are looking at the right record, not enough for a
     * screenshot or an over-the-shoulder glance to be a data breach.
     */
    public function maskedIdNumber(): ?string
    {
        $value = (string) ($this->id_number ?? '');

        if ($value === '') {
            return null;
        }

        return strlen($value) <= 4
            ? str_repeat('•', strlen($value))
            : str_repeat('•', strlen($value) - 4).substr($value, -4);
    }

    /**
     * Destroy the identifiers while keeping the record.
     *
     * POPIA s24 gives a person the right to have information deleted; procurement law requires proof
     * that a supplier was checked. Both are satisfied by erasing the identifying values and keeping
     * the fact that a check happened — the audit trail survives, and the personal information does
     * not. What is destroyed is destroyed: this is deliberately not reversible.
     */
    public function purgeIdentifiers(?string $reason = null): self
    {
        $this->forceFill([
            'id_number' => null,
            'date_of_birth' => null,
            'previous_names' => null,
            'tel' => null,
            'email' => null,
            'identifiers_purged_at' => now(),
            'is_screening_subject' => false,
            'notes' => trim(($this->notes ? $this->notes."\n" : '').'Identifiers erased'.($reason ? ': '.$reason : '.')),
        ])->save();

        return $this;
    }

    /** Records whose retention period has run out. */
    public function scopeDuePurge($query)
    {
        return $query->whereNotNull('purge_after')
            ->whereDate('purge_after', '<=', now()->toDateString())
            ->whereNull('identifiers_purged_at');
    }

    /** Whether this person can be screened usefully, or only by name. */
    public function hasIdentifier(): bool
    {
        return ! empty($this->id_number) || ! empty($this->date_of_birth);
    }
}
