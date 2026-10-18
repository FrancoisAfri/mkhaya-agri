<?php

namespace App\Modules\Assets\Notifications;

use App\Modules\Assets\Models\AssetAssignment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssetAssignedNotification extends Notification
{
    public function __construct(private readonly AssetAssignment $assignment) {}

    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $asset     = $this->assignment->asset;
        $assetName = $asset?->name ?? 'Asset';
        $assetTag  = $asset?->asset_tag ?? $asset?->asset_number ?? '';
        $dueDate   = $this->assignment->return_due_date?->toFormattedDateString();

        $mail = (new MailMessage)
            ->subject("Asset Allocated: {$assetName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("An asset has been allocated to you.")
            ->line("**Asset:** {$assetName}" . ($assetTag ? " ({$assetTag})" : ''))
            ->line("**Type:** " . ($this->assignment->assignment_type->label() ?? 'N/A'))
            ->line("**Effective:** " . ($this->assignment->effective_date?->toFormattedDateString() ?? now()->toFormattedDateString()));

        if ($dueDate) {
            $mail->line("**Return due:** {$dueDate}");
        }

        $mail->action('View Asset', url(route('assets.show', $asset?->uuid)));

        return $mail->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $asset    = $this->assignment->asset;
        $assetTag = $asset?->asset_tag ?? $asset?->asset_number ?? '';

        return [
            'type'        => 'asset_assigned',
            'title'       => 'Asset Allocated to You',
            'message'     => ($asset?->name ?? 'An asset') . ($assetTag ? " ({$assetTag})" : '') . ' has been allocated to you.',
            'url'         => $asset?->uuid ? route('assets.show', $asset->uuid) : null,
            'asset_id'    => $asset?->id,
            'asset_name'  => $asset?->name,
            'asset_tag'   => $assetTag,
            'due_date'    => $this->assignment->return_due_date?->toDateString(),
        ];
    }
}
