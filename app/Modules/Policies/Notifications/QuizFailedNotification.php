<?php

namespace App\Modules\Policies\Notifications;

use App\Core\User\Models\User;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyQuizAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuizFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Policy $policy,
        protected User $employee,
        protected PolicyQuizAttempt $attempt,
        protected ?int $attemptsLeft
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->employee->name ?? $this->employee->email;
        $attemptInfo  = $this->attemptsLeft === null
            ? 'They may continue retrying (unlimited attempts).'
            : ($this->attemptsLeft > 0
                ? "They have {$this->attemptsLeft} attempt(s) remaining."
                : 'They have exhausted all attempts.');

        return (new MailMessage)
            ->subject("Policy Quiz Failed: \"{$this->policy->name}\"")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$employeeName}** has failed the policy understanding quiz for **\"{$this->policy->name}\"**.")
            ->line("**Score:** {$this->attempt->score}% (Attempt #{$this->attempt->attempt_number})")
            ->line($attemptInfo)
            ->action('View Quiz Dashboard', url(route('policies.quiz.manage', $this->policy->uuid)))
            ->line('Please follow up to ensure the employee understands and complies with this policy.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'quiz_failed',
            'policy_id'     => $this->policy->id,
            'policy_name'   => $this->policy->name,
            'employee_id'   => $this->employee->id,
            'employee_name' => $this->employee->name ?? $this->employee->email,
            'attempt_id'    => $this->attempt->id,
            'score'         => $this->attempt->score,
            'attempt_number'=> $this->attempt->attempt_number,
            'attempts_left' => $this->attemptsLeft,
            'message'       => sprintf(
                '%s scored %d%% on the quiz for "%s" (attempt #%d).',
                $this->employee->name ?? $this->employee->email,
                $this->attempt->score,
                $this->policy->name,
                $this->attempt->attempt_number
            ),
        ];
    }
}
