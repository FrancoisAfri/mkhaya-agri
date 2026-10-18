<?php

namespace App\Modules\CRM\Jobs;

use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmMeeting;
use App\Modules\CRM\Services\CrmAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Transcribes a meeting recording, summarises it with AI, stores the result and
 * saves the summary as a note on the linked record. Runs off the request thread
 * because audio transcription can be slow.
 */
class TranscribeMeetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 900;

    public function __construct(private readonly int $meetingId)
    {
    }

    public function handle(CrmAiService $ai): void
    {
        $meeting = CrmMeeting::find($this->meetingId);
        if (! $meeting || ! $meeting->audio_path) {
            return;
        }

        $meeting->update(['status' => 'transcribing', 'status_message' => null]);

        $absolute = Storage::disk('local')->path($meeting->audio_path);

        $tr = $ai->transcribeAudio($absolute);
        if (! ($tr['available'] ?? false)) {
            $meeting->update(['status' => 'failed', 'status_message' => $tr['error'] ?? 'AI not configured.']);
            return;
        }
        if (isset($tr['error'])) {
            $meeting->update(['status' => 'failed', 'status_message' => $tr['error']]);
            return;
        }

        $transcript = $tr['text'] ?? '';
        $meeting->transcript = $transcript;

        // Summarise the transcript (summary + sentiment + action items).
        $summary = $ai->summariseConversation($transcript);
        if (($summary['available'] ?? false) && ! isset($summary['error'])) {
            $actions = $summary['action_items'] ?? [];
            $meeting->summary            = $summary['summary'] ?? '';
            $meeting->sentiment          = $summary['sentiment'] ?? null;
            $meeting->action_items       = $actions;
            $meeting->followup_suggested = ! empty($actions);
            $meeting->followup_reason    = $actions[0] ?? null;
        }

        // Conversation intelligence — score the call (sentiment, objections, coaching).
        $score = $ai->scoreCall($transcript);
        if (($score['available'] ?? false) && ! isset($score['error'])) {
            $meeting->call_score    = $score['score'] ?? null;
            $meeting->call_analysis = [
                'talk_ratio'  => $score['talk_ratio'] ?? null,
                'objections'  => $score['objections'] ?? [],
                'commitments' => $score['commitments'] ?? [],
                'next_steps'  => $score['next_steps'] ?? [],
                'coaching'    => $score['coaching'] ?? [],
            ];
            if (! $meeting->sentiment) $meeting->sentiment = $score['sentiment'] ?? null;
        }

        $meeting->status = 'done';
        $meeting->save();

        $this->saveAsNote($meeting);
    }

    /** Save the AI summary as a note activity on the linked record. */
    private function saveAsNote(CrmMeeting $meeting): void
    {
        if (! $meeting->related_type || ! $meeting->related_id || ! $meeting->summary) {
            return;
        }

        CrmActivity::create([
            'uuid'         => (string) Str::uuid(),
            'type'         => 'note',
            'subject'      => 'Meeting summary: '.$meeting->title,
            'description'  => $meeting->summary
                . (empty($meeting->action_items) ? '' : "\n\nAction items:\n- ".implode("\n- ", $meeting->action_items)),
            'related_type' => $meeting->related_type,
            'related_id'   => $meeting->related_id,
            'status'       => 'done',
            'completed_at' => now(),
            'owner_id'     => $meeting->owner_id,
            'created_by'   => $meeting->created_by,
            'ai_meta'      => ['summary' => $meeting->summary, 'sentiment' => $meeting->sentiment, 'action_items' => $meeting->action_items ?? []],
        ]);
    }
}
