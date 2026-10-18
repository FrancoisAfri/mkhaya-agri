<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmEventBus;
use App\Modules\CRM\Services\CrmRecordOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activity tracking + unified timeline (spec section 1) and AI conversation
 * summarisation (section 4).
 */
class ActivityController extends Controller
{
    public function __construct(
        private CrmAiService $ai,
        private CrmEventBus $events,
        private CrmRecordOptions $recordOptions,
    ) {
    }

    public function index(Request $request): Response
    {
        $options = $this->recordOptions->forTypes(['lead', 'contact', 'account', 'deal']);
        $labels  = $this->recordOptions->labels($options);

        $activityQuery = CrmActivity::with('owner.person')
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->related_type && $request->related_id, fn ($q) => $q
                ->where('related_type', $request->related_type)->where('related_id', $request->related_id));

        app(\App\Modules\CRM\Services\CrmVisibilityService::class)->scopeOwner($activityQuery);

        $activities = $activityQuery->latest()
            ->limit(200)
            ->get()
            ->map(fn ($a) => $this->present($a, $labels));

        return Inertia::render('Modules/CRM/Activities', [
            'activities'     => $activities,
            'types'          => CrmActivity::TYPES,
            'aiEnabled'      => $this->ai->isConfigured(),
            'relatedOptions' => $options,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type'         => 'required|in:call,email,meeting,task,reminder,note,whatsapp,sms',
            'subject'      => 'required|string|max:200',
            'description'  => 'nullable|string',
            // Related record is optional: a personal task/reminder needn't be tied
            // to a lead/contact/account/deal. When given, both parts are required.
            'related_type' => 'nullable|required_with:related_id|in:lead,contact,account,deal',
            'related_id'   => 'nullable|required_with:related_type|integer',
            'due_at'       => 'nullable|date',
            // Optional follow-up task/reminder created alongside the activity.
            'follow_up'           => 'nullable|array',
            'follow_up.kind'      => 'required_with:follow_up|in:task,reminder',
            'follow_up.due_at'    => 'required_with:follow_up|date',
            'follow_up.note'      => 'nullable|string|max:200',
        ]);

        $activity = CrmActivity::create([
            'uuid'         => Str::uuid(),
            'type'         => $data['type'],
            'subject'      => $data['subject'],
            'description'  => $data['description'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'related_id'   => $data['related_id'] ?? null,
            'due_at'       => $data['due_at'] ?? null,
            'status'       => $request->filled('due_at') ? 'pending' : 'done',
            'completed_at' => $request->filled('due_at') ? null : now(),
            'owner_id'     => Auth::id(),
            'created_by'   => Auth::id(),
        ]);

        // Spawn a follow-up task/reminder, sharing the activity's related record.
        if ($fu = ($data['follow_up'] ?? null)) {
            CrmActivity::create([
                'uuid'         => Str::uuid(),
                'type'         => $fu['kind'],
                'subject'      => $fu['note'] ?: 'Follow up: ' . $activity->subject,
                'related_type' => $activity->related_type,
                'related_id'   => $activity->related_id,
                'due_at'       => $fu['due_at'],
                'status'       => 'pending',
                'owner_id'     => Auth::id(),
                'created_by'   => Auth::id(),
            ]);
        }

        return back()->with('success', 'Activity logged.');
    }

    public function complete(CrmActivity $activity): RedirectResponse
    {
        $activity->update(['status' => 'done', 'completed_at' => now()]);
        $this->events->fire('activity.completed', $activity);

        return back()->with('success', 'Marked done.');
    }

    public function destroy(CrmActivity $activity): RedirectResponse
    {
        $activity->delete();

        return back()->with('success', 'Activity removed.');
    }

    /**
     * AI conversation summary — summarise a call/meeting/email and store the
     * summary, sentiment and extracted action items on the activity.
     */
    public function summarise(Request $request, CrmActivity $activity): RedirectResponse
    {
        if (! $this->ai->isConfigured()) {
            return back()->with('error', 'AI is not configured.');
        }

        $text = $request->input('transcript', $activity->description ?? '');
        abort_if(trim((string) $text) === '', 422, 'Nothing to summarise.');

        $result = $this->ai->summariseConversation($text);
        if (! ($result['available'] ?? false) || isset($result['error'])) {
            return back()->with('error', $result['error'] ?? 'Summary failed.');
        }

        $activity->update(['ai_meta' => [
            'summary'      => $result['summary'] ?? '',
            'sentiment'    => $result['sentiment'] ?? 'neutral',
            'action_items' => $result['action_items'] ?? [],
        ]]);

        return back()->with('success', 'Conversation summarised.');
    }

    private function present(CrmActivity $a, array $labels = []): array
    {
        return [
            'id'            => $a->id,
            'type'          => $a->type,
            'type_label'    => CrmActivity::TYPES[$a->type]['label'] ?? $a->type,
            'type_icon'     => CrmActivity::TYPES[$a->type]['icon'] ?? 'point',
            'subject'       => $a->subject,
            'description'   => $a->readableDescription(),
            'related_type'  => $a->related_type,
            'related_id'    => $a->related_id,
            'related_label' => $labels[$a->related_type][$a->related_id] ?? null,
            'status'       => $a->status,
            'due_at'       => $a->due_at?->toDateTimeString(),
            'completed_at' => $a->completed_at?->toDateTimeString(),
            'ai_meta'      => $a->ai_meta,
            'owner'        => $a->owner?->person?->first_name,
            'created_at'   => $a->created_at?->diffForHumans(),
        ];
    }
}
