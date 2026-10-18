<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmEmailTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage reusable email templates (merge-tag aware). Used by campaigns, the
 * new-lead auto-responder and complaint acknowledgements.
 */
class CrmEmailTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/CRM/EmailTemplates', [
            'templates'  => CrmEmailTemplate::orderBy('category')->orderBy('name')->get()
                ->map(fn ($t) => [
                    'id'       => $t->id,
                    'uuid'     => $t->uuid,
                    'name'     => $t->name,
                    'key'      => $t->key,
                    'category' => $t->category,
                    'subject'  => $t->subject,
                    'body'     => $t->body,
                    'active'   => $t->active,
                    'system'   => $t->key !== null,
                ]),
            'categories' => CrmEmailTemplate::CATEGORIES,
            'mergeTags'  => CrmEmailTemplate::MERGE_TAGS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['created_by'] = Auth::id();

        CrmEmailTemplate::create($data);

        return back()->with('success', 'Template created.');
    }

    public function update(Request $request, CrmEmailTemplate $template): RedirectResponse
    {
        // System templates keep their key; only content/active is editable.
        $data = $this->validateData($request);
        if ($template->key !== null) {
            unset($data['category']);
        }
        $template->update($data);

        return back()->with('success', 'Template updated.');
    }

    public function destroy(CrmEmailTemplate $template): RedirectResponse
    {
        abort_if($template->key !== null, 422, 'System templates cannot be deleted — disable them instead.');
        $template->delete();

        return back()->with('success', 'Template deleted.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'     => 'required|string|max:150',
            'category' => 'required|in:lead,deal,complaint,campaign,general',
            'subject'  => 'required|string|max:255',
            'body'     => 'required|string',
            'active'   => 'boolean',
        ]);
    }
}
