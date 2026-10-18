<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\DockAppointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Delivery appointments (WMS-031): a slot at a dock, the vehicle that fills it, and what happened.
 *
 * A dock is a RECEIVING location. Two vehicles cannot hold the same dock at the same time, and a
 * booking outside the warehouse's opening hours is refused rather than discovered when the gate is
 * locked.
 */
class DockAppointments
{
    private const TRANSITIONS = [
        'BOOKED' => ['ARRIVED', 'NO_SHOW', 'CANCELLED'],
        'ARRIVED' => ['UNLOADING', 'COMPLETED', 'CANCELLED'],
        'UNLOADING' => ['COMPLETED'],
        'COMPLETED' => [], 'NO_SHOW' => [], 'CANCELLED' => [],
    ];

    public function book(FinWarehouse $warehouse, array $data, User $user): DockAppointment
    {
        $start = Carbon::parse($data['slot_start']);
        $end = Carbon::parse($data['slot_end']);
        if ($end->lte($start)) {
            throw new RuntimeException('A slot must end after it starts.');
        }
        $dock = isset($data['dock_bin_id']) ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->findOrFail($data['dock_bin_id']) : null;
        if ($dock && $dock->kind !== 'RECEIVING') {
            throw new RuntimeException("{$dock->code} is not a receiving dock.");
        }
        $this->assertWithinHours($warehouse, $start, $end);

        return DB::transaction(function () use ($warehouse, $data, $user, $start, $end, $dock) {
            if ($dock) {
                // Serialise bookings of one dock so two clerks cannot both take 10:00.
                DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-dock:'.$dock->id)]);
                $clash = DockAppointment::query()->where('dock_bin_id', $dock->id)->whereIn('status', ['BOOKED', 'ARRIVED', 'UNLOADING'])
                    ->where('slot_start', '<', $end)->where('slot_end', '>', $start)->first();
                if ($clash) {
                    throw new RuntimeException("{$dock->code} is already booked {$clash->slot_start->format('H:i')}–{$clash->slot_end->format('H:i')} ({$clash->number}).");
                }
            }

            return DockAppointment::create([
                'company_id' => $warehouse->company_id, 'number' => $this->nextNumber((int) $warehouse->company_id),
                'warehouse_id' => $warehouse->id, 'dock_bin_id' => $dock?->id, 'slot_start' => $start, 'slot_end' => $end,
                'status' => 'BOOKED', 'created_by' => $user->id,
            ] + array_intersect_key($data, array_flip(['purchase_order_id', 'supplier_id', 'carrier', 'vehicle_reg', 'driver_name', 'driver_phone', 'assigned_user_id', 'notes'])));
        });
    }

    public function move(DockAppointment $a, string $to, User $user, array $data = []): DockAppointment
    {
        if (! in_array($to, self::TRANSITIONS[$a->status] ?? [], true)) {
            throw new RuntimeException("{$a->number} is ".strtolower($a->status).' and cannot become '.strtolower($to).'.');
        }

        $a->update(array_filter([
            'status' => $to,
            'arrived_at' => $to === 'ARRIVED' ? now() : null,
            'unloading_started_at' => $to === 'UNLOADING' ? now() : null,
            'departed_at' => $to === 'COMPLETED' ? now() : null,
            'vehicle_reg' => $data['vehicle_reg'] ?? null,
            'driver_name' => $data['driver_name'] ?? null,
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));

        return $a;
    }

    /** The day at the docks: what is booked, here, unloading and late. */
    public function queue(FinWarehouse $warehouse, Carbon $day): array
    {
        $tz = $warehouse->timezone ?: 'Africa/Johannesburg';
        $from = $day->copy()->timezone($tz)->startOfDay()->utc();

        return DockAppointment::query()->with(['dock:id,code', 'purchaseOrder:id,po_number'])
            ->where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->whereBetween('slot_start', [$from, $from->copy()->addDay()])
                ->orWhere(fn ($w) => $w->where('status', 'BOOKED')->where('slot_end', '<', now())))
            ->orderBy('slot_start')->get()
            ->map(fn (DockAppointment $a) => [
                'id' => $a->uuid, 'number' => $a->number, 'dock' => $a->dock?->code, 'po' => $a->purchaseOrder?->po_number,
                'supplier' => $a->supplier_id ? DB::table('suppliers')->where('id', $a->supplier_id)->value('company_name') : null,
                'slot_start' => $a->slot_start->toIso8601String(), 'slot_end' => $a->slot_end->toIso8601String(),
                'carrier' => $a->carrier, 'vehicle_reg' => $a->vehicle_reg, 'driver_name' => $a->driver_name,
                'status' => $a->status, 'overdue' => $a->isOverdue(),
                'arrived_at' => $a->arrived_at?->toIso8601String(), 'departed_at' => $a->departed_at?->toIso8601String(),
                'wait_minutes' => $a->arrived_at && $a->unloading_started_at ? $a->arrived_at->diffInMinutes($a->unloading_started_at) : null,
            ])->all();
    }

    private function assertWithinHours(FinWarehouse $warehouse, Carbon $start, Carbon $end): void
    {
        if (! $warehouse->opening_time || ! $warehouse->closing_time) {
            return;
        }
        $tz = $warehouse->timezone ?: 'Africa/Johannesburg';
        $s = $start->copy()->timezone($tz)->format('H:i');
        $e = $end->copy()->timezone($tz)->format('H:i');
        if ($s < substr($warehouse->opening_time, 0, 5) || $e > substr($warehouse->closing_time, 0, 5)) {
            throw new RuntimeException("{$warehouse->name} is open ".substr($warehouse->opening_time, 0, 5).'–'.substr($warehouse->closing_time, 0, 5).'; book within those hours.');
        }
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-appt-number:'.$companyId)]);
        $prefix = 'APT-'.now()->format('Y').'-';
        $last = DockAppointment::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
