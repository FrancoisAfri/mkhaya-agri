<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Line = { id: number; item: string; name: string; uom: string; lot: string | null; from_bin: string | null; qty: number; picked: number; dispatched: number; received: number; variance: number }
type Order = { id: string; number: string; status: string; from: string; to: string; reason: string | null; priority: number; source: string; needed_by: string | null; carrier: string | null; tracking_reference: string | null; cancel_reason: string | null; dispatched_at: string | null; received_at: string | null; lines: Line[] }

const props = defineProps<{
  transfers: { data: Order[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { status: string | null }
  statuses: string[]
  warehouses: Array<{ id: string; title: string }>
  can: { move: boolean; approve: boolean }
}>()

const dialog = ref(false)
const form = useForm({ from: null as string | null, to: null as string | null, reason: '', needed_by: '', priority: 50,
  lines: [] as Array<{ item: string; title: string; qty: number }> })
const itemOptions = ref<Array<{ id: string; title: string }>>([])
const pick = ref<string | null>(null)
async function searchItems(q: string) {
  const { data } = await axios.get(route('warehouse.products.search'), { params: { q } })
  itemOptions.value = data
}
function addLine() {
  const o = itemOptions.value.find(x => x.id === pick.value)
  if (o) form.lines.push({ item: o.id, title: o.title, qty: 1 })
  pick.value = null
}
function create() {
  form.transform(d => ({ ...d, needed_by: d.needed_by || null })).post(route('warehouse.floor.transfers.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } })
}

const acting = ref<{ o: Order; action: string } | null>(null)
const act = useForm({ action: '', lines: {} as Record<number, { qty: number }>, carrier: '', vehicle_reg: '', tracking_reference: '', note: '' })
function open(o: Order, action: string) {
  if (action === 'approve') { router.post(route('warehouse.floor.transfers.move', o.id), { action }, { preserveScroll: true }); return }
  acting.value = { o, action }
  act.reset()
  act.action = action
  act.lines = Object.fromEntries(o.lines.map(l => [l.id, { qty: action === 'pick' ? l.qty : action === 'receive' ? l.dispatched - l.received : 0 }]))
}
function submit() {
  if (!acting.value) return
  act.post(route('warehouse.floor.transfers.move', acting.value.o.id), { preserveScroll: true, onSuccess: () => { acting.value = null } })
}
const next: Record<string, string[]> = { DRAFT: ['approve', 'cancel'], APPROVED: ['pick', 'cancel'], PICKED: ['dispatch', 'cancel'], DISPATCHED: ['receive', 'cancel'], PARTIALLY_RECEIVED: ['receive', 'reconcile', 'cancel'], RECEIVED: ['reconcile'] }
const color = (s: string) => ({ DRAFT: 'secondary', APPROVED: 'primary', PICKED: 'info', DISPATCHED: 'warning', PARTIALLY_RECEIVED: 'warning', RECEIVED: 'success', RECONCILED: 'success', CANCELLED: 'secondary' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.transfers.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.transfers.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VSelect :model-value="filters.status" :items="statuses.map(s => ({ value: s, title: t(`warehouse_management.transfer_status.${s}`) }))" clearable density="compact" hide-details style="min-width: 200px"
                 :label="t('warehouse_management.locations.status')" @update:model-value="v => router.get(route('warehouse.floor.transfers.index'), { status: v })" />
        <VBtn v-if="can.move" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('warehouse_management.transfers.new') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VCard border>
      <VCardText v-if="!transfers.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.transfers.none') }}</VCardText>
      <VList v-else lines="three">
        <VListItem v-for="o in transfers.data" :key="o.id">
          <VListItemTitle>{{ o.number }} · {{ o.from }} → {{ o.to }} <VChip size="x-small" :color="color(o.status)" variant="tonal" class="ms-1">{{ t(`warehouse_management.transfer_status.${o.status}`) }}</VChip>
            <VChip v-if="o.source === 'REPLENISHMENT'" size="x-small" variant="outlined" class="ms-1">{{ t('warehouse_management.cards.replenishment.title') }}</VChip></VListItemTitle>
          <VListItemSubtitle>
            <div>{{ o.lines.map(l => `${l.item} ${l.qty} ${l.uom}` + (l.dispatched ? ` (${t('warehouse_management.transfers.sent', { n: l.dispatched })}, ${t('warehouse_management.transfers.got', { n: l.received })})` : '') + (l.variance ? ` · ${t('warehouse_management.transfers.short', { n: l.variance })}` : '')).join('; ') }}</div>
            <div v-if="o.reason">{{ o.reason }}</div>
            <div v-if="o.tracking_reference">{{ o.carrier }} {{ o.tracking_reference }}</div>
            <div v-if="o.cancel_reason" class="text-error">{{ o.cancel_reason }}</div>
          </VListItemSubtitle>
          <template v-if="can.move" #append>
            <template v-for="a in (next[o.status] ?? [])" :key="a">
              <VBtn v-if="a !== 'approve' || can.approve" size="small" variant="tonal" class="ms-1" :color="a === 'cancel' ? 'secondary' : 'primary'" @click="open(o, a)">{{ t(`warehouse_management.transfers.action.${a}`) }}</VBtn>
            </template>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.transfers.new') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VSelect v-model="form.from" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.putaway.from')" /></VCol>
            <VCol cols="6"><VSelect v-model="form.to" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.putaway.to')" :error-messages="form.errors.to" /></VCol>
            <VCol cols="12"><VTextField v-model="form.reason" :label="t('warehouse_management.common.reason')" /></VCol>
            <VCol cols="6"><VTextField v-model="form.needed_by" type="date" :label="t('warehouse_management.transfers.needed_by')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.priority" type="number" min="1" max="100" :label="t('warehouse_management.transfers.priority')" /></VCol>
          </VRow>
          <div v-for="(l, i) in form.lines" :key="i" class="d-flex gap-2 align-center mb-2">
            <div class="flex-grow-1">{{ l.title }}</div>
            <VTextField v-model.number="l.qty" type="number" min="0" density="compact" hide-details style="max-width: 110px" />
            <VBtn icon="tabler-x" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="form.lines.splice(i, 1)" />
          </div>
          <VAutocomplete v-model="pick" :items="itemOptions" item-title="title" item-value="id" density="compact" :label="t('warehouse_management.nonpo.add_item')" @update:search="searchItems" @update:model-value="addLine" />
          <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal">{{ (form.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :disabled="!form.lines.length" :loading="form.processing" @click="create">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!acting" max-width="560" @update:model-value="v => { if (!v) acting = null }">
      <VCard v-if="acting">
        <VCardTitle>{{ t(`warehouse_management.transfers.action.${acting.action}`) }} — {{ acting.o.number }}</VCardTitle>
        <VCardText>
          <template v-if="['pick', 'receive'].includes(acting.action)">
            <div v-for="l in acting.o.lines" :key="l.id" class="d-flex align-center gap-2 mb-2">
              <div class="flex-grow-1">{{ l.item }} — {{ l.name }}</div>
              <VTextField v-model.number="act.lines[l.id].qty" type="number" min="0" density="compact" hide-details style="max-width: 110px" />
            </div>
          </template>
          <template v-if="acting.action === 'dispatch'">
            <VTextField v-model="act.carrier" :label="t('warehouse_management.appointments.carrier')" />
            <VTextField v-model="act.vehicle_reg" :label="t('warehouse_management.appointments.vehicle')" />
            <VTextField v-model="act.tracking_reference" :label="t('warehouse_management.transfers.tracking')" />
            <p class="text-caption">{{ t('warehouse_management.transfers.dispatch_hint') }}</p>
          </template>
          <VTextarea v-if="['reconcile', 'cancel'].includes(acting.action)" v-model="act.note" rows="2" auto-grow
                     :label="acting.action === 'reconcile' ? t('warehouse_management.transfers.what_happened') : t('warehouse_management.common.reason')" />
          <VAlert v-if="(act.errors as any).refused" type="error" variant="tonal">{{ (act.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="acting = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="act.processing" @click="submit">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
