<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmCustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * No-code custom-field builder (spec section 7).
 */
class CustomFieldController extends Controller
{
    public function index(): Response
    {
        $fields = CrmCustomField::orderBy('entity_type')->orderBy('sort_order')->orderBy('id')->get()
            ->groupBy('entity_type')
            ->map(fn ($group) => $group->map(fn ($f) => [
                'id'          => $f->id,
                'name'        => $f->name,
                'label'       => $f->label,
                'field_type'  => $f->field_type,
                'options'     => $f->options ?? [],
                'is_required' => $f->is_required,
                'active'      => $f->active,
                'sort_order'  => $f->sort_order,
            ])->values());

        return Inertia::render('Modules/CRM/CustomFields', [
            'fieldsByEntity' => $fields,
            'entityTypes'    => CrmCustomField::ENTITY_TYPES,
            'fieldTypes'     => CrmCustomField::FIELD_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['name'] = $this->uniqueName($data['entity_type'], $data['label']);
        $data['sort_order'] = (int) CrmCustomField::where('entity_type', $data['entity_type'])->max('sort_order') + 1;
        $data['active'] = true;

        CrmCustomField::create($data);

        return back()->with('success', 'Custom field added.');
    }

    public function update(Request $request, CrmCustomField $field): RedirectResponse
    {
        $data = $request->validate([
            'label'       => 'required|string|max:120',
            'field_type'  => 'required|in:text,number,date,boolean,select,multiselect',
            'options'     => 'nullable|array',
            'is_required' => 'boolean',
            'active'      => 'boolean',
        ]);

        $field->update($data);

        return back()->with('success', 'Custom field updated.');
    }

    public function destroy(CrmCustomField $field): RedirectResponse
    {
        $field->delete(); // cascades to values via FK

        return back()->with('success', 'Custom field removed.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'entity_type' => 'required|in:lead,contact,account,deal',
            'label'       => 'required|string|max:120',
            'field_type'  => 'required|in:text,number,date,boolean,select,multiselect',
            'options'     => 'nullable|array',
            'is_required' => 'boolean',
        ]);
    }

    /** Build a unique machine name from the label within the entity type. */
    private function uniqueName(string $entityType, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $name = $base;
        $i = 1;
        while (CrmCustomField::where('entity_type', $entityType)->where('name', $name)->exists()) {
            $name = $base . '_' . (++$i);
        }
        return $name;
    }
}
