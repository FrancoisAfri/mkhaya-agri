<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sharing a document with somebody inside the organisation.
 *
 * The point of this is to make an exception without making a rule. HR hands one employee file to a
 * manager for one conversation; the alternatives are granting them the Employee Files folder, or
 * making them somebody who can read every confidential document in the company, and both are how a
 * repository's permissions quietly become meaningless.
 *
 * So a grant is deliberately narrow: one document, one subject, optionally until a date, and view
 * unless downloading was also intended.
 */
class InternalShareService
{
    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Shares a document, or updates the share if one already exists for that subject.
     *
     * Sharing twice with the same person is one decision restated, not two grants that then have to
     * be revoked separately.
     *
     * @param array{can_download?: bool, expires_days?: ?int, note?: ?string} $options
     */
    public function grant(Document $document, string $subjectType, ?int $subjectId, ?string $subjectName, array $options = []): DocumentGrant
    {
        return DB::transaction(function () use ($document, $subjectType, $subjectId, $subjectName, $options) {
            $days = $options['expires_days'] ?? null;

            $grant = DocumentGrant::updateOrCreate(
                [
                    'document_id' => $document->id,
                    'subject_type' => $subjectType,
                    // Company-wide grants have no subject; the unique key treats them as one row.
                    'subject_id' => $subjectType === DocumentGrant::SUBJECT_COMPANY ? null : $subjectId,
                ],
                [
                    'uuid' => (string) Str::uuid(),
                    'subject_name' => $subjectName,
                    'can_download' => (bool) ($options['can_download'] ?? false),
                    'expires_at' => $days ? Carbon::now()->addDays((int) $days) : null,
                    'note' => $options['note'] ?? null,
                    'granted_by' => Auth::id(),
                ],
            );

            $this->documents->record($document, DocumentEvent::SHARED_INTERNALLY, null, [
                'subject_type' => $subjectType,
                'subject' => $subjectName,
                'can_download' => $grant->can_download,
                'expires_at' => $grant->expires_at?->toDateString(),
            ]);

            return $grant;
        });
    }

    /** Withdraws a share. */
    public function revoke(DocumentGrant $grant): void
    {
        $document = $grant->document()->withoutGlobalScopes()->first();

        DB::transaction(function () use ($grant, $document) {
            if ($document) {
                $this->documents->record($document, DocumentEvent::SHARE_INTERNAL_REVOKED, null, [
                    'subject_type' => $grant->subject_type,
                    'subject' => $grant->subject_name,
                ]);
            }

            $grant->delete();
        });
    }

    /** Who a document is currently shared with. */
    public function grantsFor(Document $document)
    {
        return DocumentGrant::query()
            ->where('document_id', $document->id)
            ->orderBy('subject_type')
            ->orderBy('subject_name')
            ->get();
    }

    /**
     * The documents this user has been given by name, by role, by department, or company-wide.
     *
     * Returned as a subquery rather than a list of ids: a company with fifty thousand documents
     * shared company-wide would otherwise load fifty thousand integers into memory on every
     * listing, and then send them all back to the database as an IN clause.
     *
     * @return Builder<DocumentGrant>|null null when nothing could match, so callers can skip the join
     */
    public function grantedDocumentIds(?User $user): ?Builder
    {
        if (! $user) {
            return null;
        }

        $roles = $user->getRoleNames()->all();
        $departmentId = $this->departmentOf($user);
        $groupIds = $this->groupsOf($user);

        return DocumentGrant::query()
            ->live()
            ->select('document_id')
            ->where(function ($query) use ($user, $roles, $departmentId, $groupIds) {
                $query->where(fn ($q) => $q->where('subject_type', DocumentGrant::SUBJECT_USER)
                    ->where('subject_id', $user->id));

                // Company-wide: everybody the module already lets in.
                $query->orWhere('subject_type', DocumentGrant::SUBJECT_COMPANY);

                if ($roles !== []) {
                    // Matched on the name, as folder permissions are — a role renamed keeps working
                    // for the people in it, which is the behaviour already established here.
                    $query->orWhere(fn ($q) => $q->where('subject_type', DocumentGrant::SUBJECT_ROLE)
                        ->whereIn('subject_name', $roles));
                }

                if ($departmentId) {
                    $query->orWhere(fn ($q) => $q->where('subject_type', DocumentGrant::SUBJECT_DEPARTMENT)
                        ->where('subject_id', $departmentId));
                }

                if ($groupIds !== []) {
                    $query->orWhere(fn ($q) => $q->where('subject_type', DocumentGrant::SUBJECT_GROUP)
                        ->whereIn('subject_id', $groupIds));
                }
            });
    }

    /**
     * The groups this person is currently in.
     *
     * Both the membership's date and the group's own are checked: either ending should end the
     * access, and checking only one is how somebody keeps access through a group whose engagement
     * finished last quarter.
     *
     * @return list<int>
     */
    private function groupsOf(User $user): array
    {
        return DB::table('document_group_members as m')
            ->join('document_groups as g', 'g.id', '=', 'm.group_id')
            ->where('m.user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('m.expires_at')->orWhere('m.expires_at', '>', Carbon::now()))
            ->where(fn ($q) => $q->whereNull('g.expires_at')->orWhere('g.expires_at', '>', Carbon::now()))
            ->pluck('g.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Whether this user holds a live grant on this document, and what it allows. */
    public function grantFor(?User $user, Document $document): ?DocumentGrant
    {
        $query = $this->grantedDocumentIds($user);

        if (! $query) {
            return null;
        }

        return (clone $query)
            ->select('*')
            ->where('document_id', $document->id)
            // The most permissive grant wins where somebody holds two — by name and by department,
            // say. Refusing a download because the weaker one was read first would be arbitrary.
            ->orderByDesc('can_download')
            ->first();
    }

    /**
     * The user's department.
     *
     * The same join FolderAccess uses, and the same caveat: company_unit_user_pivots.company_id
     * holds a company *unit* id despite its name, and the pivot joins on the user rather than the
     * person.
     */
    private function departmentOf(User $user): ?int
    {
        $unitId = DB::table('company_unit_user_pivots as p')
            ->join('company_units as u', 'u.id', '=', 'p.company_id')
            ->where('p.user_id', $user->id)
            ->where('u.type', 'department')
            ->value('u.id');

        return $unitId === null ? null : (int) $unitId;
    }
}
