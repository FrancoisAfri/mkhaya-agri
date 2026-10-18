<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\FleetInspectionAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FleetVisionService
{
    private const VISION_PROVIDERS = ['claude', 'openai'];

    // ── FR-4.2.1 Analyse a single inspection photo ────────────────────────────

    public function analyzePhoto(
        int    $bookingId,
        string $photoPath,
        ?int   $inspectionId = null,
        string $provider = 'openai'
    ): FleetInspectionAnalysis {
        if (!in_array($provider, self::VISION_PROVIDERS)) {
            $provider = 'openai'; // vision requires capable model
        }

        $result = match ($provider) {
            'openai' => $this->callOpenAIVision($photoPath),
            default  => $this->callClaudeVision($photoPath),
        };

        return FleetInspectionAnalysis::create([
            'inspection_id'   => $inspectionId,
            'booking_id'      => $bookingId,
            'photo_path'      => $photoPath,
            'analysis_type'   => 'single',
            'damage_detected' => $result['damage_detected'],
            'damage_type'     => $result['damage_type'] ?? null,
            'confidence'      => $result['confidence'] ?? null,
            'bounding_box'    => $result['bounding_box'] ?? null,
            'ai_description'  => $result['description'] ?? null,
        ]);
    }

    // ── FR-4.2.3 Compare checkout vs check-in photo for new damage ────────────

    public function comparePhotos(
        int    $bookingId,
        string $checkoutPhotoPath,
        string $checkinPhotoPath,
        string $provider = 'openai'
    ): FleetInspectionAnalysis {
        if (!in_array($provider, self::VISION_PROVIDERS)) {
            $provider = 'openai';
        }

        $result = match ($provider) {
            'openai' => $this->callOpenAIComparison($checkoutPhotoPath, $checkinPhotoPath),
            default  => $this->callClaudeComparison($checkoutPhotoPath, $checkinPhotoPath),
        };

        return FleetInspectionAnalysis::create([
            'booking_id'            => $bookingId,
            'photo_path'            => $checkinPhotoPath,
            'reference_photo_path'  => $checkoutPhotoPath,
            'analysis_type'         => 'comparative',
            'damage_detected'       => $result['damage_detected'],
            'damage_type'           => $result['damage_type'] ?? null,
            'confidence'            => $result['confidence'] ?? null,
            'bounding_box'          => $result['bounding_box'] ?? null,
            'new_damage_detected'   => $result['new_damage_detected'],
            'ai_description'        => $result['description'] ?? null,
        ]);
    }

    // ── Claude vision call ─────────────────────────────────────────────────────

    private function callClaudeVision(string $photoPath): array
    {
        $apiKey = config('ai.providers.claude.api_key');
        $model  = config('ai.providers.claude.model', 'claude-3-5-sonnet-20241022');

        [$mediaType, $base64] = $this->encodeImage($photoPath);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 512,
                'system'     => 'You are a vehicle damage detection AI. Respond ONLY with valid JSON.',
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Analyse this vehicle inspection photo for damage. Return JSON: {"damage_detected": bool, "damage_type": "dent"|"scratch"|"crack"|"multiple"|null, "confidence": 0.0-1.0, "bounding_box": {"x":0,"y":0,"w":0,"h":0}|null, "description": "string"}',
                        ],
                    ],
                ]],
            ])->throw()->json();

            $text = $response['content'][0]['text'] ?? '{}';
            return $this->parseVisionJson($text);
        } catch (\Throwable $e) {
            Log::error('Fleet vision (Claude) failed', ['error' => $e->getMessage()]);
            return $this->emptyResult();
        }
    }

    private function callClaudeComparison(string $refPath, string $newPath): array
    {
        $apiKey = config('ai.providers.claude.api_key');
        $model  = config('ai.providers.claude.model', 'claude-3-5-sonnet-20241022');

        [$mt1, $b1] = $this->encodeImage($refPath);
        [$mt2, $b2] = $this->encodeImage($newPath);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $model,
                'max_tokens' => 768,
                'system'     => 'You are a vehicle damage comparison AI. Respond ONLY with valid JSON.',
                'messages'   => [[
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'CHECKOUT photo (before trip):'],
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mt1, 'data' => $b1]],
                        ['type' => 'text', 'text' => 'CHECK-IN photo (after trip):'],
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mt2, 'data' => $b2]],
                        ['type' => 'text', 'text' => 'Compare these two vehicle photos. Identify any NEW damage that appeared during the trip. Return JSON: {"new_damage_detected": bool, "damage_detected": bool, "damage_type": "dent"|"scratch"|"crack"|"multiple"|null, "confidence": 0.0-1.0, "bounding_box": {"x":0,"y":0,"w":0,"h":0}|null, "description": "string"}'],
                    ],
                ]],
            ])->throw()->json();

            $text = $response['content'][0]['text'] ?? '{}';
            return $this->parseVisionJson($text);
        } catch (\Throwable $e) {
            Log::error('Fleet vision comparison (Claude) failed', ['error' => $e->getMessage()]);
            return $this->emptyResult(true);
        }
    }

    // ── OpenAI vision call ─────────────────────────────────────────────────────

    private function callOpenAIVision(string $photoPath): array
    {
        $apiKey = config('ai.providers.openai.api_key');
        $model  = config('ai.providers.openai.model', 'gpt-4o');

        [$mediaType, $base64] = $this->encodeImage($photoPath);

        try {
            $response = Http::withToken($apiKey)->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => $model,
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens'      => 512,
                    'messages'        => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Analyse this vehicle inspection photo for damage. Return JSON: {"damage_detected": bool, "damage_type": "dent"|"scratch"|"crack"|"multiple"|null, "confidence": 0.0-1.0, "bounding_box": {"x":0,"y":0,"w":0,"h":0}|null, "description": "string"}'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mediaType};base64,{$base64}"]],
                        ],
                    ]],
                ])->throw()->json();

            $text = $response['choices'][0]['message']['content'] ?? '{}';
            return $this->parseVisionJson($text);
        } catch (\Throwable $e) {
            Log::error('Fleet vision (OpenAI) failed', ['error' => $e->getMessage()]);
            return $this->emptyResult();
        }
    }

    private function callOpenAIComparison(string $refPath, string $newPath): array
    {
        $apiKey = config('ai.providers.openai.api_key');
        $model  = config('ai.providers.openai.model', 'gpt-4o');

        [$mt1, $b1] = $this->encodeImage($refPath);
        [$mt2, $b2] = $this->encodeImage($newPath);

        try {
            $response = Http::withToken($apiKey)->timeout(45)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => $model,
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens'      => 768,
                    'messages'        => [[
                        'role'    => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'Compare these two vehicle inspection photos. First is BEFORE trip, second is AFTER trip. Identify any NEW damage. Return JSON: {"new_damage_detected": bool, "damage_detected": bool, "damage_type": "dent"|"scratch"|"crack"|"multiple"|null, "confidence": 0.0-1.0, "bounding_box": null|{"x":0,"y":0,"w":0,"h":0}, "description": "string"}'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mt1};base64,{$b1}", 'detail' => 'high']],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:{$mt2};base64,{$b2}", 'detail' => 'high']],
                        ],
                    ]],
                ])->throw()->json();

            $text = $response['choices'][0]['message']['content'] ?? '{}';
            return $this->parseVisionJson($text);
        } catch (\Throwable $e) {
            Log::error('Fleet vision comparison (OpenAI) failed', ['error' => $e->getMessage()]);
            return $this->emptyResult(true);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function encodeImage(string $storagePath): array
    {
        // Files are stored as JSON via FileStorage trait: {uuid, mime, type, data (base64), ...}
        $decoded = json_decode($storagePath, true);
        if (is_array($decoded) && isset($decoded['data'], $decoded['mime'])) {
            return [$decoded['mime'], $decoded['data']];
        }

        // Fallback: plain filesystem path (legacy)
        $fullPath = Storage::disk('public')->path($storagePath);
        $mime     = mime_content_type($fullPath) ?: 'image/jpeg';
        $base64   = base64_encode(file_get_contents($fullPath));
        return [$mime, $base64];
    }

    private function parseVisionJson(string $text): array
    {
        // Strip markdown fences if present
        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));

        $data = json_decode($clean, true);

        if (!is_array($data)) {
            return $this->emptyResult();
        }

        return [
            'damage_detected'     => (bool) ($data['damage_detected'] ?? false),
            'new_damage_detected' => (bool) ($data['new_damage_detected'] ?? false),
            'damage_type'         => $data['damage_type'] ?? null,
            'confidence'          => isset($data['confidence']) ? min(1, max(0, (float) $data['confidence'])) : null,
            'bounding_box'        => $data['bounding_box'] ?? null,
            'description'         => $data['description'] ?? null,
        ];
    }

    private function emptyResult(bool $comparative = false): array
    {
        return [
            'damage_detected'     => false,
            'new_damage_detected' => false,
            'damage_type'         => null,
            'confidence'          => null,
            'bounding_box'        => null,
            'description'         => 'Analysis unavailable',
        ];
    }

    public function availableProviders(): array
    {
        return collect(self::VISION_PROVIDERS)
            ->filter(fn($p) => filled(config("ai.providers.{$p}.api_key")))
            ->values()
            ->toArray();
    }
}
