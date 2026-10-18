<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetSetupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/Fleet/Setup/Index', [
            'makes'            => DB::table('fleet_vehicle_makes')->orderBy('name')->get(),
            'models'           => DB::table('fleet_vehicle_models as m')
                ->join('fleet_vehicle_makes as k', 'k.id', '=', 'm.make_id')
                ->select('m.*', 'k.name as make_name')
                ->orderBy('k.name')->orderBy('m.name')->get(),
            'vehicleTypes'     => DB::table('fleet_vehicle_types')->orderBy('name')->get(),
            'fuelTypes'        => DB::table('fleet_fuel_types')->orderBy('name')->get(),
            'incidentTypes'    => DB::table('fleet_incident_types')->orderBy('name')->get(),
            'keySafes'         => DB::table('fleet_key_safes')->orderBy('name')->get(),
            'fillingStations'  => DB::table('fleet_filling_stations')->orderBy('name')->get(),
            'cardTypes'        => DB::table('fleet_card_types')->orderBy('name')->get(),
            'licenceTypes'     => DB::table('fleet_licence_types')->orderBy('name')->get(),
            'approvalSteps'    => DB::table('fleet_booking_approval_configs as c')
                ->leftJoin('hr_people as p', 'p.user_id', '=', 'c.employee_id')
                ->leftJoin('company_units as cu', 'cu.id', '=', 'c.company_unit_id')
                ->select('c.*',
                    DB::raw("TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as employee_name"),
                    'cu.name as unit_name')
                ->orderBy('c.step_number')
                ->get(),
            'companyUnits'     => DB::table('company_units')->where('active', 1)->orderBy('name')->get(['id', 'name']),
            'roles'            => DB::table('roles')->orderBy('name')->get(['id', 'name']),
            'users'            => DB::table('users as u')
                ->join('hr_people as p', 'p.user_id', '=', 'u.id')
                ->where('u.status', 1)
                ->orderByRaw("p.first_name || ' ' || p.surname")
                ->get(['u.id', DB::raw("TRIM(p.first_name || ' ' || p.surname) as name"), 'u.email']),
        ]);
    }

    // ── Makes ──────────────────────────────────────────────────────────────────

    public function storeMake(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:60|unique:fleet_vehicle_makes,name']);
        DB::table('fleet_vehicle_makes')->insert(['name' => $request->name, 'active' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Make '{$request->name}' added.");
    }

    public function updateMake(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => "required|string|max:60|unique:fleet_vehicle_makes,name,{$id}", 'active' => 'boolean']);
        DB::table('fleet_vehicle_makes')->where('id', $id)->update(['name' => $request->name, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Make updated.');
    }

    public function destroyMake(int $id): RedirectResponse
    {
        DB::table('fleet_vehicle_makes')->where('id', $id)->delete();
        return back()->with('success', 'Make deleted.');
    }

    // ── Models ─────────────────────────────────────────────────────────────────

    public function storeModel(Request $request): RedirectResponse
    {
        $request->validate(['make_id' => 'required|exists:fleet_vehicle_makes,id', 'name' => 'required|string|max:60']);
        DB::table('fleet_vehicle_models')->insert(['make_id' => $request->make_id, 'name' => $request->name, 'active' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Model '{$request->name}' added.");
    }

    public function updateModel(Request $request, int $id): RedirectResponse
    {
        $request->validate(['make_id' => 'required|exists:fleet_vehicle_makes,id', 'name' => 'required|string|max:60', 'active' => 'boolean']);
        DB::table('fleet_vehicle_models')->where('id', $id)->update(['make_id' => $request->make_id, 'name' => $request->name, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Model updated.');
    }

    public function destroyModel(int $id): RedirectResponse
    {
        DB::table('fleet_vehicle_models')->where('id', $id)->delete();
        return back()->with('success', 'Model deleted.');
    }

    // ── Vehicle types ──────────────────────────────────────────────────────────

    public function storeVehicleType(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:60|unique:fleet_vehicle_types,name']);
        DB::table('fleet_vehicle_types')->insert(['name' => $request->name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Vehicle type '{$request->name}' added.");
    }

    public function updateVehicleType(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => "required|string|max:60|unique:fleet_vehicle_types,name,{$id}", 'active' => 'boolean']);
        DB::table('fleet_vehicle_types')->where('id', $id)->update(['name' => $request->name, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Vehicle type updated.');
    }

    public function destroyVehicleType(int $id): RedirectResponse
    {
        DB::table('fleet_vehicle_types')->where('id', $id)->delete();
        return back()->with('success', 'Vehicle type deleted.');
    }

    // ── Fuel types ─────────────────────────────────────────────────────────────

    public function storeFuelType(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:60|unique:fleet_fuel_types,name']);
        DB::table('fleet_fuel_types')->insert(['name' => $request->name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Fuel type '{$request->name}' added.");
    }

    public function updateFuelType(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => "required|string|max:60|unique:fleet_fuel_types,name,{$id}", 'active' => 'boolean']);
        DB::table('fleet_fuel_types')->where('id', $id)->update(['name' => $request->name, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Fuel type updated.');
    }

    public function destroyFuelType(int $id): RedirectResponse
    {
        DB::table('fleet_fuel_types')->where('id', $id)->delete();
        return back()->with('success', 'Fuel type deleted.');
    }

    // ── Incident types ─────────────────────────────────────────────────────────

    public function storeIncidentType(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:300']);
        DB::table('fleet_incident_types')->insert(['name' => $request->name, 'description' => $request->description, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Incident type '{$request->name}' added.");
    }

    public function updateIncidentType(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:80', 'description' => 'nullable|string|max:300', 'active' => 'boolean']);
        DB::table('fleet_incident_types')->where('id', $id)->update(['name' => $request->name, 'description' => $request->description, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Incident type updated.');
    }

    public function destroyIncidentType(int $id): RedirectResponse
    {
        DB::table('fleet_incident_types')->where('id', $id)->delete();
        return back()->with('success', 'Incident type deleted.');
    }

    // ── Key safes ──────────────────────────────────────────────────────────────

    public function storeKeySafe(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:80', 'location_description' => 'nullable|string|max:200']);
        DB::table('fleet_key_safes')->insert(['name' => $request->name, 'location_description' => $request->location_description, 'active' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Safe '{$request->name}' added.");
    }

    public function updateKeySafe(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:80', 'location_description' => 'nullable|string|max:200', 'active' => 'boolean']);
        DB::table('fleet_key_safes')->where('id', $id)->update(['name' => $request->name, 'location_description' => $request->location_description, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Safe updated.');
    }

    public function destroyKeySafe(int $id): RedirectResponse
    {
        DB::table('fleet_key_safes')->where('id', $id)->delete();
        return back()->with('success', 'Safe deleted.');
    }

    // ── Filling stations ───────────────────────────────────────────────────────

    public function storeFillingStation(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:100', 'location' => 'nullable|string|max:200', 'fuel_types' => 'nullable|string|max:100', 'contact_number' => 'nullable|string|max:30']);
        DB::table('fleet_filling_stations')->insert(['name' => $request->name, 'location' => $request->location, 'fuel_types' => $request->fuel_types, 'contact_number' => $request->contact_number, 'active' => true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Filling station '{$request->name}' added.");
    }

    public function updateFillingStation(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:100', 'location' => 'nullable|string|max:200', 'fuel_types' => 'nullable|string|max:100', 'contact_number' => 'nullable|string|max:30', 'active' => 'boolean']);
        DB::table('fleet_filling_stations')->where('id', $id)->update(['name' => $request->name, 'location' => $request->location, 'fuel_types' => $request->fuel_types, 'contact_number' => $request->contact_number, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Filling station updated.');
    }

    public function destroyFillingStation(int $id): RedirectResponse
    {
        DB::table('fleet_filling_stations')->where('id', $id)->delete();
        return back()->with('success', 'Filling station deleted.');
    }

    // ── Card types ─────────────────────────────────────────────────────────────

    public function storeCardType(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:80|unique:fleet_card_types,name', 'description' => 'nullable|string|max:200']);
        DB::table('fleet_card_types')->insert(['name' => $request->name, 'description' => $request->description, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Card type '{$request->name}' added.");
    }

    public function updateCardType(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => "required|string|max:80|unique:fleet_card_types,name,{$id}", 'description' => 'nullable|string|max:200', 'active' => 'boolean']);
        DB::table('fleet_card_types')->where('id', $id)->update(['name' => $request->name, 'description' => $request->description, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Card type updated.');
    }

    public function destroyCardType(int $id): RedirectResponse
    {
        DB::table('fleet_card_types')->where('id', $id)->delete();
        return back()->with('success', 'Card type deleted.');
    }

    // ── Licence types ──────────────────────────────────────────────────────────

    public function storeLicenceType(Request $request): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:100|unique:fleet_licence_types,name', 'description' => 'nullable|string|max:200', 'validity_months' => 'nullable|integer|min:1']);
        DB::table('fleet_licence_types')->insert(['name' => $request->name, 'description' => $request->description, 'validity_months' => $request->validity_months, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return back()->with('success', "Licence type '{$request->name}' added.");
    }

    public function updateLicenceType(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => "required|string|max:100|unique:fleet_licence_types,name,{$id}", 'description' => 'nullable|string|max:200', 'validity_months' => 'nullable|integer|min:1', 'active' => 'boolean']);
        DB::table('fleet_licence_types')->where('id', $id)->update(['name' => $request->name, 'description' => $request->description, 'validity_months' => $request->validity_months, 'active' => $request->boolean('active', true), 'updated_at' => now()]);
        return back()->with('success', 'Licence type updated.');
    }

    public function destroyLicenceType(int $id): RedirectResponse
    {
        DB::table('fleet_licence_types')->where('id', $id)->delete();
        return back()->with('success', 'Licence type deleted.');
    }

    // ── Approval steps ─────────────────────────────────────────────────────────

    public function storeApprovalStep(Request $request): RedirectResponse
    {
        $request->validate([
            'step_number'    => 'required|integer',
            'step_label'     => 'required|string|max:100',
            'approver_type'  => 'required|in:role,employee,line_manager,none',
            'role_name'      => 'nullable|string|max:80',
            'employee_id'    => 'nullable|exists:users,id',
            'company_unit_id'=> 'nullable|exists:company_units,id',
            'check_local_trip'=> 'boolean',
        ]);

        DB::table('fleet_booking_approval_configs')->insert([
            'step_number'     => $request->step_number,
            'step_label'      => $request->step_label,
            'approver_type'   => $request->approver_type,
            'role_name'       => $request->role_name,
            'employee_id'     => $request->employee_id,
            'company_unit_id' => $request->company_unit_id,
            'check_local_trip'=> $request->boolean('check_local_trip'),
            'is_active'       => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return back()->with('success', 'Approval step added.');
    }

    public function updateApprovalStep(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'step_label'      => 'required|string|max:100',
            'approver_type'   => 'required|in:role,employee,line_manager,none',
            'role_name'       => 'nullable|string|max:80',
            'employee_id'     => 'nullable|exists:users,id',
            'company_unit_id' => 'nullable|exists:company_units,id',
            'check_local_trip'=> 'boolean',
            'is_active'       => 'boolean',
        ]);

        DB::table('fleet_booking_approval_configs')->where('id', $id)->update([
            'step_label'      => $request->step_label,
            'approver_type'   => $request->approver_type,
            'role_name'       => $request->role_name,
            'employee_id'     => $request->employee_id,
            'company_unit_id' => $request->company_unit_id,
            'check_local_trip'=> $request->boolean('check_local_trip'),
            'is_active'       => $request->boolean('is_active', true),
            'updated_at'      => now(),
        ]);

        return back()->with('success', 'Approval step updated.');
    }

    public function destroyApprovalStep(int $id): RedirectResponse
    {
        DB::table('fleet_booking_approval_configs')->where('id', $id)->delete();
        return back()->with('success', 'Approval step deleted.');
    }
}
