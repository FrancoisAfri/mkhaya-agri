<?php

namespace App\Modules\Operations\DocumentManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\DocumentManagement\Models\DocumentApprovalWorkflow;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategory;
use App\Modules\Operations\DocumentManagement\Models\DocumentCategoryField;
use App\Modules\Operations\DocumentManagement\Services\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The category list an administrator owns.
 *
 * Shipped categories can be renamed, recoloured, reordered and switched off, but not deleted:
 * reporting and retention rules name them, and a deleted one turns those into silence rather than
 * an error. Deactivating does what someone actually wants when they ask to remove one — it stops
 * appearing on the upload form while everything already filed under it keeps its label.
 */
class DocumentCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'colour' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer'],
            'retention_years' => ['nullable', 'integer', 'min:1', 'max:200'],
            'retention_basis' => ['nullable', 'string', Rule::in(RetentionService::BASES)],
            'retention_note' => ['nullable', 'string', 'max:255'],
            'archive_after_years' => ['nullable', 'integer', 'min:1', 'max:200'],
            'auto_archive' => ['nullable', 'boolean'],
            'auto_delete' => ['nullable', 'boolean'],
        ]);

        DocumentCategory::create([
            'name' => $data['name'],
            'slug' => $this->availableSlug($data['name']),
            'description' => $data['description'] ?? null,
            'colour' => $data['colour'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? 99,
            'retention_years' => $data['retention_years'] ?? null,
            'retention_basis' => $data['retention_basis'] ?? null,
            'retention_note' => $data['retention_note'] ?? null,
            'archive_after_years' => $data['archive_after_years'] ?? null,
            'auto_archive' => (bool) ($data['auto_archive'] ?? false),
            'auto_delete' => (bool) ($data['auto_delete'] ?? false),
            'is_system' => false,
            'is_active' => true,
            'created_by' => Auth::id(),
        ]);

        return back()->with('success', __('document_management.categories.created'));
    }

    public function update(Request $request, DocumentCategory $category): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'colour' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer'],
            'retention_years' => ['nullable', 'integer', 'min:1', 'max:200'],
            'retention_basis' => ['nullable', 'string', Rule::in(RetentionService::BASES)],
            'retention_note' => ['nullable', 'string', 'max:255'],
            'archive_after_years' => ['nullable', 'integer', 'min:1', 'max:200'],
            'auto_archive' => ['nullable', 'boolean'],
            'auto_delete' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],

            /*
             * Rules that belong to this kind of document. The pattern is checked for at least one
             * placeholder: a pattern with none would hand every document the same number, which is
             * worse than no numbering at all because it looks like numbering.
             */
            'number_pattern' => ['nullable', 'string', 'max:60', 'regex:/[#]|\{YYYY\}|\{YY\}|\{MM\}/'],
            'number_next' => ['nullable', 'integer', 'min:1'],
            'default_workflow' => ['nullable', 'string'],
            'workflow_required' => ['nullable', 'boolean'],

            'fields' => ['nullable', 'array'],
            'fields.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'fields.*.label' => ['required', 'string', 'max:120'],
            'fields.*.type' => ['required', Rule::in(DocumentCategoryField::TYPES)],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['string', 'max:120'],
            'fields.*.hint' => ['nullable', 'string', 'max:255'],
            'fields.*.required' => ['nullable', 'boolean'],
        ]);

        // The slug stays put even when the name changes. Anything that referenced this category by
        // slug — an import, a rule, a report — keeps working, and the name is what people read.
        $category->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'colour' => $data['colour'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v) => $v !== null));

        /*
         * Retention is set outside the filter above, which drops nulls. A schedule that could be
         * added but never removed is the same trap as a flag that cannot be cleared: somebody sets
         * "5 years" on the wrong category and the only way back is the database.
         *
         * Clearing the period clears the basis with it. A basis with no period is not a rule, and
         * leaving one behind makes the next person think a schedule is in place.
         */
        if ($request->has('number_pattern')) {
            $category->number_pattern = $request->filled('number_pattern')
                ? $request->input('number_pattern')
                : null;
        }

        if ($request->filled('number_next')) {
            // Set rather than incremented: somebody migrating from a spreadsheet needs to carry on
            // from 41 rather than start at 1 and collide with everything already numbered.
            $category->number_next = (int) $request->input('number_next');
        }

        if ($request->has('default_workflow')) {
            $category->default_workflow_id = $request->filled('default_workflow')
                ? DocumentApprovalWorkflow::where('uuid', $request->input('default_workflow'))->value('id')
                : null;
        }

        if ($request->has('workflow_required')) {
            $category->workflow_required = $request->boolean('workflow_required');
        }

        if ($request->has('retention_years')) {
            $years = $request->input('retention_years');
            $category->retention_years = ($years === null || $years === '') ? null : (int) $years;

            if ($category->retention_years === null) {
                $category->retention_basis = null;
            } elseif ($request->filled('retention_basis')) {
                $category->retention_basis = $request->input('retention_basis');
            }
        }

        if ($request->has('archive_after_years')) {
            $years = $request->input('archive_after_years');
            $category->archive_after_years = ($years === null || $years === '') ? null : (int) $years;

            // A schedule with no period cannot act, so the switch goes with it rather than sitting
            // on looking as though something is configured.
            if ($category->archive_after_years === null) {
                $category->auto_archive = false;
            }
        }

        if ($request->has('auto_archive')) {
            $category->auto_archive = $request->boolean('auto_archive') && $category->archive_after_years !== null;
        }

        if ($request->has('auto_delete')) {
            $category->auto_delete = $request->boolean('auto_delete');
        }

        if ($request->has('retention_note')) {
            $category->retention_note = $request->input('retention_note') ?: null;
        }

        if ($request->has('is_active')) {
            $category->is_active = $request->boolean('is_active');
        }

        $category->save();

        if ($request->has('fields')) {
            $this->saveFields($category, $request->input('fields', []));
        }

        return back()->with('success', __('document_management.categories.updated'));
    }

    /**
     * Replaces the category's extra fields with what was sent.
     *
     * Matched on the key rather than rebuilt from scratch: the key is what every recorded value is
     * stored under, so deleting and recreating a field would orphan every value already given for
     * it. A field whose key is gone from the list is genuinely removed — the values it collected
     * stay in the documents' own columns and simply stop being shown, which is recoverable if
     * somebody puts the field back and is not if the values were stripped out.
     *
     * @param array<int, array<string, mixed>> $fields
     */
    private function saveFields(DocumentCategory $category, array $fields): void
    {
        $keys = [];

        foreach (array_values($fields) as $index => $field) {
            $key = $field['key'];
            $keys[] = $key;

            DocumentCategoryField::updateOrCreate(
                ['category_id' => $category->id, 'key' => $key],
                [
                    'uuid' => (string) Str::uuid(),
                    'label' => $field['label'],
                    'type' => $field['type'],
                    // Only a choice list has options; keeping them on a text field would mean a
                    // type changed later silently brings back a list nobody can see.
                    'options' => ($field['type'] ?? '') === DocumentCategoryField::TYPE_SELECT
                        ? array_values(array_filter((array) ($field['options'] ?? [])))
                        : null,
                    'hint' => $field['hint'] ?? null,
                    'is_required' => (bool) ($field['required'] ?? false),
                    'sort_order' => $index,
                ],
            );
        }

        DocumentCategoryField::query()
            ->where('category_id', $category->id)
            ->when($keys !== [], fn ($q) => $q->whereNotIn('key', $keys))
            ->delete();
    }

    public function destroy(DocumentCategory $category): RedirectResponse
    {
        $this->authorize('manageSettings', Document::class);

        if (! $category->isDeletable()) {
            return back()->with('error', __('document_management.categories.system_protected'));
        }

        $inUse = $category->documents()->count();

        if ($inUse > 0) {
            // Removing it would leave those documents categorised as nothing at all, which is worse
            // than an extra entry on a settings screen. Switching it off is the answer.
            return back()->with('error', __('document_management.categories.in_use', ['count' => $inUse]));
        }

        $category->delete();

        return back()->with('success', __('document_management.categories.deleted'));
    }

    /** A slug nothing else in this company is using. */
    private function availableSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 1;

        while (DocumentCategory::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$suffix;
        }

        return $slug;
    }
}
