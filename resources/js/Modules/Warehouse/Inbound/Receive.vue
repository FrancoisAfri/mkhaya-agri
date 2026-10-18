<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Line = { id: number; name: string; ordered: number; received: number; outstanding: number; stock_item: { id: string; code: string } | null; uom: string | null; lot_tracked: boolean; serial_tracked: boolean }

const props = defineProps<{
  order: { id: string; number: string; supplier: string | null; expected_delivery: string | null; status: string; warehouse: string | null }
  lines: Line[]
  warehouses: Array<{ id: string; title: string }>
  appointments: Array<{ id: number; title: string }>
  can: { exceptions: boolean }
}>()

const form = useForm({
  warehouse: props.order.warehouse ?? props.warehouses[0]?.id ?? null,
  delivery_note_ref: '', vehicle_reg: '', appointment_id: null as number | null, notes: '', allow_duplicate: false,
  idempotency_key: crypto.randomUUID?.() ?? String(Date.now()),
  lines: props.lines.map(l => ({ item_id: l.id, accepted_qty: 0, rejected_qty: 0, damaged_qty: 0, missing_qty: 0, lot_number: '', expires_on: '', manufactured_on: '', serials: '', keep_rejected: false, note: '' })),
})

// A scan of the item's barcode fills the line it belongs to, adding the packaging quantity.
const scan = ref('')
const scanMessage = reactive({ text: '', ok: true })
async function onScan() {
  const code = scan.value.trim()
  scan.value = ''
  if (!code) return
  try {
    const { data: r } = await axios.get(route('warehouse.products.resolve'), { params: { code } })
    const idx = props.lines.findIndex(l => l.stock_item?.id === r.item.id)
    if (idx < 0) { scanMessage.text = t('warehouse_management.receive.wrong_item', { code: r.item.code }); scanMessage.ok = false; return }
    form.lines[idx].accepted_qty = Number(form.lines[idx].accepted_qty) + (r.qty_in_base || 1)
    if (r.batch) form.lines[idx].lot_number = r.batch
    if (r.expiry) form.lines[idx].expires_on = r.expiry
    if (r.serial) form.lines[idx].serials = [form.lines[idx].serials, r.serial].filter(Boolean).join('\n')
    scanMessage.text = t('warehouse_management.receive.scanned', { code: r.item.code, qty: r.qty_in_base })
    scanMessage.ok = true
  } catch (e: any) {
    scanMessage.text = e?.response?.data?.error ?? t('warehouse_management.receive.not_recognised')
    scanMessage.ok = false
  }
}

function receiveAll() {
  props.lines.forEach((l, i) => { form.lines[i].accepted_qty = l.outstanding })
}

function submit() {
  form.transform(d => ({
    ...d,
    lines: d.lines.map(l => ({ ...l, serial_numbers: l.serials ? l.serials.split(/[\n,]+/).map(s => s.trim()).filter(Boolean) : null,
      expires_on: l.expires_on || null, manufactured_on: l.manufactured_on || null, lot_number: l.lot_number || null })),
  })).post(route('warehouse.inbound.receive', props.order.id))
}
const needsDuplicate = () => /already received/.test((form.errors as any).refused ?? '')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.inbound.expected')">{{ t('warehouse_management.expected.title') }}</a></div>
        <h4 class="text-h4">{{ t('warehouse_management.receive.title', { po: order.number }) }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ order.supplier }}<template v-if="order.expected_delivery"> · {{ t('warehouse_management.expected.due') }} {{ order.expected_delivery }}</template></p>
      </div>
      <VBtn variant="tonal" @click="receiveAll">{{ t('warehouse_management.receive.all_outstanding') }}</VBtn>
    </div>
    <RefusalAlert />

    <VCard border class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="4"><VSelect v-model="form.warehouse" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.common.warehouse')" :error-messages="form.errors.warehouse" /></VCol>
          <VCol cols="12" md="3"><VTextField v-model="form.delivery_note_ref" :label="t('warehouse_management.receive.delivery_note')" /></VCol>
          <VCol cols="12" md="2"><VTextField v-model="form.vehicle_reg" :label="t('warehouse_management.receive.vehicle')" /></VCol>
          <VCol cols="12" md="3"><VSelect v-model="form.appointment_id" :items="appointments" item-title="title" item-value="id" clearable :label="t('warehouse_management.receive.appointment')" /></VCol>
          <VCol cols="12"><VTextField v-model="scan" prepend-inner-icon="tabler-scan" :label="t('warehouse_management.receive.scan')" :hint="scanMessage.text" persistent-hint
                                      :color="scanMessage.ok ? undefined : 'error'" @keyup.enter="onScan" /></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard v-for="(l, i) in lines" :key="l.id" border class="mb-3">
      <VCardItem>
        <VCardTitle class="text-subtitle-1">{{ l.name }} <VChip v-if="l.stock_item" size="x-small" class="ms-1">{{ l.stock_item.code }}</VChip></VCardTitle>
        <VCardSubtitle>{{ t('warehouse_management.receive.line_summary', { ordered: l.ordered, received: l.received, outstanding: l.outstanding, uom: l.uom ?? '' }) }}
          <span v-if="!l.stock_item" class="text-warning"> · {{ t('warehouse_management.receive.no_stock_item') }}</span></VCardSubtitle>
      </VCardItem>
      <VCardText>
        <VRow dense>
          <VCol cols="6" md="2"><VTextField v-model.number="form.lines[i].accepted_qty" type="number" min="0" :label="t('warehouse_management.receive.accepted')" /></VCol>
          <VCol cols="6" md="2"><VTextField v-model.number="form.lines[i].rejected_qty" type="number" min="0" :label="t('warehouse_management.receive.rejected')" /></VCol>
          <VCol cols="6" md="2"><VTextField v-model.number="form.lines[i].damaged_qty" type="number" min="0" :label="t('warehouse_management.receive.damaged')" :hint="t('warehouse_management.receive.damaged_hint')" /></VCol>
          <VCol cols="6" md="2"><VTextField v-model.number="form.lines[i].missing_qty" type="number" min="0" :label="t('warehouse_management.receive.missing')" :hint="t('warehouse_management.receive.missing_hint')" /></VCol>
          <VCol cols="12" md="4"><VSwitch v-if="form.lines[i].rejected_qty > 0" v-model="form.lines[i].keep_rejected" color="warning" density="compact" :label="t('warehouse_management.receive.keep_rejected')" /></VCol>
          <template v-if="l.stock_item">
            <VCol cols="12" md="4"><VTextField v-model="form.lines[i].lot_number" :label="t('warehouse_management.receive.lot') + (l.lot_tracked ? ' *' : '')" /></VCol>
            <VCol cols="6" md="4"><VTextField v-model="form.lines[i].expires_on" type="date" :label="t('warehouse_management.receive.expires_on')" /></VCol>
            <VCol cols="6" md="4"><VTextField v-model="form.lines[i].manufactured_on" type="date" :label="t('warehouse_management.receive.manufactured_on')" /></VCol>
            <VCol v-if="l.serial_tracked" cols="12"><VTextarea v-model="form.lines[i].serials" rows="2" auto-grow :label="t('warehouse_management.receive.serials')" :hint="t('warehouse_management.receive.serials_hint')" persistent-hint /></VCol>
          </template>
          <VCol cols="12"><VTextField v-model="form.lines[i].note" density="compact" :label="t('warehouse_management.receive.note')" /></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard border>
      <VCardText>
        <VTextarea v-model="form.notes" rows="2" auto-grow :label="t('warehouse_management.adjustments.notes')" />
        <VSwitch v-if="needsDuplicate() && can.exceptions" v-model="form.allow_duplicate" color="warning" :label="t('warehouse_management.receive.allow_duplicate')" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn color="primary" size="large" :loading="form.processing" @click="submit">{{ t('warehouse_management.receive.submit') }}</VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
