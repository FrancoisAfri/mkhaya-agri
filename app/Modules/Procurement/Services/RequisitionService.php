<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Budget\Models\BudgetReservation;
use App\Modules\Procurement\Models\ProcurementRequisition;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\SupplierManagement\Models\RfxEvent;
use App\Modules\SupplierManagement\Models\RfxInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Procurement requisition lifecycle: convert an approved purchase request into a
 * requisition, assign an officer, confirm the budget reservation, launch sourcing
 * (RFQ via the existing RFx module) and select the supplier.
 */
class RequisitionService
{
    /** Convert an APPROVED purchase request into a requisition (carrying its reservation). */
    public function convertFromRequest(PurchaseRequest $request, ?int $userId = null): ProcurementRequisition
    {
        if ($request->status !== 'approved') {
            throw new \RuntimeException('Only approved requests can be converted to a requisition.');
        }
        if (ProcurementRequisition::where('purchase_request_id', $request->id)->exists()) {
            throw new \RuntimeException('A requisition already exists for this request.');
        }

        return DB::transaction(function () use ($request, $userId) {
            $request->loadMissing('items');

            $req = ProcurementRequisition::create([
                'requisition_number'    => $this->generateNumber(),
                'purchase_request_id'   => $request->id,
                'title'                 => 'Requisition for '.$request->request_number,
                'budget_id'             => $request->budget_id,
                'budget_reservation_id' => $request->budget_reservation_id,
                'reservation_confirmed' => (bool) $request->budget_reservation_id,
                'estimated_total'       => $request->estimated_total,
                'currency'              => $request->currency,
                'created_by'            => $userId,
            ]);

            foreach ($request->items as $item) {
                $req->items()->create([
                    'name' => $item->name, 'description' => $item->description,
                    'budget_category_id' => $item->budget_category_id,
                    'quantity' => $item->quantity, 'unit_price' => $item->unit_price,
                    // The stock item travels with the line, so the delivery posts to stock.
                    'stock_item_id' => $item->stock_item_id ?? (preg_match('/^fin_stock_item:(\d+)$/', (string) $item->external_ref, $m) ? (int) $m[1] : null),
                ]);
            }

            $request->update(['status' => 'converted']);
            $this->history($req, 'created', null, 'open', $userId, 'Converted from '.$request->request_number);

            return $req;
        });
    }

    public function assignOfficer(ProcurementRequisition $req, int $officerId, ?int $userId = null): ProcurementRequisition
    {
        $req->update(['officer_id' => $officerId]);
        $this->history($req, 'officer_assigned', null, null, $userId, 'Procurement officer assigned.');

        return $req->refresh();
    }

    public function confirmMethod(ProcurementRequisition $req, string $method, ?int $userId = null): ProcurementRequisition
    {
        $req->update(['procurement_method' => $method]);
        $this->history($req, 'method_set', null, null, $userId, "Method: {$method}");

        return $req->refresh();
    }

    /** Confirm the carried budget reservation is still active. */
    public function confirmReservation(ProcurementRequisition $req, ?int $userId = null): array
    {
        $reservation = $req->budget_reservation_id ? BudgetReservation::find($req->budget_reservation_id) : null;
        $active = $reservation && $reservation->status === 'active';

        $req->update(['reservation_confirmed' => $active]);
        $this->history($req, 'reservation_checked', null, null, $userId, $active ? 'Reservation active & confirmed.' : 'Reservation not active.');

        return ['ok' => $active, 'message' => $active ? 'Budget reservation confirmed.' : 'No active budget reservation for this requisition.'];
    }

    /** Launch an RFQ using the existing RFx module for the chosen suppliers. */
    public function createRfq(ProcurementRequisition $req, array $supplierIds, ?int $userId = null): RfxEvent
    {
        return DB::transaction(function () use ($req, $supplierIds, $userId) {
            $rfx = RfxEvent::create([
                'title'      => 'RFQ: '.$req->title,
                'type'       => 'rfq',
                'status'     => 'published',
                'created_by' => $userId,
            ]);

            foreach (array_unique($supplierIds) as $sid) {
                RfxInvitation::create([
                    'rfx_id' => $rfx->id, 'supplier_id' => $sid, 'status' => 'invited', 'invited_at' => now(),
                ]);
            }

            $req->update(['rfx_event_id' => $rfx->id, 'procurement_method' => 'rfq', 'status' => 'quoting']);
            $this->history($req, 'rfq_created', $req->status, 'quoting', $userId, $rfx->rfx_number.' issued to '.count($supplierIds).' supplier(s).');

            return $rfx;
        });
    }

    public function chooseSupplier(ProcurementRequisition $req, int $supplierId, ?int $userId = null): ProcurementRequisition
    {
        $from = $req->status;
        $req->update(['supplier_id' => $supplierId, 'status' => 'awarded']);
        $this->history($req, 'supplier_selected', $from, 'awarded', $userId, 'Supplier selected.');

        return $req->refresh();
    }

    public function cancel(ProcurementRequisition $req, ?int $userId = null): void
    {
        $from = $req->status;
        $req->update(['status' => 'cancelled']);
        $this->history($req, 'cancelled', $from, 'cancelled', $userId, 'Requisition cancelled.');
    }

    public function history(ProcurementRequisition $req, string $action, ?string $from, ?string $to, ?int $userId, ?string $note): void
    {
        $req->history()->create([
            'action' => $action, 'from_status' => $from, 'to_status' => $to,
            'performed_by' => $userId, 'note' => $note,
        ]);
    }

    private function generateNumber(): string
    {
        $max = (int) ProcurementRequisition::withTrashed()->max('id');

        return 'RQ'.date('Y').Str::padLeft((string) ($max + 1), 5, '0');
    }
}
