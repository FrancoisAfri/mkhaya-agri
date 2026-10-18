<?php

namespace App\Modules\CRM\Services;

use App\Core\User\Models\User;
use App\Modules\CRM\Models\CrmComplaint;
use App\Modules\CRM\Models\CrmComplaintUpdate;
use App\Modules\CRM\Models\CrmEmailTemplate;
use App\Modules\CRM\Models\CrmSetting;
use App\Shared\Notifications\GenericInAppNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Complaint lifecycle orchestration: capture (portal / mailbox / manual), AI
 * grading + employee identification, SLA computation, assignment, manager
 * notification (severity-aware) and customer acknowledgement.
 */
class CrmComplaintService
{
    public function __construct(
        private CrmAiService $ai,
        private CrmNotifier $notifier,
        private CrmTeamService $teams,
        private CrmMailService $mail,
    ) {}

    /** Tunable complaint settings (response / escalation), with defaults. */
    public function settings(): array
    {
        return [
            'respond_hours'        => (int) (CrmSetting::get('complaint_respond_hours') ?: 24),
            'resolve_hours'        => (int) (CrmSetting::get('complaint_resolve_hours') ?: 72),
            'escalate_severities'  => (array) (json_decode((string) CrmSetting::get('complaint_escalate_severities'), true) ?: ['high', 'critical']),
            'escalate_after_hours' => (int) (CrmSetting::get('complaint_escalate_after_hours') ?: 24),
        ];
    }

    public function saveSettings(array $data): void
    {
        CrmSetting::put('complaint_respond_hours', (string) ($data['respond_hours'] ?? 24));
        CrmSetting::put('complaint_resolve_hours', (string) ($data['resolve_hours'] ?? 72));
        CrmSetting::put('complaint_escalate_severities', json_encode($data['escalate_severities'] ?? ['high', 'critical']));
        CrmSetting::put('complaint_escalate_after_hours', (string) ($data['escalate_after_hours'] ?? 24));
    }

    /**
     * Create a complaint/compliment from raw input and run the full intake:
     * AI grading, employee match, SLA, assignment, notifications, acknowledgement.
     *
     * @param  array{subject:string, body:string, customer_name?:?string, customer_email?:?string, customer_phone?:?string}  $input
     */
    public function create(array $input, string $source): CrmComplaint
    {
        $settings = $this->settings();

        $complaint = new CrmComplaint([
            'uuid'           => (string) Str::uuid(),
            'reference'      => $this->nextReference(),
            'type'           => 'complaint',
            'source'         => $source,
            'subject'        => Str::limit($input['subject'] ?: 'Customer feedback', 250, ''),
            'body'           => $input['body'] ?? '',
            'customer_name'  => $input['customer_name'] ?? null,
            'customer_email' => $input['customer_email'] ?? null,
            'customer_phone' => $input['customer_phone'] ?? null,
            'status'         => 'new',
            'created_by'     => Auth::id(),
        ]);
        $complaint->save();

        $this->runAi($complaint);
        $this->applySla($complaint, $settings);
        $this->autoAssign($complaint);
        $complaint->save();

        $this->log($complaint, 'status', null, 'new', 'Complaint logged from '.$source.'.');
        $this->notifyIntake($complaint, $settings);
        $this->acknowledge($complaint);

        return $complaint;
    }

    /** Re-run AI grading on an existing complaint. */
    public function runAi(CrmComplaint $complaint): void
    {
        $assessment = $this->ai->assessComplaint(
            $complaint->subject,
            $complaint->body,
            $complaint->customer_name,
            $this->employeeNames(),
        );

        if (! ($assessment['available'] ?? false)) {
            return;
        }

        $complaint->type            = $assessment['type'] ?? $complaint->type;
        $complaint->severity        = $complaint->type === 'compliment' ? null : ($assessment['severity'] ?? 'medium');
        $complaint->category        = $assessment['category'] ?? null;
        $complaint->sentiment       = $assessment['sentiment'] ?? null;
        $complaint->ai_summary      = $assessment['summary'] ?? null;
        $complaint->ai_recommendations = $assessment['recommendations'] ?? null;
        $complaint->against_name    = $assessment['against_name'] ?: $complaint->against_name;

        if (! empty($assessment['against_name'])) {
            $complaint->against_employee_id = $this->matchEmployee($assessment['against_name']);
        }

        if ($complaint->wasRecentlyCreated === false) {
            $complaint->save();
            $this->log($complaint, 'ai', null, null, 'AI re-graded: '.($complaint->severity ?? $complaint->type).'.');
        }
    }

    /** Assign a complaint to a handler. */
    public function assign(CrmComplaint $complaint, int $userId): void
    {
        $complaint->update(['assigned_to' => $userId]);
        $this->log($complaint, 'assignment', null, null, 'Assigned to '.$this->nameOf($userId).'.');
        $this->ping($userId, 'Complaint assigned: '.$complaint->reference, Str::limit($complaint->subject, 120), $complaint);
    }

    /** Change status, recording the transition and stamping SLA timestamps. */
    public function changeStatus(CrmComplaint $complaint, string $status, ?string $note = null): void
    {
        $from = $complaint->status;
        $complaint->status = $status;
        if ($status === 'acknowledged' && ! $complaint->acknowledged_at) $complaint->acknowledged_at = now();
        if (in_array($status, ['resolved', 'closed'], true) && ! $complaint->resolved_at) $complaint->resolved_at = now();
        if ($status === 'escalated') { $complaint->escalated = true; $complaint->escalated_at = now(); }
        $complaint->save();

        $this->log($complaint, 'status', $from, $status, $note);
    }

    public function addNote(CrmComplaint $complaint, string $note): void
    {
        $this->log($complaint, 'note', null, null, $note);
    }

    /** Escalate: flag + notify managers/execs. */
    public function escalate(CrmComplaint $complaint, string $reason = 'SLA breach'): void
    {
        if ($complaint->escalated) {
            return;
        }
        $complaint->update(['escalated' => true, 'escalated_at' => now(), 'status' => 'escalated']);
        $this->log($complaint, 'escalation', null, 'escalated', $reason);

        foreach ($this->escalationRecipients() as $userId) {
            $this->ping($userId, 'Complaint ESCALATED: '.$complaint->reference, $reason.' — '.Str::limit($complaint->subject, 100), $complaint);
        }
    }

    // ── Intake helpers ───────────────────────────────────────────────────────

    private function applySla(CrmComplaint $complaint, array $settings): void
    {
        if ($complaint->type === 'compliment') {
            return;
        }
        $complaint->respond_by = now()->addHours($settings['respond_hours']);
        $complaint->resolve_by = now()->addHours($settings['resolve_hours']);
    }

    private function autoAssign(CrmComplaint $complaint): void
    {
        // Prefer the manager of the employee complained about; else a sales manager.
        $manager = $complaint->against_employee_id
            ? $this->teams->managerOf((int) $complaint->against_employee_id)
            : null;

        $manager ??= $this->teams->usersWithRole('sales manager')[0] ?? null;

        $complaint->manager_id  = $manager;
        $complaint->assigned_to = $manager;
    }

    private function notifyIntake(CrmComplaint $complaint, array $settings): void
    {
        $title = ($complaint->type === 'compliment' ? 'New compliment: ' : 'New complaint: ').$complaint->reference;
        $body  = ($complaint->severity ? '['.strtoupper($complaint->severity).'] ' : '').Str::limit($complaint->subject, 120);

        $recipients = [];

        // The employee the complaint is about.
        if ($complaint->against_employee_id) {
            $recipients[] = (int) $complaint->against_employee_id;
        }
        // Their manager / the assigned handler.
        if ($complaint->assigned_to)  $recipients[] = (int) $complaint->assigned_to;
        if ($complaint->manager_id)   $recipients[] = (int) $complaint->manager_id;

        // Admins are always informed.
        foreach (array_merge($this->teams->usersWithRole('administrator'), $this->teams->usersWithRole('super admin')) as $id) {
            $recipients[] = $id;
        }

        // Severity-driven escalation to execs/managers on intake.
        $isEscalating = $complaint->type === 'complaint' && in_array($complaint->severity, $settings['escalate_severities'], true);
        if ($isEscalating) {
            foreach ($this->escalationRecipients() as $id) {
                $recipients[] = $id;
            }
        }

        foreach (array_unique($recipients) as $userId) {
            $this->ping($userId, ($isEscalating ? 'High-severity ' : '').$title, $body, $complaint);
        }
    }

    private function acknowledge(CrmComplaint $complaint): void
    {
        if (! $complaint->customer_email || $complaint->type !== 'complaint') {
            return;
        }
        $template = CrmEmailTemplate::byKey('complaint_ack');
        if (! $template) {
            return;
        }
        try {
            $rendered = $template->render([
                'first_name'   => Str::before($complaint->customer_name ?: 'there', ' '),
                'company_name' => \App\Modules\Admin\Setup\Models\CompanyIdentity::systemSettings()['company_name'] ?? config('app.name', 'our company'),
                'subject'      => $complaint->subject,
            ]);
            $this->mail->sendHtml($complaint->customer_email, $rendered['subject'], $rendered['body']);
        } catch (\Throwable $e) {
            Log::warning('Complaint acknowledgement failed', ['complaint' => $complaint->id, 'error' => $e->getMessage()]);
        }
    }

    // ── Utilities ────────────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function employeeNames(): array
    {
        return DB::table('hr_people')->whereNotNull('user_id')
            ->selectRaw("trim(concat(first_name, ' ', surname)) as name")
            ->pluck('name')->filter()->unique()->values()->all();
    }

    private function matchEmployee(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        return DB::table('hr_people')->whereNotNull('user_id')
            ->whereRaw("lower(trim(concat(first_name, ' ', surname))) = ?", [mb_strtolower($name)])
            ->value('user_id')
            ?: DB::table('hr_people')->whereNotNull('user_id')
                ->whereRaw("lower(concat(first_name, ' ', surname)) like ?", ['%'.mb_strtolower($name).'%'])
                ->value('user_id');
    }

    /** @return array<int,int> exec/admin/sales-manager user ids for escalation. */
    private function escalationRecipients(): array
    {
        $ids = [];
        foreach (['sales executive', 'executive', 'administrator', 'super admin', 'sales manager'] as $role) {
            $ids = array_merge($ids, $this->teams->usersWithRole($role));
        }
        return array_values(array_unique($ids));
    }

    private function ping(int $userId, string $title, string $body, CrmComplaint $complaint): void
    {
        $link = '/crm/complaints?open='.$complaint->id;
        $this->notifier->notify($userId, 'complaint', $title, $body, $link, 'complaint', $complaint->id);
        try {
            User::find($userId)?->notify(new GenericInAppNotification(title: $title, message: $body, url: $link, type: 'complaint'));
        } catch (\Throwable) {
        }
    }

    private function log(CrmComplaint $complaint, string $type, ?string $from, ?string $to, ?string $note): void
    {
        CrmComplaintUpdate::create([
            'complaint_id' => $complaint->id,
            'type'         => $type,
            'from_status'  => $from,
            'to_status'    => $to,
            'note'         => $note,
            'user_id'      => Auth::id(),
        ]);
    }

    private function nameOf(int $userId): string
    {
        return optional(User::with('person')->find($userId))->person?->first_name ?: ('User #'.$userId);
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        $count = CrmComplaint::withTrashed()->whereYear('created_at', $year)->count() + 1;
        return 'CMP-'.$year.'-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT);
    }
}
