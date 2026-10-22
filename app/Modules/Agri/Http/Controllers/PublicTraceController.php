<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Agri\Services\PublicTrace;
use Illuminate\Http\Response;

/**
 * The consumer trace page behind a pack's QR code: public, no login, a light server-rendered page
 * (no application script), cached (server-side story cache, and briefly in the browser), rate-limited by its route, and kept out of search engines.
 */
class PublicTraceController extends Controller
{
    public function show(string $token, PublicTrace $trace): Response
    {
        $story = $trace->publicStory($token);
        abort_if($story === null, 404);

        return response()->view('agri.trace', ['s' => $story], 200)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, max-age=300')   // the story itself is cached on the server; no shared cache holds a session cookie
            ->header('Referrer-Policy', 'no-referrer');
    }
}
