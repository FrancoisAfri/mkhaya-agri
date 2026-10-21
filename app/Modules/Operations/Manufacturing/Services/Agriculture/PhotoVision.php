<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\HR\Leave\Models\AiUsageLog;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Cycle;
use App\Modules\Operations\Manufacturing\Models\Agriculture\PhotoAnalysis;
use App\Modules\Tenancy\Services\AiQuota;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Computer vision on production photos — birds, litter, lesions, leaves, fish, product — by Claude,
 * which reads images. What it sees is a prompt for a person to look, with how sure it is; it never
 * diagnoses, never prescribes, and its answer is kept with the photo (in the database) and the model.
 *
 * Every call is inside the organisation's AI quota and logged with its tokens, like every other AI
 * call. Without the Claude key, or with the quota spent, the photo is kept for a person to review.
 */
class PhotoVision
{
    public const SUBJECTS = ['birds', 'litter', 'droppings', 'crop', 'fish', 'animals', 'product', 'other'];

    public const MAX_BYTES = 5_000_000;

    public function analyse(Cycle $cycle, string $subject, string $mime, string $base64, ?string $question, User $actor): PhotoAnalysis
    {
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw ValidationException::withMessages(['photo' => 'Send a JPEG, PNG, WebP or GIF photo.']);
        }
        if (strlen($base64) * 3 / 4 > self::MAX_BYTES) {
            throw ValidationException::withMessages(['photo' => 'The photo is larger than 5 MB.']);
        }
        $photo = PhotoAnalysis::create(['company_id' => $cycle->company_id, 'cycle_id' => $cycle->id, 'unit_id' => $cycle->unit_id,
            'subject' => in_array($subject, self::SUBJECTS, true) ? $subject : 'other', 'question' => $question ? mb_strimwidth($question, 0, 500) : null,
            'mime' => $mime, 'image_data' => $base64, 'status' => 'pending', 'created_by' => $actor->id]);

        $key = config('ai.chat.providers.anthropic.key') ?: env('ANTHROPIC_API_KEY');
        $model = (string) config('manufacturing.agriculture.vision_model', env('CLAUDE_MODEL', 'claude-haiku-4-5'));
        if (! $key) {
            $photo->update(['status' => 'unavailable', 'findings' => ['note' => 'Photo kept for review: the vision model is not connected (no Claude key).']]);

            return $photo;
        }
        try {
            app(AiQuota::class)->ensureWithinQuota();
        } catch (\Throwable $e) {
            $photo->update(['status' => 'unavailable', 'findings' => ['note' => 'Photo kept for review: the organisation\'s AI allowance is used up.']]);

            return $photo;
        }
        $def = $cycle->definition();
        $started = microtime(true);
        try {
            $r = Http::timeout(60)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->post('https://api.anthropic.com/v1/messages', [
                'model' => $model, 'max_tokens' => 800,
                'system' => 'You look at photos from farms for a production manager. Describe what is visible and what it could indicate, with honest confidence. '
                    .'You never diagnose disease, never name a medicine or dose, and you say when the photo cannot show something. Reply only with JSON.',
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]],
                    ['type' => 'text', 'text' => "Production: {$def['name']} ({$def['category']}), stage {$cycle->stage}, day ".($cycle->ageDays() ?? '?').". Photo of: {$subject}."
                        .($question ? " Question: {$question}" : '')
                        .' Reply as JSON: {"observations":[string],"possible_issues":[{"issue":string,"confidence":"low"|"medium"|"high","look_for":string}],"urgent":boolean,"image_quality":"good"|"poor","caveat":string}'],
                ]]],
            ]);
            $text = (string) ($r->json('content.0.text') ?? '');
            $json = json_decode(trim(preg_replace('/^```(json)?|```$/m', '', $text)), true);
            $usage = $r->json('usage') ?? [];
            AiUsageLog::create(['user_id' => $actor->id, 'company_id' => $cycle->company_id, 'feature' => 'manufacturing.photo_vision', 'model_used' => $model,
                'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0), 'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'total_tokens' => (int) (($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)), 'input_summary' => "photo of {$subject}, cycle {$cycle->code}",
                'output_summary' => mb_strimwidth($text, 0, 200), 'reference_type' => PhotoAnalysis::class, 'reference_id' => $photo->id,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000), 'success' => $r->successful() && is_array($json), 'created_at' => now()]);
            if (! $r->successful() || ! is_array($json)) {
                throw new \RuntimeException($r->successful() ? 'The answer was not readable.' : 'Claude answered '.$r->status());
            }
            $findings = ['observations' => array_slice(array_map('strval', (array) ($json['observations'] ?? [])), 0, 8),
                'possible_issues' => array_slice(array_values(array_filter(array_map(fn ($i) => is_array($i) && ! empty($i['issue']) ? ['issue' => mb_strimwidth((string) $i['issue'], 0, 160),
                    'confidence' => in_array($i['confidence'] ?? '', ['low', 'medium', 'high'], true) ? $i['confidence'] : 'low', 'look_for' => mb_strimwidth((string) ($i['look_for'] ?? ''), 0, 300)] : null,
                    (array) ($json['possible_issues'] ?? [])))), 0, 5),
                'urgent' => (bool) ($json['urgent'] ?? false), 'image_quality' => ($json['image_quality'] ?? 'good') === 'poor' ? 'poor' : 'good',
                'caveat' => mb_strimwidth((string) ($json['caveat'] ?? ''), 0, 400), 'note' => 'What the model saw — not a diagnosis. A person decides.'];
            $photo->update(['status' => 'done', 'findings' => $findings, 'model' => $model]);
        } catch (\Throwable $e) {
            $photo->update(['status' => 'failed', 'findings' => ['note' => 'Photo kept for review: '.mb_strimwidth($e->getMessage(), 0, 200)]]);
        }

        return $photo->fresh();
    }
}
