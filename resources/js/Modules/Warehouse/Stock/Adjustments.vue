<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Adj = {
  id: string; number: string; status: string; item: string; item_name: string; uom: string; warehouse: string; bin: string | null
  lot: string | null; stock_status: string; qty: number; value: number; reason: string; notes: string | null; occurred_at: string
  approval_reasons: string[]; requested_by: string | null; decided_by: string | null; decision_note: string | null; mine: boolean
  evidence: Array<{ id: string; name: string; kind: string }>
  recoveries: Array<{ id: string; kind: string; claim_reference: string | null; claimed: number; recovered: number; status: string }>
}

const props = defineProps<{
  adjustments: { data: Adj[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { status: string | null; number: string | null }
  pending: number
  warehouses: Array<{ id: string; title: string }>
  reasons: Array<{ id: string; title: string; direction: string; always: boolean; evidence: boolean }>
  states: string[]
  can: { adjust: boolean; approve: boolean; losses: boolean }
}>()

const statusColor = (s: string) => ({ PENDING_APPROVAL: 'warning', POSTED: 'success', REJECTED: 'error', CANCELLED: 'secondary' } as Record<string, string>)[s]
const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(n)

// ── New adjustment ──────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm({ item: null as string | null, warehouse: null as string | null, bin: null as string | null, lot: null as string | null,
  stock_status: 'AVAILABLE', qty: null as number | null, uom_id: null as number | null, reason: null as string | null, notes: '', occurred_at: '',
  evidence: [] as File[] })
const itemOptions = ref<Array<{ id: string; title: string }>>([])
const positions = ref<Array<{ bin: string | null; bin_code: string | null; lot: string | null; lot_number: string | null; status: string; qty: number }>>([])
const bins = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) {
  const { data } = await axios.get(route('warehouse.products.search'), { params: { q } })
  itemOptions.value = data
}
watch(() => [form.item, form.warehouse], async ([item, warehouse]) => {
  positions.value = []
  if (!item || !warehouse) return
  const { data } = await axios.get(route('warehouse.stock.adjustments.positions'), { params: { item, warehouse } })
  positions.value = data.positions
  bins.value = data.bins
})
function usePosition(p: { bin: string | null; lot: string | null; status: string }) {
  form.bin = p.bin
  form.lot = p.lot
  form.stock_status = p.status
}
const reasonDirection = computed(() => props.reasons.find(r => r.id === form.reason)?.direction)
function save() {
  form.transform(d => ({ ...d, occurred_at: d.occurred_at || null })).post(route('warehouse.stock.adjustments.store'), {
    preserveScroll: true, forceFormData: true, onSuccess: () => { dialog.value = false; form.reset() },
  })
}
const needsEvidence = computed(() => props.reasons.find(r => r.id === form.reason)?.evidence ?? false)

// ── Evidence and recovery after the fact (WMS-081) ──────────────────────────
function attachEvidence(a: Adj, e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) router.post(route('warehouse.losses.evidence.store', a.id), { file: f }, { preserveScroll: true, forceFormData: true })
}
const claiming = ref<Adj | null>(null)
const claim = useForm({ adjustment: '', kind: 'INSURANCE', claim_reference: '', amount_claimed: null as number | null, notes: '' })
function openClaim(a: Adj) { claiming.value = a; claim.reset(); claim.adjustment = a.id; claim.amount_claimed = a.value }
function submitClaim() { claim.post(route('warehouse.losses.recoveries.store'), { preserveScroll: true, onSuccess: () => { claiming.value = null } }) }

// ── Decide ──────────────────────────────────────────────────────────────────
const deciding = ref<{ adj: Adj; decision: string } | null>(null)
const decideForm = useForm({ decision: 'approve', note: '' })
function openDecide(adj: Adj, decision: string) {
  deciding.value = { adj, decision }
  decideForm.reset()
  decideForm.decision = decision
}
function decide() {
  if (!deciding.value) return
  decideForm.post(route('warehouse.stock.adjustments.decide', deciding.value.adj.id), { preserveScroll: true, onSuccess: () => { deciding.value = null } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.adjustments.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.adjustments.subtitle') }}</p>
      </div>
      <VBtn v-if="can.adjust" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('warehouse_management.adjustments.new') }}</VBtn>
    </div>

    <RefusalAlert />

    <div class="d-flex gap-2 mb-3 flex-wrap">
      <VChip v-for="s in [null, 'PENDING_APPROVAL', 'POSTED', 'REJECTED', 'CANCELLED']" :key="s ?? 'all'" :color="filters.status === s ? 'primary' : undefined"
             :variant="filters.status === s ? 'flat' : 'tonal'" @click="router.get(route('warehouse.stock.adjustments.index'), { status: s })">
        {{ s ? t(`warehouse_management.adjustment_status.${s}`) : t('warehouse_management.common.all') }}
        <template v-if="s === 'PENDING_APPROVAL' && pending"> ({{ pending }})</template>
      </VChip>
      <VChip v-if="filters.number" closable @click:close="router.get(route('warehouse.stock.adjustments.index'))">{{ filters.number }}</VChip>
    </div>

    <VCard border>
      <VCardText v-if="!adjustments.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.adjustments.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('warehouse_management.adjustments.number') }}</th><th>{{ t('warehouse_management.movements.item') }}</th>
              <th>{{ t('warehouse_management.movements.where') }}</th><th class="text-end">{{ t('warehouse_management.movements.qty') }}</th>
              <th class="text-end">{{ t('warehouse_management.movements.value') }}</th><th>{{ t('warehouse_management.common.reason') }}</th>
              <th>{{ t('warehouse_management.locations.status') }}</th><th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in adjustments.data" :key="a.id">
              <td class="text-no-wrap">{{ a.number }}<div class="text-caption text-medium-emphasis">{{ new Date(a.occurred_at).toLocaleDateString() }}</div></td>
              <td>{{ a.item }}<div class="text-caption text-medium-emphasis">{{ a.item_name }}</div></td>
              <td class="text-caption">{{ a.warehouse }}<template v-if="a.bin"> / {{ a.bin }}</template><div v-if="a.lot">{{ a.lot }}</div><div v-if="a.stock_status !== 'AVAILABLE'">{{ a.stock_status }}</div></td>
              <td class="text-end" :class="a.qty < 0 ? 'text-error' : 'text-success'" style="font-variant-numeric: tabular-nums">{{ a.qty > 0 ? '+' : '' }}{{ fmt(a.qty) }} {{ a.uom }}</td>
              <td class="text-end" style="font-variant-numeric: tabular-nums">{{ a.value.toFixed(2) }}</td>
              <td class="text-caption">{{ a.reason }}<div v-if="a.notes">{{ a.notes }}</div><div>{{ t('warehouse_management.movements.by', { who: a.requested_by }) }}</div></td>
              <td>
                <VChip size="small" label variant="tonal" :color="statusColor(a.status)">{{ t(`warehouse_management.adjustment_status.${a.status}`) }}</VChip>
                <div v-if="a.approval_reasons.length && a.status === 'PENDING_APPROVAL'" class="text-caption text-warning">{{ a.approval_reasons.join('; ') }}</div>
                <div v-if="a.decided_by" class="text-caption">{{ a.decided_by }}<template v-if="a.decision_note">: {{ a.decision_note }}</template></div>
                <div v-for="e in a.evidence" :key="e.id" class="text-caption"><VIcon icon="tabler-paperclip" size="12" /> <a :href="route('warehouse.losses.evidence.download', e.id)">{{ e.name }}</a></div>
                <div v-for="r in a.recoveries" :key="r.id" class="text-caption">{{ t(`warehouse_management.recovery_kind.${r.kind}`) }}: {{ r.claimed.toFixed(2) }} · {{ t(`warehouse_management.recovery_status.${r.status}`) }}</div>
              </td>
              <td class="text-end text-no-wrap">
                <template v-if="a.status === 'PENDING_APPROVAL'">
                  <template v-if="can.approve && !a.mine">
                    <VBtn size="small" color="success" variant="tonal" class="me-1" @click="openDecide(a, 'approve')">{{ t('warehouse_management.adjustments.approve') }}</VBtn>
                    <VBtn size="small" color="error" variant="tonal" @click="openDecide(a, 'reject')">{{ t('warehouse_management.adjustments.reject') }}</VBtn>
                  </template>
                  <VBtn v-if="a.mine" size="small" variant="text" @click="openDecide(a, 'cancel')">{{ t('warehouse_management.adjustments.withdraw') }}</VBtn>
                </template>
                <label v-if="can.adjust || can.losses" class="v-btn v-btn--variant-text v-btn--size-small" style="cursor: pointer" :title="t('warehouse_management.adjustments.add_evidence')">
                  <VIcon icon="tabler-camera" size="16" /><input type="file" accept="image/*,application/pdf" hidden @change="e => attachEvidence(a, e)">
                </label>
                <VBtn v-if="can.losses && a.qty < 0 && a.status === 'POSTED'" size="small" variant="text" @click="openClaim(a)">{{ t('warehouse_management.adjustments.claim') }}</VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="adjustments.links.length > 3" class="justify-center flex-wrap">
        <VBtn v-for="(l, i) in adjustments.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardActions>
    </VCard>

    <VDialog v-model="dialog" max-width="680" scrollable>
      <VCard>
        <VCardTitle>{{ t('warehouse_management.adjustments.new') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="7"><VAutocomplete v-model="form.item" :items="itemOptions" item-title="title" item-value="id" :label="t('warehouse_management.movements.item')" :error-messages="form.errors.item" @update:search="searchItems" /></VCol>
            <VCol cols="12" md="5"><VSelect v-model="form.warehouse" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.common.warehouse')" :error-messages="form.errors.warehouse" /></VCol>
          </VRow>
          <div v-if="positions.length" class="mb-3">
            <div class="text-caption mb-1">{{ t('warehouse_management.adjustments.pick_position') }}</div>
            <VChip v-for="(p, i) in positions" :key="i" size="small" class="me-1 mb-1" :color="form.bin === p.bin && form.lot === p.lot && form.stock_status === p.status ? 'primary' : undefined" @click="usePosition(p)">
              {{ p.bin_code ?? t('warehouse_management.stock.no_bin') }}<template v-if="p.lot_number"> · {{ p.lot_number }}</template> · {{ p.status }} · {{ fmt(p.qty) }}
            </VChip>
          </div>
          <VRow dense>
            <VCol cols="12" md="4"><VSelect v-model="form.bin" :items="bins" item-title="title" item-value="id" clearable :label="t('warehouse_management.stock.bin')" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="form.stock_status" :items="states.map(s => ({ value: s, title: t(`warehouse_management.stock_state.${s}`) }))" :label="t('warehouse_management.locations.status')" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="form.reason" :items="reasons" item-title="title" item-value="id" :label="t('warehouse_management.common.reason')" :error-messages="form.errors.reason" /></VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="form.qty" type="number" :label="t('warehouse_management.adjustments.qty')" :hint="reasonDirection === 'OUT' ? t('warehouse_management.adjustments.negative') : reasonDirection === 'IN' ? t('warehouse_management.adjustments.positive') : t('warehouse_management.adjustments.signed')" persistent-hint :error-messages="form.errors.qty" />
            </VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.occurred_at" type="datetime-local" :label="t('warehouse_management.adjustments.occurred_at')" :hint="t('warehouse_management.adjustments.occurred_hint')" persistent-hint /></VCol>
            <VCol cols="12"><VTextarea v-model="form.notes" rows="2" auto-grow :label="t('warehouse_management.adjustments.notes')" /></VCol>
            <VCol cols="12"><VFileInput v-model="form.evidence" multiple accept="image/*,application/pdf" prepend-icon="tabler-camera" :label="t('warehouse_management.adjustments.evidence')"
              :hint="needsEvidence ? t('warehouse_management.adjustments.evidence_required') : t('warehouse_management.adjustments.evidence_hint')" persistent-hint /></VCol>
          </VRow>
          <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal">{{ (form.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!deciding" max-width="460" @update:model-value="v => { if (!v) deciding = null }">
      <VCard v-if="deciding">
        <VCardTitle>{{ t(`warehouse_management.adjustments.decide_title.${deciding.decision}`, { number: deciding.adj.number }) }}</VCardTitle>
        <VCardText>
          <VTextarea v-model="decideForm.note" rows="2" auto-grow :label="deciding.decision === 'reject' ? t('warehouse_management.common.reason') : t('warehouse_management.adjustments.note')" />
          <VAlert v-if="(decideForm.errors as any).refused" type="error" variant="tonal">{{ (decideForm.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deciding = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn :color="deciding.decision === 'approve' ? 'success' : 'error'" :loading="decideForm.processing" @click="decide">{{ t('warehouse_management.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
    <VDialog :model-value="claiming !== null" max-width="460" @update:model-value="v => !v && (claiming = null)">
      <VCard v-if="claiming" :title="t('warehouse_management.adjustments.claim_title', { number: claiming.number })">
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-model="claim.kind" :items="['INSURANCE', 'SUPPLIER', 'CARRIER', 'STAFF', 'OTHER'].map(k => ({ value: k, title: t(`warehouse_management.recovery_kind.${k}`) }))" :label="t('warehouse_management.losses.from_whom')" />
          <VTextField v-model="claim.claim_reference" :label="t('warehouse_management.adjustments.claim_reference')" />
          <VTextField v-model.number="claim.amount_claimed" type="number" min="0" :label="t('warehouse_management.losses.claimed')" :error-messages="claim.errors.amount_claimed" />
          <VTextarea v-model="claim.notes" rows="2" :label="t('warehouse_management.adjustments.notes')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="claiming = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="claim.processing" @click="submitClaim">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
