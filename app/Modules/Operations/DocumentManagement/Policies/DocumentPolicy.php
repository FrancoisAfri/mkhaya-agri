<?php

namespace App\Modules\Operations\DocumentManagement\Policies;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Services\FolderAccess;

/**
 * Who may do what to a document.
 *
 * The module's fourteen permissions were seeded but never checked, which meant module entitlement
 * was the only thing between a user and the purge route: anyone who could open the library could
 * permanently destroy anything in it. Entitlement answers "has this company bought the module",
 * which is a billing question, not an access one.
 *
 * Three separate things have to agree before bytes are served, and they are deliberately not
 * collapsed into one check:
 *
 *   tenancy      — row-level security, which happens in the database and never reaches here
 *   permission   — what this role is allowed to do at all
 *   sensitivity  — whether this particular document is one they may see
 *
 * The last is the one a permission list cannot express. `document.view` is a statement about a
 * person's job; a confidential disciplinary record is a statement about a document. Someone can
 * legitimately hold the first and still have no business opening the second.
 */
class DocumentPolicy
{
    /**
     * Platform administrators are answered here, before any per-document rule.
     *
     * They have no company, so RLS does not scope them and the sensitivity checks below would be
     * reasoning about a tenant relationship that does not exist. Returning null for everyone else
     * lets the individual methods decide rather than short-circuiting them.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_platform_admin || $user->is_master_admin) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->can('document.view');
    }

    /**
     * Seeing one document.
     *
     * Confidentiality is checked after the permission, not instead of it, and it is inherited from
     * the folder — filing something in a confidential folder has to be enough, or the protection
     * depends on whoever uploaded it remembering to tick a box.
     */
    public function view(User $user, Document $document): bool
    {
        if (! $user->can('document.view')) {
            return false;
        }

        /*
         * An explicit share opens this one document.
         *
         * It has to, or the scope and the policy disagree: the listing would show a shared document
         * and opening it would be refused, which is the worse of the two ways they can drift apart.
         * Legal sensitivity is still checked inside sharedWith(), because a share does not confer
         * that clearance.
         */
        if ($this->sharedWith($user, $document)) {
            return true;
        }

        return $this->maySeeSensitive($user, $document) && $this->folderAllows($user, $document, 'view');
    }

    public function create(User $user): bool
    {
        return $user->can('document.upload');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('document.update')
            && $this->maySeeSensitive($user, $document)
            && $this->folderAllows($user, $document, 'edit');
    }

    /** Replacing the bytes is an edit to the document, and needs to see it first. */
    public function replace(User $user, Document $document): bool
    {
        return $user->can('document.replace')
            && $this->maySeeSensitive($user, $document)
            && $this->folderAllows($user, $document, 'edit');
    }

    /**
     * Downloading is its own permission.
     *
     * Reading a document on screen and taking a copy away are different acts with different
     * consequences, and plenty of roles should be able to do the first without the second.
     */
    public function download(User $user, Document $document): bool
    {
        if (! $user->can('document.download')) {
            return false;
        }

        /*
         * An explicit grant that says "download" is somebody deciding this person may have a copy
         * of this document, and it is answered first — including over a read-only marking. That is
         * not a hole in the marking, it is how an exception to it is made: approving a download
         * request mints exactly this grant, and if the marking outranked it, approving a request
         * would do nothing.
         *
         * A grant that does not say "download" is not read as a refusal. It only ever adds; a share
         * arriving in somebody's inbox must not be able to take away a copy they could already have
         * made, or adding a person to a share becomes a way of quietly demoting them.
         */
        $grant = $this->grant($user, $document);

        if ($grant && $grant->can_download) {
            return true;
        }

        if (! $this->maySeeSensitive($user, $document)) {
            return false;
        }

        // Marked read-only: readable on screen, and no copy leaves without somebody approving it.
        if ($document->download_disabled) {
            return false;
        }

        return $this->folderAllows($user, $document, 'download');
    }

    /**
     * Taking a document back from the person holding it.
     *
     * Deliberately narrower than editing. A colleague who left the company still holding a document
     * has to be recoverable from, but if anybody who wanted the file could break the lock, the lock
     * would mean nothing — the whole point is that it stops the person who wants to start editing.
     */
    public function breakLock(User $user, Document $document): bool
    {
        return $user->can('document.folder.manage') || (int) $document->owner_id === (int) $user->id;
    }

    /**
     * Marking a document read-only, or lifting the marking.
     *
     * The same people who may change what a document says, because deciding a signed contract may
     * be read but not circulated is a decision about the document, not about any one person.
     */
    public function setDownloadPolicy(User $user, Document $document): bool
    {
        return $this->update($user, $document);
    }

    /**
     * Asking for a copy of something readable but not downloadable.
     *
     * Deliberately only requires being able to read it. Somebody who cannot open a document should
     * be asking for access, not for a copy, and letting them ask for the copy instead would be a
     * way of finding out that a document exists.
     */
    public function requestDownload(User $user, Document $document): bool
    {
        return $this->view($user, $document) && ! $this->download($user, $document);
    }

    public function archive(User $user, Document $document): bool
    {
        return $user->can('document.archive')
            && $this->maySeeSensitive($user, $document)
            && $this->folderAllows($user, $document, 'edit');
    }

    public function restore(User $user, Document $document): bool
    {
        return $user->can('document.restore')
            && $this->maySeeSensitive($user, $document)
            && $this->folderAllows($user, $document, 'edit');
    }

    /**
     * Recoverable deletion.
     *
     * The legal-hold check lives in the service as well, because a policy protects a route and the
     * service is what a job or a command would call. Duplicating it is intentional: the rule has to
     * hold at every entrance, not just the one with a browser in front of it.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->can('document.delete')
            && $document->isDestructible()
            && $this->maySeeSensitive($user, $document)
            && $this->folderAllows($user, $document, 'delete');
    }

    /** Permanent destruction — separate permission, and never available under legal hold. */
    public function purge(User $user, ?Document $document = null): bool
    {
        if (! $user->can('document.purge')) {
            return false;
        }

        /*
         * Asked without a document when the question is "may this person destroy anything at all" —
         * emptying the recycle bin is one decision over many documents, and each of them is checked
         * again by the service as it goes.
         */
        if ($document === null) {
            return true;
        }

        return $document->isDestructible()
            && $this->maySeeSensitive($user, $document)
            && $this->folderAllows($user, $document, 'delete');
    }

    /** Marking something confidential or legally sensitive is itself a privileged act. */
    public function setSensitivity(User $user, Document $document): bool
    {
        if ($document->is_legally_sensitive || $document->isConfidential()) {
            // Only somebody trusted with sensitive documents may change how one is classified —
            // otherwise the flag can simply be turned off by anyone who wants what it protects.
            return $user->can('document.confidential.manage') || $user->can('document.legal_hold');
        }

        return $user->can('document.update');
    }

    /**
     * Sending a document outside the organisation.
     *
     * Its own permission rather than an extension of download, because the two are different acts:
     * downloading gives a copy to somebody the organisation already trusts with an account, and
     * sharing hands one to somebody it does not. A role that may read everything is not
     * automatically a role that may publish it.
     *
     * Confidential and legally sensitive documents are refused here even to a holder of the
     * permission unless they are also cleared for that document — the sensitivity check is not a
     * listing nicety, and this is the one route that puts a file where the policy cannot follow it.
     */
    public function share(User $user, ?Document $document = null): bool
    {
        if (! $user->can('document.share')) {
            return false;
        }

        /*
         * Asked without a document when the question is "may this person share at all" — the access
         * request queue answers requests for several documents at once, and there is no single one
         * to check against.
         */
        if ($document === null) {
            return true;
        }

        return $this->maySeeSensitive($user, $document)
            && $this->folderAllows($user, $document, 'view');
    }

    /**
     * Placing and lifting legal holds.
     *
     * One permission for both directions, because the people trusted to decide a document must be
     * preserved are the people trusted to decide it need not be any more. Splitting them produces
     * holds that can be placed by anybody and lifted by nobody, which is how a hold register fills
     * with matters that closed years ago.
     */
    public function manageLegalHold(User $user, ?Document $document = null): bool
    {
        return $user->can('document.legal_hold')
            && ($document === null || $this->maySeeSensitive($user, $document));
    }

    /**
     * Answering the disposal queue.
     *
     * Separate from purge on purpose: approving a disposal is a decision, carrying it out is an
     * act, and one person holding both means the review step protects nothing.
     */
    public function reviewRetention(User $user): bool
    {
        return $user->can('document.retention.manage');
    }

    public function viewActivity(User $user): bool
    {
        return $user->can('document.activity.view');
    }

    public function manageSettings(User $user): bool
    {
        return $user->can('document.settings.manage');
    }

    public function manageFolders(User $user): bool
    {
        return $user->can('document.folder.manage');
    }

    /**
     * The per-document half of the decision.
     *
     * A confidential document is readable by whoever is trusted with confidential material, and by
     * the person who put it there — that second clause is what stops someone locking themselves out
     * of their own upload the moment they file it in a restricted folder.
     *
     * Legally sensitive is stricter and has no author exemption: a document under hold is often
     * under hold because of the person who created it.
     */
    /**
     * Whether a live internal share covers this document for this user.
     *
     * Legal sensitivity is checked here rather than left to the caller: it is the one restriction a
     * share does not lift, and putting the check anywhere else invites a future caller to forget it.
     */
    private function sharedWith(User $user, Document $document): bool
    {
        if ($document->is_legally_sensitive && ! $user->can('document.legal_hold')) {
            return false;
        }

        return $this->grant($user, $document) !== null;
    }

    private function grant(User $user, Document $document): ?\App\Modules\Operations\DocumentManagement\Models\DocumentGrant
    {
        if ($document->is_legally_sensitive && ! $user->can('document.legal_hold')) {
            return null;
        }

        return app(\App\Modules\Operations\DocumentManagement\Services\InternalShareService::class)
            ->grantFor($user, $document);
    }

    private function maySeeSensitive(User $user, Document $document): bool
    {
        if ($document->is_legally_sensitive && ! $user->can('document.legal_hold')) {
            return false;
        }

        if (! $document->isConfidential()) {
            return true;
        }

        return $user->can('document.confidential.manage')
            || (int) $document->created_by === (int) $user->id;
    }

    /**
     * Whether the folder this document sits in allows the act.
     *
     * Asked after the permission, never instead of it. A grant on a folder narrows what someone may
     * do there; it does not hand them an ability the module never gave them, so a person without
     * `document.delete` cannot be granted deletion by being named on a folder.
     *
     * A document filed nowhere has no folder to ask, and is governed by the module's permissions
     * alone.
     */
    private function folderAllows(User $user, Document $document, string $ability): bool
    {
        return app(FolderAccess::class)->can($user, $document->folder, $ability);
    }
}
