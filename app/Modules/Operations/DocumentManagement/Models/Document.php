<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Operations\DocumentManagement\Support\DocumentRelations;
use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A document, which outlives any single file it has held.
 *
 * Its identity, description, sensitivity and place in the tree stay put while the bytes underneath
 * it are replaced. That is what makes "update this document" different from "upload another one".
 */
class Document extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $table = 'documents';

    protected $guarded = ['id'];

    protected $casts = [
        'tags' => 'array',
        'document_date' => 'date',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'is_confidential' => 'boolean',
        'download_disabled' => 'boolean',
        'custom_fields' => 'array',
        'superseded_at' => 'datetime',
        'locked_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'is_legally_sensitive' => 'boolean',
        'archived_at' => 'datetime',
        'published_at' => 'datetime',
        'version_count' => 'integer',
        'disposal_due_at' => 'datetime',
        'disposal_reviewed_at' => 'datetime',
        'auto_archived_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /**
     * Replaced by a different document, not by a new version of itself.
     *
     * Next year's policy is not version 2 of this year's: both exist, both are referred to, and
     * people hold links to the old one. It stays readable and says plainly that it is not the one
     * in force, which is the thing archiving cannot do — archiving hides it.
     */
    public const STATUS_SUPERSEDED = 'superseded';

    /*
     * How sensitive a document is, as a level rather than a flag.
     *
     * `is_confidential` answers yes or no, and organisations need the middle — internal documents
     * that are not secret but are not for everyone either. The booleans stay and are kept in step
     * below, because the access rules already read them and rewriting those at the same time as
     * adding a field is how both end up broken.
     */
    public const CONFIDENTIALITY_PUBLIC = 'public';
    public const CONFIDENTIALITY_INTERNAL = 'internal';
    public const CONFIDENTIALITY_CONFIDENTIAL = 'confidential';
    public const CONFIDENTIALITY_RESTRICTED = 'restricted';

    /*
     * Where a document is in its review, kept apart from `status`.
     *
     * `status` answers whether it is active or archived, which stays true throughout an approval
     * and after it. Conflating the two would mean a rejected document could not also be archived,
     * and archiving would silently lose whether it had ever been approved.
     */
    public const APPROVAL_DRAFT = 'draft';
    public const APPROVAL_SUBMITTED = 'submitted';
    public const APPROVAL_UNDER_REVIEW = 'under_review';
    public const APPROVAL_CHANGES_REQUESTED = 'changes_requested';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';
    public const APPROVAL_PUBLISHED = 'published';

    public const APPROVAL_STATES = [
        self::APPROVAL_DRAFT,
        self::APPROVAL_SUBMITTED,
        self::APPROVAL_UNDER_REVIEW,
        self::APPROVAL_CHANGES_REQUESTED,
        self::APPROVAL_APPROVED,
        self::APPROVAL_REJECTED,
        self::APPROVAL_PUBLISHED,
    ];

    public const CONFIDENTIALITY_LEVELS = [
        self::CONFIDENTIALITY_PUBLIC,
        self::CONFIDENTIALITY_INTERNAL,
        self::CONFIDENTIALITY_CONFIDENTIAL,
        self::CONFIDENTIALITY_RESTRICTED,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $document) {
            $document->uuid ??= (string) Str::uuid();
            $document->document_number ??= self::nextDocumentNumber();
        });

        /*
         * The level and the two flags describe the same thing, so they are reconciled on every
         * save. Letting them drift would mean a document marked restricted on screen that the
         * access rules — which read the flags — treat as ordinary.
         *
         * The level leads when it is the field that changed, and follows the flags otherwise, so
         * code written before this column existed keeps working unchanged.
         */
        static::saving(function (self $document) {
            // Never null in memory. The columns are NOT NULL with a false default, so a document
            // read straight back after being created would otherwise disagree with the same
            // document loaded from the database a moment later.
            $document->is_confidential = (bool) $document->is_confidential;
            $document->is_legally_sensitive = (bool) $document->is_legally_sensitive;

            // `filled` and not merely dirty: an upload that leaves the level unset sends null,
            // which is "no opinion", not "public". Treating that as the leading value would undo
            // an is_confidential flag set alongside it in the same save.
            if ($document->isDirty('confidentiality') && filled($document->confidentiality)) {
                $document->is_legally_sensitive = $document->confidentiality === self::CONFIDENTIALITY_RESTRICTED;
                $document->is_confidential = in_array($document->confidentiality, [
                    self::CONFIDENTIALITY_CONFIDENTIAL,
                    self::CONFIDENTIALITY_RESTRICTED,
                ], true);

                return;
            }

            if ($document->isDirty(['is_confidential', 'is_legally_sensitive']) || blank($document->confidentiality)) {
                $document->confidentiality = match (true) {
                    (bool) $document->is_legally_sensitive => self::CONFIDENTIALITY_RESTRICTED,
                    (bool) $document->is_confidential => self::CONFIDENTIALITY_CONFIDENTIAL,
                    default => $document->confidentiality ?: self::CONFIDENTIALITY_INTERNAL,
                };
            }
        });
    }

    /**
     * The next reference for this company.
     *
     * Generated only when nobody supplies one — an organisation with its own numbering keeps it.
     * Derived from the highest existing number rather than a count, so deleting a document does not
     * hand its number to the next one.
     */
    public static function nextDocumentNumber(): string
    {
        $year = now()->format('Y');
        $prefix = 'DOC-' . $year . '-';

        $last = static::withTrashed()
            ->where('document_number', 'like', $prefix . '%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $sequence = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }


    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class, 'document_id')->latest('created_at');
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /** Confidential in its own right, or by inheritance from the folder holding it. */
    public function isConfidential(): bool
    {
        return $this->is_confidential || (bool) $this->folder?->isConfidential();
    }

    /**
     * Whether this may be destroyed at all.
     *
     * A document under legal hold survives a routine deletion regardless of who is asking. That is
     * the entire purpose of the flag — a permission check alone would let an administrator delete
     * the one thing the hold exists to preserve.
     */
    public function isDestructible(): bool
    {
        return ! $this->isUnderLegalHold();
    }

    /**
     * Whether anything is currently requiring this document to be preserved.
     *
     * Reads the flag rather than counting hold rows, because this is asked on every delete, every
     * purge and every pass of the retention sweep, and a query per document turns a sweep into a
     * scan. LegalHoldService owns the flag and updates it whenever a hold is placed or lifted; it
     * is a cache of the holds, not a second opinion about them.
     */
    public function isUnderLegalHold(): bool
    {
        return (bool) $this->is_legally_sensitive;
    }

    /**
     * What the current version says.
     *
     * Through current_version_id rather than latest(): "the text of this document" means the text
     * of the file people are looking at, and an older version's words would answer for a file that
     * has been replaced.
     */
    public function currentExtraction()
    {
        return $this->hasOne(DocumentExtraction::class, 'version_id', 'current_version_id');
    }

    /** Every hold ever placed on this document, live and lifted. */
    public function legalHolds(): HasMany
    {
        return $this->hasMany(DocumentLegalHold::class, 'document_id')->orderByDesc('placed_at');
    }

    /**
     * Narrows a listing to what this user is allowed to know exists.
     *
     * The policy decides whether someone may open a document. That is not the same question as
     * whether they may see it in a list, and answering only the first leaves the second wide open:
     * a title like "Disciplinary hearing — J. Dlamini" discloses the thing the confidentiality flag
     * was set to protect, and the search box matches on description and notes, so a listing is also
     * an oracle for their contents.
     *
     * Kept as a scope so it composes with whatever filters a screen applies, and so counts and
     * exports go through the same rule as the page. It mirrors DocumentPolicy::maySeeSensitive —
     * the two are tested against each other, because the day they disagree is the day a document
     * appears in a list nobody can open, or worse, the other way round.
     */
    public function scopeVisibleTo(Builder $query, $user): Builder
    {
        // No company, so nothing to scope against — the same reason the policy answers them first.
        if ($user === null || $user->is_platform_admin || $user->is_master_admin) {
            return $query;
        }

        /*
         * Legal sensitivity is checked first and on its own, because it is the one restriction an
         * internal share does not lift. A document being preserved for a matter is not made
         * readable to somebody uncleared by sharing it with them, and a share is not the mechanism
         * by which that clearance should be handed out.
         */
        if (! $user->can('document.legal_hold')) {
            $query->where(fn ($q) => $q->where('is_legally_sensitive', false)->orWhereNull('is_legally_sensitive'));
        }

        /*
         * Documents shared with this person by name, by role, by department or company-wide.
         *
         * A subquery rather than a list of ids: a document shared company-wide in an installation
         * with fifty thousand documents would otherwise load fifty thousand integers and send them
         * back as an IN clause.
         */
        $granted = app(\App\Modules\Operations\DocumentManagement\Services\InternalShareService::class)
            ->grantedDocumentIds($user);

        /*
         * Folders the person has no access to, by department or by an explicit grant. Resolved once
         * into a list of ids rather than asked per row — the alternative is a permission lookup for
         * every document in a listing, and the answer is the same for all of them.
         */
        $closedFolders = app(\App\Modules\Operations\DocumentManagement\Services\FolderAccess::class)
            ->closedFolderIds($user);

        $restrictConfidential = ! $user->can('document.confidential.manage');

        /*
         * Somebody nothing restricts sees everything, and the grants are not consulted at all.
         *
         * This early return is load-bearing. Without it the wrapper below builds a floor group with
         * no conditions in it, which the query builder renders as nothing — and the OR that was
         * meant to widen the floor becomes the whole condition, so a person cleared for every
         * document in the company sees only the handful explicitly shared with them. It read as an
         * empty repository rather than as a broken rule, which is why the tests did not catch it:
         * every user in them had something restricting their view.
         */
        if ($closedFolders === [] && ! $restrictConfidential) {
            return $query;
        }

        /*
         * Everything below is the floor, and a grant raises it for one document.
         *
         * The folder and confidentiality rules are wrapped together so an explicit share reaches
         * past both at once — which is the whole point of internal sharing: handing one employee
         * file to a manager should not require giving them the Employee Files folder, nor making
         * them somebody who can read every confidential document in the company.
         */
        $query->where(function ($outer) use ($user, $granted, $closedFolders, $restrictConfidential) {
            $outer->where(function ($floor) use ($user, $closedFolders, $restrictConfidential) {
                if ($closedFolders !== []) {
                    $floor->where(fn ($q) => $q->whereNull('folder_id')->orWhereNotIn('folder_id', $closedFolders));
                }

                if ($restrictConfidential) {
                    $confidentialFolders = DocumentFolder::confidentialIds();

                    $floor->where(function ($q) use ($user, $confidentialFolders) {
                        $q->where(function ($clean) use ($confidentialFolders) {
                            $clean->where(fn ($c) => $c->where('is_confidential', false)->orWhereNull('is_confidential'));

                            // Filing something in a confidential folder has to be enough on its
                            // own, or the protection depends on the uploader also remembering to
                            // tick the box.
                            if ($confidentialFolders !== []) {
                                // NOT IN is never true for a NULL folder_id, so an unfiled document
                                // would fall out of every listing for everyone except whoever
                                // uploaded it. A document in no folder is in no confidential folder.
                                $clean->where(fn ($f) => $f->whereNull('folder_id')->orWhereNotIn('folder_id', $confidentialFolders));
                            }
                        })
                        // Their own upload stays theirs, so nobody loses a file by filing it
                        // correctly.
                        ->orWhere('created_by', $user->id);
                    });
                }
            });

            if ($granted) {
                $outer->orWhereIn('id', $granted);
            }
        });

        return $query;
    }

    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class, 'document_id');
    }

    /** The documents this one is about, grouped the way a detail screen shows them. */
    public function linksByRelation(): array
    {
        return $this->links->groupBy('relation')
            ->map(fn ($group) => $group->map(fn (DocumentLink $l) => $l->toPayload())->values()->all())
            ->all();
    }

    /**
     * Files this document against a record in another module.
     *
     * The mirror of HasDocuments::attachDocument, for the times the document is the thing in hand.
     */
    public function linkTo(Model $record, ?string $relation = null): DocumentLink
    {
        $relation ??= DocumentRelations::forModel($record) ?? 'related';

        return DocumentLink::firstOrCreate(
            [
                'document_id' => $this->id,
                'relation' => $relation,
                'linkable_type' => $record::class,
                'linkable_id' => $record->getKey(),
            ],
            [
                'label' => DocumentRelations::labelFor($relation, $record),
                'created_by' => Auth::id(),
            ],
        );
    }

    /** The document that replaced this one, where one did. */
    public function supersededBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /**
     * Whether this is in force today.
     *
     * A document can exist, be current, and not yet apply — a policy approved in March and
     * effective from April is all three at once, and answering "is this the one in force" with its
     * status alone gets that wrong.
     */
    public function isInForce(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $today = now()->startOfDay();

        return ($this->effective_date === null || $this->effective_date->lte($today))
            && ($this->expiry_date === null || $this->expiry_date->gte($today));
    }

    /** Documents past their expiry date — for the screen that chases renewals. */
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')->whereDate('expiry_date', '<', now());
    }

    /**
     * Documents expiring within the given number of days.
     *
     * Excludes the already-expired: those are a different list with a different urgency, and
     * mixing them means the ones still worth renewing get lost among the ones that are not.
     */
    public function scopeExpiringWithin($query, int $days)
    {
        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays($days));
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentApprovalRequest::class, 'approval_request_id');
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(DocumentApprovalRequest::class, 'document_id')->latest('id');
    }

    /** In force: approved and published, as opposed to merely uploaded. */
    public function isPublished(): bool
    {
        return $this->approval_status === self::APPROVAL_PUBLISHED;
    }

    public function isAwaitingApproval(): bool
    {
        return in_array($this->approval_status, [
            self::APPROVAL_SUBMITTED,
            self::APPROVAL_UNDER_REVIEW,
        ], true);
    }
}
