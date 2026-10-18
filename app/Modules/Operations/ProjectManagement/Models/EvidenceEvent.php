<?php

namespace App\Modules\Operations\ProjectManagement\Models;

use App\Modules\Operations\ProjectManagement\Concerns\HasPublicUuid;
use App\Modules\Operations\ProjectManagement\Support\Evidence\EvidenceTaxonomy;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One immutable observation about the world (PM-ADR-002).
 *
 * Deliberately unlike every other model here:
 *  - no SoftDeletes — evidence is never deleted, only superseded;
 *  - no update path — the database trigger rejects it, and this model refuses before the query is
 *    even built, so the failure is a clear LogicException at the call site rather than an SQLSTATE
 *    from three layers down;
 *  - no LogsActivity — the ledger *is* the audit record, and mirroring it into activity_log would
 *    double every write on the highest-volume table in the module.
 *
 * Writes go through EvidenceLedger::append(), which owns hash-chaining. Constructing and saving
 * this model directly bypasses the chain and is rejected.
 */
class EvidenceEvent extends Model
{
    use BelongsToCompany, HasPublicUuid;

    /** No created_at/updated_at: `occurred_at` is when it happened, `recorded_at` when we heard. */
    public $timestamps = false;

    protected $table = 'pm_evidence_events';

    protected $fillable = [
        'uuid', 'company_id', 'project_id', 'subject_type', 'subject_id', 'subject_uuid',
        'event_type', 'source_module', 'source_ref', 'occurred_at', 'occurred_tz',
        'actor_type', 'actor_id', 'quantity', 'unit', 'amount', 'currency', 'confidence',
        'geo_lat', 'geo_lng', 'payload', 'attachments', 'idempotency_key',
        'hash', 'prev_hash', 'hash_version',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
        'payload'     => 'array',
        'attachments' => 'array',
        'quantity'    => 'decimal:4',
        'amount'      => 'decimal:2',
        'confidence'  => 'decimal:2',
        'geo_lat'     => 'decimal:6',
        'geo_lng'     => 'decimal:6',
    ];

    /**
     * The canonical vocabulary lives in EvidenceTaxonomy — one list, so the picker, the ingestion
     * validator and derivation cannot describe different systems.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return EvidenceTaxonomy::types();
    }

    /**
     * What an event can be *about*.
     *
     * This list has to grow with the module, and silently does not: an unknown subject fails
     * validation, the event is dead-lettered, and the caller gets a null back that almost nobody
     * checks. The visible symptom is a timeline with a hole in it — a document revised, a risk
     * raised, an announcement sent, none of which ever appear — while every screen reports success.
     *
     * So: adding an emitter with a new subject means adding it here in the same change.
     */
    public const SUBJECT_TYPES = [
        'task', 'wbs_node', 'project', 'resource', 'supplier',
        'document', 'time_entry', 'purchase', 'announcement',
        // The registers (§11–§13).
        'risk', 'issue', 'change_request',
    ];

    public const ACTOR_TYPES = ['user', 'system', 'integration', 'device'];

    // ── Immutability guards ──────────────────────────────────────────────────

    /**
     * The database trigger is the real enforcement (it survives raw SQL and future code). These
     * guards exist so a mistake surfaces as a readable exception at the call site.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException(
                'Evidence is append-only: correct it with EvidenceLedger::supersede(), never an update.'
            );
        });

        static::deleting(function () {
            throw new LogicException(
                'Evidence is append-only: it cannot be deleted. Archive by detaching a partition.'
            );
        });
    }

    // ── Relations & scopes ───────────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** Only events that still count — a superseded event is history, not input to derivation. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('superseded_by');
    }

    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public function isSuperseded(): bool
    {
        return $this->superseded_by !== null;
    }

    /**
     * The event as the ledger hashed it — cast values, not raw database strings, so a stored row
     * and a freshly-built one canonicalise identically. Used by EvidenceLedger::verifyChain().
     *
     * @return array<string,mixed>
     */
    public function attributesToHash(): array
    {
        return [
            'uuid'          => $this->uuid,
            'company_id'    => $this->company_id,
            'project_id'    => $this->project_id,
            'subject_type'  => $this->subject_type,
            'subject_id'    => $this->subject_id,
            'subject_uuid'  => $this->subject_uuid,
            'event_type'    => $this->event_type,
            'source_module' => $this->source_module,
            'source_ref'    => $this->source_ref,
            'occurred_at'   => $this->occurred_at,
            'occurred_tz'   => $this->occurred_tz,
            'actor_type'    => $this->actor_type,
            'actor_id'      => $this->actor_id,
            'quantity'      => $this->quantity,
            'unit'          => $this->unit,
            'amount'        => $this->amount,
            'currency'      => $this->currency,
            'confidence'    => $this->confidence,
            'geo_lat'       => $this->geo_lat,
            'geo_lng'       => $this->geo_lng,
            'payload'       => $this->payload,
            'attachments'   => $this->attachments,
        ];
    }
}
