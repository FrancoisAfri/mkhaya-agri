<?php

namespace App\Modules\Finance\Accounting\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Customer-facing dunning templates, localised to the customer's language. Stored as JSON (the same
 * way the rest of the app is translated) at resources/finance/reminder_templates.json so the set can
 * be translated into every supported language. A company can still override any step's subject/body
 * in settings; this is the built-in fallback. Falls back to English for any missing language/tone.
 */
class ReminderTemplates
{
    public const TONES = ['friendly', 'firm', 'urgent', 'final'];

    private static function all(): array
    {
        return Cache::rememberForever('fin_reminder_templates', function () {
            $path = resource_path('finance/reminder_templates.json');

            return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        });
    }

    /** Resolve a template for a language + tone, rendering {placeholders}. */
    public static function render(string $lang, string $tone, array $vars): array
    {
        $all = self::all();
        $lang = $all[$lang] ?? $all['en'] ?? [];
        $en = $all['en'] ?? [];
        $tpl = $lang[$tone] ?? $en[$tone] ?? $en['friendly'] ?? ['subject' => 'Payment reminder: {number}', 'body' => 'Invoice {number} — {currency} {amount} — is due.', 'sms' => 'Invoice {number} ({currency} {amount}) is due. {pay_link}'];

        return [
            'subject' => self::fill($tpl['subject'] ?? '', $vars),
            'body'    => self::fill($tpl['body'] ?? '', $vars),
            'sms'     => self::fill($tpl['sms'] ?? ($tpl['body'] ?? ''), $vars),
        ];
    }

    private static function fill(string $s, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $s = str_replace('{' . $k . '}', (string) $v, $s);
        }

        return $s;
    }
}
