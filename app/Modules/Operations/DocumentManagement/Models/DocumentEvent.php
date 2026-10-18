<?php

namespace App\Modules\Operations\DocumentManagement\Models;

use App\Modules\Tenancy\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * What was done to a document.
 *
 * Reads are recorded as well as writes, which is unusual and deliberate: "who has opened this?" is
 * the first question asked of a confidential document, and it cannot be answered later if nobody
 * wrote it down at the time.
 */
class DocumentEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'document_events';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    public function document(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Core\User\Models\User::class, 'user_id');
    }

    public function folder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function version(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public const UPLOADED = 'uploaded';
    public const VIEWED = 'viewed';
    public const DOWNLOADED = 'downloaded';
    public const REPLACED = 'replaced';
    public const RENAMED = 'renamed';
    public const UPDATED = 'updated';
    public const MOVED = 'moved';
    public const ARCHIVED = 'archived';
    public const RESTORED = 'restored';
    public const DELETED = 'deleted';
    public const PURGED = 'purged';
    public const SENSITIVITY_CHANGED = 'sensitivity_changed';

    // Expiry and version control. Recorded like any other event, so "when did this lapse" and
    // "who put the old one back" are answered from the same history as everything else.
    public const EXPIRED = 'expired';
    public const EXPIRY_REMINDED = 'expiry_reminded';
    public const VERSION_RESTORED = 'version_restored';

    // Approval. Recorded in the same history as everything else, so "who approved version 2.0 and
    // when" is answered from one place rather than from a workflow table nobody thinks to open.
    public const APPROVAL_SUBMITTED = 'approval_submitted';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const CHANGES_REQUESTED = 'changes_requested';
    public const APPROVAL_DELEGATED = 'approval_delegated';
    public const PUBLISHED = 'published';

    // Generated from a template rather than uploaded. Recorded distinctly, because "where did this
    // contract come from" has a real answer for these and the history should give it.
    public const GENERATED = 'generated';

    // Signing. The envelope keeps its own audit trail; these put the milestones in the document's
    // history too, so one timeline answers what happened to it.
    public const SIGNATURE_REQUESTED = 'signature_requested';
    public const SIGNED = 'signed';

    // Retention and legal holds. Every one of these is a decision somebody made about whether a
    // document continues to exist, which is the part of a document's history most likely to be
    // asked about years later and least likely to be remembered.
    public const HOLD_PLACED = 'hold_placed';
    public const HOLD_RELEASED = 'hold_released';
    public const DISPOSAL_DUE = 'disposal_due';
    public const DISPOSAL_APPROVED = 'disposal_approved';
    public const DISPOSAL_RETAINED = 'disposal_retained';

    // External sharing. A document leaving the organisation is the event most worth being able to
    // point at afterwards, and the only one where the reader is somebody nobody here can name.
    /**
     * Somebody reached a document and was shown the door.
     *
     * Not every policy refusal — only the ones where a person actually arrived at a document and
     * was turned away, which is the signal worth having. Logging every denied check would fill the
     * trail with a browser's own prefetches and teach nobody anything.
     */
    public const ACCESS_REFUSED = 'access_refused';

    /*
     * Replaced by a different document, not by a new version of itself. Recorded on both: the old
     * one so its readers can see when it stopped applying, the new one so "what did this replace"
     * has an answer.
     */
    public const SUPERSEDED = 'superseded';
    public const SUPERSEDES = 'supersedes';

    // Filed in answer to somebody asking for it. Worth distinguishing from an ordinary upload:
    // "we asked for this and they sent it" is a different fact from "somebody filed this".
    public const REQUEST_FULFILLED = 'request_fulfilled';

    /*
     * The conversation about a document. Recorded on the timeline as well as shown in the panel:
     * "when was this queried" is asked about the document, not about the comment thread.
     */
    public const COMMENTED = 'commented';
    public const COMMENT_RESOLVED = 'comment_resolved';

    /*
     * Checking out. A lock is a claim rather than a permission, and the claim is worth recording:
     * "why could I not edit this on Tuesday" has an answer, and so does "who took it off me".
     */
    public const CHECKED_OUT = 'checked_out';
    public const CHECKED_IN = 'checked_in';
    public const LOCK_BROKEN = 'lock_broken';

    /*
     * Copies. Marking something read-only and lifting the marking are both worth being able to
     * point at: "why could nobody download this in March" is a question with an answer.
     */
    public const DOWNLOAD_RESTRICTED = 'download_restricted';
    public const DOWNLOAD_ALLOWED = 'download_allowed';
    public const DOWNLOAD_REQUESTED = 'download_requested';

    public const SHARED = 'shared';
    public const SHARE_ACCESSED = 'share_accessed';
    public const SHARE_REVOKED = 'share_revoked';
    public const SHARED_INTERNALLY = 'shared_internally';
    public const SHARE_INTERNAL_REVOKED = 'share_internal_revoked';

    /*
     * Folders. Kept in the same table as document events because "what happened here" is one
     * question, and the permission change is the reason this exists: granting yourself access to
     * Employee Files and then reading it is two acts, and only the second was ever recorded.
     */
    public const FOLDER_CREATED = 'folder_created';
    public const FOLDER_RENAMED = 'folder_renamed';
    public const FOLDER_MOVED = 'folder_moved';
    public const FOLDER_COPIED = 'folder_copied';
    public const FOLDER_ARCHIVED = 'folder_archived';
    public const FOLDER_RESTORED = 'folder_restored';
    public const FOLDER_DELETED = 'folder_deleted';
    public const FOLDER_PERMISSIONS_CHANGED = 'folder_permissions_changed';
}
