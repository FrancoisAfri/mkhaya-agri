<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * The locales this installation ships, which is what the language picker offers.
     *
     * Read from the `lang/` directories that are present rather than listed here. It was a literal
     * array of five while `i18n.ts` offered twelve, so somebody who chose isiXhosa got isiXhosa
     * from Vue and English from everything the server renders — validation messages, mailed
     * notifications, the public access-pass page, PDF exports — with nothing to say why half the
     * screen changed language and half did not.
     *
     * Deriving it means the two can no longer disagree: the front end discovers its languages from
     * the locale files it has, this discovers them from the translation directories, and removing
     * or restoring a language updates both without an edit. LocaleCoverageTest holds them in step.
     *
     * @var list<string>|null
     */
    private static ?array $supported = null;

    /** @return list<string> */
    public static function supported(): array
    {
        if (self::$supported !== null) {
            return self::$supported;
        }

        $directories = glob(lang_path('*'), GLOB_ONLYDIR) ?: [];

        $codes = array_values(array_filter(
            array_map('basename', $directories),
            // Laravel keeps `vendor` under lang/ for package translations; it is not a language.
            fn (string $name) => $name !== 'vendor',
        ));

        sort($codes);

        // English is the fallback and must always be offered, even on an install whose lang/
        // directory has been emptied.
        return self::$supported = array_values(array_unique(array_merge(['en'], $codes)));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);
        App::setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        // 1. Authenticated user's saved preference
        if ($user = $request->user()) {
            $saved = $user->locale ?? null;
            if ($saved && in_array($saved, self::supported(), true)) {
                return $saved;
            }
        }

        // 2. Session (set after login before profile loads)
        $session = $request->session()->get('locale');
        if ($session && in_array($session, self::supported(), true)) {
            return $session;
        }

        // 3. Accept-Language header fallback
        $browser = substr($request->header('Accept-Language', 'en'), 0, 2);
        if (in_array($browser, self::supported(), true)) {
            return $browser;
        }

        return 'en';
    }
}
