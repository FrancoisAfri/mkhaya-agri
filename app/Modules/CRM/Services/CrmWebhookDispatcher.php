<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmWebhook;
use App\Modules\CRM\Models\CrmWebhookDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Outbound webhook delivery (spec section 5).
 *
 * For an event, every active subscribed endpoint receives a POST with the JSON
 * payload and an HMAC-SHA256 signature header (X-Crm-Signature) computed from
 * the raw body and the webhook secret, so receivers can verify authenticity.
 * Each attempt is logged. Delivery is synchronous with a short timeout and never
 * throws into the caller.
 */
class CrmWebhookDispatcher
{
    public function dispatch(string $event, Model $entity): void
    {
        $webhooks = CrmWebhook::where('is_active', true)->get()
            ->filter(fn ($w) => in_array($event, $w->events ?? [], true));

        if ($webhooks->isEmpty()) return;

        $payload = [
            'event'     => $event,
            'entity'    => $this->entityType($entity),
            'id'        => $entity->getKey(),
            'data'      => $this->serialise($entity),
            'timestamp' => now()->toIso8601String(),
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        foreach ($webhooks as $webhook) {
            $this->deliver($webhook, $event, $payload, $body);
        }
    }

    private function deliver(CrmWebhook $webhook, string $event, array $payload, string $body): void
    {
        $signature = hash_hmac('sha256', $body, $webhook->secret);
        $status    = null;
        $success   = false;
        $error     = null;

        try {
            $response = Http::withHeaders([
                'Content-Type'    => 'application/json',
                'X-Crm-Event'     => $event,
                'X-Crm-Signature' => 'sha256=' . $signature,
                'User-Agent'      => 'Mkhaya-CRM-Webhook/1.0',
            ])->timeout(8)->withBody($body, 'application/json')->post($webhook->url);

            $status  = $response->status();
            $success = $response->successful();
            if (! $success) {
                $error = 'HTTP ' . $status;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            Log::warning('CRM webhook delivery failed', ['webhook' => $webhook->id, 'error' => $error]);
        }

        try {
            CrmWebhookDelivery::create([
                'webhook_id'  => $webhook->id,
                'event'       => $event,
                'payload'     => $payload,
                'status_code' => $status,
                'success'     => $success,
                'error'       => $error,
            ]);
            $webhook->forceFill(['last_triggered_at' => now()])->saveQuietly();
        } catch (\Throwable $e) {
            Log::warning('CRM webhook delivery log failed', ['error' => $e->getMessage()]);
        }
    }

    public static function newSecret(): string
    {
        return Str::random(48);
    }

    private function serialise(Model $entity): array
    {
        return collect($entity->getAttributes())
            ->except(['created_by', 'deleted_at'])
            ->toArray();
    }

    private function entityType(Model $entity): string
    {
        return match (class_basename($entity)) {
            'CrmLead'     => 'lead',
            'CrmContact'  => 'contact',
            'CrmAccount'  => 'account',
            'CrmDeal'     => 'deal',
            'CrmActivity' => 'activity',
            default       => Str::snake(class_basename($entity)),
        };
    }
}
