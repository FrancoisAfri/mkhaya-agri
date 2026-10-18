<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute
const confirmer = ref()

type Row = {
  id: string; parent_id: string | null; code: string; name: string | null; level: string; kind: string
  status: string; effective_refusal: string | null; status_reason: string | null; restricted_until: string | null
  is_active: boolean; barcode: string | null; max_units: number | null; max_weight_kg: number | null
  max_volume_m3: number | null; max_pallets: number | null; length_cm: number | null; width_cm: number | null
  height_cm: number | null; requires_temperature_monitoring: boolean; temperature_min_c: number | null
  temperature_max_c: number | null; hazmat_allowed: boolean; handling_notes: string | null; pick_sequence: number | null
  qty: number; held_qty: number; items: number; weight_kg: number; bins: number; bins_with_stock: number
  bins_blocked: number; depth: number; occupancy: number | null; warning: 'NEAR_FULL' | 'OVER' | null
}

const props = defineProps<{
  warehouses: Array<{ id: string; code: string; name: string }>
  warehouse: { id: string; code: string; name: string } | null
  explorer: { rows: Row[]; totals: Record<string, number> } | null
  levels: string[]
  kinds: string[]
  can: { manage: boolean; block: boolean }
}>()

const search = ref('')
const showRetired = ref(false)
const collapsed = ref<Set<string>>(new Set())

const rows = computed(() => {
  const all = props.explorer?.rows ?? []
  const q = search.value.trim().toUpperCase()
  if (q) return all.filter(r => (showRetired.value || r.is_active) && (r.code.includes(q) || (r.name ?? '').toUpperCase().includes(q) || (r.barcode ?? '').toUpperCase().includes(q)))
  const hidden = new Set<string>()
  return all.filter(r => {
    if (r.parent_id && (hidden.has(r.parent_id) || collapsed.value.has(r.parent_id))) { hidden.add(r.id); return false }
    return showRetired.value || r.is_active
  })
})

const hasChildren = (r: Row) => (props.explorer?.rows ?? []).some(c => c.parent_id === r.id)
function toggle(r: Row) {
  const s = new Set(collapsed.value)
  s.has(r.id) ? s.delete(r.id) : s.add(r.id)
  collapsed.value = s
}

function chooseWarehouse(id: string) {
  router.get(route('warehouse.layout.locations.index'), { warehouse: id }, { preserveState: false })
}

const fmt = (n: number | null) => n === null ? '—' : new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(n)
const statusColor = (r: Row) => !r.is_active ? 'secondary' : r.status === 'BLOCKED' ? 'error' : r.status === 'RESTRICTED' ? 'warning' : 'success'
const nextLevel = (level: string | null) => level ? props.levels[props.levels.indexOf(level) + 1] ?? null : props.levels[0]

// ── Add / edit ──────────────────────────────────────────────────────────────
const dialog = ref(false)
const editing = ref<Row | null>(null)
const blank = () => ({
  code: '', name: '', level: 'BIN', kind: 'STORAGE', parent: null as string | null, barcode: '',
  max_units: null as number | null, max_weight_kg: null as number | null, max_volume_m3: null as number | null,
  max_pallets: null as number | null, length_cm: null as number | null, width_cm: null as number | null,
  height_cm: null as number | null, requires_temperature_monitoring: false, temperature_min_c: null as number | null,
  temperature_max_c: null as number | null, hazmat_allowed: false, handling_notes: '', pick_sequence: null as number | null,
})
const form = useForm(blank())
const parentOptions = computed(() => (props.explorer?.rows ?? [])
  .filter(r => r.level !== 'BIN' && r.is_active && r.id !== editing.value?.id)
  .map(r => ({ value: r.id, title: `${r.code} (${t(`warehouse_management.level.${r.level}`)})` })))

function openAdd(parent: Row | null) {
  editing.value = null
  Object.assign(form, blank(), {
    parent: parent?.id ?? null,
    level: nextLevel(parent?.level ?? null) ?? 'BIN',
    kind: parent?.kind ?? 'STORAGE',
    code: parent ? parent.code + '-' : '',
  })
  form.clearErrors()
  dialog.value = true
}

function openEdit(r: Row) {
  editing.value = r
  Object.assign(form, {
    code: r.code, name: r.name ?? '', level: r.level, kind: r.kind, parent: r.parent_id, barcode: r.barcode ?? '',
    max_units: r.max_units, max_weight_kg: r.max_weight_kg, max_volume_m3: r.max_volume_m3, max_pallets: r.max_pallets,
    length_cm: r.length_cm, width_cm: r.width_cm, height_cm: r.height_cm,
    requires_temperature_monitoring: r.requires_temperature_monitoring, temperature_min_c: r.temperature_min_c,
    temperature_max_c: r.temperature_max_c, hazmat_allowed: r.hazmat_allowed, handling_notes: r.handling_notes ?? '',
    pick_sequence: r.pick_sequence,
  })
  form.clearErrors()
  dialog.value = true
}

function save() {
  if (!props.warehouse) return
  const done = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editing.value) form.patch(route('warehouse.layout.locations.update', [props.warehouse.id, editing.value.id]), done)
  else form.post(route('warehouse.layout.locations.store', props.warehouse.id), done)
}

// ── Generate a run ──────────────────────────────────────────────────────────
const genFor = ref<Row | null | undefined>(undefined)
const genForm = useForm({ parent: null as string | null, count: 10, level: null as string | null, prefix: '', max_units: null as number | null, max_weight_kg: null as number | null })
function openGenerate(parent: Row | null) {
  genFor.value = parent
  genForm.reset()
  genForm.parent = parent?.id ?? null
  genForm.level = nextLevel(parent?.level ?? null)
  genForm.clearErrors()
}
function generate() {
  if (!props.warehouse) return
  genForm.post(route('warehouse.layout.locations.generate', props.warehouse.id), {
    preserveScroll: true, onSuccess: () => { genFor.value = undefined },
  })
}

// ── Block / restrict / reopen ───────────────────────────────────────────────
const statusFor = ref<Row | null>(null)
const statusForm = useForm({ status: 'BLOCKED', reason: '', until: '' })
function openStatus(r: Row, status: string) {
  statusFor.value = r
  statusForm.reset()
  statusForm.status = status
  statusForm.clearErrors()
}
function saveStatus() {
  if (!props.warehouse || !statusFor.value) return
  statusForm.transform(d => ({ ...d, until: d.until || null }))
    .post(route('warehouse.layout.locations.status', [props.warehouse.id, statusFor.value.id]), {
      preserveScroll: true, onSuccess: () => { statusFor.value = null },
    })
}

function retire(r: Row) {
  if (!props.warehouse) return
  confirmer.value.open({
    title: t('warehouse_management.locations.retire'),
    message: t('warehouse_management.locations.retire_confirm', { code: r.code }),
    confirmText: t('warehouse_management.locations.retire'),
    icon: 'tabler-archive',
  }, () => router.delete(route('warehouse.layout.locations.destroy', [props.warehouse!.id, r.id]), { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.locations.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.locations.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VSelect v-if="warehouses.length" :model-value="warehouse?.id" :items="warehouses.map(w => ({ value: w.id, title: `${w.code} — ${w.name}` }))"
                 density="compact" hide-details style="min-width: 260px" :label="t('warehouse_management.common.warehouse')"
                 @update:model-value="chooseWarehouse" />
        <VBtn v-if="warehouse" variant="tonal" prepend-icon="tabler-qrcode" :href="route('warehouse.layout.labels', { warehouse: warehouse.id })">
          {{ t('warehouse_management.cards.labels.title') }}
        </VBtn>
        <VBtn v-if="warehouse && can.manage" variant="tonal" prepend-icon="tabler-stack-2" @click="openGenerate(null)">{{ t('warehouse_management.locations.add_zones') }}</VBtn>
        <VBtn v-if="warehouse && can.manage" color="primary" prepend-icon="tabler-plus" @click="openAdd(null)">{{ t('warehouse_management.locations.add') }}</VBtn>
      </div>
    </div>

    <RefusalAlert />

    <VCard v-if="!warehouse" border>
      <VCardText class="text-center text-medium-emphasis py-10">
        {{ t('warehouse_management.locations.no_warehouse') }}
        <div class="mt-3"><VBtn variant="tonal" :href="route('warehouse.layout.warehouses.index')">{{ t('warehouse_management.warehouses.add') }}</VBtn></div>
      </VCardText>
    </VCard>

    <template v-else-if="explorer">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <VChip v-for="k in ['bins', 'bins_with_stock', 'bins_empty', 'bins_blocked', 'bins_restricted', 'bins_near_full']" :key="k"
               label variant="tonal" :color="k === 'bins_blocked' && explorer.totals[k] ? 'error' : k === 'bins_near_full' && explorer.totals[k] ? 'warning' : undefined">
          {{ t(`warehouse_management.locations.totals.${k}`) }}: {{ explorer.totals[k] }}
        </VChip>
      </div>
      <VAlert v-if="explorer.totals.unbinned_qty" type="info" variant="tonal" class="mb-3">
        {{ t('warehouse_management.locations.unbinned', { qty: fmt(explorer.totals.unbinned_qty) }) }}
      </VAlert>

      <VCard border>
        <VCardText class="d-flex gap-3 flex-wrap align-center">
          <VTextField v-model="search" prepend-inner-icon="tabler-search" density="compact" hide-details clearable
                      :placeholder="t('warehouse_management.locations.search')" style="max-width: 320px" />
          <VSwitch v-model="showRetired" density="compact" hide-details color="primary" :label="t('warehouse_management.locations.show_retired')" />
        </VCardText>
        <VCardText v-if="!rows.length" class="text-center text-medium-emphasis py-8">
          {{ t('warehouse_management.locations.none') }}
        </VCardText>
        <div v-else style="overflow-x: auto">
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('warehouse_management.common.code') }}</th>
                <th>{{ t('warehouse_management.locations.level') }}</th>
                <th>{{ t('warehouse_management.locations.kind') }}</th>
                <th>{{ t('warehouse_management.locations.status') }}</th>
                <th class="text-end">{{ t('warehouse_management.locations.qty') }}</th>
                <th class="text-end">{{ t('warehouse_management.locations.items') }}</th>
                <th style="min-width: 140px">{{ t('warehouse_management.locations.occupancy') }}</th>
                <th>{{ t('warehouse_management.locations.handling') }}</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id" :class="{ 'opacity-60': !r.is_active }">
                <td :style="{ paddingInlineStart: `${12 + r.depth * 20}px` }" class="text-no-wrap">
                  <VBtn v-if="hasChildren(r)" :icon="collapsed.has(r.id) ? 'tabler-chevron-right' : 'tabler-chevron-down'"
                        size="x-small" variant="text" :aria-label="t('warehouse_management.locations.toggle')" @click="toggle(r)" />
                  <span class="font-weight-medium">{{ r.code }}</span>
                  <span v-if="r.name" class="text-medium-emphasis"> · {{ r.name }}</span>
                  <div v-if="r.level !== 'BIN'" class="text-caption text-medium-emphasis">
                    {{ t('warehouse_management.locations.rollup', { bins: r.bins, stocked: r.bins_with_stock }) }}
                  </div>
                </td>
                <td>{{ t(`warehouse_management.level.${r.level}`) }}</td>
                <td>{{ t(`warehouse_management.kind.${r.kind}`) }}</td>
                <td>
                  <VTooltip :disabled="!r.status_reason && !r.effective_refusal" location="top">
                    <template #activator="{ props: tip }">
                      <VChip v-bind="tip" size="small" label :color="statusColor(r)" variant="tonal">
                        {{ r.is_active ? t(`warehouse_management.status.${r.status}`) : t('warehouse_management.common.retired') }}
                      </VChip>
                    </template>
                    {{ r.status_reason || r.effective_refusal }}
                  </VTooltip>
                  <VIcon v-if="r.is_active && r.status === 'OPEN' && r.effective_refusal" icon="tabler-alert-triangle" color="warning" size="16" class="ms-1" />
                </td>
                <td class="text-end" style="font-variant-numeric: tabular-nums">
                  {{ fmt(r.qty) }}
                  <div v-if="r.held_qty" class="text-caption text-warning">{{ t('warehouse_management.locations.held', { qty: fmt(r.held_qty) }) }}</div>
                </td>
                <td class="text-end">{{ r.level === 'BIN' ? r.items : '' }}</td>
                <td>
                  <template v-if="r.occupancy !== null">
                    <VProgressLinear :model-value="Math.min(100, r.occupancy * 100)" height="8" rounded
                                     :color="r.warning === 'OVER' ? 'error' : r.warning ? 'warning' : 'primary'" />
                    <div class="text-caption">{{ Math.round(r.occupancy * 100) }}%</div>
                  </template>
                  <span v-else class="text-caption text-medium-emphasis">{{ t('warehouse_management.locations.no_limit') }}</span>
                </td>
                <td>
                  <VIcon v-if="r.requires_temperature_monitoring" icon="tabler-temperature" size="18" class="me-1"
                         :title="`${fmt(r.temperature_min_c)}°C – ${fmt(r.temperature_max_c)}°C`" />
                  <VIcon v-if="r.hazmat_allowed" icon="tabler-flame" size="18" color="warning" :title="t('warehouse_management.locations.hazmat_allowed')" />
                </td>
                <td class="text-end text-no-wrap">
                  <VMenu v-if="r.is_active && (can.manage || can.block)">
                    <template #activator="{ props: menu }">
                      <VBtn v-bind="menu" icon="tabler-dots-vertical" size="small" variant="text" :aria-label="t('warehouse_management.common.actions')" />
                    </template>
                    <VList density="compact">
                      <template v-if="can.manage && r.level !== 'BIN'">
                        <VListItem prepend-icon="tabler-plus" :title="t('warehouse_management.locations.add_inside')" @click="openAdd(r)" />
                        <VListItem prepend-icon="tabler-stack-2" :title="t('warehouse_management.locations.generate_inside')" @click="openGenerate(r)" />
                      </template>
                      <VListItem v-if="can.manage" prepend-icon="tabler-pencil" :title="t('warehouse_management.common.edit')" @click="openEdit(r)" />
                      <template v-if="can.block">
                        <VListItem v-if="r.status !== 'BLOCKED'" prepend-icon="tabler-ban" :title="t('warehouse_management.locations.block')" @click="openStatus(r, 'BLOCKED')" />
                        <VListItem v-if="r.status !== 'RESTRICTED'" prepend-icon="tabler-clock-pause" :title="t('warehouse_management.locations.restrict')" @click="openStatus(r, 'RESTRICTED')" />
                        <VListItem v-if="r.status !== 'OPEN'" prepend-icon="tabler-lock-open" :title="t('warehouse_management.locations.reopen')" @click="openStatus(r, 'OPEN')" />
                      </template>
                      <VListItem prepend-icon="tabler-history" :title="t('warehouse_management.cards.location_history.title')"
                                 :href="route('warehouse.layout.history', { warehouse: warehouse.id, location: r.id })" />
                      <VListItem v-if="can.manage" prepend-icon="tabler-archive" :title="t('warehouse_management.locations.retire')" @click="retire(r)" />
                    </VList>
                  </VMenu>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </VCard>
    </template>

    <!-- Add / edit location -->
    <VDialog v-model="dialog" max-width="760" scrollable>
      <VCard>
        <VCardTitle>{{ editing ? t('warehouse_management.locations.edit', { code: editing.code }) : t('warehouse_management.locations.add') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4"><VTextField v-model="form.code" :label="t('warehouse_management.common.code')" :error-messages="form.errors.code" /></VCol>
            <VCol cols="12" md="8"><VTextField v-model="form.name" :label="t('warehouse_management.common.name')" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.level" :items="levels.map(v => ({ value: v, title: t(`warehouse_management.level.${v}`) }))" :label="t('warehouse_management.locations.level')" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.kind" :items="kinds.map(v => ({ value: v, title: t(`warehouse_management.kind.${v}`) }))" :label="t('warehouse_management.locations.kind')" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.parent" :items="parentOptions" clearable :label="t('warehouse_management.locations.inside')" />
            </VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.barcode" :label="t('warehouse_management.locations.barcode')" :hint="t('warehouse_management.locations.barcode_hint')" persistent-hint :error-messages="form.errors.barcode" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model.number="form.pick_sequence" type="number" min="0" :label="t('warehouse_management.locations.pick_sequence')" /></VCol>

            <VCol cols="12"><div class="text-subtitle-2 mt-2">{{ t('warehouse_management.locations.capacity_section') }}</div></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.max_units" type="number" min="0" :label="t('warehouse_management.locations.max_units')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.max_weight_kg" type="number" min="0" :label="t('warehouse_management.locations.max_weight_kg')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.max_volume_m3" type="number" min="0" :label="t('warehouse_management.locations.max_volume_m3')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.max_pallets" type="number" min="0" :label="t('warehouse_management.locations.max_pallets')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="form.length_cm" type="number" min="0" :label="t('warehouse_management.locations.length_cm')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="form.width_cm" type="number" min="0" :label="t('warehouse_management.locations.width_cm')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="form.height_cm" type="number" min="0" :label="t('warehouse_management.locations.height_cm')" /></VCol>

            <VCol cols="12"><div class="text-subtitle-2 mt-2">{{ t('warehouse_management.locations.handling_section') }}</div></VCol>
            <VCol cols="12" md="6"><VSwitch v-model="form.requires_temperature_monitoring" color="primary" density="compact" hide-details :label="t('warehouse_management.locations.temperature')" /></VCol>
            <VCol cols="12" md="6"><VSwitch v-model="form.hazmat_allowed" color="warning" density="compact" hide-details :label="t('warehouse_management.locations.hazmat_allowed')" /></VCol>
            <template v-if="form.requires_temperature_monitoring">
              <VCol cols="6"><VTextField v-model.number="form.temperature_min_c" type="number" :label="t('warehouse_management.locations.temp_min')" :error-messages="form.errors.temperature_min_c" /></VCol>
              <VCol cols="6"><VTextField v-model.number="form.temperature_max_c" type="number" :label="t('warehouse_management.locations.temp_max')" :error-messages="form.errors.temperature_max_c" /></VCol>
            </template>
            <VCol cols="12"><VTextarea v-model="form.handling_notes" rows="2" auto-grow :label="t('warehouse_management.locations.handling_notes')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Generate a run -->
    <VDialog :model-value="genFor !== undefined" max-width="520" @update:model-value="v => { if (!v) genFor = undefined }">
      <VCard>
        <VCardTitle>{{ genFor ? t('warehouse_management.locations.generate_title', { code: genFor.code }) : t('warehouse_management.locations.add_zones') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.locations.generate_hint') }}</p>
          <VRow dense>
            <VCol cols="6"><VTextField v-model.number="genForm.count" type="number" min="1" max="500" :label="t('warehouse_management.locations.count')" :error-messages="genForm.errors.count" /></VCol>
            <VCol cols="6">
              <VSelect v-model="genForm.level" :items="levels.map(v => ({ value: v, title: t(`warehouse_management.level.${v}`) }))" :label="t('warehouse_management.locations.level')" />
            </VCol>
            <VCol cols="12"><VTextField v-model="genForm.prefix" :label="t('warehouse_management.locations.prefix')" :hint="t('warehouse_management.locations.prefix_hint')" persistent-hint /></VCol>
            <template v-if="genForm.level === 'BIN'">
              <VCol cols="6"><VTextField v-model.number="genForm.max_units" type="number" min="0" :label="t('warehouse_management.locations.max_units')" /></VCol>
              <VCol cols="6"><VTextField v-model.number="genForm.max_weight_kg" type="number" min="0" :label="t('warehouse_management.locations.max_weight_kg')" /></VCol>
            </template>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="genFor = undefined">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="genForm.processing" @click="generate">{{ t('warehouse_management.locations.generate') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Block / restrict / reopen -->
    <VDialog :model-value="!!statusFor" max-width="480" @update:model-value="v => { if (!v) statusFor = null }">
      <VCard v-if="statusFor">
        <VCardTitle>{{ t(`warehouse_management.locations.status_title.${statusForm.status}`, { code: statusFor.code }) }}</VCardTitle>
        <VCardText>
          <p v-if="statusFor.level !== 'BIN' && statusForm.status !== 'OPEN'" class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.locations.cascades') }}</p>
          <VTextarea v-model="statusForm.reason" rows="2" auto-grow :label="t('warehouse_management.common.reason')" :error-messages="statusForm.errors.reason" />
          <VTextField v-if="statusForm.status === 'RESTRICTED'" v-model="statusForm.until" type="datetime-local"
                      :label="t('warehouse_management.locations.until')" :hint="t('warehouse_management.locations.until_hint')" persistent-hint :error-messages="statusForm.errors.until" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="statusFor = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn :color="statusForm.status === 'OPEN' ? 'primary' : 'error'" :loading="statusForm.processing" @click="saveStatus">{{ t('warehouse_management.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
