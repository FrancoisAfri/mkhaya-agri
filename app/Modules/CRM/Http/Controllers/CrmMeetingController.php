<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Jobs\TranscribeMeetingJob;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmMeeting;
use App\Modules\CRM\Services\CrmAiService;
use App\Modules\CRM\Services\CrmRecordOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Meeting recordings: upload audio → AI transcription + summary → saved to the
 * linked record's notes, with an option to schedule a follow-up meeting.
 */
class CrmMeetingController extends Controller
{
    public function index(CrmAiService $ai, CrmRecordOptions $records): Response
    {
        $meetings = CrmMeeting::with('owner.person')->latest()->limit(200)->get()->map(fn ($m) => [
            'id'                 => $m->id,
            'title'              => $m->title,
            'related_type'       => $m->related_type,
            'related_id'         => $m->related_id,
            'status'             => $m->status,
            'status_message'     => $m->status_message,
            'summary'            => $m->summary,
            'sentiment'          => $m->sentiment,
            'action_items'       => $m->action_items ?: [],
            'call_score'         => $m->call_score,
            'call_analysis'      => $m->call_analysis ?: null,
            'transcript'         => $m->transcript,
            'followup_suggested' => $m->followup_suggested,
            'followup_reason'    => $m->followup_reason,
            'owner'              => $m->owner?->person?->first_name,
            'created_at'         => $m->created_at?->diffForHumans(),
        ]);

        return Inertia::render('Modules/CRM/Meetings', [
            'meetings'      => $meetings,
            'aiEnabled'     => $ai->isConfigured(),
            'recordOptions' => $records->forTypes(['lead', 'contact', 'account', 'deal']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title'        => 'required|string|max:200',
            'audio'        => 'required|file|mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/webm,audio/ogg,video/mp4,video/webm|max:51200',
            'related_type' => 'nullable|in:lead,contact,account,deal',
            'related_id'   => 'nullable|integer',
        ]);

        $path = $request->file('audio')->store('crm/meetings', 'local');

        $meeting = CrmMeeting::create([
            'uuid'         => (string) Str::uuid(),
            'title'        => $data['title'],
            'related_type' => $data['related_type'] ?? null,
            'related_id'   => $data['related_id'] ?? null,
            'audio_path'   => $path,
            'status'       => 'uploaded',
            'owner_id'     => Auth::id(),
            'created_by'   => Auth::id(),
        ]);

        TranscribeMeetingJob::dispatch($meeting->id);

        return back()->with('success', 'Uploaded — transcription is processing.');
    }

    /** Schedule a follow-up meeting (creates a dated activity on the record). */
    public function scheduleFollowup(Request $request, CrmMeeting $meeting): RedirectResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'due_at'  => 'required|date',
        ]);

        CrmActivity::create([
            'uuid'         => (string) Str::uuid(),
            'type'         => 'meeting',
            'subject'      => $data['subject'],
            'description'  => 'Follow-up from: '.$meeting->title,
            'related_type' => $meeting->related_type ?: 'deal',
            'related_id'   => $meeting->related_id ?: 0,
            'status'       => 'pending',
            'due_at'       => $data['due_at'],
            'owner_id'     => Auth::id(),
            'created_by'   => Auth::id(),
        ]);

        return back()->with('success', 'Follow-up meeting scheduled.');
    }

    public function retry(CrmMeeting $meeting): RedirectResponse
    {
        $meeting->update(['status' => 'uploaded', 'status_message' => null]);
        TranscribeMeetingJob::dispatch($meeting->id);
        return back()->with('success', 'Re-processing.');
    }

    /** Run conversation-intelligence scoring on an existing transcript. */
    public function score(CrmMeeting $meeting, CrmAiService $ai): RedirectResponse
    {
        if (! $meeting->transcript) {
            return back()->with('error', 'No transcript to score yet.');
        }
        $score = $ai->scoreCall($meeting->transcript);
        if (! ($score['available'] ?? false) || isset($score['error'])) {
            return back()->with('error', $score['error'] ?? 'Scoring failed.');
        }
        $meeting->update([
            'call_score'    => $score['score'] ?? null,
            'sentiment'     => $meeting->sentiment ?: ($score['sentiment'] ?? null),
            'call_analysis' => [
                'talk_ratio'  => $score['talk_ratio'] ?? null,
                'objections'  => $score['objections'] ?? [],
                'commitments' => $score['commitments'] ?? [],
                'next_steps'  => $score['next_steps'] ?? [],
                'coaching'    => $score['coaching'] ?? [],
            ],
        ]);
        return back()->with('success', 'Call scored.');
    }

    public function destroy(CrmMeeting $meeting): RedirectResponse
    {
        if ($meeting->audio_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($meeting->audio_path);
        }
        $meeting->delete();
        return back()->with('success', 'Meeting removed.');
    }
}
