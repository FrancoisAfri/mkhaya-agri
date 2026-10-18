<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\Announcement;
use App\Modules\Operations\ProjectManagement\Models\AnnouncementRead;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Notifications\AnnouncementPostedNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Posting a notice to everyone on a project, and knowing who has seen it (EPIC-031).
 *
 * The audience is the project's own members, not everyone with access to the module. A notice about
 * an access route on one site is noise to the eleven people working on other sites, and a channel
 * that is mostly noise stops being read before the notice that mattered arrives.
 *
 * A portfolio-wide notice (`project_id` null) reaches everyone who is a member of *some* live
 * project, which is the closest honest reading of "everyone doing project work" — and is bounded,
 * unlike "every user on the tenant".
 */
class AnnouncementService
{
    public function __construct(private readonly InternalEmitter $emitter)
    {
    }

    /**
     * Post, and tell people.
     *
     * Publishing and notifying are one step on purpose: a notice that exists but was never sent is
     * the failure mode this replaces — somebody posts it, assumes the team saw it, and nobody did.
     *
     * @param  array<string,mixed>  $data
     */
    public function post(?Project $project, array $data): Announcement
    {
        $announcement = DB::transaction(function () use ($project, $data) {
            $record = Announcement::create([
                'project_id'   => $project?->id,
                'title'        => $data['title'],
                'body'         => $data['body'],
                'severity'     => $data['severity'] ?? 'info',
                'is_pinned'    => (bool) ($data['is_pinned'] ?? false),
                'requires_ack' => (bool) ($data['requires_ack'] ?? false),
                'published_at' => $data['published_at'] ?? now(),
                'expires_at'   => $data['expires_at'] ?? null,
                'created_by'   => Auth::id(),
            ]);

            // On the timeline as well as in the inbox: "the team was told about the shutdown on the
            // 4th" is a fact about the project, and it belongs where the other facts are.
            if ($project) {
                $this->emitter->emit($project, 'status.reported', [
                    'subject_type' => 'announcement',
                    'subject_id'   => $record->uuid,
                    'source_ref'   => 'announcement:' . $record->uuid,
                    // The taxonomy's own field names — `status` and `note` — rather than the
                    // announcement's own vocabulary. An event whose payload does not match its
                    // schema is dead-lettered, which would make the notice invisible on the
                    // timeline while looking successful here.
                    'payload'      => [
                        'status' => $record->severity,
                        'note'   => $record->title,
                    ],
                ]);
            }

            return $record;
        });

        // Outside the transaction: notifications are queued work and a mail failure must not roll
        // back the notice itself.
        $recipients = $this->audience($announcement);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AnnouncementPostedNotification($announcement));

            $announcement->sent_sms = $announcement->severity === 'urgent';
            $announcement->save();
        }

        return $announcement;
    }

    /**
     * Record that this person has seen it, and — where asked — that they accept it.
     *
     * Idempotent: opening the page twice is one read, and `read_at` is not moved on a second visit
     * because "when did they first see this" is the question that matters in a dispute.
     */
    public function markRead(Announcement $announcement, int $userId, bool $acknowledge = false): AnnouncementRead
    {
        $read = AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $userId],
            ['read_at' => now()],
        );

        if ($acknowledge && $read->acknowledged_at === null) {
            $read->acknowledged_at = now();
            $read->save();
        }

        return $read;
    }

    /**
     * What this person should be looking at, newest and most urgent first.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(User $user, ?Project $project = null): array
    {
        $announcements = Announcement::query()
            ->live()
            ->forProject($project?->id)
            ->with('project:id,uuid,code,name')
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(100)
            ->get();

        if ($announcements->isEmpty()) {
            return [];
        }

        // One query for the reader's own rows rather than one per notice.
        $mine = AnnouncementRead::whereIn('announcement_id', $announcements->pluck('id'))
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('announcement_id');

        // Counts for the author's view: a notice requiring acknowledgement is only useful if
        // somebody can see who has not given it.
        $ackCounts = AnnouncementRead::selectRaw('announcement_id, count(*) filter (where acknowledged_at is not null) as acked')
            ->whereIn('announcement_id', $announcements->pluck('id'))
            ->groupBy('announcement_id')
            ->pluck('acked', 'announcement_id');

        return $announcements->map(fn (Announcement $a) => [
            'id'           => $a->uuid,
            'title'        => $a->title,
            'body'         => $a->body,
            'severity'     => $a->severity,
            'is_pinned'    => $a->is_pinned,
            'requires_ack' => $a->requires_ack,
            'published_at' => optional($a->published_at)->toIso8601String(),
            'expires_at'   => optional($a->expires_at)->toIso8601String(),
            'project'      => $a->project ? ['id' => $a->project->uuid, 'code' => $a->project->code, 'name' => $a->project->name] : null,
            'read'         => isset($mine[$a->id]),
            'acknowledged' => $mine[$a->id]?->acknowledged_at !== null,
            'ack_count'    => (int) ($ackCounts[$a->id] ?? 0),
            'audience'     => $this->audienceSize($a),
        ])->all();
    }

    /**
     * Who gets told.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function audience(Announcement $announcement)
    {
        $userIds = ProjectMember::query()
            ->active()
            ->whereNotNull('user_id')
            ->when(
                $announcement->project_id,
                fn ($q, $id) => $q->where('project_id', $id),
                // Portfolio-wide: everyone on some project, each counted once.
                fn ($q) => $q,
            )
            ->distinct()
            ->pluck('user_id');

        // Not the author. Being emailed one's own notice reads as a system that is not paying
        // attention.
        return User::whereIn('id', $userIds)
            ->where('id', '!=', (int) $announcement->created_by)
            ->get();
    }

    private function audienceSize(Announcement $announcement): int
    {
        return ProjectMember::query()
            ->active()
            ->whereNotNull('user_id')
            ->when($announcement->project_id, fn ($q, $id) => $q->where('project_id', $id))
            ->distinct('user_id')
            ->count('user_id');
    }
}
