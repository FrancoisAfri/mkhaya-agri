<?php

namespace App\Shared\DTOs;

class AgentResponse
{
    public function __construct(
        public readonly string  $content,
        public readonly string  $provider,
        public readonly string  $model,
        public readonly int     $promptTokens,
        public readonly int     $completionTokens,
        public readonly int     $totalTokens,
        public readonly int     $durationMs,
        public readonly bool    $success,
        public readonly ?string $errorMessage = null,
        public readonly array   $raw          = [],
    ) {}

    public function isJson(): bool
    {
        json_decode($this->content);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Decode the response content to an array. Tolerant of the ways models wrap JSON: a strict
     * decode is tried first, then markdown ```json fences are stripped, then the first {...}/[...]
     * block is extracted from any surrounding prose. Strictly additive — valid JSON still decodes
     * exactly as before; previously-unparseable (fenced/wrapped) responses now decode instead of
     * silently returning []. Used by every AI JSON caller (askForJson).
     */
    public function toArray(): array
    {
        $decoded = json_decode($this->content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $text = trim($this->content);
        if ($text === '') {
            return [];
        }

        // Strip a surrounding markdown code fence (```json … ``` or ``` … ```).
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/is', $text, $m)) {
            $text = trim($m[1]);
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Extract the first JSON object/array spanning to its matching last bracket.
        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $close = $text[$start] === '{' ? '}' : ']';
            $end = strrpos($text, $close);
            if ($end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    public function failed(): bool
    {
        return ! $this->success;
    }

    public static function failure(string $reason, string $provider): self
    {
        return new self(
            content          : '',
            provider         : $provider,
            model            : 'unknown',
            promptTokens     : 0,
            completionTokens : 0,
            totalTokens      : 0,
            durationMs       : 0,
            success          : false,
            errorMessage     : $reason,
        );
    }
}
