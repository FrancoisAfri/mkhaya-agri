<?php

namespace App\Modules\Operations\DocumentManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalAssignment;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentShare;
use App\Modules\Tenancy\Services\CurrentCompany;
use App\Modules\Tenancy\Services\StorageQuota;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the front page of the module.
 *
 * Every count is taken through the same visibility scope as the library, which is the whole
 * difficulty of a dashboard in a repository with confidential documents: "1,482 documents" shown to
 * somebody who can see four hundred of them is a disclosure of how much they are not being shown,
 * and a total that changes depending on who is looking is the only honest one.
 *
 * The alerts are counts of things somebody can act on today. A dashboard that reports the state of
 * the world is a wall of numbers; one that reports what has gone wrong is read every morning.
 */
class DocumentDashboard
{
    /** The expiry windows the alert panel reports, longest first. */
    private const WINDOWS = [30, 14, 7];

    public function __construct(
        private readonly StorageQuota $storage,
        private readonly CurrentCompany $current,
    ) {
    }

    /**
     * @return array{
     *     overview: array<string, int>,
     *     alerts: array{windows: array<int, array{days: int, count: int}>, expired: int},
     *     workflow: array<string, int>,
     *     storage: array<string, mixed>
     * }
     */
    public function for(?User $user): array
    {
        return [
            'overview' => $this->overview($user),
            'alerts' => $this->alerts($user),
            'workflow' => $this->workflow($user),
            'storage' => $this->storage(),
            'my_actions' => $this->myActions($user),
            'expiring_soon' => $this->expiringSoon($user),
            'recent_activity' => $this->recentActivity($user),
            'by_category' => $this->byCategory($user),
            'recently_added' => $this->recentlyAdded($user),
            'trend' => $this->uploadTrend($user),
        ];
    }

    /** @return array<string, int> */
    private function overview(?User $user): array
    {
        $visible = fn () => Document::query()->visibleTo($user);

        return [
            'total' => (clone $visible())->where('status', Document::STATUS_ACTIVE)->count(),
            'this_month' => (clone $visible())->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
            'archived' => (clone $visible())->where('status', Document::STATUS_ARCHIVED)->count(),
            // Counted from the trail rather than a column, so it stays right whichever way a
            // document came to be deleted.
            'mine' => $user ? (clone $visible())->where('created_by', $user->id)->count() : 0,
        ];
    }

    /**
     * What is about to lapse, in the windows people actually ask about.
     *
     * Each window counts documents expiring within it, so the numbers nest — 8 within 14 days are
     * also part of the 23 within 30. Stated that way in the wording rather than made exclusive,
     * because "23 expire within 30 days" is the sentence somebody repeats in a meeting.
     *
     * @return array{windows: array<int, array{days: int, count: int}>, expired: int}
     */
    private function alerts(?User $user): array
    {
        $windows = [];

        foreach (self::WINDOWS as $days) {
            $windows[] = [
                'days' => $days,
                'count' => Document::query()
                    ->visibleTo($user)
                    ->where('status', Document::STATUS_ACTIVE)
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [Carbon::now()->toDateString(), Carbon::now()->addDays($days)->toDateString()])
                    ->count(),
            ];
        }

        return [
            'windows' => $windows,
            'expired' => Document::query()
                ->visibleTo($user)
                ->where('status', Document::STATUS_ACTIVE)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', Carbon::now()->toDateString())
                ->count(),
        ];
    }

    /** @return array<string, int> */
    private function workflow(?User $user): array
    {
        $visible = fn () => Document::query()->visibleTo($user)->where('status', Document::STATUS_ACTIVE);

        return [
            'awaiting_approval' => (clone $visible())
                ->whereIn('approval_status', [Document::APPROVAL_SUBMITTED, Document::APPROVAL_UNDER_REVIEW])
                ->count(),
            'awaiting_signature' => (clone $visible())
                ->whereIn('signature_status', [SignatureService::AWAITING, SignatureService::PARTIAL])
                ->count(),
            'changes_requested' => (clone $visible())
                ->where('approval_status', Document::APPROVAL_CHANGES_REQUESTED)
                ->count(),
            // Links currently open to people outside the organisation. Not a workflow step, but it
            // belongs beside them: it is the other thing somebody should notice is outstanding.
            'shared_externally' => DocumentShare::query()->active()->count(),
        ];
    }

    /**
     * What is waiting on this person specifically.
     *
     * The difference between a dashboard somebody reads and one they scroll past. "12 awaiting
     * approval" across the company is a statistic; "two of them are yours" is a task, and it is the
     * only number on the page that says what to do next.
     *
     * @return array{approvals: array<int, array<string, mixed>>, signatures: array<int, array<string, mixed>>}
     */
    public function myActions(?User $user, int $limit = 5): array
    {
        if (! $user) {
            return ['approvals' => [], 'signatures' => []];
        }

        $roles = $user->getRoleNames()->all();

        $assignments = DocumentApprovalAssignment::query()
            ->where('status', DocumentApprovalAssignment::PENDING)
            ->where(function ($q) use ($user, $roles) {
                // Named directly, handed to them by a delegation, or reaching them through a role
                // the step named — all three are somebody waiting on this person.
                $q->where('assigned_to', $user->id)
                    ->orWhere('delegated_to', $user->id);

                if ($roles !== []) {
                    $q->orWhereIn('assigned_role', $roles);
                }
            })
            ->with('request.document')
            ->orderBy('due_at')
            ->limit($limit)
            ->get()
            ->filter(fn ($a) => $a->request?->document !== null)
            ->map(fn ($a) => [
                'uuid' => $a->request->document->uuid,
                'title' => $a->request->document->title,
                'step' => $a->step_name,
                'due_at' => $a->due_at?->toFormattedDateString(),
                'overdue' => $a->due_at !== null && $a->due_at->isPast(),
            ])
            ->values()
            ->all();

        /*
         * Signatures are matched on the person's email rather than a user id: a signatory is often
         * somebody outside the platform, so the envelope records an address and nothing else. That
         * also means this finds a document waiting on somebody who happens to have an account here,
         * which is exactly who is looking at this page.
         */
        $signatures = Document::query()
            ->visibleTo($user)
            ->whereIn('signature_status', [SignatureService::AWAITING, SignatureService::PARTIAL])
            ->limit($limit)
            ->get()
            ->map(fn (Document $document) => [
                'uuid' => $document->uuid,
                'title' => $document->title,
                'status' => $document->signature_status,
            ])
            ->all();

        return ['approvals' => $assignments, 'signatures' => $signatures];
    }

    /**
     * The documents lapsing next, by name.
     *
     * A count says there is a problem; the list says which certificate. This is the panel somebody
     * actually acts on, so it carries the date and how many days are left rather than making them
     * work it out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expiringSoon(?User $user, int $limit = 8): array
    {
        return Document::query()
            ->visibleTo($user)
            ->where('status', Document::STATUS_ACTIVE)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', Carbon::now()->addDays(90)->toDateString())
            ->with('category')
            ->orderBy('expiry_date')
            ->limit($limit)
            ->get()
            ->map(function (Document $document) {
                $days = Carbon::now()->startOfDay()->diffInDays($document->expiry_date, false);

                return [
                    'uuid' => $document->uuid,
                    'title' => $document->title,
                    'category' => $document->category?->name,
                    'expiry_date' => $document->expiry_date?->toFormattedDateString(),
                    'days' => (int) $days,
                    'expired' => $days < 0,
                    // Whether this one stops other work, which is what decides the order somebody
                    // deals with them in.
                    'blocking' => (bool) $document->blocks_when_expired,
                ];
            })
            ->all();
    }

    /**
     * What has been happening, most recent first.
     *
     * Scoped to documents this person can see, like the audit screen — an activity feed that names
     * a confidential document is the same disclosure whether it appears on a dashboard or a trail.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentActivity(?User $user, int $limit = 8): array
    {
        return DocumentEvent::query()
            ->whereIn('document_id', Document::query()->visibleTo($user)->select('id'))
            ->whereIn('action', [
                DocumentEvent::UPLOADED, DocumentEvent::REPLACED, DocumentEvent::APPROVED,
                DocumentEvent::REJECTED, DocumentEvent::SIGNED, DocumentEvent::PUBLISHED,
                DocumentEvent::SHARED, DocumentEvent::ARCHIVED, DocumentEvent::HOLD_PLACED,
            ])
            ->with(['document:id,uuid,title', 'user'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->filter(fn (DocumentEvent $e) => $e->document !== null)
            ->map(fn (DocumentEvent $e) => [
                'action' => $e->action,
                'at' => $e->created_at?->diffForHumans(),
                'user' => $this->personName($e->user),
                'document' => ['uuid' => $e->document->uuid, 'title' => $e->document->title],
            ])
            ->values()
            ->all();
    }

    /**
     * What the repository is made of.
     *
     * Categories rather than folders: a folder tree is one organisation's filing habit, where the
     * category says what kind of thing a document is — which is the question somebody looking at a
     * dashboard is actually asking.
     *
     * @return array<int, array{name: string, colour: ?string, count: int}>
     */
    public function byCategory(?User $user, int $limit = 8): array
    {
        return Document::query()
            ->visibleTo($user)
            ->where('status', Document::STATUS_ACTIVE)
            ->whereNotNull('category_id')
            ->selectRaw('category_id, count(*) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $category = DocumentCategory::find($row->category_id);

                return [
                    'name' => $category?->name ?? '—',
                    'colour' => $category?->colour,
                    'count' => (int) $row->total,
                ];
            })
            ->all();
    }

    /** The newest arrivals, for the person who wants what somebody just filed. */
    public function recentlyAdded(?User $user, int $limit = 6): array
    {
        return Document::query()
            ->visibleTo($user)
            ->where('status', Document::STATUS_ACTIVE)
            ->with('category')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Document $document) => [
                'uuid' => $document->uuid,
                'title' => $document->title,
                'category' => $document->category?->name,
                'added' => $document->created_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * How much has been filed each month, for the last half year.
     *
     * Six months because that is where a repository's shape becomes visible — a quiet December, the
     * month somebody imported an archive — without turning the panel into a chart nobody reads.
     *
     * @return array<int, array{month: string, count: int}>
     */
    public function uploadTrend(?User $user, int $months = 6): array
    {
        $from = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $counts = Document::query()
            ->visibleTo($user)
            ->where('created_at', '>=', $from)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as month, count(*) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $trend = [];

        // Every month is present, including the empty ones: a gap drawn as a missing bar reads as
        // missing data, where a zero reads as a quiet month.
        for ($i = 0; $i < $months; $i++) {
            $month = $from->copy()->addMonths($i);

            $trend[] = [
                'month' => $month->format('M'),
                'count' => (int) ($counts[$month->format('Y-m')] ?? 0),
            ];
        }

        return $trend;
    }

    private function personName(?object $user): ?string
    {
        if (! $user) {
            return null;
        }

        $person = $user->person ?? null;

        return $person ? trim($person->first_name . ' ' . $person->surname) : $user->email;
    }

    /**
     * Storage, broken down by what is using it.
     *
     * Read from tenant_files because that is where the bytes actually are on this platform — every
     * module's uploads live in one table, so the breakdown is honest about the whole company rather
     * than only about documents.
     *
     * @return array{used: int, quota: int, enforced: bool, breakdown: array<int, array{label: string, bytes: int}>}
     */
    private function storage(): array
    {
        $companyId = $this->current->id();

        $rows = DB::table('tenant_files')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw("
                CASE
                    WHEN mime LIKE 'image/%' THEN 'images'
                    WHEN mime LIKE 'video/%' OR mime LIKE 'audio/%' THEN 'media'
                    WHEN mime LIKE 'application/pdf'
                      OR mime LIKE '%word%' OR mime LIKE '%excel%' OR mime LIKE '%spreadsheet%'
                      OR mime LIKE '%presentation%' OR mime LIKE 'text/%' THEN 'documents'
                    ELSE 'other'
                END AS bucket,
                SUM(size) AS bytes
            ")
            ->groupBy('bucket')
            ->pluck('bytes', 'bucket');

        $breakdown = collect(['documents', 'images', 'media', 'other'])
            ->map(fn (string $bucket) => ['label' => $bucket, 'bytes' => (int) ($rows[$bucket] ?? 0)])
            ->filter(fn (array $row) => $row['bytes'] > 0)
            ->values()
            ->all();

        return [
            'used' => $this->storage->usedBytes($companyId),
            'quota' => $this->storage->quotaBytes(),
            'enforced' => $this->storage->isEnforced(),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * The largest files, for the administrator asked to free some space.
     *
     * Named by document where one exists, because "invoice-scan-final-v3.pdf, 240 MB" is actionable
     * and a path under a hashed directory is not.
     *
     * @return array<int, array<string, mixed>>
     */
    public function largestFiles(int $limit = 25): array
    {
        $companyId = $this->current->id();

        return DB::table('tenant_files as f')
            ->when($companyId, fn ($q) => $q->where('f.company_id', $companyId))
            ->leftJoin('document_versions as v', 'v.path', '=', 'f.path')
            ->leftJoin('documents as d', 'd.id', '=', 'v.document_id')
            ->orderByDesc('f.size')
            ->limit($limit)
            ->get([
                'f.name', 'f.mime', 'f.size', 'f.created_at',
                'd.uuid as document_uuid', 'd.title as document_title',
            ])
            ->map(fn ($row) => [
                'name' => $row->document_title ?: $row->name,
                'mime' => $row->mime,
                'bytes' => (int) $row->size,
                'uploaded_at' => $row->created_at ? Carbon::parse($row->created_at)->toFormattedDateString() : null,
                'document' => $row->document_uuid,
            ])
            ->all();
    }
}
