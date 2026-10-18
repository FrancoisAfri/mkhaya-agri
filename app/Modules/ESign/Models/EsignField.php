<?php

namespace App\Modules\ESign\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Where somebody signs, initials, dates or types on the page.
 *
 * Coordinates are fractions of the page rather than points, so the same placement survives the
 * document being rendered at another size — an absolute position is right for exactly one
 * rendering, and wrong everywhere else.
 */
class EsignField extends Model
{
    use BelongsToCompany;

    protected $table = 'esign_fields';

    protected $guarded = ['id'];

    protected $casts = [
        'page' => 'integer',
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'is_required' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public const SIGNATURE = 'signature';
    public const INITIALS = 'initials';
    public const DATE = 'date';
    public const TEXT = 'text';

    public const TYPES = [self::SIGNATURE, self::INITIALS, self::DATE, self::TEXT];

    protected static function booted(): void
    {
        static::creating(function (self $field) {
            $field->uuid ??= (string) Str::uuid();

            // Clamped rather than trusted. A field placed off the page by a dragging bug is
            // invisible to whoever has to sign it, and they cannot report what they cannot see.
            foreach (['x', 'y', 'width', 'height'] as $axis) {
                $field->{$axis} = max(0, min(1, (float) $field->{$axis}));
            }
        });
    }

    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class, 'envelope_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(Signer::class, 'signer_id');
    }

    /**
     * Whether this field has been dealt with.
     *
     * A signature field is satisfied by its signer having signed, rather than by anything written
     * here — one person signs once, however many places their mark appears.
     */
    public function isComplete(): bool
    {
        if (in_array($this->type, [self::SIGNATURE, self::INITIALS], true)) {
            return $this->signer?->signed_at !== null;
        }

        return $this->completed_at !== null || filled($this->value);
    }
}
