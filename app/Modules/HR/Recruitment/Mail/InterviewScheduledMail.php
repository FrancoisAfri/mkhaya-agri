<?php

namespace App\Modules\HR\Recruitment\Mail;

use App\Modules\HR\Recruitment\Models\Interview;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InterviewScheduledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Interview $interview,
        public readonly string    $recipient, // 'candidate' or 'interviewer'
    ) {}

    public function envelope(): Envelope
    {
        $jobTitle = $this->interview->application?->job?->title ?? 'Position';
        $subject  = $this->recipient === 'interviewer'
            ? "Interview Scheduled — {$jobTitle}"
            : "Your Interview Has Been Scheduled — {$jobTitle}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.recruitment.interview-scheduled',
            with: [
                'recipient'     => $this->recipient,
                'interview'     => $this->interview,
                'candidateName' => $this->interview->application?->candidate?->full_name ?? 'Candidate',
                'jobTitle'      => $this->interview->application?->job?->title ?? 'Position',
                'scheduledAt'   => $this->interview->scheduled_at?->format('d M Y, H:i'),
                'duration'      => $this->interview->duration_minutes,
                'location'      => $this->interview->location,
                'meetingUrl'    => $this->interview->meeting_url,
                'type'          => $this->interview->type?->label() ?? $this->interview->type,
            ],
        );
    }
}
