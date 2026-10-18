<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAccessRequest;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolder;
use App\Modules\Operations\DocumentManagement\Models\DocumentFolderPermission;
use App\Modules\Operations\DocumentManagement\Models\DocumentGrant;
use App\Modules\Operations\DocumentManagement\Models\DocumentGroup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who can reach what, and what ends when.
 *
 * Access to this repository can now come from six places — the module's permissions, the folder
 * tree, a role, a department, a group, or a share of one document — and each of them is set up on
 * its own screen. That is right for granting and useless for reviewing: nobody can answer "why can
 * this person open Employee Files" by visiting six screens and holding the answer in their head.
 *
 * Two questions this exists to answer, and they are the two that get asked.
 *
 * "What is going to end?" — every grant, membership and group carrying a date, in one list. The
 * point of temporary access is that nobody has to remember it, but somebody still has to be able to
 * see it, or "temporary" is a promise with nothing behind it.
 *
 * "Why can this person see this?" — resolved through the same code that decides it, not a
 * reimplementation. A screen that explained access using its own logic would eventually explain it
 * wrongly, and be believed.
 */
class AccessOverview
{
    public function __construct(
        private readonly FolderAccess $folders,
        private readonly LockService $locks,
    ) {}

    /**
     * The counts along the top.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $now = Carbon::now();

        return [
            'pending_access' => DocumentAccessRequest::query()->pending()
                ->where('kind', DocumentAccessRequest::KIND_ACCESS)->count(),
            'pending_downloads' => DocumentAccessRequest::query()->pending()
                ->where('kind', DocumentAccessRequest::KIND_DOWNLOAD)->count(),
            'temporary' => $this->temporaryCount($now),
            'groups' => DocumentGroup::query()->count(),
            'checked_out' => $this->locks->outstanding()->count(),
            // Restricted folders and documents, because "what is read-only around here" is asked
            // about the setting rather than about any one document.
            'read_only_folders' => DocumentFolder::query()->where('download_disabled', true)->count(),
            'read_only_documents' => Document::query()->where('download_disabled', true)->count(),
        ];
    }

    private function temporaryCount(Carbon $now): int
    {
        $live = fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '>', $now);

        return DocumentGrant::query()->tap($live)->count()
            + DocumentFolderPermission::query()->tap($live)->count()
            + DocumentGroup::query()->tap($live)->count()
            + DB::table('document_group_members')->whereNotNull('expires_at')->where('expires_at', '>', $now)->count();
    }

    /**
     * Everything with an end date, from all four places one can be set.
     *
     * Assembled in PHP rather than as a union query on purpose: the four are different shapes with
     * different revoke routes, and flattening them into one result set would mean the screen could
     * list them and not act on them.
     *
     * @return list<array<string, mixed>>
     */
    public function temporary(): array
    {
        $now = Carbon::now();
        $rows = [];

        foreach (DocumentGrant::query()->whereNotNull('expires_at')->where('expires_at', '>', $now)->get() as $grant) {
            $document = Document::withoutGlobalScopes()->find($grant->document_id);

            $rows[] = [
                'type' => 'document_share',
                'uuid' => $grant->uuid,
                'who' => $grant->subject_name,
                'what' => $document?->title,
                'target' => $document?->uuid,
                'ends' => $grant->expires_at?->toFormattedDateString(),
                'ends_in' => $grant->expires_at?->diffForHumans(),
                'can_download' => (bool) $grant->can_download,
            ];
        }

        foreach (DocumentFolderPermission::query()->whereNotNull('expires_at')->where('expires_at', '>', $now)->with('folder')->get() as $permission) {
            $rows[] = [
                'type' => 'folder_grant',
                'uuid' => $permission->uuid,
                'who' => $permission->subject_name,
                'what' => $permission->folder?->name,
                'target' => $permission->folder?->uuid,
                'ends' => $permission->expires_at?->toFormattedDateString(),
                'ends_in' => $permission->expires_at?->diffForHumans(),
                'can_download' => (bool) $permission->can_download,
            ];
        }

        foreach (DocumentGroup::query()->whereNotNull('expires_at')->where('expires_at', '>', $now)->get() as $group) {
            $rows[] = [
                'type' => 'group',
                'uuid' => $group->uuid,
                'who' => $group->name,
                // Said in folders rather than named: a group's whole point is that it holds several,
                // and naming the first one would read as if it held only that.
                'what' => null,
                'folders' => DocumentFolderPermission::query()
                    ->where('subject_type', DocumentFolderPermission::SUBJECT_GROUP)
                    ->where('subject_id', $group->id)
                    ->count(),
                'target' => $group->uuid,
                'ends' => $group->expires_at?->toFormattedDateString(),
                'ends_in' => $group->expires_at?->diffForHumans(),
            ];
        }

        foreach (DB::table('document_group_members as m')
            ->join('document_groups as g', 'g.id', '=', 'm.group_id')
            ->whereNotNull('m.expires_at')->where('m.expires_at', '>', $now)
            ->get(['m.user_id', 'm.expires_at', 'g.name as group_name', 'g.uuid as group_uuid']) as $member) {
            $expires = Carbon::parse($member->expires_at);

            $rows[] = [
                'type' => 'membership',
                'uuid' => $member->group_uuid . ':' . $member->user_id,
                'who' => $this->nameOf((int) $member->user_id),
                'what' => $member->group_name,
                'target' => $member->group_uuid,
                'user_id' => (int) $member->user_id,
                'ends' => $expires->toFormattedDateString(),
                'ends_in' => $expires->diffForHumans(),
            ];
        }

        // Soonest first: the review this screen exists for is "what is about to lapse", and a list
        // ordered any other way makes somebody sort it themselves every time.
        usort($rows, fn ($a, $b) => strcmp((string) $a['ends'], (string) $b['ends']));

        return $rows;
    }

    /**
     * Who has been turned away, recently.
     *
     * The question this answers is not "did somebody try" — it is "is the same person trying
     * repeatedly", which is the shape that means something. One refusal is a stale link somebody
     * was sent; nine in a week is a conversation worth having.
     *
     * @return list<array<string, mixed>>
     */
    public function refusals(int $days = 30, int $limit = 25): array
    {
        return DB::table('document_events as e')
            ->join('documents as d', 'd.id', '=', 'e.document_id')
            ->where('e.action', DocumentEvent::ACCESS_REFUSED)
            ->where('e.created_at', '>=', Carbon::now()->subDays($days))
            ->groupBy('e.user_id', 'd.uuid', 'd.title')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit($limit)
            ->get([
                'e.user_id',
                'd.uuid as document_uuid',
                'd.title as document_title',
                DB::raw('count(*) as attempts'),
                DB::raw('max(e.created_at) as last_attempt'),
            ])
            ->map(fn ($row) => [
                'who' => $this->nameOf($row->user_id === null ? null : (int) $row->user_id),
                'document' => $row->document_uuid,
                'title' => $row->document_title,
                'attempts' => (int) $row->attempts,
                'last' => Carbon::parse($row->last_attempt)->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Copies taken recently.
     *
     * A download is the moment a document stops being under this system's control, so it is the
     * one event worth a screen of its own rather than a line in a per-document history nobody
     * reads across.
     *
     * @return list<array<string, mixed>>
     */
    public function recentDownloads(int $days = 7, int $limit = 30): array
    {
        return DB::table('document_events as e')
            ->join('documents as d', 'd.id', '=', 'e.document_id')
            ->where('e.action', DocumentEvent::DOWNLOADED)
            ->where('e.created_at', '>=', Carbon::now()->subDays($days))
            ->orderByDesc('e.created_at')
            ->limit($limit)
            ->get(['e.user_id', 'e.created_at', 'd.uuid as document_uuid', 'd.title as document_title'])
            ->map(fn ($row) => [
                'who' => $this->nameOf($row->user_id === null ? null : (int) $row->user_id),
                'document' => $row->document_uuid,
                'title' => $row->document_title,
                'at' => Carbon::parse($row->created_at)->diffForHumans(),
            ])
            ->all();
    }

    /**
     * People holding an unusual amount of access.
     *
     * Not a judgement — a prompt. Somebody with fourteen document shares is either doing something
     * that needs them or has accumulated them one exception at a time, and nobody can tell which
     * without being shown the number.
     *
     * @return list<array<string, mixed>>
     */
    public function heavilyGranted(int $threshold = 5, int $limit = 20): array
    {
        return DocumentGrant::query()
            ->live()
            ->where('subject_type', DocumentGrant::SUBJECT_USER)
            ->groupBy('subject_id', 'subject_name')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->orderByDesc(DB::raw('count(*)'))
            ->limit($limit)
            ->get(['subject_id', 'subject_name', DB::raw('count(*) as grants')])
            ->map(fn ($row) => [
                'who' => $row->subject_name ?: $this->nameOf((int) $row->subject_id),
                'user_id' => (int) $row->subject_id,
                'grants' => (int) $row->grants,
            ])
            ->all();
    }

    /**
     * Why one person can reach what they can reach.
     *
     * Answered through FolderAccess, which is the thing that actually decides it. A screen that
     * explained access using its own reasoning would drift from the enforcement and be believed
     * anyway, which is worse than not having the screen.
     *
     * @return array<string, mixed>
     */
    public function forUser(?User $user): array
    {
        if (! $user) {
            return ['folders' => [], 'documents' => [], 'groups' => []];
        }

        $folders = DocumentFolder::query()
            ->whereNull('archived_at')
            ->orderBy('path')
            ->get()
            ->map(function (DocumentFolder $folder) use ($user) {
                $abilities = $this->folders->abilities($user, $folder);

                if (! $abilities['view']) {
                    return null;
                }

                return [
                    'uuid' => $folder->uuid,
                    'name' => $folder->name,
                    'depth' => (int) $folder->depth,
                    'can' => $abilities,
                    'why' => $this->reasonFor($user, $folder),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $documents = DocumentGrant::query()
            ->live()
            ->where(function ($query) use ($user) {
                $query->where(fn ($q) => $q->where('subject_type', DocumentGrant::SUBJECT_USER)->where('subject_id', $user->id));
                $query->orWhere('subject_type', DocumentGrant::SUBJECT_COMPANY);
            })
            ->get()
            ->map(function (DocumentGrant $grant) {
                $document = Document::withoutGlobalScopes()->find($grant->document_id);

                return $document ? [
                    'uuid' => $document->uuid,
                    'title' => $document->title,
                    'can_download' => (bool) $grant->can_download,
                    'ends' => $grant->expires_at?->toFormattedDateString(),
                    'grant' => $grant->uuid,
                ] : null;
            })
            ->filter()
            ->values()
            ->all();

        $groups = DB::table('document_group_members as m')
            ->join('document_groups as g', 'g.id', '=', 'm.group_id')
            ->where('m.user_id', $user->id)
            ->get(['g.uuid', 'g.name', 'g.expires_at', 'm.expires_at as member_expires_at'])
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'name' => $row->name,
                'ends' => $row->member_expires_at ?? $row->expires_at,
            ])
            ->all();

        return ['folders' => $folders, 'documents' => $documents, 'groups' => $groups];
    }

    /**
     * The nearest thing to a plain-English answer to "why".
     *
     * Reports the grant that actually applies, or says the folder is open to the company — which is
     * the answer people are most often surprised by, and the one no permissions screen shows because
     * there is no row to show.
     */
    private function reasonFor(User $user, DocumentFolder $folder): string
    {
        $chain = array_merge($folder->ancestors(), [$folder]);
        $roles = $user->getRoleNames()->all();
        $groupIds = $this->groupsOf($user);

        foreach (array_reverse($chain) as $candidate) {
            $grants = DocumentFolderPermission::query()
                ->where('folder_id', $candidate->id)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
                ->get();

            foreach ($grants as $grant) {
                $matches = match ($grant->subject_type) {
                    DocumentFolderPermission::SUBJECT_USER => (int) $grant->subject_id === (int) $user->id,
                    DocumentFolderPermission::SUBJECT_ROLE => in_array($grant->subject_name, $roles, true),
                    DocumentFolderPermission::SUBJECT_GROUP => in_array((int) $grant->subject_id, $groupIds, true),
                    DocumentFolderPermission::SUBJECT_DEPARTMENT => true,
                    default => false,
                };

                if ($matches) {
                    $inherited = $candidate->id !== $folder->id;

                    return $inherited
                        ? __('document_management.access_map.via_parent', [
                            'reason' => __('document_management.access_map.via_' . $grant->subject_type, ['name' => $grant->subject_name]),
                            'folder' => $candidate->name,
                        ])
                        : __('document_management.access_map.via_' . $grant->subject_type, ['name' => $grant->subject_name]);
                }
            }
        }

        return $folder->isDepartmental()
            ? __('document_management.access_map.via_department', ['name' => $folder->name])
            : __('document_management.access_map.via_company');
    }

    /** @return list<int> */
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

    private function nameOf(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->with('person')->find($userId);

        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }
}
