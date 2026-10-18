<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Ev = { id: string; kind: string; name: string; caption: string | null; by: string | null; at: string }
type Line = { id: number; item: string; name: string; uom: string; lot: string | null; serials: string[]; authorised: number; received: number; reason_code: string | null; condition: string | null; bin: string | null; outcome: string | null; notes: string | null; inspection: { id: string; number: string; status: string; result: string; disposition: string | null } | null; evidence: Ev[] }
const props = defineProps<{
  rma: { id: string; number: string; status: string; warehouse: string; order: string | null; invoice: string | null; invoice_outstanding: number | null; reference: string | null; customer: string | null; reason: string | null; credit_note: string | null; credit_amount: number | null; cancel_reason: string | null; received_at: string | null; completed_at: string | null; value: number }
  lines: Line[]
  bins: Array<{ id: number; title: string }>
  lots: Array<{ id: number; item_id: number; title: string }>
  item_ids: Record<number, number>
  conditions: string[]
  can: { returns: boolean; credit: boolean; quality: boolean }
}>()

const receiving = reactive<Record<number, { qty: number; condition: string; lot_id: number | null; bin_id: number | null; notes: string }>>(
  Object.fromEntries(props.lines.map(l => [l.id, { qty: l.authorised, condition: 'OPENED', lot_id: null, bin_id: null, notes: '' }])))
const receive = () => router.post(route('warehouse.returns.act', props.rma.id), { action: 'receive', lines: receiving }, { preserveScroll: true })
const credit = useForm({ action: 'credit', amount: null as number | null })
const crediting = ref(false)
const cancelling = ref(false)
const note = ref('')
const upload = useForm({ action: 'evidence', line_id: 0, file: null as File | null, kind: 'PHOTO' })
function attach(line: Line, e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (!f) return
  upload.line_id = line.id
  upload.file = f
  upload.post(route('warehouse.returns.act', props.rma.id), { preserveScroll: true, forceFormData: true })
}
const lotsFor = (lineId: number) => props.lots.filter(l => l.item_id === props.item_ids[lineId])
const outcomeColor = (o: string | null) => ({ RESTOCKED: 'success', QUARANTINED: 'warning', REPAIR: 'info', SUPPLIER_RETURN: 'secondary', DISPOSED: 'error', REJECTED: 'error' } as Record<string, string>)[o ?? ''] ?? 'secondary'
const money = (v: number | null) => v === null ? '—' : v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.returns.index')">{{ t('warehouse_management.returns_page.title') }}</a></div>
        <h4 class="text-h4">{{ rma.number }} <VChip size="small" variant="tonal">{{ t(`warehouse_management.rma_status.${rma.status}`) }}</VChip></h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ rma.customer ?? '—' }} · {{ rma.order ?? rma.invoice ?? rma.reference }} · {{ rma.warehouse }}<span v-if="rma.reason"> · {{ rma.reason }}</span>
        </p>
        <p v-if="rma.credit_note" class="text-body-2 mb-0">{{ t('warehouse_management.returns_page.credited', { note: rma.credit_note, amount: money(rma.credit_amount) }) }}</p>
        <p v-if="rma.cancel_reason" class="text-body-2 text-error mb-0">{{ rma.cancel_reason }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="can.credit && rma.invoice && !rma.credit_note && rma.status !== 'AUTHORISED' && rma.status !== 'CANCELLED'" color="primary" variant="tonal" @click="crediting = true">{{ t('warehouse_management.returns_page.raise_credit') }}</VBtn>
        <VBtn v-if="can.returns && rma.status === 'AUTHORISED'" variant="text" color="error" @click="cancelling = true">{{ t('warehouse_management.common.cancel') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />

    <VCard border>
      <div class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.counts.item') }}</th><th class="text-end">{{ t('warehouse_management.returns_page.authorised') }}</th>
            <th>{{ rma.status === 'AUTHORISED' ? t('warehouse_management.returns_page.arrived') : t('warehouse_management.returns_page.received') }}</th>
            <th>{{ t('warehouse_management.returns_page.outcome') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="l in lines" :key="l.id">
              <td>{{ l.item }} <span class="text-medium-emphasis">{{ l.name }}</span>
                <div class="text-caption"><span v-if="l.reason_code">{{ t(`warehouse_management.return_reason.${l.reason_code}`) }}</span><span v-if="l.lot"> · {{ t('warehouse_management.counts.lot', { lot: l.lot }) }}</span><span v-if="l.bin"> · {{ l.bin }}</span></div>
                <div v-for="e in l.evidence" :key="e.id" class="text-caption"><a :href="route('warehouse.losses.evidence.download', e.id)">{{ e.name }}</a></div></td>
              <td class="text-end">{{ l.authorised }} {{ l.uom }}</td>
              <td v-if="rma.status === 'AUTHORISED' && can.returns" style="min-width: 420px">
                <div class="d-flex gap-2 flex-wrap py-2">
                  <VTextField v-model.number="receiving[l.id].qty" type="number" min="0" density="compact" hide-details style="max-width: 90px" :label="t('warehouse_management.counts.counted')" />
                  <VSelect v-model="receiving[l.id].condition" :items="conditions.map(c => ({ value: c, title: t(`warehouse_management.return_condition.${c}`) }))" density="compact" hide-details style="max-width: 160px" />
                  <VSelect v-if="lotsFor(l.id).length" v-model="receiving[l.id].lot_id" :items="lotsFor(l.id)" item-value="id" density="compact" hide-details clearable style="max-width: 150px" :label="t('warehouse_management.returns_page.lot')" />
                  <VSelect v-model="receiving[l.id].bin_id" :items="bins" item-value="id" density="compact" hide-details clearable style="max-width: 170px" :label="t('warehouse_management.counts.bin')" />
                </div>
              </td>
              <td v-else>{{ l.received }} {{ l.uom }}<span v-if="l.condition" class="text-caption"> · {{ t(`warehouse_management.return_condition.${l.condition}`) }}</span></td>
              <td>
                <VChip v-if="l.outcome" size="small" :color="outcomeColor(l.outcome)" variant="tonal">{{ t(`warehouse_management.return_outcome.${l.outcome}`) }}</VChip>
                <div v-if="l.inspection" class="text-caption"><a :href="route('warehouse.quality.index')">{{ l.inspection.number }}</a> · {{ t(`warehouse_management.inspection_status.${l.inspection.status}`) }}</div>
              </td>
              <td class="text-end">
                <label v-if="can.returns && rma.status !== 'AUTHORISED'" class="v-btn v-btn--variant-text v-btn--size-small" style="cursor: pointer">
                  <VIcon icon="tabler-camera" size="16" class="me-1" />{{ t('warehouse_management.returns_page.add_photo') }}
                  <input type="file" accept="image/*,application/pdf" hidden @change="e => attach(l, e)">
                </label>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="rma.status === 'AUTHORISED' && can.returns">
        <span class="text-caption text-medium-emphasis ms-2">{{ t('warehouse_management.returns_page.receive_hint') }}</span>
        <VSpacer /><VBtn color="primary" @click="receive">{{ t('warehouse_management.returns_page.receive') }}</VBtn>
      </VCardActions>
    </VCard>

    <VDialog v-model="crediting" max-width="440">
      <VCard :title="t('warehouse_management.returns_page.raise_credit')">
        <VCardText>
          <p class="text-body-2">{{ t('warehouse_management.returns_page.credit_hint', { invoice: rma.invoice, outstanding: money(rma.invoice_outstanding), cost: money(rma.value) }) }}</p>
          <VTextField v-model.number="credit.amount" type="number" min="0" :label="t('warehouse_management.returns_page.amount')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="crediting = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="credit.processing" @click="credit.post(route('warehouse.returns.act', rma.id), { preserveScroll: true, onSuccess: () => crediting = false })">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog v-model="cancelling" max-width="440">
      <VCard :title="t('warehouse_management.returns_page.cancel_title')">
        <VCardText><VTextarea v-model="note" rows="2" :label="t('warehouse_management.common.reason')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="cancelling = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="error" @click="router.post(route('warehouse.returns.act', rma.id), { action: 'cancel', note }); cancelling = false">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
