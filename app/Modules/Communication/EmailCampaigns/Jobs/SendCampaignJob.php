<?php

namespace App\Modules\Communication\EmailCampaigns\Jobs;

use App\Core\CompanyUnits\Models\CompanyUnit;
use App\Core\User\Models\User;
use App\Modules\Communication\EmailCampaigns\Mail\CampaignMail;
use App\Modules\Communication\EmailCampaigns\Models\CampaignRecipient;
use App\Modules\Communication\EmailCampaigns\Models\EmailCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(public readonly EmailCampaign $campaign) {}

    public function handle(): void
    {
        $campaign = $this->campaign;

        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            return;
        }

        $campaign->update(['status' => 'sending']);

        $recipients = $this->resolveRecipients($campaign);

        $campaign->update(['total_recipients' => count($recipients)]);

        foreach ($recipients as $r) {
            $recipient = CampaignRecipient::firstOrCreate(
                ['campaign_id' => $campaign->id, 'email' => $r['email']],
                ['name' => $r['name'], 'user_id' => $r['user_id'] ?? null],
            );

            try {
                Mail::to($r['email'])->send(new CampaignMail($campaign, $recipient));
                $recipient->update(['sent_at' => now()]);
                $campaign->increment('sent_count');
            } catch (\Throwable) {
                // Continue to next recipient on individual failure
            }
        }

        $campaign->update(['status' => 'sent', 'sent_at' => now()]);
    }

    private function resolveRecipients(EmailCampaign $campaign): array
    {
        return match ($campaign->audience_type) {
            'department' => $this->byDepartment($campaign->audience_filter['department_ids'] ?? []),
            'custom'     => $this->fromCustomList($campaign->audience_filter['emails'] ?? []),
            default      => $this->allActiveUsers(),
        };
    }

    private function allActiveUsers(): array
    {
        return User::where('status', 1)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->toArray();
    }

    private function byDepartment(array $unitIds): array
    {
        if (empty($unitIds)) return $this->allActiveUsers();

        return User::where('status', 1)
            ->whereNotNull('email')
            ->whereHas('companyUnits', fn ($q) => $q->whereIn('company_units.id', $unitIds))
            ->get(['users.id', 'users.name', 'users.email'])
            ->map(fn ($u) => ['user_id' => $u->id, 'name' => $u->name, 'email' => $u->email])
            ->toArray();
    }

    private function fromCustomList(array $rawEmails): array
    {
        return collect($rawEmails)
            ->filter(fn ($e) => filter_var(trim($e), FILTER_VALIDATE_EMAIL))
            ->map(fn ($e) => [
                'user_id' => null,
                'name'    => explode('@', trim($e))[0],
                'email'   => trim($e),
            ])
            ->values()
            ->toArray();
    }
}
