<?php

namespace App\Modules\PerformanceAppraisal\Controllers;

use App\Core\User\Models\User;
use App\Core\User\Trait\SafeNotifies;
use App\Http\Controllers\Controller;
use App\Modules\PerformanceAppraisal\Models\PaRecognition;
use App\Modules\PerformanceAppraisal\Models\PaRecognitionComment;
use App\Modules\PerformanceAppraisal\Models\PaRecognitionReaction;
use App\Modules\PerformanceAppraisal\Notifications\PaRecognitionNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PaRecognitionController extends Controller
{
    use \App\Modules\HR\Support\PicksTenantPeople;

    use SafeNotifies;

    public const BADGES = [
        'team_player'    => 'Team Player',
        'innovation'     => 'Innovation',
        'customer_hero'  => 'Customer Hero',
        'above_beyond'   => 'Above & Beyond',
        'leadership'     => 'Leadership',
        'ownership'      => 'Ownership',
    ];

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $feed = PaRecognition::with(['giver', 'receiver', 'reactions', 'comments.user'])
            ->where(function ($q) use ($userId) {
                $q->where('is_public', true)
                  ->orWhere('giver_id', $userId)
                  ->orWhere('receiver_id', $userId);
            })
            ->latest()
            ->limit(100)
            ->get();

        $people = $this->people($feed);

        $items = $feed->map(fn ($r) => $this->present($r, $people, $userId))->values();

        // Lightweight leaderboard: most-recognised this period.
        $leaderboard = PaRecognition::select('receiver_id', DB::raw('count(*) as total'))
            ->where('created_at', '>=', now()->subDays(90))
            ->groupBy('receiver_id')->orderByDesc('total')->limit(5)->get()
            ->map(fn ($row) => [
                'employee_id' => $row->receiver_id,
                'name'        => $this->nameFor($row->receiver_id),
                'total'       => (int) $row->total,
            ]);

        return Inertia::render('Modules/PerformanceAppraisal/Recognition/Index', [
            'feed'        => $items,
            'badges'      => self::BADGES,
            'leaderboard' => $leaderboard,
            'people'      => $this->directory($userId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'receiver_id'        => 'required|exists:users,id|different:giver',
            'badge'              => 'nullable|string|max:50',
            'value_tag'          => 'nullable|string|max:50',
            'message'            => 'required|string|max:1000',
            'is_public'          => 'boolean',
            'mentioned_user_ids' => 'nullable|array',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
        ]);

        $rec = PaRecognition::create([
            'giver_id'           => $request->user()->id,
            'receiver_id'        => $data['receiver_id'],
            'badge'              => $data['badge'] ?? null,
            'value_tag'          => $data['value_tag'] ?? null,
            'message'            => $data['message'],
            'is_public'          => $data['is_public'] ?? true,
            'mentioned_user_ids' => $data['mentioned_user_ids'] ?? [],
        ]);

        // Notify the receiver and any @mentioned colleagues.
        $this->safeNotify(User::find($rec->receiver_id), new PaRecognitionNotification($rec));
        foreach ($rec->mentioned_user_ids ?? [] as $uid) {
            if ($uid !== $rec->receiver_id) {
                $this->safeNotify(User::find($uid), new PaRecognitionNotification($rec, true));
            }
        }

        return back()->with('success', 'Recognition posted!');
    }

    public function react(Request $request, PaRecognition $recognition): RedirectResponse
    {
        $data = $request->validate(['emoji' => 'nullable|string|max:16']);
        $emoji = $data['emoji'] ?? '👏';
        $userId = $request->user()->id;

        $existing = PaRecognitionReaction::where([
            'recognition_id' => $recognition->id, 'user_id' => $userId, 'emoji' => $emoji,
        ])->first();

        // Toggle the reaction.
        if ($existing) {
            $existing->delete();
        } else {
            PaRecognitionReaction::create([
                'recognition_id' => $recognition->id, 'user_id' => $userId, 'emoji' => $emoji,
            ]);
        }

        return back();
    }

    public function comment(Request $request, PaRecognition $recognition): RedirectResponse
    {
        $data = $request->validate(['body' => 'required|string|max:500']);

        PaRecognitionComment::create([
            'recognition_id' => $recognition->id,
            'user_id'        => $request->user()->id,
            'body'           => $data['body'],
        ]);

        if ($recognition->giver_id !== $request->user()->id) {
            $this->safeNotify(User::find($recognition->giver_id), new PaRecognitionNotification($recognition, true));
        }

        return back();
    }

    public function destroy(Request $request, PaRecognition $recognition): RedirectResponse
    {
        abort_unless($recognition->giver_id === $request->user()->id || $request->user()->hasRole(['super admin', 'administrator', 'HR']), 403);
        $recognition->delete();

        return back()->with('success', 'Recognition removed.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function present(PaRecognition $r, $people, int $userId): array
    {
        $reactions = $r->reactions->groupBy('emoji')->map->count();

        return [
            'id'            => $r->id,
            'giver_name'    => $this->nameFor($r->giver_id, $people),
            'receiver_id'   => $r->receiver_id,
            'receiver_name' => $this->nameFor($r->receiver_id, $people),
            'badge'         => $r->badge,
            'badge_label'   => self::BADGES[$r->badge] ?? null,
            'value_tag'     => $r->value_tag,
            'message'       => $r->message,
            'created_at'    => $r->created_at?->diffForHumans(),
            'reactions'     => $reactions,
            'my_reactions'  => $r->reactions->where('user_id', $userId)->pluck('emoji')->values(),
            'comments'      => $r->comments->map(fn ($c) => [
                'id'   => $c->id,
                'name' => $this->nameFor($c->user_id, $people),
                'body' => $c->body,
                'at'   => $c->created_at?->diffForHumans(),
            ])->values(),
            'can_delete'    => $r->giver_id === $userId,
        ];
    }

    private function people($feed)
    {
        $ids = $feed->pluck('giver_id')
            ->merge($feed->pluck('receiver_id'))
            ->merge($feed->flatMap(fn ($r) => $r->comments->pluck('user_id')))
            ->unique()->values();

        return DB::table('hr_people')->whereIn('user_id', $ids)
            ->get(['user_id', 'first_name', 'surname'])->keyBy('user_id');
    }

    private function nameFor(int $userId, $people = null): string
    {
        $person = $people?->get($userId)
            ?? DB::table('hr_people')->where('user_id', $userId)->first(['first_name', 'surname']);

        return $person ? trim("{$person->first_name} {$person->surname}") : ('User #' . $userId);
    }

    /**
     * Everybody this tenant may recognise, minus the person doing the recognising.
     *
     * Read `hr_people` directly before, with no tenancy clause at all and nothing to
     * offer for an account that has no personnel record yet.
     */
    private function directory(int $excludeUserId): array
    {
        return collect($this->tenantPeopleOptions())
            ->reject(fn ($person) => (int) $person['id'] === $excludeUserId)
            ->values()
            ->all();
    }
}
