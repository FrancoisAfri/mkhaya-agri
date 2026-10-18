<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps<{
  order: Record<string, any>
  packages: Array<{ sscc: string; type: string; index: number; of: number; weight_kg: number | null; qr: string; tracking_number: string | null; contents: Array<{ item: string; name: string; uom: string; lot: string | null; qty: number }> }>
  shipment: { number: string; carrier: string | null; tracking_reference: string | null; vehicle_reg: string | null; driver_name: string | null; handover_name: string | null } | null
}>()
const print = () => window.print()
</script>

<template>
  <div class="pa-4">
    <div class="d-flex justify-space-between align-center mb-4 no-print">
      <h4 class="text-h4">{{ shipment ? t('warehouse_management.outbound.dispatch_note') : t('warehouse_management.outbound.documents') }} — {{ order.number }}</h4>
      <VBtn color="primary" prepend-icon="tabler-printer" @click="print">{{ t('warehouse_management.labels.print') }}</VBtn>
    </div>

    <section class="sheet mb-6">
      <h2 class="text-h5 mb-1">{{ shipment ? t('warehouse_management.outbound.dispatch_note') + ' ' + shipment.number : t('warehouse_management.outbound.packing_slip') }}</h2>
      <div class="text-body-2">{{ order.number }} · {{ t(`warehouse_management.order_source.${order.source}`) }} {{ order.external_ref }} · {{ order.warehouse }}</div>
      <div v-if="order.ship_to" class="text-body-2 mt-2"><strong>{{ t('warehouse_management.outbound.ship_to') }}:</strong> {{ Object.values(order.ship_to).join(', ') }}</div>
      <div v-if="shipment" class="text-body-2">{{ shipment.carrier }} {{ shipment.tracking_reference }} · {{ shipment.vehicle_reg }} {{ shipment.driver_name }} · {{ t('warehouse_management.outbound.handed_to') }}: {{ shipment.handover_name }}</div>
      <table class="mt-3 w-100">
        <thead><tr><th class="text-start">{{ t('warehouse_management.outbound.package') }}</th><th class="text-start">{{ t('warehouse_management.movements.item') }}</th><th class="text-start">{{ t('warehouse_management.receive.lot') }}</th><th class="text-end">{{ t('warehouse_management.locations.qty') }}</th></tr></thead>
        <tbody>
          <template v-for="p in packages" :key="p.sscc">
            <tr v-for="(c, i) in p.contents" :key="p.sscc + i"><td>{{ i === 0 ? p.sscc : '' }}</td><td>{{ c.item }} — {{ c.name }}</td><td>{{ c.lot ?? '' }}</td><td class="text-end">{{ c.qty }} {{ c.uom }}</td></tr>
          </template>
        </tbody>
      </table>
      <div v-if="shipment" class="mt-8 d-flex gap-12"><div>{{ t('warehouse_management.outbound.signed_warehouse') }} ____________________</div><div>{{ t('warehouse_management.outbound.signed_carrier') }} ____________________</div></div>
    </section>

    <div v-if="!shipment" class="labels">
      <div v-for="p in packages" :key="p.sscc" class="label">
        <div class="qr" v-html="p.qr" />
        <div class="big">{{ p.index }} / {{ p.of }}</div>
        <div class="mono">(00) {{ p.sscc }}</div>
        <div>{{ order.number }} · {{ order.customer }}</div>
        <div v-if="order.ship_to" class="small">{{ Object.values(order.ship_to).join(', ') }}</div>
        <div class="small">{{ t(`warehouse_management.package_type.${p.type}`) }}<template v-if="p.weight_kg"> · {{ p.weight_kg }} kg</template><template v-if="p.tracking_number"> · {{ p.tracking_number }}</template></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sheet { background: #fff; color: #000; padding: 12mm; border: 1px solid rgba(var(--v-border-color), 0.4); }
.sheet table { border-collapse: collapse; }
.sheet th, .sheet td { border-bottom: 1px solid #ccc; padding: 4px 6px; font-size: 10pt; }
.labels { display: grid; grid-template-columns: repeat(auto-fill, minmax(95mm, 1fr)); gap: 4mm; }
.label { background: #fff; color: #000; border: 1px solid #000; padding: 4mm; break-inside: avoid; }
.qr :deep(svg) { width: 34mm; height: 34mm; }
.big { font-size: 22pt; font-weight: 700; }
.mono { font-family: ui-monospace, monospace; font-size: 12pt; }
.small { font-size: 8pt; }
@media print { .no-print { display: none !important; } .sheet { border: none; } }
</style>
