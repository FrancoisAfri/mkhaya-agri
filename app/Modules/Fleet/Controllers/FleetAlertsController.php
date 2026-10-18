<?php

namespace App\Modules\Fleet\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FleetAlertsController extends Controller
{
    public function index(): Response
    {
        $now = now();

        // Collection overdue — approved bookings whose departure_at has passed and they haven't been collected
        $collectionOverdue = DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'v.company_unit_id')
            ->where('b.workflow_state', 'approved')
            ->where('b.departure_at', '<', $now)
            ->whereNull('b.deleted_at')
            ->selectRaw("v.fleet_number as \"Fleet Number\", v.make as \"Make\", v.model as \"Model\", v.registration_number as \"Registration Number\", b.destination as \"Company\", cu.name as \"Department\", v.odometer_reading || 'Kms' as \"Odometer/Hours Reading\", b.departure_at::date as \"Collection Date\"")
            ->orderBy('b.departure_at')
            ->get()
            ->map(fn ($r) => (array) $r);

        // Return overdue — in_use bookings whose expected_return_at has passed
        $returnOverdue = DB::table('fleet_bookings as b')
            ->join('fleet_vehicles as v', 'v.id', '=', 'b.vehicle_id')
            ->leftJoin('fleet_drivers as d', 'd.id', '=', 'b.driver_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'v.company_unit_id')
            ->whereIn('b.workflow_state', ['security_out', 'security_in'])
            ->where('b.expected_return_at', '<', $now)
            ->whereNull('b.deleted_at')
            ->selectRaw("v.fleet_number as \"Fleet Number\", v.make as \"Make\", v.model as \"Model\", v.registration_number as \"Registration Number\", b.destination as \"Company\", cu.name as \"Department\", v.odometer_reading || 'Kms' as \"Odometer/Hours Reading\", b.expected_return_at::date as \"Expected Return\"")
            ->orderBy('b.expected_return_at')
            ->get()
            ->map(fn ($r) => (array) $r);

        // Open incidents
        $incidents = DB::table('fleet_booking_incidents as i')
            ->join('fleet_vehicles as v', 'v.id', '=', 'i.vehicle_id')
            ->join('fleet_bookings as b', 'b.id', '=', 'i.booking_id')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'b.requester_id')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'v.company_unit_id')
            ->selectRaw("v.registration_number as \"Registration\", v.make || ' ' || v.model as \"Vehicle\", i.incident_type as \"Type\", i.severity as \"Severity\", i.description as \"Description\", i.incident_at::date as \"Date\", TRIM(COALESCE(p.first_name,'') || ' ' || COALESCE(p.surname,'')) as \"Reported By\"")
            ->orderByDesc('i.incident_at')
            ->limit(100)
            ->get()
            ->map(fn ($r) => (array) $r);

        // Service due within 30 days
        $serviceDue = DB::table('fleet_maintenance as m')
            ->join('fleet_vehicles as v', 'v.id', '=', 'm.vehicle_id')
            ->leftJoin('company_units as cu', 'cu.id', '=', 'v.company_unit_id')
            ->whereNull('m.deleted_at')
            ->whereIn('m.status', ['scheduled'])
            ->where('m.scheduled_date', '<=', $now->copy()->addDays(30))
            ->where('m.scheduled_date', '>=', $now->copy()->subDays(7))
            ->selectRaw("v.fleet_number as \"Fleet Number\", v.registration_number as \"Registration\", v.make || ' ' || v.model as \"Vehicle\", m.maintenance_type as \"Type\", m.category as \"Category\", m.scheduled_date as \"Due Date\", m.priority as \"Priority\", m.service_provider as \"Service Provider\"")
            ->orderBy('m.scheduled_date')
            ->get()
            ->map(fn ($r) => (array) $r);

        // Documents expiring/expired (insurance, roadworthy, licence, permits)
        $alertDays = DB::table('fleet_settings')->where('key', 'doc_expiry_alert_days')->value('value') ?? 30;
        $docAlerts = DB::table('fleet_documents as d')
            ->join('fleet_vehicles as v', 'v.id', '=', 'd.vehicle_id')
            ->whereNull('d.deleted_at')
            ->whereNotNull('d.expiry_date')
            ->where('d.expiry_date', '<=', $now->copy()->addDays((int) $alertDays))
            ->selectRaw("v.fleet_number as \"Fleet Number\", v.registration_number as \"Registration\", d.document_type as \"Document Type\", d.document_number as \"Document No.\", d.expiry_date as \"Expiry Date\", CASE WHEN d.expiry_date < NOW()::date THEN 'Expired' WHEN d.expiry_date <= NOW()::date + INTERVAL '7 days' THEN 'Critical' ELSE 'Expiring Soon' END as \"Status\"")
            ->orderBy('d.expiry_date')
            ->get()
            ->map(fn ($r) => (array) $r);

        // Warranty expired/expiring
        $warrantyAlerts = DB::table('fleet_documents as d')
            ->join('fleet_vehicles as v', 'v.id', '=', 'd.vehicle_id')
            ->whereNull('d.deleted_at')
            ->where('d.document_type', 'warranty')
            ->whereNotNull('d.expiry_date')
            ->where('d.expiry_date', '<=', $now->copy()->addDays(90))
            ->selectRaw("v.fleet_number as \"Fleet Number\", v.registration_number as \"Registration\", v.make || ' ' || v.model as \"Vehicle\", d.expiry_date as \"Warranty Expiry\", CASE WHEN d.expiry_date < NOW()::date THEN 'Expired' ELSE 'Expiring' END as \"Status\"")
            ->orderBy('d.expiry_date')
            ->get()
            ->map(fn ($r) => (array) $r);

        // Permits expiring
        $permitAlerts = DB::table('fleet_documents as d')
            ->join('fleet_vehicles as v', 'v.id', '=', 'd.vehicle_id')
            ->whereNull('d.deleted_at')
            ->where('d.document_type', 'permit')
            ->whereNotNull('d.expiry_date')
            ->where('d.expiry_date', '<=', $now->copy()->addDays((int) $alertDays))
            ->selectRaw("v.fleet_number as \"Fleet Number\", v.registration_number as \"Registration\", v.make || ' ' || v.model as \"Vehicle\", d.document_number as \"Permit No.\", d.expiry_date as \"Expiry Date\", CASE WHEN d.expiry_date < NOW()::date THEN 'Expired' ELSE 'Expiring' END as \"Status\"")
            ->orderBy('d.expiry_date')
            ->get()
            ->map(fn ($r) => (array) $r);

        return Inertia::render('Modules/Fleet/Alerts/Index', [
            'collectionOverdue' => $collectionOverdue,
            'returnOverdue'     => $returnOverdue,
            'incidents'         => $incidents,
            'serviceDue'        => $serviceDue,
            'docAlerts'         => $docAlerts,
            'warrantyAlerts'    => $warrantyAlerts,
            'permitAlerts'      => $permitAlerts,
        ]);
    }
}
