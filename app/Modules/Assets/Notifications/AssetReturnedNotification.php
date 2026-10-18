<?php

namespace App\Modules\Assets\Notifications;

use App\Modules\Assets\Models\AssetAssignment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AssetReturnedNotification extends Notification
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

        return (new MailMessage)
            ->subject("Asset Return Confirmed: {$assetName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The return of the following asset has been recorded.")
            ->line("**Asset:** {$assetName}" . ($assetTag ? " ({$assetTag})" : ''))
            ->line("**Returned on:** " . ($this->assignment->returned_at?->toFormattedDateString() ?? now()->toFormattedDateString()))
            ->action('View Asset', url(route('assets.show', $asset?->uuid)))
            ->salutation('Mkhaya HR System');
    }

    public function toArray(mixed $notifiable): array
    {
        $asset    = $this->assignment->asset;
        $assetTag = $asset?->asset_tag ?? $asset?->asset_number ?? '';

        return [
            'type'       => 'asset_returned',
            'title'      => 'Asset Return Confirmed',
            'message'    => 'Your return of ' . ($asset?->name ?? 'an asset') . ($assetTag ? " ({$assetTag})" : '') . ' has been recorded.',
            'url'        => $asset?->uuid ? route('assets.show', $asset->uuid) : null,
            'asset_id'   => $asset?->id,
            'asset_name' => $asset?->name,
            'asset_tag'  => $assetTag,
        ];
    }
}
