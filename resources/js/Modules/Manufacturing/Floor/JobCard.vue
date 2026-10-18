<script setup lang="ts">
import { onMounted, ref } from 'vue'
import QRCode from 'qrcode'
import { useI18n } from 'vue-i18n'

/**
 * A printable job card: the order on one sheet with a QR code per operation. Scanning one at the
 * terminal opens that job, so an operator never types an order number.
 */
const { t } = useI18n()

const props = defineProps<{
  order: { id: string; number: string; status: string; product: string; planned_qty: number; due_date: string | null; factory: string | null }
  operations: Array<{ sequence: number; name: string; job_code: string; work_centre: string | null; planned_minutes: number; is_gate: boolean }>
  components: Array<{ code: string | null; name: string | null; required: number; uom: string | null }>
}>()

const codes = ref<Record<string, string>>({})
const orderCode = ref('')

onMounted(async () => {
  orderCode.value = await QRCode.toDataURL(props.order.number, { margin: 1, width: 140 })
  for (const op of props.operations) {
    codes.value[op.job_code] = await QRCode.toDataURL(op.job_code, { margin: 1, width: 120 })
  }
})
</script>

<template>
  <div class="pa-4 job-card">
    <div class="d-flex justify-space-between align-start mb-4">
      <div>
        <div class="text-overline">{{ t('manufacturing.floor.job_card') }}</div>
        <h4 class="text-h4">{{ order.number }}</h4>
        <div class="text-body-1">{{ order.product }}</div>
        <div class="text-body-2">{{ t('manufacturing.orders.quantity') }}: {{ order.planned_qty }} · {{ t('manufacturing.orders.due') }}: {{ order.due_date }}</div>
        <div v-if="order.factory" class="text-body-2">{{ order.factory }}</div>
      </div>
      <div class="text-center">
        <img v-if="orderCode" :src="orderCode" :alt="order.number" width="140" height="140">
        <div class="text-caption">{{ order.number }}</div>
      </div>
    </div>

    <VBtn class="d-print-none mb-4" color="primary" prepend-icon="tabler-printer" onclick="window.print()">{{ t('manufacturing.floor.print') }}</VBtn>

    <VTable density="compact" class="mb-4">
      <thead><tr><th>#</th><th>{{ t('manufacturing.floor.operation') }}</th><th>{{ t('manufacturing.floor.work_centre') }}</th><th class="text-end">{{ t('manufacturing.floor.minutes') }}</th><th>{{ t('manufacturing.floor.scan_to_open') }}</th><th>{{ t('manufacturing.floor.sign_off') }}</th></tr></thead>
      <tbody>
        <tr v-for="op in operations" :key="op.job_code">
          <td>{{ op.sequence }}</td>
          <td>{{ op.name }}<VChip v-if="op.is_gate" size="x-small" label class="ms-1">{{ t('manufacturing.routing.gate') }}</VChip></td>
          <td>{{ op.work_centre ?? '—' }}</td>
          <td class="text-end">{{ op.planned_minutes.toFixed(0) }}</td>
          <td class="py-2"><img v-if="codes[op.job_code]" :src="codes[op.job_code]" :alt="op.job_code" width="96" height="96"></td>
          <td style="min-width: 140px">&nbsp;</td>
        </tr>
      </tbody>
    </VTable>

    <div class="text-subtitle-1 font-weight-medium">{{ t('manufacturing.orders.tab.materials') }}</div>
    <VTable density="compact">
      <thead><tr><th>{{ t('manufacturing.orders.material') }}</th><th class="text-end">{{ t('manufacturing.orders.required') }}</th><th>{{ t('manufacturing.quality.lot') }}</th></tr></thead>
      <tbody>
        <tr v-for="(c, i) in components" :key="i">
          <td>{{ c.code }} — {{ c.name }}</td>
          <td class="text-end">{{ c.required }} {{ c.uom }}</td>
          <td style="min-width: 160px">&nbsp;</td>
        </tr>
      </tbody>
    </VTable>
  </div>
</template>

<style scoped>
@media print {
  .job-card { padding: 0 !important; }
}
</style>
