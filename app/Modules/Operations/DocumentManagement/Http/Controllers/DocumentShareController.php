<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentSetting;
use App\Modules\Operations\DocumentManagement\Models\DocumentShare;
use App\Modules\Operations\DocumentManagement\Services\ShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Issuing and withdrawing the links that let somebody outside read a document.
 *
 * The link is shown to the person who created it, once, on the redirect back. It is not stored in
 * a form this controller could show again — reissuing is a new link, which is also the honest
 * behaviour: a link somebody has lost may not be lost at all.
 */
class DocumentShareController extends Controller
{
    public function __construct(private readonly ShareService $shares)
    {
    }

    public function store(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('share', $document);

        if (! DocumentSetting::current()->sharing_enabled) {
            return back()->with('error', __('document_management.sharing.disabled'));
        }

        $data = $request->validate([
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'password' => ['nullable', 'string', 'min:6', 'max:200'],
            'allow_download' => ['nullable', 'boolean'],
            'require_email_code' => ['nullable', 'boolean'],
            'watermark' => ['nullable', 'boolean'],
            'max_downloads' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $result = $this->shares->create($document, [
            'recipient_email' => $data['recipient_email'] ?? null,
            'note' => $data['note'] ?? null,
            'expires_days' => $data['expires_days'] ?? null,
            'password' => $data['password'] ?? null,
            'allow_download' => $request->boolean('allow_download'),
            'require_email_code' => $request->boolean('require_email_code'),
            'watermark' => $request->has('watermark') ? $request->boolean('watermark') : null,
            'max_downloads' => $data['max_downloads'] ?? null,
        ]);

        /*
         * Flashed rather than rendered into the page's props. The link is a credential, and props
         * are serialised into the HTML of every subsequent Inertia response on this page — a flash
         * is read once and gone.
         */
        return back()->with('share_link', route('documents.shared.show', $result['token']))
            ->with('success', __('document_management.sharing.created'));
    }

    public function destroy(DocumentShare $share): RedirectResponse
    {
        $document = $share->document()->withoutGlobalScopes()->firstOrFail();

        $this->authorize('share', $document);

        $this->shares->revoke($share);

        return back()->with('success', __('document_management.sharing.revoked'));
    }
}
