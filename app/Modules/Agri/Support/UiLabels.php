<?php

namespace App\Modules\Agri\Support;

/**
 * The interface's English labels for pages rendered on the server (the public trace page, PDF
 * labels and export summaries), read from the same file the Vue screens use, so every label lives
 * in resources/js/utils/locales/modules/en.json only.
 */
final class UiLabels
{
    private static ?array $labels = null;

    /** A label by dotted key (e.g. "agri.trace_public.title"), with {name} placeholders filled. */
    public static function get(string $key, array $params = []): string
    {
        self::$labels ??= json_decode((string) @file_get_contents(resource_path('js/utils/locales/modules/en.json')), true) ?: [];
        $v = data_get(self::$labels, $key);
        if (! is_string($v)) {
            return $key;
        }
        foreach ($params as $k => $p) {
            $v = str_replace('{'.$k.'}', (string) $p, $v);
        }

        return $v;
    }
}
