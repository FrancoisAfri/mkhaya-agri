<?php

namespace App\Modules\Operations\Sheq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Sheq\Services\AccessPassService;
use App\Modules\Operations\Sheq\Services\FileService;
use App\Modules\Operations\Sheq\Support\ResolvesCompany;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The QR pass, and the page a scan opens (CON-11, CON-12, CON-13).
 *
 * Two surfaces with deliberately different rules:
 *
 * **Issuing and printing a pass** needs an account and `sheq.contractor.manage`, like every other
 * write on a contractor record.
 *
 * **Verifying a scanned code** needs no account at all. A guard at a boom holding their own phone
 * cannot be expected to have one, and a check that requires a login is a check that gets replaced
 * by waving people through. The token is the credential: sixty-four random characters, rotatable,
 * and every scan recorded.
 *
 * The verification page is plain server-rendered HTML rather than part of the application shell.
 * It has to load on a bad signal at a gate, in one request, with no bundle and no session — and a
 * verdict that takes four seconds to appear is a verdict nobody waits for.
 */
class AccessPassController extends Controller
{
    use ResolvesCompany;

    public function __construct(private readonly AccessPassService $passes) {}

    /**
     * The QR image for a contractor or one of their workers.
     *
     * SVG rather than PNG: it is a fifth of the size, prints at any resolution on whatever card
     * stock a site uses, and needs no image extension installed on the server.
     */
    public function image(Request $request, string $subject, string $uuid): Response
    {
        $companyId = $this->requireCompany($request);

        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $token = $subject === 'workers'
            ? $this->passes->workerToken($companyId, $uuid, $request->user()?->id)
            : $this->passes->contractorToken($companyId, $uuid, $request->user()?->id);

        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd()));

        return response($writer->writeString($this->verifyUrl($token)), 200, [
            'Content-Type' => 'image/svg+xml',
            // Never cached by a proxy: a rotated card must stop working everywhere at once, and a
            // cached image of the old code would keep being handed out.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * The printable access card — the thing a person actually carries.
     *
     * The QR on its own was never the card. A guard matches the face in front of them to the
     * register, and a code they have to scan to learn anything is no help when the queue is twenty
     * deep and the scanner is flat. So the card prints the photograph, the name, the trade, the
     * site and the project beside the code, and the code stays the live check for when the answer
     * matters.
     *
     * Still nothing but an identifier in the QR: the printed detail is on the card because a
     * guard needs it, and no ID number, medical fact or certificate goes anywhere near it. A card
     * is photographed and forwarded; whatever it carries is published.
     *
     * Plain Blade at CR80 (85.6 × 54 mm) rather than a page in the application shell, for the same
     * reason the verification page is: it has to print identically from any browser, with no
     * bundle, and a card that reflows because a stylesheet did not load is a card nobody trusts.
     */
    public function card(Request $request, string $subject, string $uuid): View
    {
        $companyId = $this->requireCompany($request);

        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $token = $subject === 'workers'
            ? $this->passes->workerToken($companyId, $uuid, $request->user()?->id)
            : $this->passes->contractorToken($companyId, $uuid, $request->user()?->id);

        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 0), new SvgImageBackEnd()));

        $card = $this->passes->cardDetails($companyId, $subject === 'workers'
            ? 'CONTRACTOR_WORKER' : 'CONTRACTOR', $uuid);

        return view('sheq.access-card', [
            'card' => $card,
            // Inlined rather than linked: a card printed from a laptop with no network still has
            // its code, and the image route is behind the session anyway.
            'qr' => $writer->writeString($this->verifyUrl($token)),
            'photo' => $this->photoDataUri($companyId, $card['photo_uuid'] ?? null),
        ]);
    }

    /** Retire a lost card and issue another. */
    public function rotate(Request $request, string $subject, string $uuid): RedirectResponse
    {
        abort_unless($request->user()?->can('sheq.contractor.manage'), 403,
            'ENTITLEMENT_REQUIRED: sheq.contractor.manage');

        $this->passes->rotate($this->requireCompany($request),
            $subject === 'workers' ? 'CONTRACTOR_WORKER' : 'CONTRACTOR', $uuid,
            $request->user()?->id);

        return back()->with('success',
            'A new code has been issued. The previous card stops working immediately.');
    }

    /**
     * What a scan resolves to, live (CON-12).
     *
     * No authentication, by design. The site and the activity may be given, because a scanner at a
     * particular boom knows where it is and what work happens there — and "may they work" has a
     * different answer for lifting than for painting.
     */
    public function verify(Request $request, string $token): View
    {
        $validated = $request->validate([
            'site' => ['nullable', 'integer'],
            'activity' => ['nullable', 'string', 'max:60'],
            'device' => ['nullable', 'string', 'max:80'],
            /*
             * Whether this read is a movement (CON-16).
             *
             * Absent, the scan is a look-up: somebody checking a contractor's file is not an
             * arrival, and treating every read as one would put people on the roll-call who are
             * standing in an office two kilometres away. A scanner at a boom is configured to
             * say which way they are going, and then the same read is the check-in — which is
             * what a gate with a phone and no turnstile actually has.
             */
            'direction' => ['nullable', 'string',
                Rule::in(['ENTRY', 'EXIT', 'CLOCK_IN', 'CLOCK_OUT',
                    'entry', 'exit', 'clock_in', 'clock_out']),
            ],
        ]);

        $result = $this->passes->verify(
            $token,
            $validated['site'] ?? null,
            $validated['activity'] ?? null,
            $validated['device'] ?? null,
            $validated['direction'] ?? null,
            /*
             * Who scanned it, where that is knowable (CON-23). This page is public — a guard at a
             * boom has no session — so the user is usually absent and the address is what there
             * is. Recording the address is what makes a run of unknown codes attributable to one
             * phone rather than looking like nine unrelated events.
             */
            ['user_id' => $request->user()?->id, 'ip' => $request->ip()],
        );

        return view('sheq.access-pass', [
            'result' => $result,
            // Carried on the verdict by the service, which already resolved the tenant. An unknown
            // code has no company on it, so it reveals nothing about which one it was not for.
            'company' => $result['company'] ?? null,
        ]);
    }

    /**
     * The worker's photograph as a data URI, or null.
     *
     * Inlined for the same reason the QR is: the card has to print from a machine that cannot
     * reach the server at that moment, and a broken image on an access card is worse than no
     * photograph — it reads as a card that has been tampered with.
     *
     * A photograph that cannot be read is not an error worth failing the card for. The card is
     * still useful without it, so a missing or unreadable file leaves a placeholder instead.
     */
    private function photoDataUri(int $companyId, ?string $fileUuid): ?string
    {
        if ($fileUuid === null) {
            return null;
        }

        try {
            $shown = app(FileService::class)->display($companyId, $fileUuid);
        } catch (\Throwable) {
            return null;
        }

        $uri = $shown['url'] ?? $shown['src'] ?? null;

        return is_string($uri) && str_starts_with($uri, 'data:image/') ? $uri : null;
    }

    private function verifyUrl(string $token): string
    {
        return url('/sheq/pass/'.$token);
    }
}
