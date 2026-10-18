<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Entry = { round: number; by: string | null; qty: number; expected: number | null; moved: number; duplicate: boolean; at: string }
type Line = { id: number; bin: string | null; item: string; item_id: string; name: string; uom: string; lot: string | null; status: string; round: number; unexpected: boolean; found_empty: boolean; snapshot: number | null; expected: number | null; counted: number | null; variance: number | null; variance_value: number | null; causes: Array<{ code: string; text: string }>; investigation: string | null; adjustment: { number: string; status: string } | null; mine: boolean; entries: Entry[] }
type CountInfo = { id: string; number: string; status: string; warehouse: string; warehouse_id: string; plan: string | null; reason: string | null; trigger: string; blind: boolean; freeze: boolean; snapshot_at: string; deadline_at: string | null; completed_at: string | null; posted_at: string | null; created_by: string | null; approved_by: string | null; thresholds: { qty: number | null; pct: number | null; value: number | null } }

const props = defineProps<{ count: CountInfo; lines: Line[]; bins: Array<{ id: number; title: string }>; reveal: boolean; can: { count: boolean; manage: boolean } }>()

const counting = computed(() => ['OPEN', 'COUNTING'].includes(props.count.status))
const reviewing = computed(() => props.count.status === 'REVIEW')
const only = ref<'all' | 'todo' | 'variance'>(counting.value ? 'todo' : 'all')
const shown = computed(() => props.lines.filter(l => only.value === 'all' ? true
  : only.value === 'todo' ? ['OPEN', 'RECOUNT'].includes(l.status) : ['VARIANCE', 'ACCEPTED', 'DISMISSED', 'ADJUSTED', 'RECOUNT'].includes(l.status)))
const totals = computed(() => ({
  todo: props.lines.filter(l => ['OPEN', 'RECOUNT'].includes(l.status)).length,
  variance: props.lines.filter(l => ['VARIANCE', 'ACCEPTED', 'ADJUSTED'].includes(l.status)).length,
  value: props.lines.reduce((s, l) => s + (['DISMISSED'].includes(l.status) ? 0 : (l.variance_value ?? 0)), 0),
}))

// Each entry carries its own reference, so a double tap or a retry on a weak signal records once.
const qty = ref<Record<number, number | null>>({})
const busy = ref<number | null>(null)
const refs: Record<number, string> = {}
function enter(l: Line) {
  if (qty.value[l.id] === null || qty.value[l.id] === undefined) return
  busy.value = l.id
  // Kept until the entry is accepted, so a retry after a failed request sends the same reference.
  refs[l.id] ??= `${l.id}:${l.round}:${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`
  router.post(route('warehouse.counts.record', props.count.id), { line_id: l.id, qty: qty.value[l.id], client_ref: refs[l.id] },
    { preserveScroll: true, onSuccess: () => { qty.value[l.id] = null; delete refs[l.id] }, onFinish: () => { busy.value = null } })
}

const found = ref(false)
const foundForm = useForm({ bin_id: null as number | null, item: null as string | null, qty: 1 })
const items = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
function recordFound() { foundForm.post(route('warehouse.counts.record', props.count.id), { preserveScroll: true, onSuccess: () => { found.value = false; foundForm.reset() } }) }

const act = (action: string, extra: Record<string, unknown> = {}) => router.post(route('warehouse.counts.act', props.count.id), { action, ...extra }, { preserveScroll: true })
const deciding = ref<{ line: Line; action: 'accept' | 'dismiss' } | null>(null)
const note = ref('')
function decide() { if (!deciding.value) return; act(deciding.value.action, { line_id: deciding.value.line.id, note: note.value }); deciding.value = null; note.value = '' }
const cancelling = ref(false)
const expanded = ref<number[]>([])
const toggle = (id: number) => { expanded.value = expanded.value.includes(id) ? expanded.value.filter(x => x !== id) : [...expanded.value, id] }

const statusColor = (s: string) => ({ OPEN: 'secondary', RECOUNT: 'warning', MATCHED: 'success', VARIANCE: 'error', ACCEPTED: 'warning', DISMISSED: 'secondary', ADJUSTED: 'success' } as Record<string, string>)[s]
const countColor = (s: string) => ({ OPEN: 'secondary', COUNTING: 'primary', REVIEW: 'warning', POSTED: 'success', CANCELLED: 'secondary' } as Record<string, string>)[s]
const n = (v: number | null) => v === null ? '—' : Number(v.toFixed(4)).toString()
const money = (v: number | null) => v === null ? '—' : v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const when = (s: string | null) => s ? new Date(s).toLocaleString() : '—'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.counts.index')">{{ t('warehouse_management.counts.title') }}</a></div>
        <h4 class="text-h4">{{ count.number }} <VChip size="small" :color="countColor(count.status)" variant="tonal">{{ t(`warehouse_management.count_status.${count.status}`) }}</VChip></h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ count.warehouse }} · {{ count.plan ?? count.reason ?? t(`warehouse_management.count_trigger.${count.trigger}`) }} ·
          {{ t('warehouse_management.counts.snapshot_at', { at: when(count.snapshot_at) }) }}
          <span v-if="count.deadline_at"> · {{ t('warehouse_management.counts.due', { at: when(count.deadline_at) }) }}</span>
        </p>
        <div class="d-flex gap-1 mt-1">
          <VChip v-if="count.blind" size="x-small" prepend-icon="tabler-eye-off">{{ t('warehouse_management.counts.blind') }}</VChip>
          <VChip v-if="count.freeze" size="x-small" prepend-icon="tabler-snowflake" color="info">{{ t('warehouse_management.counts.frozen') }}</VChip>
          <VChip v-if="count.approved_by" size="x-small">{{ t('warehouse_management.counts.posted_by', { who: count.approved_by }) }}</VChip>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn v-if="counting && can.count" variant="tonal" prepend-icon="tabler-plus" @click="found = true">{{ t('warehouse_management.counts.found_stock') }}</VBtn>
        <VBtn v-if="counting && (can.count || can.manage)" color="primary" :disabled="totals.todo > 0" @click="act('submit')">{{ t('warehouse_management.counts.submit') }}</VBtn>
        <VBtn v-if="reviewing && can.manage" color="success" @click="act('post')">{{ t('warehouse_management.counts.post') }}</VBtn>
        <VBtn v-if="can.manage && ['OPEN', 'COUNTING', 'REVIEW'].includes(count.status)" variant="text" color="error" @click="cancelling = true">{{ t('warehouse_management.counts.cancel') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />

    <div class="d-flex gap-3 mb-3 flex-wrap">
      <VCard border class="pa-3" min-width="150"><div class="text-caption">{{ t('warehouse_management.counts.lines') }}</div><div class="text-h5">{{ lines.length }}</div></VCard>
      <VCard border class="pa-3" min-width="150"><div class="text-caption">{{ t('warehouse_management.counts.waiting') }}</div><div class="text-h5">{{ totals.todo }}</div></VCard>
      <VCard v-if="reveal" border class="pa-3" min-width="150"><div class="text-caption">{{ t('warehouse_management.counts.variances') }}</div><div class="text-h5">{{ totals.variance }}</div></VCard>
      <VCard v-if="reveal" border class="pa-3" min-width="150"><div class="text-caption">{{ t('warehouse_management.counts.net_value') }}</div><div class="text-h5" :class="totals.value < 0 ? 'text-error' : ''">{{ money(totals.value) }}</div></VCard>
    </div>

    <VBtnToggle v-model="only" density="compact" variant="outlined" mandatory class="mb-3">
      <VBtn value="todo">{{ t('warehouse_management.counts.show_todo') }}</VBtn>
      <VBtn value="variance">{{ t('warehouse_management.counts.show_variance') }}</VBtn>
      <VBtn value="all">{{ t('warehouse_management.common.all') }}</VBtn>
    </VBtnToggle>

    <VCard border>
      <VCardText v-if="!shown.length" class="text-center text-medium-emphasis py-8">{{ t('warehouse_management.counts.nothing_here') }}</VCardText>
      <div v-else class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr>
            <th>{{ t('warehouse_management.counts.bin') }}</th><th>{{ t('warehouse_management.counts.item') }}</th>
            <th v-if="reveal" class="text-end">{{ t('warehouse_management.counts.expected') }}</th>
            <th class="text-end">{{ t('warehouse_management.counts.counted') }}</th>
            <th v-if="reveal" class="text-end">{{ t('warehouse_management.counts.variance') }}</th>
            <th>{{ t('warehouse_management.locations.status') }}</th><th />
          </tr></thead>
          <tbody>
            <template v-for="l in shown" :key="l.id">
              <tr>
                <td class="font-weight-medium text-no-wrap">{{ l.bin ?? '—' }}</td>
                <td>{{ l.item }} <span class="text-medium-emphasis">{{ l.name }}</span><span v-if="l.lot" class="text-caption"> · {{ t('warehouse_management.counts.lot', { lot: l.lot }) }}</span>
                  <VChip v-if="l.unexpected" size="x-small" color="info" class="ms-1">{{ t('warehouse_management.counts.unexpected') }}</VChip>
                  <VChip v-if="l.found_empty" size="x-small" color="error" variant="outlined" class="ms-1">{{ t('warehouse_management.counts.empty') }}</VChip></td>
                <td v-if="reveal" class="text-end">{{ n(l.expected ?? l.snapshot) }}</td>
                <td class="text-end">
                  <div v-if="counting && can.count && ['OPEN', 'RECOUNT'].includes(l.status)" class="d-flex align-center justify-end gap-1">
                    <VTextField v-model.number="qty[l.id]" type="number" min="0" density="compact" hide-details style="max-width: 110px" :suffix="l.uom" @keyup.enter="enter(l)" />
                    <VBtn size="small" color="primary" :loading="busy === l.id" @click="enter(l)">{{ t('warehouse_management.counts.enter') }}</VBtn>
                  </div>
                  <span v-else>{{ n(l.counted) }}</span>
                </td>
                <td v-if="reveal" class="text-end" :class="(l.variance ?? 0) < 0 ? 'text-error' : (l.variance ?? 0) > 0 ? 'text-success' : ''">
                  {{ n(l.variance) }}<div v-if="l.variance_value" class="text-caption">{{ money(l.variance_value) }}</div></td>
                <td>
                  <VChip size="small" :color="statusColor(l.status)" variant="tonal">{{ t(`warehouse_management.count_line_status.${l.status}`) }}</VChip>
                  <div v-if="l.status === 'RECOUNT'" class="text-caption">{{ t('warehouse_management.counts.round', { n: l.round }) }}<span v-if="l.mine"> · {{ t('warehouse_management.counts.not_you') }}</span></div>
                  <div v-if="l.adjustment" class="text-caption">{{ l.adjustment.number }} · {{ t(`warehouse_management.adjustment_status.${l.adjustment.status}`) }}</div>
                </td>
                <td class="text-end text-no-wrap">
                  <template v-if="can.manage && (reviewing || count.status === 'COUNTING') && ['MATCHED', 'VARIANCE'].includes(l.status) && l.round < 3">
                    <VBtn size="small" variant="text" @click="act('recount', { line_id: l.id })">{{ t('warehouse_management.counts.recount') }}</VBtn>
                  </template>
                  <template v-if="can.manage && reviewing && ['VARIANCE', 'ACCEPTED', 'DISMISSED'].includes(l.status)">
                    <VBtn size="small" variant="text" color="success" @click="deciding = { line: l, action: 'accept' }">{{ t('warehouse_management.counts.accept') }}</VBtn>
                    <VBtn size="small" variant="text" @click="deciding = { line: l, action: 'dismiss' }">{{ t('warehouse_management.counts.dismiss') }}</VBtn>
                  </template>
                  <VBtn v-if="l.entries.length || l.causes.length" size="small" variant="text" :icon="expanded.includes(l.id) ? 'tabler-chevron-up' : 'tabler-chevron-down'" @click="toggle(l.id)" />
                </td>
              </tr>
              <tr v-if="expanded.includes(l.id)">
                <td :colspan="reveal ? 7 : 5" class="bg-surface-variant">
                  <div v-if="l.causes.length" class="mb-2">
                    <div class="text-caption font-weight-medium">{{ t('warehouse_management.counts.possible_causes') }}</div>
                    <div v-for="c in l.causes" :key="c.code" class="text-body-2">· {{ c.text }}</div>
                  </div>
                  <div v-if="l.investigation" class="text-body-2 mb-2"><strong>{{ t('warehouse_management.counts.investigation') }}:</strong> {{ l.investigation }}</div>
                  <div v-for="(e, i) in l.entries" :key="i" class="text-caption">
                    {{ t('warehouse_management.counts.entry_line', { round: e.round, who: e.by ?? '—', qty: n(e.qty), at: when(e.at) }) }}
                    <span v-if="e.expected !== null"> · {{ t('warehouse_management.counts.entry_expected', { qty: n(e.expected) }) }}</span>
                    <span v-if="e.moved"> · {{ t('warehouse_management.counts.entry_moved', { qty: n(e.moved) }) }}</span>
                    <VChip v-if="e.duplicate" size="x-small" color="warning" class="ms-1">{{ t('warehouse_management.counts.duplicate') }}</VChip>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog v-model="found" max-width="480">
      <VCard :title="t('warehouse_management.counts.found_stock')">
        <VCardText class="d-flex flex-column gap-3">
          <p class="text-body-2 mb-0">{{ t('warehouse_management.counts.found_hint') }}</p>
          <VAutocomplete v-model="foundForm.bin_id" :items="bins" item-value="id" :label="t('warehouse_management.counts.bin')" :error-messages="foundForm.errors.bin_id" />
          <VAutocomplete v-model="foundForm.item" :items="items" item-value="id" :label="t('warehouse_management.counts.item')" :error-messages="foundForm.errors.item" @update:search="searchItems" />
          <VTextField v-model.number="foundForm.qty" type="number" min="0" :label="t('warehouse_management.counts.counted')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="found = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="foundForm.processing" @click="recordFound">{{ t('warehouse_management.counts.enter') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="deciding !== null" max-width="480" @update:model-value="v => !v && (deciding = null)">
      <VCard v-if="deciding" :title="deciding.action === 'accept' ? t('warehouse_management.counts.accept_title') : t('warehouse_management.counts.dismiss_title')">
        <VCardText>
          <p class="text-body-2">{{ deciding.action === 'accept' ? t('warehouse_management.counts.accept_hint') : t('warehouse_management.counts.dismiss_hint') }}</p>
          <VTextarea v-model="note" rows="3" :label="t('warehouse_management.counts.investigation')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="deciding = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="decide">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="cancelling" max-width="480">
      <VCard :title="t('warehouse_management.counts.cancel')">
        <VCardText><VTextarea v-model="note" rows="3" :label="t('warehouse_management.common.reason')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="cancelling = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="error" @click="act('cancel', { note }); cancelling = false">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
