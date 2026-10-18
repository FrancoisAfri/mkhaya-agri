<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Concerns\ServesDocumentBytes;
use App\Modules\Operations\DocumentManagement\Models\DocumentEvent;
use App\Modules\Operations\DocumentManagement\Models\DocumentShare;
use App\Modules\Operations\DocumentManagement\Models\DocumentShareAccess;
use App\Modules\Operations\DocumentManagement\Services\DocumentService;
use App\Modules\Operations\DocumentManagement\Services\ShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A shared document, opened by somebody with no account here.
 *
 * The single most exposed surface in the module, and written accordingly.
 *
 * Every failure looks the same. A token that never existed, one that expired, one that was revoked
 * and one that has been used up all produce the same 404. Telling them apart tells whoever is
 * working through guesses which of them was once real, and "expired" on a wrong guess is a
 * confirmation that the guess was right.
 *
 * The tenant is pinned from the share as the first thing that happens after the token resolves.
 * Without it every later query runs with no company set, which row-level security reads as "no
 * restriction" — the state where one bad join reaches another organisation's documents. The token
 * decides the tenant; nothing else on this request is trusted to.
 */
class PublicDocumentShareController extends Controller
{
    use ServesDocumentBytes;

    public function __construct(
        private readonly ShareService $shares,
        private readonly DocumentService $documents,
    ) {
    }

    /** The landing page: what the document is, and either a password prompt or the document. */
    public function show(Request $request, string $token): Response
    {
        $share = $this->pinned($token);
        $document = $this->documentFor($share);

        /*
         * Two gates, and the code comes second on purpose. Sending an email the moment anybody
         * loads the page would make a link that has gone astray into a way of pestering the person
         * it was meant for — the password, where there is one, is answered first.
         */
        $needsPassword = $share->needsPassword() && ! $this->unlocked($request, $share);
        $needsCode = ! $needsPassword && $share->needsEmailCode() && ! $this->verified($request, $share);

        if ($needsPassword || $needsCode) {
            return Inertia::render('Modules/DocumentManagement/Pages/SharedDocument', [
                'token' => $token,
                'locked' => true,
                // Nothing about the document is disclosed before the password is given. A title is
                // often the sensitive part — "Disciplinary hearing — J. Dlamini" needs no file.
                'document' => null,
                'share' => null,
                'gate' => $needsPassword ? 'password' : 'code',
                // Masked: enough for the right person to recognise their own address, not enough
                // for the wrong person to learn it.
                'recipient' => $needsCode ? $share->maskedRecipient() : null,
                'code_sent' => $needsCode && $share->code_expires_at !== null && $share->code_expires_at->isFuture(),
            ]);
        }

        $this->shares->recordAccess($share, DocumentShareAccess::VIEWED, $request);

        // Written into the document's own history too, so one timeline answers what happened to it.
        $this->documents->record($document, DocumentEvent::SHARE_ACCESSED, null, [
            'share' => $share->uuid,
            'ip' => $request->ip(),
        ]);

        return Inertia::render('Modules/DocumentManagement/Pages/SharedDocument', [
            'token' => $token,
            'locked' => false,
            'document' => [
                'title' => $document->title,
                'description' => $document->description,
                'extension' => $document->currentVersion?->extension,
                'size' => $document->currentVersion?->size,
                // Inline preview only where a browser can render it safely inside the sandbox the
                // stream sets. Anything else is offered as a download or not at all.
                'previewable' => in_array($document->currentVersion?->extension, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'], true),
            ],
            'share' => [
                'allow_download' => $share->allow_download,
                'expires_at' => $share->expires_at?->toFormattedDateString(),
                'note' => $share->note,
            ],
        ]);
    }

    /** Answering the password prompt. */
    public function unlock(Request $request, string $token): RedirectResponse
    {
        $share = $this->pinned($token);

        $request->validate(['password' => ['required', 'string']]);

        if (! $this->shares->passwordMatches($share, $request->input('password'))) {
            return back()->withErrors(['password' => __('document_management.sharing.wrong_password')]);
        }

        // Kept in the session rather than re-asked on every request, and keyed to the share so
        // unlocking one link does not unlock another that happens to share a browser.
        $request->session()->put($this->sessionKey($share), true);

        return redirect()->route('documents.shared.show', $token);
    }

    /** Asks for a code to be sent to the address the link was made out to. */
    public function sendCode(Request $request, string $token): RedirectResponse
    {
        $share = $this->pinned($token);

        if (! $share->needsEmailCode()) {
            return redirect()->route('documents.shared.show', $token);
        }

        $result = $this->shares->sendCode($share);

        return $result['sent']
            ? back()->with('success', __('document_management.sharing.code_sent'))
            : back()->withErrors(['code' => $result['error'] ?? __('document_management.sharing.code_too_soon')]);
    }

    /** Answering the code prompt. */
    public function verifyCode(Request $request, string $token): RedirectResponse
    {
        $share = $this->pinned($token);

        $request->validate(['code' => ['required', 'string', 'max:12']]);

        $result = $this->shares->verifyCode($share, $request->input('code'));

        if (! $result['ok']) {
            return back()->withErrors(['code' => $result['error']]);
        }

        /*
         * Held in the session, like the password. Recording it only on the share would mean the
         * link opens for anybody at all once the real recipient has verified it once, which is
         * exactly the failure the code exists to prevent.
         */
        $request->session()->put($this->verifiedKey($share), true);

        return redirect()->route('documents.shared.show', $token);
    }

    private function verified(Request $request, DocumentShare $share): bool
    {
        return (bool) $request->session()->get($this->verifiedKey($share), false);
    }

    private function verifiedKey(DocumentShare $share): string
    {
        return 'document_share_verified:' . $share->uuid;
    }

    /** The file itself, inline. */
    public function view(Request $request, string $token): HttpResponse
    {
        return $this->serve($request, $token, inline: true);
    }

    /** The file as a download, if the share was issued allowing one. */
    public function download(Request $request, string $token): HttpResponse
    {
        return $this->serve($request, $token, inline: false);
    }

    private function serve(Request $request, string $token, bool $inline): HttpResponse
    {
        $share = $this->pinned($token);

        if ($share->needsPassword() && ! $this->unlocked($request, $share)) {
            throw new NotFoundHttpException();
        }

        /*
         * Enforced here as well as on the landing page, because this is the route that serves the
         * bytes. A gate that only the landing page honours is a gate somebody walks around by
         * pasting the /view URL, and they would have that URL from the page's own markup.
         */
        if ($share->needsEmailCode() && ! $this->verified($request, $share)) {
            throw new NotFoundHttpException();
        }

        /*
         * View-only means view-only. Refused as a 404 rather than a 403: the link's holder has no
         * business knowing that a download route exists behind a share that does not permit one.
         */
        if (! $inline && ! $share->allow_download) {
            throw new NotFoundHttpException();
        }

        $document = $this->documentFor($share);
        $version = $document->currentVersion;

        if (! $version) {
            throw new NotFoundHttpException();
        }

        $this->shares->recordAccess(
            $share,
            $inline ? DocumentShareAccess::VIEWED : DocumentShareAccess::DOWNLOADED,
            $request,
        );

        /*
         * The stamp is applied to the copy being served and never to the stored file. The document
         * in the repository is the record; this is a derivative that exists for the length of one
         * response.
         *
         * A file that cannot be stamped is served plain rather than refused. A watermark is not a
         * security control — anybody determined can remove one — so withholding a document the
         * sender chose to share, because it happens to be a spreadsheet, would trade something real
         * for something decorative.
         */
        if ($share->watermark) {
            $watermarks = app(\App\Modules\Operations\DocumentManagement\Services\WatermarkService::class);

            if ($watermarks->supports((string) $version->mime, (string) $version->extension)) {
                $bytes = $this->documents->contents($version);

                $stamped = $bytes === null ? null : $watermarks->apply(
                    $bytes,
                    (string) $version->mime,
                    (string) $version->extension,
                    $watermarks->textFor($share->recipient_email, $this->companyName()),
                );

                if ($stamped !== null) {
                    return $this->streamBytes($stamped, $version, $inline);
                }
            }
        }

        return $this->streamVersion($this->documents, $version, $inline);
    }

    /**
     * Resolves the token and sets the tenant for the rest of the request.
     *
     * Both halves matter. The resolve is the authentication; the pin is what stops everything after
     * it from running unscoped.
     */
    private function pinned(string $token): DocumentShare
    {
        $share = $this->shares->resolve($token);

        if (! $share || $share->company_id === null) {
            // A share with no company cannot be scoped to one, so it is not served at all rather
            // than served with row-level security switched off.
            throw new NotFoundHttpException();
        }

        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $share->company_id]);
        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set((int) $share->company_id);

        return $share;
    }

    /**
     * The document behind the share, refused if it has since been withdrawn.
     *
     * A link issued last month must not keep serving a document that has been archived, deleted or
     * put under legal hold since. The share was a decision about the document as it stood then.
     */
    private function documentFor(DocumentShare $share)
    {
        $document = $share->document()->with('currentVersion')->first();

        if (! $document || $document->trashed() || $document->isUnderLegalHold()) {
            throw new NotFoundHttpException();
        }

        return $document;
    }

    /** The organisation's name for the stamp, where there is one to use. */
    private function companyName(): ?string
    {
        $id = app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id();

        return $id ? DB::table('companies')->where('id', $id)->value('name') : null;
    }

    private function unlocked(Request $request, DocumentShare $share): bool
    {
        return (bool) $request->session()->get($this->sessionKey($share), false);
    }

    private function sessionKey(DocumentShare $share): string
    {
        return 'document_share_unlocked:' . $share->uuid;
    }
}
