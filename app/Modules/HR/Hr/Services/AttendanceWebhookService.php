<?php

namespace App\Modules\HR\Hr\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AttendanceWebhookService
{
    /**
     * Send an alert to a Microsoft Teams incoming webhook.
     * Payload is an Adaptive Card compatible with Teams Connector format.
     */
    public function sendTeams(string $webhookUrl, string $title, string $body, ?string $url = null): void
    {
        $facts = [];
        if ($url) {
            $facts[] = ['name' => 'Link', 'value' => url($url)];
        }

        $payload = [
            '@type'      => 'MessageCard',
            '@context'   => 'https://schema.org/extensions',
            'themeColor' => 'E53935',
            'summary'    => $title,
            'sections'   => [[
                'activityTitle'    => $title,
                'activitySubtitle' => 'Mkhaya HR — Attendance Alert',
                'activityText'     => $body,
                'facts'            => $facts,
            ]],
        ];

        if ($url) {
            $payload['potentialAction'] = [[
                '@type' => 'OpenUri',
                'name'  => 'View in Mkhaya HR',
                'targets' => [['os' => 'default', 'uri' => url($url)]],
            ]];
        }

        $this->send($webhookUrl, $payload, 'Teams');
    }

    /**
     * Send an alert to a Slack incoming webhook using Block Kit.
     */
    public function sendSlack(string $webhookUrl, string $title, string $body, ?string $url = null): void
    {
        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => $title, 'emoji' => false],
            ],
            [
                'type'   => 'section',
                'text'   => ['type' => 'mrkdwn', 'text' => $body],
            ],
        ];

        if ($url) {
            $blocks[] = [
                'type'     => 'actions',
                'elements' => [[
                    'type'  => 'button',
                    'text'  => ['type' => 'plain_text', 'text' => 'View in Mkhaya HR'],
                    'url'   => url($url),
                    'style' => 'primary',
                ]],
            ];
        }

        $this->send($webhookUrl, ['blocks' => $blocks], 'Slack');
    }

    private function send(string $url, array $payload, string $provider): void
    {
        try {
            $response = Http::timeout(8)->post($url, $payload);

            if (!$response->successful()) {
                Log::warning("{$provider} webhook failed", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("{$provider} webhook exception", ['message' => $e->getMessage()]);
        }
    }
}
