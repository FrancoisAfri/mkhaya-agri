<?php

namespace App\Modules\Finance\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinDocumentTemplate;
use App\Modules\Finance\Accounting\Services\FinanceDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage a tenant's customizable invoice/quote templates. Tenant isolation is enforced by
 * RLS on fin_document_templates, so no manual company filtering is needed.
 */
class FinTemplateController extends Controller
{
    public function index(): Response
    {
        // First time in: seed a professional starter set (Classic/Modern/Bold/Minimal) rather
        // than a single blank template, so users have ready-made styles to pick from.
        if (! FinDocumentTemplate::query()->exists()) {
            foreach (FinDocumentTemplate::starterSet() as $tpl) {
                FinDocumentTemplate::create($tpl + ['doc_type' => 'both']);
            }
        }

        return Inertia::render('Modules/Finance/Accounting/Templates', [
            'templates' => FinDocumentTemplate::query()->orderByDesc('is_default')->orderBy('name')->get(),
            // The tenant's own logo, so the on-screen previews show their letterhead rather than a
            // placeholder. Null until one is uploaded in Document Settings.
            'logo'      => \App\Modules\Finance\Accounting\Models\FinSetting::current()->logo,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->apply(new FinDocumentTemplate(), $this->rules($request));

        return back()->with('success', 'Template created.');
    }

    public function update(Request $request, FinDocumentTemplate $template): RedirectResponse
    {
        $this->apply($template, $this->rules($request));

        return back()->with('success', 'Template saved.');
    }

    public function destroy(FinDocumentTemplate $template): RedirectResponse
    {
        if ($template->is_default) {
            return back()->with('error', 'Cannot delete the default template. Make another one default first.');
        }
        $template->delete();

        return back()->with('success', 'Template removed.');
    }

    /**
     * Stream a sample document rendered with this template, so a user sees their own letterhead,
     * colour and logo placement before choosing it. Inline rather than a download — it is a look,
     * not a deliverable — and it creates nothing: no document, no numbering, no ledger entry.
     */
    public function preview(Request $request, FinDocumentTemplate $template, FinanceDocumentService $documents): HttpResponse
    {
        $docType = $request->query('doc') === 'statement' ? 'statement' : 'invoice';

        return $documents->previewPdf($template, $docType)
            ->stream("template-preview-{$template->uuid}.pdf");
    }

    public function setDefault(FinDocumentTemplate $template): RedirectResponse
    {
        FinDocumentTemplate::query()->update(['is_default' => false]);
        $template->update(['is_default' => true]);

        return back()->with('success', 'Default template set.');
    }

    private function apply(FinDocumentTemplate $template, array $data): void
    {
        $wasDefault = $template->is_default;
        $template->fill($data)->save();

        // Keep exactly one default.
        if (($data['is_default'] ?? false) && ! $wasDefault) {
            FinDocumentTemplate::query()->whereKeyNot($template->id)->update(['is_default' => false]);
        }
    }

    private function rules(Request $request): array
    {
        return $request->validate([
            'name'              => 'required|string|max:120',
            'doc_type'          => 'required|in:both,invoice,quote,statement',
            'accent_color'      => 'nullable|string|max:20',
            // Off unless somebody says otherwise: the document is set in ink, and colour is a
            // decision rather than a default.
            'use_accent'        => 'boolean',
            'orientation'       => 'required|in:portrait,landscape',
            'page_size'         => 'required|in:a4,letter',
            'font'              => 'required|in:sans,serif,mono',
            // Drawn from the model so the two cannot drift when a layout is added or retired.
            'layout'            => ['required', Rule::in(FinDocumentTemplate::LAYOUTS)],
            'footer_note'       => 'nullable|string|max:2000',
            'terms'             => 'nullable|string|max:8000',
            'show_logo'         => 'boolean',
            'show_bank_details' => 'boolean',
            'is_default'        => 'boolean',
            'logo_position'     => 'required|in:none,left,center,right',
            // Bounded so a mis-typed value cannot push the letterhead off the page.
            'logo_height'       => 'required|integer|min:20|max:160',
        ]);
    }
}
