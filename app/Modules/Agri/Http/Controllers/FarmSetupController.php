<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Models\User;
use App\Http\Controllers\Controller;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmLocation;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Services\FarmRegistry;
use App\Modules\Agri\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What lives under a farm: its fields, its other locations and who may work on it
 * (FR-FARM-003/004/005).
 *
 * Children are always looked up through the farm in the URL, so a field or membership belonging to
 * another farm is a 404 even when its uuid is known.
 */
class FarmSetupController extends Controller
{
    public function __construct(private FarmRegistry $registry) {}

    // ── Fields ───────────────────────────────────────────────────────────────────────────────────

    public function storeField(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FIELDS);

        $field = $this->registry->createField($farm, $request->validate($this->fieldRules()), $request->user());

        return back()->with('success', "Field {$field->code} added to {$farm->name}.");
    }

    public function updateField(Request $request, Farm $farm, string $field): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FIELDS);

        $row = $this->field($farm, $field);
        $this->registry->updateField($row, $request->validate($this->fieldRules() + [
            'status' => ['sometimes', Rule::in(Farm::STATUSES)],
        ]));

        return back()->with('success', "Field {$row->code} updated.");
    }

    // ── Locations ────────────────────────────────────────────────────────────────────────────────

    public function storeLocation(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FIELDS);
        $this->registry->assertOperational($farm);

        FarmLocation::create($request->validate($this->locationRules()) + [
            'company_id' => $farm->company_id,
            'farm_id' => $farm->id,
            'status' => 'active',
        ]);

        return back()->with('success', 'Location added.');
    }

    public function updateLocation(Request $request, Farm $farm, string $location): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_FIELDS);

        $row = FarmLocation::query()->where('farm_id', $farm->id)->where('uuid', $location)->firstOrFail();
        $row->update($request->validate($this->locationRules() + [
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'archived'])],
        ]));

        return back()->with('success', 'Location updated.');
    }

    // ── Access ───────────────────────────────────────────────────────────────────────────────────

    public function grant(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_ACCESS);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', Rule::in(FarmMembership::ROLES)],
        ]);
        // Looked up inside the organisation, so somebody else's user id is simply "not found".
        $member = User::query()->where('company_id', $farm->company_id)->find($data['user_id']);
        abort_if($member === null, 422, 'That person is not part of this organisation.');

        $this->registry->grant($farm, $member, $data['role'], $request->user());

        return back()->with('success', 'Access granted.');
    }

    public function updateMembership(Request $request, Farm $farm, string $membership): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_ACCESS);

        $row = $this->membership($farm, $membership);
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(FarmMembership::ROLES)],
            'status' => ['sometimes', Rule::in([FarmMembership::ACTIVE, FarmMembership::SUSPENDED])],
        ]);

        if (($data['status'] ?? null) === FarmMembership::SUSPENDED) {
            $this->registry->suspend($row, $request->user());
            unset($data['status']);
        } elseif (($data['status'] ?? null) === FarmMembership::ACTIVE) {
            $data += ['suspended_at' => null, 'suspended_by' => null];
        }
        if ($data !== []) {
            $row->update($data);
        }

        return back()->with('success', 'Access updated.');
    }

    public function revoke(Request $request, Farm $farm, string $membership): RedirectResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_ACCESS);

        // Removal is logged by the audit trail, so the record of who had access survives it.
        $this->membership($farm, $membership)->delete();

        return back()->with('success', 'Access removed.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function field(Farm $farm, string $uuid): FarmField
    {
        return FarmField::query()->where('farm_id', $farm->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function membership(Farm $farm, string $uuid): FarmMembership
    {
        return FarmMembership::query()->where('farm_id', $farm->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function fieldRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/'],
            'name' => ['required', 'string', 'max:150'],
            'land_use_type' => ['required', Rule::in(FarmField::LAND_USE_TYPES)],
            'area' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'area_unit' => ['required', Rule::in(Farm::AREA_UNITS)],
            'soil_type' => ['nullable', 'string', 'max:60'],
            'irrigation' => ['nullable', Rule::in(FarmField::IRRIGATION)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function locationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'location_type' => ['required', Rule::in(FarmLocation::TYPES)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'You do not have permission to do that.');
    }
}
