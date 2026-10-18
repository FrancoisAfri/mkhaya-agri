<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent;
use App\Modules\Operations\WarehouseManagement\Models\Carrier;
use App\Modules\Operations\WarehouseManagement\Models\ClientAccess;
use App\Modules\Operations\WarehouseManagement\Models\Count;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturn;
use App\Modules\Operations\WarehouseManagement\Models\ItemEvent;
use App\Modules\Operations\WarehouseManagement\Models\LandedCost;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\Revaluation;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Models\SupplierReturn;
use App\Modules\Operations\WarehouseManagement\Models\TransferOrder;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use App\Modules\Operations\WarehouseManagement\Models\Webhook;
use Illuminate\Database\Eloquent\Model;

/**
 * What the audit ledger hears from records themselves (WMS-203): transactions as they are
 * requested, decided, posted and reversed; configuration as it changes; product master changes; and
 * integration failures. Explicit calls elsewhere cover what has no model of its own (rules,
 * policies, delegations, overrides, sensitive downloads, investigation access, API commands).
 */
class WarehouseAuditHooks
{
    /** Transaction models whose status changes are recorded: class => entity type */
    private const TRANSACTIONS = [
        StockAdjustment::class => 'ADJUSTMENT', Revaluation::class => 'REVALUATION', LandedCost::class => 'LANDED_COST',
        TransferOrder::class => 'TRANSFER', CustomerReturn::class => 'CUSTOMER_RETURN', SupplierReturn::class => 'SUPPLIER_RETURN', Count::class => 'STOCKTAKE',
    ];

    /** Configuration models: class => [entity type, fields that are configuration (not run-time counters)] */
    private const CONFIGURATION = [
        Carrier::class => ['CARRIER', ['code', 'name', 'driver', 'account_ref', 'tracking_url', 'is_active']],
        Webhook::class => ['WEBHOOK', ['name', 'url', 'event_types', 'warehouse_ids', 'is_active', 'secret_hint']],
        ReasonCode::class => ['REASON_CODE', ['code', 'name', 'category', 'direction', 'approval_qty', 'approval_value', 'always_requires_approval', 'is_active', 'requires_evidence', 'approve_high_risk']],
        WarehouseStaff::class => ['WAREHOUSE_STAFF', ['warehouse_id', 'user_id', 'activities', 'is_active']],
        ClientAccess::class => ['CLIENT_ACCESS', ['user_id', 'party_id']],
    ];

    public function __construct(private readonly WarehouseAudit $audit) {}

    public function listen(): void
    {
        foreach (self::TRANSACTIONS as $class => $type) {
            $class::created(fn (Model $m) => $this->audit->record((int) $m->company_id, strtolower($type).'.created', $type, (string) $m->number,
                "{$type} {$m->number} created ({$m->status})", null, $this->pick($m, ['status'])));
            $class::updated(function (Model $m) use ($type) {
                if ($m->wasChanged('status')) {
                    $this->audit->record((int) $m->company_id, strtolower($type).'.'.strtolower((string) $m->status), $type, (string) $m->number,
                        "{$type} {$m->number}: {$m->getOriginal('status')} → {$m->status}", ['status' => $m->getOriginal('status')], ['status' => $m->status]);
                }
            });
        }
        foreach (self::CONFIGURATION as $class => [$type, $fields]) {
            $class::created(fn (Model $m) => $this->audit->record((int) $m->company_id, strtolower($type).'.created', $type, $this->ref($m), "{$type} added", null, $this->pick($m, $fields)));
            $class::updated(function (Model $m) use ($type, $fields) {
                $changed = array_values(array_intersect(array_keys($m->getChanges()), $fields));
                if ($changed !== []) {
                    $this->audit->record((int) $m->company_id, strtolower($type).'.changed', $type, $this->ref($m), "{$type} changed: ".implode(', ', $changed),
                        array_intersect_key($m->getOriginal(), array_flip($changed)), array_intersect_key($m->getAttributes(), array_flip($changed)));
                }
            });
            $class::deleted(fn (Model $m) => $this->audit->record((int) $m->company_id, strtolower($type).'.removed', $type, $this->ref($m), "{$type} removed", $this->pick($m, $fields), null));
        }
        FinWarehouse::updated(function (FinWarehouse $w) {
            $fields = ['policies', 'is_wms_managed', 'is_active', 'manager_user_id', 'timezone', 'work_calendar_id', 'warehouse_type', 'code', 'name'];
            $changed = array_values(array_intersect(array_keys($w->getChanges()), $fields));
            if ($changed !== [] && ($w->is_wms_managed || $w->wasChanged('is_wms_managed')) && $w->company_id) {
                $this->audit->record((int) $w->company_id, 'warehouse.changed', 'WAREHOUSE', $w->code, 'Warehouse changed: '.implode(', ', $changed),
                    array_intersect_key($w->getOriginal(), array_flip($changed)), array_intersect_key($w->getAttributes(), array_flip($changed)));
            }
        });
        ItemEvent::created(fn (ItemEvent $e) => $this->audit->record((int) $e->company_id, 'product.'.strtolower((string) $e->action), 'PRODUCT',
            (string) \Illuminate\Support\Facades\DB::table('fin_stock_items')->where('id', $e->stock_item_id)->value('code'), "Product master: {$e->action}".($e->reason ? " ({$e->reason})" : ''),
            null, is_array($e->changes) ? $e->changes : json_decode((string) $e->changes, true)));
        FinStockMovement::created(function (FinStockMovement $m) {
            if ($m->reversal_of_id && $m->company_id && app(WarehouseEvents::class)->publishes((int) $m->warehouse_id)) {
                $this->audit->record((int) $m->company_id, 'movement.reversed', 'MOVEMENT', (string) $m->uuid, "Stock movement reversed ({$m->type}, {$m->qty})",
                    ['reverses' => $m->reversal_of_id], ['qty' => (float) $m->qty]);
            }
        });
        IntegrationEvent::updated(function (IntegrationEvent $e) {
            if ($e->wasChanged('status') && $e->status === 'DEAD' && str_starts_with((string) $e->event_type, 'WMS_')) {
                $this->audit->record((int) $e->company_id, 'integration.failed', 'INTEGRATION', "{$e->event_type}:{$e->external_id}", "Message gave up after {$e->attempts} attempts",
                    null, ['error' => mb_substr((string) $e->last_error, 0, 300)]);
            }
        });
    }

    private function pick(Model $m, array $fields): array
    {
        return array_intersect_key($m->getAttributes(), array_flip($fields));
    }

    private function ref(Model $m): string
    {
        return (string) ($m->code ?? $m->name ?? $m->uuid ?? $m->getKey());
    }
}
