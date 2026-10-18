<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmObjectRecord;
use App\Modules\CRM\Models\CrmObjectType;
use App\Modules\CRM\Services\CrmObjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Custom objects — build-your-own CRM entities (spec section 11).
 */
class CustomObjectController extends Controller
{
    public function __construct(private CrmObjectService $service)
    {
    }

    public function index(Request $request): Response
    {
        $types = CrmObjectType::withCount('records')->orderBy('name')->get()
            ->map(fn ($t) => [
                'id'           => $t->id,
                'uuid'         => $t->uuid,
                'name'         => $t->name,
                'slug'         => $t->slug,
                'icon'         => $t->icon,
                'record_label' => $t->record_label,
                'fields'       => $t->fields ?? [],
                'records'      => $t->records_count,
            ]);

        $active = $request->filled('type')
            ? CrmObjectType::where('slug', $request->type)->first()
            : null;

        $records = $active
            ? CrmObjectRecord::where('object_type_id', $active->id)->latest()->get()
                ->map(fn ($r) => [
                    'id'    => $r->id,
                    'label' => $this->service->recordLabel($active, $r->data ?? [], $r->id),
                    'data'  => $r->data ?? [],
                    'at'    => $r->created_at?->toDateString(),
                ])
            : collect();

        return Inertia::render('Modules/CRM/CustomObjects', [
            'types'      => $types,
            'activeType' => $active?->slug,
            'records'    => $records,
            'fieldTypes' => CrmObjectType::FIELD_TYPES,
        ]);
    }

    public function storeType(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'             => 'required|string|max:120',
            'icon'             => 'nullable|string|max:60',
            'fields'           => 'required|array|min:1',
            'fields.*.label'   => 'required|string|max:120',
            'fields.*.type'    => 'required|in:text,number,date,boolean,select,multiselect',
            'fields.*.options' => 'nullable|array',
            'fields.*.required'=> 'boolean',
        ]);

        $fields = array_map(function ($f) {
            $f['name'] = Str::slug($f['label'], '_');
            return $f;
        }, $data['fields']);

        $type = CrmObjectType::create([
            'uuid'         => Str::uuid(),
            'name'         => $data['name'],
            'slug'         => $this->uniqueSlug($data['name']),
            'icon'         => $data['icon'] ?? 'box',
            'record_label' => $fields[0]['name'] ?? null,
            'fields'       => $fields,
            'is_active'    => true,
            'created_by'   => Auth::id(),
        ]);

        return redirect()->route('crm.objects', ['type' => $type->slug])->with('success', "Object “{$type->name}” created.");
    }

    public function destroyType(CrmObjectType $type): RedirectResponse
    {
        $type->delete();

        return redirect()->route('crm.objects')->with('success', 'Object deleted.');
    }

    public function storeRecord(Request $request, CrmObjectType $type): RedirectResponse
    {
        $data = $this->service->validateData($type, (array) $request->input('data', []));

        CrmObjectRecord::create([
            'uuid'           => Str::uuid(),
            'object_type_id' => $type->id,
            'data'           => $data,
            'created_by'     => Auth::id(),
        ]);

        return back()->with('success', "{$type->name} record added.");
    }

    public function updateRecord(Request $request, CrmObjectType $type, CrmObjectRecord $record): RedirectResponse
    {
        abort_if($record->object_type_id !== $type->id, 404);

        $record->update(['data' => $this->service->validateData($type, (array) $request->input('data', []))]);

        return back()->with('success', 'Record updated.');
    }

    public function destroyRecord(CrmObjectType $type, CrmObjectRecord $record): RedirectResponse
    {
        abort_if($record->object_type_id !== $type->id, 404);
        $record->delete();

        return back()->with('success', 'Record removed.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'object';
        $slug = $base;
        $i = 1;
        while (CrmObjectType::where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }
        return $slug;
    }
}
