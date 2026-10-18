<?php

namespace App\Modules\Workflow\Support;

/**
 * Fills {{field}} placeholders from request data (notifications, comments, documents). Values are
 * inserted as text — never evaluated — and fields marked sensitive are masked.
 */
final class Template
{
    public static function render(?string $text, array $data, array $sensitive = []): string
    {
        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}\}/', function ($m) use ($data, $sensitive) {
            if (in_array($m[1], $sensitive, true)) {
                return '••••';
            }
            $v = data_get($data, $m[1]);

            return is_array($v) ? implode(', ', array_map(fn ($x) => is_array($x) ? json_encode($x) : (string) $x, $v)) : (string) ($v ?? '');
        }, (string) $text);
    }
}
