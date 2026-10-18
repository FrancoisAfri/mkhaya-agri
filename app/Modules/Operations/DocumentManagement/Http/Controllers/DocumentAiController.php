<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentAiInsight;
use App\Modules\Operations\DocumentManagement\Services\DocumentFieldExtractor;
use App\Modules\Operations\DocumentManagement\Services\DocumentAiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Summarising a document and asking questions about it.
 *
 * Both are gated on being able to view the document, not on a permission of their own. Neither
 * discloses anything the person could not already read by opening it — and a second permission
 * would mean the people who most need a summary, because they have not read the document, are the
 * ones refused one.
 *
 * What is gated is the document itself: the service refuses confidential and held documents unless
 * the company has said their contents may leave, because that decision belongs to the organisation
 * rather than to whoever happens to be looking at the file.
 */
class DocumentAiController extends Controller
{
    public function __construct(private readonly DocumentAiService $ai)
    {
    }

    public function summarise(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        $result = $this->ai->summarise($document, $request->boolean('refresh'));

        return $result['error']
            ? back()->with('error', $this->reason($result['error']))
            : back()->with('success', __('document_management.ai.summarised'));
    }

    public function ask(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('view', $document);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $result = $this->ai->answer($document, $data['question']);

        return $result['error']
            ? back()->with('error', $this->reason($result['error']))
            : back();
    }

    /**
     * Reads the fields off the document and offers them for confirmation.
     *
     * Nothing is written here. The suggestion is stored and shown; accepting it is a second,
     * deliberate act, because a model's reading of a scanned date is right most of the time and the
     * times it is wrong look exactly the same.
     */
    public function suggestFields(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);

        $result = app(DocumentFieldExtractor::class)->suggest($document, $request->boolean('refresh'));

        return $result['error']
            ? back()->with('error', $this->reason($result['error']))
            : back();
    }

    /** Writes the fields somebody accepted. */
    public function applyFields(Request $request, DocumentAiInsight $insight): RedirectResponse
    {
        $document = $insight->document()->withoutGlobalScopes()->firstOrFail();

        $this->authorize('update', $document);

        $data = $request->validate([
            'accept' => ['required', 'array', 'min:1'],
            'accept.*' => ['string'],
        ]);

        $result = app(DocumentFieldExtractor::class)->apply($insight, $data['accept']);

        return $result['error']
            ? back()->with('error', $this->reason($result['error']))
            : back()->with('success', __('document_management.ai.fields_applied', ['count' => count($result['applied'])]));
    }

    /** Rejects a reading, so the same wrong one is not offered again. */
    public function dismissFields(DocumentAiInsight $insight): RedirectResponse
    {
        $document = $insight->document()->withoutGlobalScopes()->firstOrFail();

        $this->authorize('update', $document);

        app(DocumentFieldExtractor::class)->dismiss($insight);

        return back();
    }

    /**
     * The reason, in words.
     *
     * The codes carry an ai_ prefix because the page renders them as its own translation keys.
     * Stripping it here keeps the PHP side from reading document_management.ai.ai_disabled.
     */
    private function reason(string $code): string
    {
        return __('document_management.ai.' . Str::after($code, 'ai_'));
    }
}
