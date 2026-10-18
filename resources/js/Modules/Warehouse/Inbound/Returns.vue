<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Ret = { id: string; number: string; status: string; supplier: string | null; warehouse: string; grn: string | null; reason: string; carrier: string | null; dispatch_reference: string | null; dispatched_at: string | null; acknowledged_by_name: string | null; outcome: string | null; credit_reference: string | null; lines: Array<{ item: string; name: string; qty: number; uom: string; bin: string | null; lot: string | null; status: string }> }
type Pos = { item_id: number; item: string; name: string; uom: string; bin_id: number | null; bin: string | null; lot_id: number | null; lot: string | null; status: string; qty: number }

const props = defineProps<{
  returns: { data: Ret[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  warehouses: Array<{ id: string; title: string }>
  suppliers: Array<{ id: number; title: string }>
  prefill: { grn: string; grn_number: string; supplier_id: number | null; warehouse: string | null } | null
  can: { manage: boolean }
}>()

const dialog = ref(!!props.prefill)
const form = useForm({ warehouse: props.prefill?.warehouse ?? props.warehouses[0]?.id ?? null, supplier_id: props.prefill?.supplier_id ?? null,
  grn: props.prefill?.grn ?? null, reason: props.prefill ? t('warehouse_management.returns.from_grn', { grn: props.prefill.grn_number }) : '',
  lines: [] as Array<Pos & { take: number }> })
const positions = ref<Pos[]>([])
watch(() => form.warehouse, async (w) => {
  if (!w) return
  const { data } = await axios.get(route('warehouse.inbound.returns.returnable'), { params: { warehouse: w } })
  positions.value = data
}, { immediate: true })
function add(p: Pos) {
  if (!form.lines.some(l => l.item_id === p.item_id && l.bin_id === p.bin_id && l.lot_id === p.lot_id && l.status === p.status)) form.lines.push({ ...p, take: p.qty })
}
function save() {
  form.transform(d => ({ ...d, lines: d.lines.map(l => ({ item_id: l.item_id, qty: l.take, bin_id: l.bin_id, lot_id: l.lot_id, stock_status: l.status })) }))
    .post(route('warehouse.inbound.returns.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } })
}

const acting = ref<{ r: Ret; action: string } | null>(null)
const actForm = useForm({ action: '', carrier: '', reference: '', acknowledged_by: '', outcome: 'CREDIT', credit_reference: '' })
function act(r: Ret, action: string) {
  if (['approve', 'cancel'].includes(action)) {
    router.post(route('warehouse.inbound.returns.move', r.id), { action }, { preserveScroll: true })
    return
  }
  acting.value = { r, action }
  actForm.reset()
  actForm.action = action
}
function submitAct() {
  if (!acting.value) return
  actForm.post(route('warehouse.inbound.returns.move', acting.value.r.id), { preserveScroll: true, onSuccess: () => { acting.value = null } })
}
const actions: Record<string, string[]> = { DRAFT: ['approve', 'cancel'], APPROVED: ['dispatch', 'cancel'], DISPATCHED: ['acknowledge'], ACKNOWLEDGED: ['close'] }
const color = (s: string) => ({ DRAFT: 'secondary', APPROVED: 'primary', DISPATCHED: 'warning', ACKNOWLEDGED: 'info', CLOSED: 'success', CANCELLED: 'secondary' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.returns.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.returns.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('warehouse_management.returns.new') }}</VBtn>
    </div>
    <RefusalAlert />
    <VCard border>
      <VCardText v-if="!returns.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.returns.none') }}</VCardText>
      <VList v-else lines="three">
        <VListItem v-for="r in returns.data" :key="r.id">
          <VListItemTitle>{{ r.number }} · {{ r.supplier }} <VChip size="x-small" :color="color(r.status)" variant="tonal" class="ms-1">{{ t(`warehouse_management.return_status.${r.status}`) }}</VChip></VListItemTitle>
          <VListItemSubtitle>
            {{ r.reason }}<template v-if="r.grn"> · {{ r.grn }}</template>
            <div>{{ r.lines.map(l => `${l.qty} ${l.uom} ${l.item}${l.lot ? ' (' + l.lot + ')' : ''}${l.bin ? ' @' + l.bin : ''}`).join('; ') }}</div>
            <div v-if="r.dispatched_at">{{ t('warehouse_management.returns.dispatched', { at: new Date(r.dispatched_at).toLocaleString(), carrier: r.carrier ?? '', ref: r.dispatch_reference ?? '' }) }}</div>
            <div v-if="r.acknowledged_by_name">{{ t('warehouse_management.returns.acknowledged', { by: r.acknowledged_by_name }) }}</div>
            <div v-if="r.outcome">{{ t(`warehouse_management.return_outcome.${r.outcome}`) }} {{ r.credit_reference ?? '' }}</div>
          </VListItemSubtitle>
          <template v-if="can.manage" #append>
            <VBtn v-for="a in (actions[r.status] ?? [])" :key="a" size="small" variant="tonal" class="ms-1" :color="a === 'cancel' ? 'secondary' : 'primary'" @click="act(r, a)">{{ t(`warehouse_management.returns.action.${a}`) }}</VBtn>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <VDialog v-model="dialog" max-width="760" scrollable>
      <VCard>
        <VCardTitle>{{ t('warehouse_management.returns.new') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="form.warehouse" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.common.warehouse')" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="form.supplier_id" :items="suppliers" item-title="title" item-value="id" :label="t('warehouse_management.products.supplier')" :error-messages="form.errors.supplier_id" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.reason" rows="2" auto-grow :label="t('warehouse_management.common.reason')" :error-messages="form.errors.reason" /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-2">{{ t('warehouse_management.returns.held_stock') }}</div>
          <div class="d-flex flex-wrap gap-1 mb-3">
            <VChip v-for="(p, i) in positions" :key="i" size="small" @click="add(p)">{{ p.item }} · {{ p.qty }} {{ p.uom }} · {{ t(`warehouse_management.stock_state.${p.status}`) }}<template v-if="p.bin"> · {{ p.bin }}</template><template v-if="p.lot"> · {{ p.lot }}</template></VChip>
            <span v-if="!positions.length" class="text-caption text-medium-emphasis">{{ t('warehouse_management.stock.nothing_held') }}</span>
          </div>
          <div v-for="(l, i) in form.lines" :key="i" class="d-flex align-center gap-2 mb-2">
            <div class="flex-grow-1">{{ l.item }} — {{ l.name }} · {{ t(`warehouse_management.stock_state.${l.status}`) }}<template v-if="l.bin"> · {{ l.bin }}</template></div>
            <VTextField v-model.number="l.take" type="number" min="0" :max="l.qty" density="compact" hide-details style="max-width: 120px" />
            <VBtn icon="tabler-x" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="form.lines.splice(i, 1)" />
          </div>
          <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal">{{ (form.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :disabled="!form.lines.length" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!acting" max-width="480" @update:model-value="v => { if (!v) acting = null }">
      <VCard v-if="acting">
        <VCardTitle>{{ t(`warehouse_management.returns.action.${acting.action}`) }} — {{ acting.r.number }}</VCardTitle>
        <VCardText>
          <template v-if="acting.action === 'dispatch'">
            <VTextField v-model="actForm.carrier" :label="t('warehouse_management.appointments.carrier')" />
            <VTextField v-model="actForm.reference" :label="t('warehouse_management.returns.reference')" />
            <p class="text-caption">{{ t('warehouse_management.returns.dispatch_hint') }}</p>
          </template>
          <VTextField v-if="acting.action === 'acknowledge'" v-model="actForm.acknowledged_by" :label="t('warehouse_management.returns.acknowledged_by')" />
          <template v-if="acting.action === 'close'">
            <VSelect v-model="actForm.outcome" :items="['CREDIT', 'REPLACEMENT', 'NONE'].map(o => ({ value: o, title: t(`warehouse_management.return_outcome.${o}`) }))" />
            <VTextField v-if="actForm.outcome === 'CREDIT'" v-model="actForm.credit_reference" :label="t('warehouse_management.returns.credit_reference')" />
          </template>
          <VAlert v-if="(actForm.errors as any).refused" type="error" variant="tonal">{{ (actForm.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="acting = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="actForm.processing" @click="submitAct">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
